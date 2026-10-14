<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { get, put } from '../api.js'
import { reais, pct, data } from '../formato.js'
import { sessao } from '../sessao.js'
import NovaTabela from '../componentes/NovaTabela.vue'

const podeEditar = computed(() => ['administrativo', 'gestao'].includes(sessao.usuario?.perfil))

const reps = ref([])
const tabelas = ref([])
const repId = ref(null)
const tabId = ref(null)
const busca = ref('')
const situacao = ref('ativos')
const pagina = ref(1)
const res = ref(null)
const carregando = ref(false)
const erro = ref('')
const editando = ref(null)
const erroEd = ref('')
const novaTabela = ref(false)

const lembrar = (k, v) => { try { localStorage.setItem('catalogo.' + k, String(v)) } catch { /* sem storage */ } }
const lembrado = (k) => { try { return localStorage.getItem('catalogo.' + k) } catch { return null } }

const tabelasDaRep = computed(() => tabelas.value.filter(t => t.representada_id === repId.value && t.ativo))
const tabelaAtual = computed(() => tabelas.value.find(t => t.id === tabId.value))
const rotuloTabela = (t) => t.tipo === 'promocional'
  ? `${t.nome} (promoção${t.vigencia_fim ? ' até ' + data(t.vigencia_fim) : ''})`
  : `${t.nome}${t.qtd_precos ? '' : ' — sem preços'}`

async function carregarBase() {
  const [r, t] = await Promise.all([get('/representadas'), get('/catalogo/tabelas')])
  reps.value = r.representadas.filter(x => x.ativo)
  tabelas.value = t.tabelas
  const lr = Number(lembrado('rep'))
  repId.value = reps.value.some(x => x.id === lr) ? lr : reps.value[0]?.id ?? null
  escolherTabelaPadrao(Number(lembrado('tab')))
}
function escolherTabelaPadrao(preferida) {
  const lista = tabelasDaRep.value
  tabId.value = lista.some(t => t.id === preferida) ? preferida
    : (lista.find(t => t.qtd_precos > 0 && t.tipo !== 'promocional') || lista[0])?.id ?? null
}

let atraso
async function buscar() {
  if (!repId.value) return
  carregando.value = true; erro.value = ''
  try {
    const q = new URLSearchParams({ representada_id: repId.value, tabela_id: tabId.value || 0, q: busca.value, situacao: situacao.value, pagina: pagina.value })
    res.value = await get('/catalogo/produtos?' + q)
  } catch (e) { erro.value = e.message }
  finally { carregando.value = false }
}

watch(repId, (v, antes) => { if (antes !== null && antes !== undefined) { lembrar('rep', v); escolherTabelaPadrao(null) } })
watch(tabId, (v) => { lembrar('tab', v); pagina.value = 1; buscar() })
watch([situacao], () => { pagina.value = 1; buscar() })
watch(busca, () => { clearTimeout(atraso); atraso = setTimeout(() => { pagina.value = 1; buscar() }, 300) })
watch(pagina, buscar)

function editar(p) {
  editando.value = { ...p, substituido_por_codigo: p.substituido_por?.codigo || '' }
  erroEd.value = ''
}
async function salvar() {
  const p = editando.value
  try {
    await put(`/catalogo/produtos/${p.id}`, {
      disponibilidade: p.disponibilidade, previsao_disponivel: p.previsao_disponivel,
      substituido_por_codigo: p.substituido_por_codigo, ativo: p.ativo,
    })
    editando.value = null
    buscar()
  } catch (e) { erroEd.value = e.message }
}
async function tabelaCriada(t) {
  novaTabela.value = false
  tabelas.value = (await get('/catalogo/tabelas')).tabelas
  tabId.value = t.id
}

const disp = { em_falta: 'Em falta', sob_encomenda: 'Sob encomenda' }

onMounted(async () => {
  try { await carregarBase() } catch (e) { erro.value = e.message }
})
</script>

<template>
  <section class="tela">
    <header class="cabeca">
      <h1>Catálogo</h1>
      <div class="acoes" v-if="podeEditar">
        <button class="botao secundario" @click="novaTabela = true">Nova tabela promocional</button>
        <RouterLink class="botao" :to="{ name: 'importar-tabela', query: { representada: repId } }">Importar tabela de preços</RouterLink>
      </div>
    </header>

    <div class="reps" role="tablist" aria-label="Representada">
      <button v-for="r in reps" :key="r.id" role="tab" :aria-selected="repId === r.id" class="rep" :class="{ ativa: repId === r.id }" @click="repId = r.id">
        <span class="cor" :style="{ background: r.cor }" aria-hidden="true"></span>{{ r.nome }}
      </button>
    </div>

    <div class="filtros">
      <div class="campo">
        <label for="tab">Tabela</label>
        <select id="tab" v-model="tabId">
          <option v-for="t in tabelasDaRep" :key="t.id" :value="t.id">{{ rotuloTabela(t) }}</option>
        </select>
      </div>
      <div class="campo busca">
        <label for="q">Buscar</label>
        <input id="q" v-model="busca" type="search" placeholder="Código ou parte da descrição" autocomplete="off" />
      </div>
      <div class="campo">
        <label for="sit">Mostrar</label>
        <select id="sit" v-model="situacao">
          <option value="ativos">Produtos ativos</option>
          <option value="em_falta">Em falta ou sob encomenda</option>
          <option value="sem_preco">Sem preço nesta tabela</option>
          <option value="todos">Todos, inclusive inativos</option>
        </select>
      </div>
    </div>

    <p v-if="erro" class="aviso-erro">{{ erro }}</p>

    <div v-if="res && res.total === 0 && !carregando" class="folha vazio">
      <template v-if="busca || situacao !== 'ativos'"><p>Nenhum produto encontrado com esse filtro.</p></template>
      <template v-else>
        <p>Esta representada ainda não tem produtos.</p>
        <RouterLink v-if="podeEditar" class="botao" :to="{ name: 'importar-tabela', query: { representada: repId } }">Importar tabela de preços</RouterLink>
      </template>
    </div>

    <div v-else-if="res" class="folha lista" :class="{ carregando }">
      <p class="contagem">{{ res.total }} produto{{ res.total === 1 ? '' : 's' }}<span v-if="tabelaAtual"> · preços da tabela {{ tabelaAtual.nome }}</span></p>
      <ul class="produtos">
        <li v-for="p in res.produtos" :key="p.id" :class="{ inativo: !p.ativo }">
          <span class="codigo">{{ p.codigo }}</span>
          <div class="desc">
            <strong>{{ p.descricao }}</strong>
            <div class="marcas">
              <span v-if="p.disponibilidade !== 'normal'" class="selo ruim">{{ disp[p.disponibilidade] }}<template v-if="p.previsao_disponivel"> · {{ p.previsao_disponivel }}</template></span>
              <span v-if="p.substituido_por" class="selo info">Use o código {{ p.substituido_por.codigo }}</span>
              <span v-if="!p.ativo" class="selo neutro">Inativo</span>
              <span class="detalhe">{{ p.unidade }}<template v-if="p.ipi_pct"> · IPI {{ pct(p.ipi_pct) }}</template><template v-if="p.multiplo_venda > 1"> · múltiplo {{ p.multiplo_venda }}</template><template v-if="p.categoria"> · {{ p.categoria }}</template></span>
            </div>
          </div>
          <div class="preco">
            <span v-if="p.promocao" class="promo">{{ reais(p.promocao.preco) }}</span>
            <span :class="{ riscado: p.promocao }">{{ reais(p.preco) }}</span>
            <small v-if="p.promocao">{{ p.promocao.tabela }}<template v-if="p.promocao.ate"> até {{ data(p.promocao.ate) }}</template></small>
          </div>
          <button v-if="podeEditar" class="botao discreto" @click="editar(p)" :aria-label="'Editar ' + p.codigo">Editar</button>
        </li>
      </ul>
      <nav v-if="res.paginas > 1" class="paginacao" aria-label="Páginas">
        <button class="botao secundario" :disabled="pagina <= 1" @click="pagina--">Anterior</button>
        <span>Página {{ pagina }} de {{ res.paginas }}</span>
        <button class="botao secundario" :disabled="pagina >= res.paginas" @click="pagina++">Próxima</button>
      </nav>
    </div>

    <div v-if="editando" class="painel-lateral" role="dialog" aria-label="Editar produto" @click.self="editando = null">
      <form class="folha formulario" @submit.prevent="salvar">
        <h2>{{ editando.codigo }}</h2>
        <p>{{ editando.descricao }}</p>
        <div class="campo">
          <label for="e-disp">Disponibilidade</label>
          <select id="e-disp" v-model="editando.disponibilidade">
            <option value="normal">Normal</option>
            <option value="em_falta">Em falta</option>
            <option value="sob_encomenda">Sob encomenda</option>
          </select>
        </div>
        <div class="campo" v-if="editando.disponibilidade !== 'normal'">
          <label for="e-prev">Previsão (texto livre)</label>
          <input id="e-prev" v-model="editando.previsao_disponivel" placeholder="ex.: 2ª quinzena de novembro" />
        </div>
        <div class="campo">
          <label for="e-subst">Substituído pelo código</label>
          <input id="e-subst" v-model="editando.substituido_por_codigo" placeholder="vazio = não foi substituído" />
          <span class="dica">O vendedor recebe o aviso ao escolher este produto no pedido.</span>
        </div>
        <label class="check"><input type="checkbox" v-model="editando.ativo" /> Ativo (aparece para venda)</label>
        <p v-if="erroEd" class="aviso-erro" role="alert">{{ erroEd }}</p>
        <div class="acoes-form">
          <button type="button" class="botao secundario" @click="editando = null">Cancelar</button>
          <button class="botao">Salvar alterações</button>
        </div>
      </form>
    </div>

    <NovaTabela v-if="novaTabela" :representadas="reps" :representada-id="repId" @fechar="novaTabela = false" @criada="tabelaCriada" />
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 16px; max-width: 1080px; }
.cabeca { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.acoes { display: flex; gap: 8px; flex-wrap: wrap; }
.reps { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 2px; }
.rep {
  font: inherit; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;
  min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--linha-forte); background: var(--folha); color: var(--grafite); cursor: pointer;
}
.rep.ativa { border-color: var(--carbono); background: var(--carbono); color: #fff; }
.cor { width: 10px; height: 10px; border-radius: 50%; }
.filtros { display: grid; grid-template-columns: minmax(220px, 1.2fr) 2fr minmax(180px, 1fr); gap: 12px; }
.lista.carregando { opacity: .6; }
.contagem { padding: 12px 16px 4px; color: var(--grafite-2); font-size: var(--t-1); }
.produtos { list-style: none; margin: 0; padding: 0; }
.produtos li { display: grid; grid-template-columns: 90px 1fr auto auto; gap: 14px; align-items: center; padding: 12px 16px; border-top: 1px solid var(--linha); }
.produtos li.inativo { color: var(--grafite-3); }
.codigo { font-family: var(--fonte-estreita); font-weight: 600; color: var(--carbono); font-size: 1.05rem; }
.desc strong { font-weight: 500; }
.marcas { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 4px; }
.detalhe { font-size: var(--t-1); color: var(--grafite-2); }
.preco { display: grid; justify-items: end; font-family: var(--fonte-estreita); font-weight: 600; font-size: 1.1rem; min-width: 110px; }
.preco .promo { color: var(--pago); }
.preco .riscado { text-decoration: line-through; color: var(--grafite-3); font-weight: 500; font-size: 0.95rem; }
.preco small { font-family: var(--fonte); font-weight: 400; font-size: 0.75rem; color: var(--grafite-2); }
.paginacao { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 14px; border-top: 1px solid var(--linha); }
.painel-lateral { position: fixed; inset: 0; background: rgb(26 31 39 / 0.35); display: flex; justify-content: flex-end; z-index: 30; }
.formulario { width: min(440px, 100%); height: 100%; overflow-y: auto; border-radius: 0; padding: 22px 20px; display: grid; gap: 14px; align-content: start; }
.dica { font-size: var(--t-1); color: var(--grafite-3); }
.acoes-form { display: flex; gap: 10px; justify-content: flex-end; }

@media (max-width: 860px) {
  .filtros { grid-template-columns: 1fr; }
  .produtos li { grid-template-columns: 1fr auto; grid-template-areas: "cod preco" "desc desc" "ed ed"; gap: 4px 12px; }
  .codigo { grid-area: cod; }
  .desc { grid-area: desc; }
  .preco { grid-area: preco; }
  .produtos li > .botao { grid-area: ed; justify-self: start; padding-left: 0; }
}
</style>

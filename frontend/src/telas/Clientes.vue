<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get, post } from '../api.js'
import { reais, data } from '../formato.js'
import { sessao, ehGestao } from '../sessao.js'

const route = useRoute()
const router = useRouter()
const ehVendedor = computed(() => sessao.usuario?.perfil === 'vendedor')

const f = ref({
  q: route.query.q || '', cidade: route.query.cidade || '', segmento_id: route.query.segmento_id || '',
  tag_id: route.query.tag_id || '', carteira: route.query.carteira || '', vendedor_id: route.query.vendedor_id || '',
  contribuinte: route.query.contribuinte || '', ordem: route.query.ordem || 'nome',
})
const pagina = ref(Number(route.query.pagina) || 1)
const opcoes = ref(null)
const res = ref(null)
const carregando = ref(false)
const erro = ref('')
const maisFiltros = ref(false)
const atribuindo = ref(null)
const msg = ref('')

const filtrosAtivos = computed(() => ['cidade', 'segmento_id', 'tag_id', 'carteira', 'vendedor_id', 'contribuinte'].filter(k => f.value[k] !== '').length)
const corCarteira = { 'Ativo': 'ok', 'Inativo recente': 'aviso', 'Inativo antigo': 'ruim', 'Prospect': 'neutro' }

function params() {
  const p = { pagina: pagina.value }
  for (const [k, v] of Object.entries(f.value)) if (v !== '' && v !== null) p[k] = v
  return p
}
let atraso
async function buscar() {
  carregando.value = true; erro.value = ''
  const p = params()
  router.replace({ query: p })
  try { res.value = await get('/clientes?' + new URLSearchParams(p)) }
  catch (e) { erro.value = e.message }
  finally { carregando.value = false }
}
watch(() => f.value.q, () => { clearTimeout(atraso); atraso = setTimeout(() => { pagina.value = 1; buscar() }, 300) })
watch(() => [f.value.cidade, f.value.segmento_id, f.value.tag_id, f.value.carteira, f.value.vendedor_id, f.value.contribuinte, f.value.ordem], () => { pagina.value = 1; buscar() })
watch(pagina, buscar)

function limpar() {
  Object.assign(f.value, { cidade: '', segmento_id: '', tag_id: '', carteira: '', vendedor_id: '', contribuinte: '' })
}

async function atribuir() {
  msg.value = ''
  try {
    const p = params(); delete p.pagina; delete p.ordem
    const r = await post('/clientes/atribuir-vendedor', { ...p, para_vendedor_id: atribuindo.value.para })
    msg.value = `${r.alterados} cliente${r.alterados === 1 ? '' : 's'} passado${r.alterados === 1 ? '' : 's'} para ${opcoes.value.vendedores.find(v => v.id === atribuindo.value.para)?.nome || 'ninguém (sem vendedor)'}.`
    atribuindo.value = null
    buscar()
  } catch (e) { erro.value = e.message }
}

onMounted(async () => {
  try { opcoes.value = await get('/clientes/opcoes') } catch { /* filtros ficam vazios */ }
  buscar()
})
</script>

<template>
  <section class="tela">
    <header class="cabeca">
      <h1>Clientes</h1>
      <div class="acoes">
        <RouterLink v-if="ehGestao()" class="botao secundario" :to="{ name: 'importar-clientes' }">Importar planilha</RouterLink>
        <RouterLink class="botao" :to="{ name: 'cliente-novo' }">Cadastrar cliente</RouterLink>
      </div>
    </header>

    <div class="busca-linha">
      <div class="campo busca">
        <label for="q" class="sr">Buscar cliente</label>
        <input id="q" v-model="f.q" type="search" placeholder="Nome, fantasia, cidade ou CNPJ" autocomplete="off" />
      </div>
      <button class="botao secundario" @click="maisFiltros = !maisFiltros" :aria-expanded="maisFiltros">
        Filtros<span v-if="filtrosAtivos" class="contador">{{ filtrosAtivos }}</span>
      </button>
    </div>

    <div v-if="maisFiltros && opcoes" class="folha filtros">
      <div class="campo">
        <label for="f-cid">Cidade</label>
        <select id="f-cid" v-model="f.cidade">
          <option value="">Todas</option>
          <option v-for="c in opcoes.cidades" :key="c.nome" :value="c.nome">{{ c.nome }} ({{ c.n }})</option>
        </select>
      </div>
      <div class="campo">
        <label for="f-cart">Situação da carteira</label>
        <select id="f-cart" v-model="f.carteira">
          <option value="">Todas</option>
          <option v-for="c in opcoes.carteira" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>
      <div class="campo">
        <label for="f-seg">Segmento</label>
        <select id="f-seg" v-model="f.segmento_id">
          <option value="">Todos</option>
          <option v-for="s in opcoes.segmentos" :key="s.id" :value="s.id">{{ s.nome }}</option>
        </select>
      </div>
      <div class="campo">
        <label for="f-tag">Tag</label>
        <select id="f-tag" v-model="f.tag_id">
          <option value="">Todas</option>
          <option v-for="s in opcoes.tags" :key="s.id" :value="s.id">{{ s.nome }}</option>
        </select>
      </div>
      <div class="campo" v-if="!ehVendedor">
        <label for="f-vend">Vendedor</label>
        <select id="f-vend" v-model="f.vendedor_id">
          <option value="">Todos</option>
          <option value="sem">Sem vendedor</option>
          <option v-for="s in opcoes.vendedores" :key="s.id" :value="s.id">{{ s.nome }}</option>
        </select>
      </div>
      <div class="campo">
        <label for="f-contr">Contribuinte</label>
        <select id="f-contr" v-model="f.contribuinte">
          <option value="">Todos</option>
          <option value="1">Contribuinte</option>
          <option value="0">Não contribuinte</option>
          <option value="sem">Não informado</option>
        </select>
      </div>
      <div class="campo">
        <label for="f-ord">Ordenar por</label>
        <select id="f-ord" v-model="f.ordem">
          <option value="nome">Nome</option>
          <option value="ultima_compra">Compra mais recente</option>
          <option value="sem_comprar">Há mais tempo sem comprar</option>
          <option value="cadastro">Cadastro mais recente</option>
        </select>
      </div>
      <div class="filtros-acoes">
        <button class="botao discreto" @click="limpar" :disabled="!filtrosAtivos">Limpar filtros</button>
        <button v-if="ehGestao() && res?.total" class="botao discreto" @click="atribuindo = { para: '' }">Passar estes {{ res.total }} para um vendedor</button>
      </div>
    </div>

    <p v-if="msg" class="selo ok" role="status">{{ msg }}</p>
    <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>

    <div v-if="res && !res.total && !carregando" class="folha vazio">
      <template v-if="f.q || filtrosAtivos"><p>Nenhum cliente com esse filtro.</p></template>
      <template v-else-if="ehVendedor"><p>Você ainda não tem clientes na sua carteira. A gestão passa os clientes para você, ou cadastre um novo.</p></template>
      <template v-else>
        <p>Nenhum cliente cadastrado ainda.</p>
        <RouterLink v-if="ehGestao()" class="botao" :to="{ name: 'importar-clientes' }">Importar planilha do Mercos</RouterLink>
      </template>
    </div>

    <div v-else-if="res" class="folha lista" :class="{ carregando }">
      <p class="contagem">{{ res.total.toLocaleString('pt-BR') }} cliente{{ res.total === 1 ? '' : 's' }}</p>
      <ul class="clientes">
        <li v-for="c in res.clientes" :key="c.id">
          <RouterLink :to="{ name: 'cliente', params: { id: c.id } }" class="linha-cliente">
            <div class="nome">
              <strong>{{ c.fantasia || c.razao_social }}</strong>
              <span v-if="c.fantasia && c.fantasia !== c.razao_social" class="razao">{{ c.razao_social }}</span>
              <span class="meta">{{ c.cidade }}<template v-if="c.uf && c.uf !== 'SP'">/{{ c.uf }}</template><template v-if="c.segmento"> · {{ c.segmento }}</template><template v-if="c.vendedor && !ehVendedor"> · {{ c.vendedor }}</template></span>
            </div>
            <div class="selos">
              <span v-if="c.carteira" class="selo" :class="corCarteira[c.carteira]">{{ c.carteira }}</span>
              <span v-if="c.situacao === 'bloqueado'" class="selo ruim">Bloqueado</span>
              <span v-if="c.contribuinte === true" class="selo neutro">Contribuinte</span>
              <span v-else-if="c.contribuinte === false" class="selo neutro">Não contribuinte</span>
            </div>
            <div class="compra">
              <template v-if="c.ultimo_pedido_data">
                <span>{{ reais(c.ultimo_pedido_valor) }}</span>
                <small>última compra {{ data(c.ultimo_pedido_data) }}</small>
              </template>
              <small v-else>sem compras</small>
            </div>
          </RouterLink>
        </li>
      </ul>
      <nav v-if="res.paginas > 1" class="paginacao" aria-label="Páginas">
        <button class="botao secundario" :disabled="pagina <= 1" @click="pagina--">Anterior</button>
        <span>Página {{ pagina }} de {{ res.paginas }}</span>
        <button class="botao secundario" :disabled="pagina >= res.paginas" @click="pagina++">Próxima</button>
      </nav>
    </div>

    <div v-if="atribuindo" class="painel-lateral" role="dialog" aria-label="Passar para vendedor" @click.self="atribuindo = null">
      <form class="folha formulario" @submit.prevent="atribuir">
        <h2>Passar {{ res.total }} cliente{{ res.total === 1 ? '' : 's' }} para um vendedor</h2>
        <p class="explica">Todos os clientes do filtro atual ficam na carteira do vendedor escolhido.</p>
        <div class="campo">
          <label for="a-vend">Vendedor</label>
          <select id="a-vend" v-model="atribuindo.para">
            <option value="">Ninguém (tirar o vendedor)</option>
            <option v-for="v in opcoes.vendedores" :key="v.id" :value="v.id">{{ v.nome }}</option>
          </select>
        </div>
        <div class="acoes-form">
          <button type="button" class="botao secundario" @click="atribuindo = null">Cancelar</button>
          <button class="botao">Passar clientes</button>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 14px; max-width: 1080px; }
.cabeca { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.acoes { display: flex; gap: 8px; flex-wrap: wrap; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.busca-linha { display: flex; gap: 8px; align-items: end; }
.busca { flex: 1; }
.contador { background: var(--carbono); color: #fff; border-radius: 999px; font-size: 0.75rem; padding: 0 7px; margin-left: 6px; }
.filtros { padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.filtros-acoes { grid-column: 1 / -1; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.lista.carregando { opacity: .6; }
.contagem { padding: 12px 16px 4px; color: var(--grafite-2); font-size: var(--t-1); }
.clientes { list-style: none; margin: 0; padding: 0; }
.linha-cliente { display: grid; grid-template-columns: 1fr auto 150px; gap: 14px; align-items: center; padding: 12px 16px; border-top: 1px solid var(--linha); color: inherit; text-decoration: none; }
.linha-cliente:hover { background: var(--papel); }
.nome { display: grid; min-width: 0; }
.nome strong { font-weight: 600; }
.razao, .meta { font-size: var(--t-1); color: var(--grafite-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.selos { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.selo.aviso { color: var(--aviso); background: var(--aviso-claro); }
.compra { display: grid; justify-items: end; font-family: var(--fonte-estreita); font-weight: 600; }
.compra small { font-family: var(--fonte); font-weight: 400; font-size: 0.75rem; color: var(--grafite-2); }
.paginacao { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 14px; border-top: 1px solid var(--linha); }
.painel-lateral { position: fixed; inset: 0; background: rgb(26 31 39 / 0.35); display: flex; justify-content: flex-end; z-index: 30; }
.formulario { width: min(440px, 100%); height: 100%; overflow-y: auto; border-radius: 0; padding: 22px 20px; display: grid; gap: 14px; align-content: start; }
.explica { color: var(--grafite-2); }
.acoes-form { display: flex; gap: 10px; justify-content: flex-end; }
@media (max-width: 860px) {
  .linha-cliente { grid-template-columns: 1fr auto; grid-template-areas: "nome compra" "selos selos"; gap: 6px 10px; }
  .nome { grid-area: nome; }
  .compra { grid-area: compra; }
  .selos { grid-area: selos; justify-content: flex-start; }
}
</style>

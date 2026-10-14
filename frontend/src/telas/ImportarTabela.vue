<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { get, post, enviarArquivo } from '../api.js'
import { reais, pct, amostra, dataHora, data } from '../formato.js'
import NovaTabela from '../componentes/NovaTabela.vue'

const route = useRoute()
const passo = ref(1) // 1 arquivo · 2 colunas · 3 conferência · 4 pronto
const reps = ref([])
const tabelas = ref([])
const repId = ref(Number(route.query.representada) || null)
const arquivo = ref(null)
const arrastando = ref(false)
const ocupado = ref(false)
const erro = ref('')

const imp = ref(null)          // resposta do envio (colunas, sugestão)
const cfg = ref(null)          // escolhas do usuário
const previa = ref(null)
const resultado = ref(null)
const historico = ref([])
const novaTabela = ref(false)
const verLista = ref('alterados')

const campos = [
  { k: 'codigo', rotulo: 'Código', obrigatorio: true },
  { k: 'descricao', rotulo: 'Descrição', obrigatorio: true },
  { k: 'unidade', rotulo: 'Unidade' },
  { k: 'ipi', rotulo: 'IPI %' },
  { k: 'categoria', rotulo: 'Categoria / grupo' },
  { k: 'desconto_max', rotulo: 'Desconto máximo %' },
  { k: 'multiplo', rotulo: 'Múltiplo de venda' },
]

const rep = computed(() => reps.value.find(r => r.id === repId.value))
const tabelasDaRep = computed(() => tabelas.value.filter(t => t.representada_id === repId.value && t.ativo))
const nomeTabela = (id) => tabelas.value.find(t => t.id === Number(id))?.nome || ''
const rotuloColuna = (c) => `${c.letra}${c.titulo ? ' · ' + c.titulo : ''}`

async function carregarBase() {
  const [r, t] = await Promise.all([get('/representadas'), get('/catalogo/tabelas')])
  reps.value = r.representadas.filter(x => x.ativo)
  tabelas.value = t.tabelas
  if (!repId.value) repId.value = reps.value[0]?.id
  carregarHistorico()
}
async function carregarHistorico() {
  try { historico.value = (await get('/catalogo/importacoes?representada_id=' + (repId.value || 0))).importacoes } catch { historico.value = [] }
}

function escolher(e) {
  const f = e.target.files?.[0] || e.dataTransfer?.files?.[0]
  arrastando.value = false
  if (f) arquivo.value = f
}

async function enviar() {
  erro.value = ''; ocupado.value = true
  try {
    imp.value = await enviarArquivo('/catalogo/importar', arquivo.value, { representada_id: repId.value })
    montarCfg()
    passo.value = 2
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}

function montarCfg() {
  const s = imp.value.sugestao
  // só pré-escolhe a tabela quando não há dúvida (a representada tem uma tabela só)
  const normais = tabelasDaRep.value.filter(t => t.tipo !== 'promocional')
  cfg.value = {
    aba: imp.value.aba,
    linha_cabecalho: imp.value.linha_cabecalho,
    colunas: Object.fromEntries(campos.map(c => [c.k, s[c.k] ?? ''])),
    precos: (s.precos.length ? s.precos : ['']).map((col) => ({ coluna: col, tabela_id: normais.length === 1 ? normais[0].id : '' })),
    ausentes: 'manter',
  }
}

async function trocarAba(aba) {
  ocupado.value = true; erro.value = ''
  try {
    imp.value = await post(`/catalogo/importar/${imp.value.importacao_id}/aba`, { aba })
    montarCfg()
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}

async function verPrevia() {
  ocupado.value = true; erro.value = ''
  try {
    previa.value = await post(`/catalogo/importar/${imp.value.importacao_id}/previa`, {
      ...cfg.value,
      precos: cfg.value.precos.filter(p => p.coluna !== '' && p.tabela_id !== ''),
    })
    verLista.value = previa.value.precos_alterados.length ? 'alterados' : previa.value.novos.length ? 'novos' : 'erros'
    passo.value = 3
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}

async function gravar() {
  ocupado.value = true; erro.value = ''
  try {
    resultado.value = await post(`/catalogo/importar/${imp.value.importacao_id}/confirmar`)
    passo.value = 4
    carregarHistorico()
    tabelas.value = (await get('/catalogo/tabelas')).tabelas
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}

async function desfazer(id) {
  if (!confirm('Desfazer esta importação? Preços e produtos voltam ao que eram antes dela.')) return
  ocupado.value = true; erro.value = ''
  try {
    await post(`/catalogo/importacoes/${id}/desfazer`)
    await carregarHistorico()
    if (resultado.value && imp.value?.importacao_id === id) recomecar()
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}

function recomecar() {
  passo.value = 1; arquivo.value = null; imp.value = null; cfg.value = null; previa.value = null; resultado.value = null; erro.value = ''
}

async function tabelaCriada(t) {
  novaTabela.value = false
  tabelas.value = (await get('/catalogo/tabelas')).tabelas
  const vazia = cfg.value.precos.find(p => p.tabela_id === '')
  if (vazia) vazia.tabela_id = t.id
  else cfg.value.precos.push({ coluna: '', tabela_id: t.id })
}

const precoCompleto = computed(() => cfg.value?.precos.some(p => p.coluna !== '' && p.tabela_id !== ''))
const amostraDe = (col) => imp.value?.colunas.find(c => c.indice === Number(col))?.amostra.map(amostra).join(' · ') || ''
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`

onMounted(() => carregarBase().catch(e => { erro.value = e.message }))
</script>

<template>
  <section class="tela">
    <header>
      <RouterLink :to="{ name: 'catalogo' }" class="voltar">Catálogo</RouterLink>
      <h1>Importar tabela de preços</h1>
    </header>

    <ol class="passos" aria-label="Etapas">
      <li :class="{ atual: passo === 1, feito: passo > 1 }">Arquivo</li>
      <li :class="{ atual: passo === 2, feito: passo > 2 }">Colunas</li>
      <li :class="{ atual: passo === 3, feito: passo > 3 }">Conferência</li>
      <li :class="{ atual: passo === 4 }">Pronto</li>
    </ol>

    <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>

    <!-- 1. arquivo -->
    <div v-if="passo === 1" class="folha bloco">
      <div class="campo">
        <label for="rep">Representada</label>
        <select id="rep" v-model="repId" @change="carregarHistorico">
          <option v-for="r in reps" :key="r.id" :value="r.id">{{ r.nome }}</option>
        </select>
      </div>
      <label class="soltar" :class="{ arrastando, escolhido: arquivo }"
        @dragover.prevent="arrastando = true" @dragleave="arrastando = false" @drop.prevent="escolher">
        <input type="file" accept=".xlsx,.xlsm,.csv,.txt,.xls" @change="escolher" />
        <strong v-if="arquivo">{{ arquivo.name }}</strong>
        <strong v-else>Escolha a planilha da fábrica</strong>
        <span>.xlsx ou .csv, do jeito que a fábrica mandou. Arquivos .xls antigos: abra no Excel e salve como .xlsx.</span>
      </label>
      <div class="acoes">
        <button class="botao" :disabled="!arquivo || !repId || ocupado" @click="enviar">{{ ocupado ? 'Lendo a planilha…' : 'Ler a planilha' }}</button>
      </div>
    </div>

    <!-- 2. colunas -->
    <div v-if="passo === 2" class="grade-colunas">
      <div class="folha bloco">
        <h2>Onde está cada informação</h2>
        <p class="explica">{{ rep?.nome }} · {{ imp.total_linhas }} linhas abaixo do cabeçalho. Confira as colunas que o sistema reconheceu.</p>
        <div class="linha-dupla">
          <div class="campo" v-if="imp.abas.length > 1">
            <label for="aba">Aba da planilha</label>
            <select id="aba" :value="imp.aba" @change="trocarAba(Number($event.target.value))">
              <option v-for="(a, i) in imp.abas" :key="i" :value="i">{{ a }}</option>
            </select>
          </div>
          <div class="campo">
            <label for="cab">Linha do cabeçalho (0 = não tem)</label>
            <input id="cab" v-model.number="cfg.linha_cabecalho" type="number" min="0" inputmode="numeric" />
          </div>
        </div>

        <div v-for="c in campos" :key="c.k" class="campo mapa">
          <label :for="'m-' + c.k">{{ c.rotulo }}<template v-if="c.obrigatorio"> *</template></label>
          <select :id="'m-' + c.k" v-model="cfg.colunas[c.k]">
            <option value="">{{ c.obrigatorio ? 'Escolha a coluna' : 'Não tem na planilha' }}</option>
            <option v-for="col in imp.colunas" :key="col.indice" :value="col.indice">{{ rotuloColuna(col) }}</option>
          </select>
          <span class="amostra" v-if="cfg.colunas[c.k] !== ''">{{ amostraDe(cfg.colunas[c.k]) }}</span>
        </div>

        <h3>Preços</h3>
        <p class="explica">Cada coluna de preço entra numa tabela. Ex.: "Preço contribuinte" na tabela Rental (contribuinte).</p>
        <div v-for="(p, i) in cfg.precos" :key="i" class="preco-linha">
          <div class="campo">
            <label :for="'pc-' + i">Coluna</label>
            <select :id="'pc-' + i" v-model="p.coluna">
              <option value="">Escolha</option>
              <option v-for="col in imp.colunas" :key="col.indice" :value="col.indice">{{ rotuloColuna(col) }}</option>
            </select>
            <span class="amostra" v-if="p.coluna !== ''">{{ amostraDe(p.coluna) }}</span>
          </div>
          <div class="campo">
            <label :for="'pt-' + i">Entra na tabela</label>
            <select :id="'pt-' + i" v-model="p.tabela_id">
              <option value="">Escolha</option>
              <option v-for="t in tabelasDaRep" :key="t.id" :value="t.id">{{ t.nome }}{{ t.tipo === 'promocional' ? ' (promoção)' : '' }}</option>
            </select>
          </div>
          <button v-if="cfg.precos.length > 1" class="botao discreto" @click="cfg.precos.splice(i, 1)" :aria-label="'Remover preço ' + (i + 1)">Remover</button>
        </div>
        <div class="acoes-esq">
          <button class="botao discreto" @click="cfg.precos.push({ coluna: '', tabela_id: '' })">Adicionar outra coluna de preço</button>
          <button class="botao discreto" @click="novaTabela = true">Criar tabela promocional</button>
        </div>

        <fieldset class="ausentes">
          <legend>Produtos que estão nessas tabelas mas não vieram na planilha</legend>
          <label class="check"><input type="radio" value="manter" v-model="cfg.ausentes" /> Manter como estão (planilha parcial ou de reajuste)</label>
          <label class="check"><input type="radio" value="tirar_da_tabela" v-model="cfg.ausentes" /> Tirar o preço deles destas tabelas</label>
          <label class="check"><input type="radio" value="desativar" v-model="cfg.ausentes" /> Desativar os produtos (fora de linha)</label>
        </fieldset>

        <div class="acoes">
          <button class="botao secundario" @click="recomecar">Trocar arquivo</button>
          <span v-if="!precoCompleto" class="dica-acao">Escolha a coluna de preço e a tabela onde ela entra.</span>
          <button class="botao" :disabled="ocupado || cfg.colunas.codigo === '' || cfg.colunas.descricao === '' || !precoCompleto" @click="verPrevia">{{ ocupado ? 'Comparando…' : 'Ver o que vai mudar' }}</button>
        </div>
      </div>

      <aside class="folha bloco previa-planilha">
        <h3>Colunas da planilha</h3>
        <table class="tabela">
          <thead><tr><th>Col.</th><th>Título</th><th>Exemplo</th></tr></thead>
          <tbody>
            <tr v-for="c in imp.colunas" :key="c.indice"><td>{{ c.letra }}</td><td>{{ c.titulo || '—' }}</td><td>{{ c.amostra.map(amostra).join(' · ') }}</td></tr>
          </tbody>
        </table>
      </aside>
    </div>

    <!-- 3. conferência -->
    <div v-if="passo === 3" class="folha bloco">
      <h2>O que vai mudar</h2>
      <ul class="resumo">
        <li v-if="previa.resumo.produtos_novos"><strong>{{ plural(previa.resumo.produtos_novos, 'produto novo', 'produtos novos') }}</strong> no catálogo</li>
        <li v-if="previa.resumo.precos_subiram" class="sobe"><strong>{{ plural(previa.resumo.precos_subiram, 'preço sobe', 'preços sobem') }}</strong></li>
        <li v-if="previa.resumo.precos_cairam" class="cai"><strong>{{ plural(previa.resumo.precos_cairam, 'preço cai', 'preços caem') }}</strong></li>
        <li v-if="previa.resumo.precos_entram"><strong>{{ plural(previa.resumo.precos_entram, 'preço entra', 'preços entram') }}</strong> em tabela onde o produto não tinha preço</li>
        <li v-if="previa.resumo.precos_iguais">{{ plural(previa.resumo.precos_iguais, 'preço continua igual', 'preços continuam iguais') }}</li>
        <li v-if="previa.resumo.produtos_atualizados">{{ plural(previa.resumo.produtos_atualizados, 'produto tem', 'produtos têm') }} descrição, IPI ou disponibilidade atualizados</li>
        <li v-if="previa.resumo.em_falta">{{ plural(previa.resumo.em_falta, 'produto marcado', 'produtos marcados') }} como em falta (estava escrito na descrição)</li>
        <li v-if="previa.resumo.substituicoes">{{ plural(previa.resumo.substituicoes, 'substituição de código', 'substituições de código') }} (estava escrito na descrição)</li>
        <li v-if="previa.resumo.ausentes" class="cai">{{ plural(previa.resumo.ausentes, 'produto não veio', 'produtos não vieram') }} na planilha e {{ previa.resumo.acao_ausentes === 'desativar' ? 'serão desativados' : 'sairão das tabelas' }}</li>
        <li v-if="previa.resumo.erros" class="erro"><strong>{{ plural(previa.resumo.erros, 'linha ficará', 'linhas ficarão') }} de fora</strong> (veja abaixo)</li>
        <li v-if="previa.resumo.so_promocional">Tabela promocional: só os preços são gravados; o cadastro dos produtos não muda.</li>
      </ul>

      <div class="abas" role="tablist">
        <button role="tab" :aria-selected="verLista === 'alterados'" @click="verLista = 'alterados'" :class="{ ativa: verLista === 'alterados' }">Preços alterados ({{ previa.precos_alterados.length }})</button>
        <button role="tab" :aria-selected="verLista === 'novos'" @click="verLista = 'novos'" :class="{ ativa: verLista === 'novos' }">Novos ({{ previa.resumo.produtos_novos }})</button>
        <button v-if="previa.resumo.ausentes" role="tab" :aria-selected="verLista === 'ausentes'" @click="verLista = 'ausentes'" :class="{ ativa: verLista === 'ausentes' }">Não vieram ({{ previa.resumo.ausentes }})</button>
        <button v-if="previa.resumo.substituicoes" role="tab" :aria-selected="verLista === 'subst'" @click="verLista = 'subst'" :class="{ ativa: verLista === 'subst' }">Substituições ({{ previa.resumo.substituicoes }})</button>
        <button role="tab" :aria-selected="verLista === 'erros'" @click="verLista = 'erros'" :class="{ ativa: verLista === 'erros' }">Linhas de fora ({{ previa.resumo.erros }})</button>
      </div>

      <div class="lista-previa">
        <table v-if="verLista === 'alterados'" class="tabela">
          <thead><tr><th>Código</th><th>Descrição</th><th>Tabela</th><th class="num">Antes</th><th class="num">Agora</th><th class="num">Variação</th></tr></thead>
          <tbody>
            <tr v-for="a in previa.precos_alterados" :key="a.codigo + a.tabela_id">
              <td>{{ a.codigo }}</td><td>{{ a.descricao }}</td><td>{{ nomeTabela(a.tabela_id) }}</td>
              <td class="num">{{ reais(a.de) }}</td><td class="num">{{ reais(a.para) }}</td>
              <td class="num" :class="a.para > a.de ? 'sobe' : 'cai'">{{ a.variacao_pct > 0 ? '+' : '' }}{{ pct(a.variacao_pct) }}</td>
            </tr>
            <tr v-if="!previa.precos_alterados.length"><td colspan="6" class="vazio-linha">Nenhum preço existente muda.</td></tr>
          </tbody>
        </table>
        <table v-if="verLista === 'novos'" class="tabela">
          <thead><tr><th>Linha</th><th>Código</th><th>Descrição</th><th class="num">Preços</th></tr></thead>
          <tbody>
            <tr v-for="n in previa.novos" :key="n.codigo">
              <td>{{ n.linha }}</td><td>{{ n.codigo }}</td><td>{{ n.descricao }}</td>
              <td class="num">{{ Object.values(n.precos).map(reais).join(' / ') || 'sem preço' }}</td>
            </tr>
            <tr v-if="!previa.novos.length"><td colspan="4" class="vazio-linha">Nenhum produto novo.</td></tr>
          </tbody>
        </table>
        <table v-if="verLista === 'ausentes'" class="tabela">
          <thead><tr><th>Código</th><th>Descrição</th></tr></thead>
          <tbody><tr v-for="a in previa.ausentes" :key="a.produto_id"><td>{{ a.codigo }}</td><td>{{ a.descricao }}</td></tr></tbody>
        </table>
        <table v-if="verLista === 'subst'" class="tabela">
          <thead><tr><th>Código antigo</th><th>Usar no lugar</th></tr></thead>
          <tbody><tr v-for="s in previa.substituicoes" :key="s.codigo"><td>{{ s.codigo }}</td><td>{{ s.substituido_por }}</td></tr></tbody>
        </table>
        <table v-if="verLista === 'erros'" class="tabela">
          <thead><tr><th>Linha</th><th>Código</th><th>Motivo</th></tr></thead>
          <tbody>
            <tr v-for="e in previa.erros" :key="e.linha"><td>{{ e.linha }}</td><td>{{ e.codigo || '—' }}</td><td>{{ e.motivo }}</td></tr>
            <tr v-if="!previa.erros.length"><td colspan="3" class="vazio-linha">Todas as linhas foram lidas.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="acoes">
        <button class="botao secundario" @click="passo = 2">Voltar e ajustar colunas</button>
        <button class="botao" :disabled="ocupado || !previa.resumo.linhas_ok" @click="gravar">{{ ocupado ? 'Gravando…' : 'Gravar tabela' }}</button>
      </div>
    </div>

    <!-- 4. pronto -->
    <div v-if="passo === 4" class="folha bloco pronto">
      <h2>Tabela gravada</h2>
      <p>{{ plural(resultado.resumo.linhas_ok, 'produto lido', 'produtos lidos') }}<template v-if="resultado.resumo.produtos_novos">, {{ plural(resultado.resumo.produtos_novos, 'novo', 'novos') }}</template><template v-if="resultado.resumo.precos_subiram + resultado.resumo.precos_cairam">, {{ plural(resultado.resumo.precos_subiram + resultado.resumo.precos_cairam, 'preço alterado', 'preços alterados') }}</template>. Se algo saiu errado, dá para desfazer.</p>
      <div class="acoes-esq">
        <RouterLink class="botao" :to="{ name: 'catalogo' }">Ver no catálogo</RouterLink>
        <button class="botao secundario" @click="recomecar">Importar outra planilha</button>
        <button class="botao discreto" @click="desfazer(imp.importacao_id)" :disabled="ocupado">Desfazer esta importação</button>
      </div>
    </div>

    <section v-if="passo === 1 && historico.length" class="folha bloco">
      <h2>Importações anteriores</h2>
      <table class="tabela">
        <thead><tr><th>Quando</th><th>Arquivo</th><th>Por</th><th class="num">Produtos</th><th></th></tr></thead>
        <tbody>
          <tr v-for="h in historico" :key="h.id" :class="{ inativo: h.situacao === 'desfeita' }">
            <td>{{ dataHora(h.importada_em || h.created_at) }}</td>
            <td>{{ h.nome_original }}<span v-if="h.situacao === 'desfeita'" class="selo neutro">Desfeita</span></td>
            <td>{{ h.usuario }}</td>
            <td class="num">{{ h.linhas_ok }}</td>
            <td class="num"><button v-if="h.situacao === 'importada'" class="botao discreto" @click="desfazer(h.id)">Desfazer</button></td>
          </tr>
        </tbody>
      </table>
    </section>

    <NovaTabela v-if="novaTabela" :representadas="reps" :representada-id="repId" @fechar="novaTabela = false" @criada="tabelaCriada" />
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 16px; max-width: 1180px; }
.voltar { font-size: var(--t-1); text-decoration: none; }
.voltar::before { content: '‹ '; }
.passos { list-style: none; margin: 0; padding: 0; display: flex; gap: 0; counter-reset: p; }
.passos li { counter-increment: p; flex: 1; padding: 8px 10px; border-bottom: 3px solid var(--linha); color: var(--grafite-3); font-weight: 500; font-size: 0.95rem; }
.passos li::before { content: counter(p) '. '; }
.passos li.feito { border-color: var(--carbono-claro); color: var(--grafite-2); }
.passos li.atual { border-color: var(--carbono); color: var(--carbono); }
.bloco { padding: 20px; display: grid; gap: 14px; align-content: start; }
.explica { color: var(--grafite-2); max-width: 70ch; }
.soltar { display: grid; gap: 6px; justify-items: center; text-align: center; padding: 32px 16px; border: 2px dashed var(--linha-forte); border-radius: var(--r-folha); cursor: pointer; color: var(--grafite-2); }
.soltar strong { color: var(--grafite); font-size: var(--t1); }
.soltar.arrastando, .soltar:hover { border-color: var(--carbono); background: var(--carbono-claro); }
.soltar.escolhido { border-style: solid; border-color: var(--carbono); }
.soltar input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.soltar:focus-within { outline: 3px solid var(--carbono); outline-offset: 2px; }
.acoes { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; align-items: center; }
.dica-acao { font-size: var(--t-1); color: var(--aviso); margin-right: auto; }
.acoes-esq { display: flex; gap: 10px; flex-wrap: wrap; }
.grade-colunas { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.previa-planilha { position: sticky; top: 16px; max-height: calc(100dvh - 32px); overflow: auto; }
.linha-dupla { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.mapa { grid-template-columns: 170px 1fr; align-items: center; column-gap: 12px; }
.mapa .amostra { grid-column: 2; }
.amostra { font-size: var(--t-1); color: var(--grafite-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
h3 { margin-top: 8px; }
.preco-linha { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
.ausentes { border: 1px solid var(--linha); border-radius: var(--r-campo); padding: 8px 14px 6px; margin: 4px 0 0; }
.ausentes legend { font-size: var(--t-1); color: var(--grafite-2); padding: 0 4px; }
.resumo { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 1.05rem; }
.resumo li { padding-left: 16px; border-left: 3px solid var(--linha-forte); }
.resumo .sobe { color: var(--carimbo); border-color: var(--carimbo); }
.resumo .cai { color: var(--pago); border-color: var(--pago); }
td.sobe { color: var(--carimbo); }
td.cai { color: var(--pago); }
.resumo .erro { color: var(--aviso); border-color: var(--aviso); }
.abas { display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 1px solid var(--linha); }
.abas button { font: inherit; background: none; border: 0; border-bottom: 3px solid transparent; padding: 8px 10px; cursor: pointer; color: var(--grafite-2); }
.abas button.ativa { color: var(--carbono); border-color: var(--carbono); font-weight: 600; }
.lista-previa { max-height: 420px; overflow: auto; }
.vazio-linha { color: var(--grafite-3); text-align: center; }
.tabela tr.inativo td { color: var(--grafite-3); }
@media (max-width: 960px) {
  .grade-colunas { grid-template-columns: 1fr; }
  .previa-planilha { position: static; max-height: none; }
  .mapa { grid-template-columns: 1fr; }
  .mapa .amostra { grid-column: 1; }
  .preco-linha { grid-template-columns: 1fr; }
  .linha-dupla { grid-template-columns: 1fr; }
}
</style>

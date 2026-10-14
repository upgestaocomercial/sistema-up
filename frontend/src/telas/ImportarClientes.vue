<script setup>
import { onMounted, ref } from 'vue'
import { get, post, enviarArquivo } from '../api.js'
import { dataHora } from '../formato.js'

const arquivo = ref(null)
const arrastando = ref(false)
const ocupado = ref(false)
const erro = ref('')
const previa = ref(null)
const resultado = ref(null)
const historico = ref([])
const aba = ref('exemplos')

const n = (v) => Number(v || 0).toLocaleString('pt-BR')
const rotuloCampo = {
  razao_social: 'Razão social', fantasia: 'Nome fantasia', documento: 'CNPJ/CPF', ie: 'Inscrição estadual', email: 'E-mail',
  telefone: 'Telefone', cidade: 'Cidade', uf: 'Estado', bairro: 'Bairro', cep: 'CEP', endereco: 'Endereço', segmento: 'Segmento',
  rede: 'Rede', tags: 'Tags', vendedor: 'Vendedor', data_cadastro: 'Data de cadastro', ultimo_pedido_data: 'Data do último pedido',
  ultimo_pedido_valor: 'Valor do último pedido', situacao: 'Situação', acesso_b2b: 'Acesso B2B',
}

async function carregarHistorico() {
  try { historico.value = (await get('/clientes/importacoes')).importacoes } catch { historico.value = [] }
}
function escolher(e) {
  const f = e.target.files?.[0] || e.dataTransfer?.files?.[0]
  arrastando.value = false
  if (f) arquivo.value = f
}
async function enviar() {
  ocupado.value = true; erro.value = ''
  try { previa.value = await enviarArquivo('/clientes/importar', arquivo.value) }
  catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}
async function gravar() {
  ocupado.value = true; erro.value = ''
  try {
    resultado.value = await post(`/clientes/importar/${previa.value.importacao_id}/confirmar`)
    carregarHistorico()
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}
async function desfazer(id) {
  if (!confirm('Desfazer esta importação? Clientes criados por ela são apagados (menos os que já têm pedido) e os alterados voltam ao que eram.')) return
  ocupado.value = true; erro.value = ''
  try {
    const r = await post(`/clientes/importacoes/${id}/desfazer`)
    if (r.mantidos_por_ter_pedido) erro.value = `${r.mantidos_por_ter_pedido} cliente(s) foram mantidos porque já têm pedido ou visita.`
    await carregarHistorico()
    if (resultado.value && previa.value?.importacao_id === id) recomecar()
  } catch (e) { erro.value = e.message }
  finally { ocupado.value = false }
}
function recomecar() { arquivo.value = null; previa.value = null; resultado.value = null }

onMounted(carregarHistorico)
</script>

<template>
  <section class="tela">
    <header>
      <RouterLink :to="{ name: 'clientes' }" class="voltar">Clientes</RouterLink>
      <h1>Importar clientes</h1>
      <p class="explica">Use o relatório <strong>Carteira detalhada de clientes</strong> do Mercos (Excel). Quem já existe é atualizado pelo CNPJ/CPF; quem não existe é cadastrado. Nada é apagado.</p>
    </header>

    <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>

    <div v-if="!previa" class="folha bloco">
      <label class="soltar" :class="{ arrastando, escolhido: arquivo }" @dragover.prevent="arrastando = true" @dragleave="arrastando = false" @drop.prevent="escolher">
        <input type="file" accept=".xls,.xlsx,.csv" @change="escolher" />
        <strong>{{ arquivo ? arquivo.name : 'Escolha a planilha de clientes' }}</strong>
        <span>.xls (como o Mercos exporta), .xlsx ou .csv</span>
      </label>
      <div class="acoes">
        <button class="botao" :disabled="!arquivo || ocupado" @click="enviar">{{ ocupado ? 'Lendo e conferindo…' : 'Ler a planilha' }}</button>
      </div>
    </div>

    <template v-if="previa && !resultado">
      <div class="folha bloco">
        <h2>O que vai acontecer</h2>
        <ul class="resumo">
          <li><strong>{{ n(previa.resumo.novos) }}</strong> clientes novos</li>
          <li><strong>{{ n(previa.resumo.atualizados) }}</strong> clientes já cadastrados serão atualizados</li>
          <li v-if="previa.resumo.iguais">{{ n(previa.resumo.iguais) }} já estão iguais e não mudam</li>
          <li v-if="previa.resumo.erros" class="erro"><strong>{{ n(previa.resumo.erros) }}</strong> linhas ficam de fora (veja abaixo)</li>
        </ul>
        <h3>Limpeza feita no caminho</h3>
        <ul class="resumo limpeza">
          <li>{{ n(previa.resumo.contribuinte_pelo_nome) }} tinham "(CONTRIBUINTE)" ou parecido no nome: a marca saiu do nome e virou o campo contribuinte</li>
          <li>{{ n(previa.resumo.contribuinte_pela_ie) }} sem marca no nome tiveram o contribuinte definido pela inscrição estadual (tem IE = contribuinte; "ISENTO" = não)</li>
          <li>{{ n(previa.resumo.telefones) }} telefones separados e formatados, cada um no seu contato</li>
          <li>{{ n(previa.resumo.cidades_padronizadas) }} nomes de cidade padronizados (ex.: "SAO PAULO" → "São Paulo")</li>
          <li v-if="previa.resumo.emails_descartados">{{ n(previa.resumo.emails_descartados) }} e-mails inválidos ou de preenchimento descartados</li>
          <li v-if="previa.resumo.doc_invalido" class="erro">{{ n(previa.resumo.doc_invalido) }} CNPJ/CPF com dígito inválido (importados assim mesmo; veja os avisos)</li>
        </ul>
        <p v-if="!previa.resumo.tem_coluna_vendedor" class="aviso-amarelo">
          Esta planilha não tem a coluna de vendedor. Os clientes entram sem vendedor; depois, na lista de clientes, filtre (por tag, cidade…) e use "Passar para um vendedor".
        </p>
        <p v-if="previa.vendedores_nao_encontrados?.length" class="aviso-amarelo">
          Vendedores da planilha que não existem no sistema: {{ previa.vendedores_nao_encontrados.map(v => `${v.nome} (${v.clientes})`).join(', ') }}. Cadastre o usuário e importe de novo para ligar.
        </p>
      </div>

      <div class="folha bloco">
        <div class="abas" role="tablist">
          <button role="tab" :aria-selected="aba === 'exemplos'" :class="{ ativa: aba === 'exemplos' }" @click="aba = 'exemplos'">Exemplos da limpeza</button>
          <button role="tab" :aria-selected="aba === 'colunas'" :class="{ ativa: aba === 'colunas' }" @click="aba = 'colunas'">Colunas lidas</button>
          <button role="tab" :aria-selected="aba === 'avisos'" :class="{ ativa: aba === 'avisos' }" @click="aba = 'avisos'">Avisos ({{ previa.resumo.avisos }})</button>
          <button role="tab" :aria-selected="aba === 'erros'" :class="{ ativa: aba === 'erros' }" @click="aba = 'erros'">Linhas de fora ({{ previa.resumo.erros }})</button>
        </div>
        <div class="lista-previa">
          <table v-if="aba === 'exemplos'" class="tabela">
            <thead><tr><th>Como estava</th><th>Como fica</th></tr></thead>
            <tbody>
              <tr v-for="e in previa.exemplos" :key="e.linha">
                <td>
                  <div>{{ e.antes.razao }}</div>
                  <small>{{ e.antes.cidade }} · {{ e.antes.telefone }}</small>
                  <small v-if="e.antes.email">{{ e.antes.email }}</small>
                </td>
                <td>
                  <div>{{ e.depois.razao }} <span v-if="e.depois.contribuinte !== null" class="selo neutro">{{ e.depois.contribuinte ? 'Contribuinte' : 'Não contribuinte' }}</span></div>
                  <small>{{ e.depois.cidade }}</small>
                  <small v-for="(ct, i) in e.depois.contatos" :key="i">{{ [ct.nome, ct.telefone, ct.email].filter(Boolean).join(' · ') }}<template v-if="ct.whatsapp"> (WhatsApp)</template></small>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="aba === 'colunas'" class="colunas">
            <p><strong>Usadas:</strong> {{ previa.colunas_reconhecidas.map(c => `${c.titulo} → ${rotuloCampo[c.campo]}`).join(' · ') }}</p>
            <p v-if="previa.colunas_ignoradas.length"><strong>Não usadas</strong> (o sistema novo calcula sozinho a partir dos pedidos): {{ previa.colunas_ignoradas.join(' · ') }}</p>
          </div>
          <table v-if="aba === 'avisos'" class="tabela">
            <thead><tr><th>Linha</th><th>Cliente</th><th>Aviso</th></tr></thead>
            <tbody>
              <tr v-for="a in previa.avisos" :key="a.linha"><td>{{ a.linha }}</td><td>{{ a.cliente }}</td><td>{{ a.motivo }}</td></tr>
              <tr v-if="!previa.avisos.length"><td colspan="3" class="vazio-linha">Nenhum aviso.</td></tr>
            </tbody>
          </table>
          <table v-if="aba === 'erros'" class="tabela">
            <thead><tr><th>Linha</th><th>Cliente</th><th>Motivo</th></tr></thead>
            <tbody>
              <tr v-for="a in previa.erros" :key="a.linha"><td>{{ a.linha }}</td><td>{{ a.cliente || '—' }}</td><td>{{ a.motivo }}</td></tr>
              <tr v-if="!previa.erros.length"><td colspan="3" class="vazio-linha">Todas as linhas foram lidas.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="acoes">
          <button class="botao secundario" @click="recomecar">Trocar arquivo</button>
          <button class="botao" :disabled="ocupado || !(previa.resumo.novos + previa.resumo.atualizados)" @click="gravar">{{ ocupado ? 'Gravando…' : `Gravar ${n(previa.resumo.novos + previa.resumo.atualizados)} clientes` }}</button>
        </div>
      </div>
    </template>

    <div v-if="resultado" class="folha bloco">
      <h2>Clientes gravados</h2>
      <p>{{ n(resultado.novos) }} novos e {{ n(resultado.atualizados) }} atualizados. Se algo saiu errado, dá para desfazer.</p>
      <div class="acoes-esq">
        <RouterLink class="botao" :to="{ name: 'clientes' }">Ver clientes</RouterLink>
        <button class="botao discreto" @click="desfazer(previa.importacao_id)" :disabled="ocupado">Desfazer esta importação</button>
      </div>
    </div>

    <section v-if="!previa && historico.length" class="folha bloco">
      <h2>Importações anteriores</h2>
      <table class="tabela">
        <thead><tr><th>Quando</th><th>Arquivo</th><th>Por</th><th class="num">Clientes</th><th></th></tr></thead>
        <tbody>
          <tr v-for="h in historico" :key="h.id" :class="{ inativo: h.situacao === 'desfeita' }">
            <td>{{ dataHora(h.importada_em || h.created_at) }}</td>
            <td>{{ h.nome_original }} <span v-if="h.situacao === 'desfeita'" class="selo neutro">Desfeita</span></td>
            <td>{{ h.usuario }}</td>
            <td class="num">{{ n(h.linhas_ok) }}</td>
            <td class="num"><button v-if="h.situacao === 'importada'" class="botao discreto" @click="desfazer(h.id)">Desfazer</button></td>
          </tr>
        </tbody>
      </table>
    </section>
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 16px; max-width: 1080px; }
.voltar { font-size: var(--t-1); text-decoration: none; }
.voltar::before { content: '‹ '; }
.explica { color: var(--grafite-2); max-width: 75ch; margin-top: 6px; }
.bloco { padding: 20px; display: grid; gap: 14px; align-content: start; }
.soltar { display: grid; gap: 6px; justify-items: center; text-align: center; padding: 32px 16px; border: 2px dashed var(--linha-forte); border-radius: var(--r-folha); cursor: pointer; color: var(--grafite-2); }
.soltar strong { color: var(--grafite); font-size: var(--t1); }
.soltar.arrastando, .soltar:hover { border-color: var(--carbono); background: var(--carbono-claro); }
.soltar.escolhido { border-style: solid; border-color: var(--carbono); }
.soltar input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.soltar:focus-within { outline: 3px solid var(--carbono); outline-offset: 2px; }
.acoes { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
.acoes-esq { display: flex; gap: 10px; flex-wrap: wrap; }
.resumo { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.resumo li { padding-left: 14px; border-left: 3px solid var(--linha-forte); }
.resumo.limpeza li { border-color: var(--carbono-claro); color: var(--grafite-2); }
.resumo .erro { color: var(--aviso); border-color: var(--aviso); }
.aviso-amarelo { background: var(--aviso-claro); color: var(--aviso); border-left: 3px solid var(--aviso); padding: 10px 12px; border-radius: 4px; }
.abas { display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 1px solid var(--linha); }
.abas button { font: inherit; background: none; border: 0; border-bottom: 3px solid transparent; padding: 8px 10px; cursor: pointer; color: var(--grafite-2); }
.abas button.ativa { color: var(--carbono); border-color: var(--carbono); font-weight: 600; }
.lista-previa { max-height: 460px; overflow: auto; }
.lista-previa td small { display: block; color: var(--grafite-2); font-size: var(--t-1); }
.colunas { display: grid; gap: 10px; padding: 8px 0; }
.vazio-linha { color: var(--grafite-3); text-align: center; }
.tabela tr.inativo td { color: var(--grafite-3); }
</style>

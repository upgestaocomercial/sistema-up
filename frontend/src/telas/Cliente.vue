<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get, post, put } from '../api.js'
import { reais, data } from '../formato.js'
import { sessao } from '../sessao.js'

const route = useRoute()
const router = useRouter()
const novo = computed(() => route.name === 'cliente-novo')
const ehVendedor = computed(() => sessao.usuario?.perfil === 'vendedor')

const c = ref(null)
const opcoes = ref(null)
const editando = ref(false)
const form = ref(null)
const erro = ref('')
const erros = ref({})
const salvando = ref(false)
const buscandoCnpj = ref(false)
const avisoCnpj = ref('')

const vazio = () => ({
  tipo_pessoa: 'PJ', cnpj_cpf: '', razao_social: '', fantasia: '', inscricao_estadual: '', contribuinte: '',
  cep: '', endereco: '', numero: '', complemento: '', bairro: '', municipio_nome: '', uf: 'SP',
  vendedor_id: ehVendedor.value ? sessao.usuario.id : '', segmento_id: '', rede_id: '', aniversario: '', limite_credito_sugerido: '',
  situacao: 'ativo', observacoes: '', contatos: [{ nome: '', cargo: '', telefone: '', whatsapp: true, email: '' }], tags: [],
})

async function carregar() {
  erro.value = ''
  if (!opcoes.value) opcoes.value = await get('/clientes/opcoes')
  if (novo.value) {
    c.value = null
    form.value = vazio()
    editando.value = true
    return
  }
  c.value = (await get('/clientes/' + route.params.id)).cliente
  editando.value = false
}

function editar() {
  const x = c.value
  form.value = {
    ...vazio(),
    ...Object.fromEntries(Object.keys(vazio()).filter(k => !['contatos', 'tags'].includes(k)).map(k => [k, x[k] ?? ''])),
    contribuinte: x.contribuinte === null ? '' : String(x.contribuinte),
    contatos: x.contatos.length ? x.contatos.map(ct => ({ ...ct, whatsapp: !!ct.whatsapp })) : vazio().contatos,
    tags: x.tags.map(t => t.id),
  }
  editando.value = true
}

async function salvar() {
  salvando.value = true; erro.value = ''; erros.value = {}
  const corpo = { ...form.value, contribuinte: form.value.contribuinte === '' ? null : Number(form.value.contribuinte) }
  try {
    if (novo.value) {
      corpo.uuid_local = crypto.randomUUID?.()
      const r = await post('/clientes', corpo)
      router.replace({ name: 'cliente', params: { id: r.cliente.id } })
    } else {
      await put('/clientes/' + c.value.id, corpo)
      await carregar()
    }
  } catch (e) {
    erro.value = e.message; erros.value = e.detalhes || {}
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally { salvando.value = false }
}

async function consultarCnpj() {
  avisoCnpj.value = ''
  const d = (form.value.cnpj_cpf || '').replace(/\D/g, '')
  if (d.length !== 14) return
  buscandoCnpj.value = true
  try {
    const r = await get('/cnpj/' + d)
    if (r.ja_cadastrado && r.ja_cadastrado.id !== c.value?.id) {
      avisoCnpj.value = `Este CNPJ já está cadastrado: ${r.ja_cadastrado.razao_social}.`
    } else if (r.encontrado) {
      for (const [k, v] of Object.entries(r.dados)) if (v && k in form.value && !form.value[k]) form.value[k] = v
      if (r.dados.situacao_receita && r.dados.situacao_receita !== 'ATIVA') avisoCnpj.value = `Situação na Receita: ${r.dados.situacao_receita}.`
    } else {
      avisoCnpj.value = 'Não encontrei este CNPJ na Receita. Preencha os dados à mão.'
    }
  } catch (e) { avisoCnpj.value = e.message }
  finally { buscandoCnpj.value = false }
}

const whats = (t) => 'https://wa.me/55' + (t || '').replace(/\D/g, '')
const enderecoCompleto = computed(() => {
  const x = c.value
  if (!x) return ''
  return [[x.endereco, x.numero].filter(Boolean).join(', '), x.complemento, x.bairro, [x.municipio_nome, x.uf].filter(Boolean).join('/'), x.cep].filter(Boolean).join(' · ')
})
const mapa = computed(() => 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(enderecoCompleto.value))
const corCarteira = { 'Ativo': 'ok', 'Inativo recente': 'aviso', 'Inativo antigo': 'ruim', 'Prospect': 'neutro' }

watch(() => route.fullPath, () => { if (['cliente', 'cliente-novo'].includes(route.name)) carregar().catch(e => { erro.value = e.message }) })
onMounted(() => carregar().catch(e => { erro.value = e.message }))
</script>

<template>
  <section class="tela">
    <RouterLink :to="{ name: 'clientes' }" class="voltar">Clientes</RouterLink>
    <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>

    <!-- visualização -->
    <template v-if="c && !editando">
      <header class="cabeca">
        <div>
          <h1>{{ c.fantasia || c.razao_social }}</h1>
          <p class="sub"><template v-if="c.fantasia && c.fantasia !== c.razao_social">{{ c.razao_social }} · </template>{{ c.cnpj_cpf || 'Sem CNPJ/CPF' }}</p>
          <div class="selos">
            <span v-if="c.mercos_situacao" class="selo" :class="corCarteira[c.mercos_situacao]">{{ c.mercos_situacao }}</span>
            <span v-if="c.situacao === 'bloqueado'" class="selo ruim">Bloqueado</span>
            <span v-if="c.contribuinte == 1" class="selo neutro">Contribuinte</span>
            <span v-else-if="c.contribuinte == 0" class="selo neutro">Não contribuinte</span>
            <span v-for="t in c.tags" :key="t.id" class="selo info">{{ t.nome }}</span>
          </div>
        </div>
        <div class="acoes">
          <button class="botao secundario" @click="editar">Editar</button>
          <RouterLink class="botao" :to="{ name: 'novo-pedido', query: { cliente: c.id } }">Novo pedido</RouterLink>
        </div>
      </header>

      <div class="grade">
        <div class="folha bloco">
          <h2>Contatos</h2>
          <ul class="contatos" v-if="c.contatos.length">
            <li v-for="ct in c.contatos" :key="ct.id">
              <strong v-if="ct.nome">{{ ct.nome }}<small v-if="ct.cargo"> · {{ ct.cargo }}</small></strong>
              <span v-if="ct.telefone" class="linha-contato">
                <a :href="'tel:' + ct.telefone.replace(/\D/g, '')">{{ ct.telefone }}</a>
                <a v-if="ct.whatsapp" :href="whats(ct.telefone)" target="_blank" rel="noopener" class="botao discreto">WhatsApp</a>
              </span>
              <a v-if="ct.email" :href="'mailto:' + ct.email">{{ ct.email }}</a>
            </li>
          </ul>
          <p v-else class="apagado">Nenhum contato cadastrado.</p>
        </div>

        <div class="folha bloco">
          <h2>Cadastro</h2>
          <dl>
            <dt>Endereço</dt><dd><a v-if="enderecoCompleto" :href="mapa" target="_blank" rel="noopener">{{ enderecoCompleto }}</a><span v-else>—</span></dd>
            <dt>Vendedor</dt><dd>{{ c.vendedor || 'Sem vendedor' }}</dd>
            <dt>Segmento</dt><dd>{{ c.segmento || '—' }}</dd>
            <dt v-if="c.rede">Rede</dt><dd v-if="c.rede">{{ c.rede }}</dd>
            <dt>Inscrição estadual</dt><dd>{{ c.inscricao_estadual || '—' }}</dd>
            <dt>Cliente desde</dt><dd>{{ data(c.data_cadastro) }}</dd>
            <dt v-if="c.limite_credito_sugerido">Limite de crédito sugerido</dt><dd v-if="c.limite_credito_sugerido">{{ reais(Number(c.limite_credito_sugerido)) }}</dd>
          </dl>
          <p v-if="c.observacoes" class="obs">{{ c.observacoes }}</p>
        </div>

        <div class="folha bloco largo">
          <h2>Compras</h2>
          <p v-if="c.ultimo_pedido_data" class="ultima">Última compra em {{ data(c.ultimo_pedido_data) }}: <strong>{{ reais(Number(c.ultimo_pedido_valor)) }}</strong> <small>(informação trazida do Mercos)</small></p>
          <table v-if="c.pedidos.length" class="tabela">
            <thead><tr><th>Pedido</th><th>Data</th><th>Representada</th><th>Situação</th><th class="num">Total</th></tr></thead>
            <tbody>
              <tr v-for="p in c.pedidos" :key="p.id"><td>{{ p.numero }}</td><td>{{ data(p.data_emissao) }}</td><td>{{ p.representada }}</td><td>{{ p.situacao }}</td><td class="num">{{ reais(Number(p.total_liquido)) }}</td></tr>
            </tbody>
          </table>
          <p v-else class="apagado">Os pedidos feitos no sistema novo aparecem aqui.</p>
        </div>
      </div>
    </template>

    <!-- formulário -->
    <form v-if="editando && form" class="formulario" @submit.prevent="salvar" novalidate>
      <header class="cabeca">
        <h1>{{ novo ? 'Cadastrar cliente' : 'Editar cliente' }}</h1>
      </header>

      <fieldset class="folha bloco">
        <legend>Empresa</legend>
        <div class="grade-campos">
          <div class="campo" :class="{ 'com-erro': erros.cnpj_cpf }">
            <label for="doc">CNPJ ou CPF</label>
            <input id="doc" v-model="form.cnpj_cpf" inputmode="numeric" @blur="consultarCnpj" />
            <span v-if="erros.cnpj_cpf" class="erro-campo">{{ erros.cnpj_cpf }}</span>
            <span v-else-if="buscandoCnpj" class="dica">Consultando a Receita…</span>
            <span v-else-if="avisoCnpj" class="dica aviso">{{ avisoCnpj }}</span>
          </div>
          <div class="campo">
            <label for="ie">Inscrição estadual</label>
            <input id="ie" v-model="form.inscricao_estadual" />
          </div>
          <div class="campo dobro" :class="{ 'com-erro': erros.razao_social }">
            <label for="rs">Razão social *</label>
            <input id="rs" v-model="form.razao_social" />
            <span v-if="erros.razao_social" class="erro-campo">{{ erros.razao_social }}</span>
          </div>
          <div class="campo dobro">
            <label for="fan">Nome fantasia</label>
            <input id="fan" v-model="form.fantasia" />
          </div>
          <div class="campo">
            <label for="contr">Contribuinte de ICMS</label>
            <select id="contr" v-model="form.contribuinte">
              <option value="">Não informado</option>
              <option value="1">Contribuinte</option>
              <option value="0">Não contribuinte</option>
            </select>
          </div>
          <div class="campo">
            <label for="seg">Segmento</label>
            <select id="seg" v-model="form.segmento_id">
              <option value="">—</option>
              <option v-for="s in opcoes.segmentos" :key="s.id" :value="s.id">{{ s.nome }}</option>
            </select>
          </div>
          <div class="campo">
            <label for="rede">Rede</label>
            <select id="rede" v-model="form.rede_id">
              <option value="">Nenhuma</option>
              <option v-for="s in opcoes.redes" :key="s.id" :value="s.id">{{ s.nome }}</option>
            </select>
          </div>
          <div class="campo" v-if="!ehVendedor">
            <label for="vend">Vendedor</label>
            <select id="vend" v-model="form.vendedor_id">
              <option value="">Sem vendedor</option>
              <option v-for="s in opcoes.vendedores" :key="s.id" :value="s.id">{{ s.nome }}</option>
            </select>
          </div>
        </div>
      </fieldset>

      <fieldset class="folha bloco">
        <legend>Endereço</legend>
        <div class="grade-campos">
          <div class="campo"><label for="cep">CEP</label><input id="cep" v-model="form.cep" inputmode="numeric" /></div>
          <div class="campo dobro"><label for="end">Logradouro</label><input id="end" v-model="form.endereco" /></div>
          <div class="campo"><label for="num">Número</label><input id="num" v-model="form.numero" /></div>
          <div class="campo"><label for="comp">Complemento</label><input id="comp" v-model="form.complemento" /></div>
          <div class="campo"><label for="bai">Bairro</label><input id="bai" v-model="form.bairro" /></div>
          <div class="campo">
            <label for="cid">Cidade</label>
            <input id="cid" v-model="form.municipio_nome" list="lista-cidades" />
            <datalist id="lista-cidades"><option v-for="x in opcoes.cidades" :key="x.nome" :value="x.nome" /></datalist>
          </div>
          <div class="campo"><label for="uf">UF</label><input id="uf" v-model="form.uf" maxlength="2" /></div>
        </div>
      </fieldset>

      <fieldset class="folha bloco">
        <legend>Contatos</legend>
        <div v-for="(ct, i) in form.contatos" :key="i" class="contato-form">
          <div class="campo"><label :for="'cn' + i">Nome</label><input :id="'cn' + i" v-model="ct.nome" /></div>
          <div class="campo"><label :for="'cc' + i">Cargo</label><input :id="'cc' + i" v-model="ct.cargo" /></div>
          <div class="campo"><label :for="'ct' + i">Telefone</label><input :id="'ct' + i" v-model="ct.telefone" inputmode="tel" /></div>
          <div class="campo"><label :for="'ce' + i">E-mail</label><input :id="'ce' + i" v-model="ct.email" type="email" /></div>
          <label class="check"><input type="checkbox" v-model="ct.whatsapp" /> WhatsApp</label>
          <button type="button" class="botao discreto" @click="form.contatos.splice(i, 1)" :aria-label="'Remover contato ' + (i + 1)">Remover</button>
        </div>
        <button type="button" class="botao discreto" @click="form.contatos.push({ nome: '', cargo: '', telefone: '', whatsapp: true, email: '' })">Adicionar contato</button>
      </fieldset>

      <fieldset class="folha bloco">
        <legend>Outros</legend>
        <div class="grade-campos">
          <div class="campo"><label for="aniv">Aniversário</label><input id="aniv" v-model="form.aniversario" type="date" /></div>
          <div class="campo"><label for="lim">Limite de crédito sugerido (R$)</label><input id="lim" v-model="form.limite_credito_sugerido" inputmode="decimal" /></div>
          <div class="campo" v-if="!ehVendedor">
            <label for="sit">Situação</label>
            <select id="sit" v-model="form.situacao">
              <option value="ativo">Ativo</option>
              <option value="bloqueado">Bloqueado para pedidos</option>
              <option value="inativo">Inativo (some das listas)</option>
            </select>
          </div>
        </div>
        <div class="tags" v-if="opcoes.tags.length">
          <span class="rotulo">Tags</span>
          <label v-for="t in opcoes.tags" :key="t.id" class="check"><input type="checkbox" :value="t.id" v-model="form.tags" /> {{ t.nome }}</label>
        </div>
        <div class="campo"><label for="obs">Observações</label><textarea id="obs" v-model="form.observacoes" rows="3"></textarea></div>
      </fieldset>

      <div class="acoes-form">
        <button type="button" class="botao secundario" @click="novo ? router.back() : (editando = false)">Cancelar</button>
        <button class="botao" :disabled="salvando || !form.razao_social">{{ salvando ? 'Salvando…' : (novo ? 'Cadastrar cliente' : 'Salvar alterações') }}</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 16px; max-width: 1080px; }
.voltar { font-size: var(--t-1); text-decoration: none; }
.voltar::before { content: '‹ '; }
.cabeca { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.sub { color: var(--grafite-2); margin-top: 4px; }
.selos { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.selo.aviso { color: var(--aviso); background: var(--aviso-claro); }
.acoes { display: flex; gap: 8px; }
.grade { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.largo { grid-column: 1 / -1; }
.bloco { padding: 18px 20px; display: grid; gap: 12px; }
.contatos { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.contatos li { display: grid; gap: 2px; }
.linha-contato { display: flex; align-items: center; gap: 8px; }
.linha-contato .botao { min-height: 32px; font-size: 0.9rem; }
dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0; }
dt { color: var(--grafite-2); font-size: 0.92rem; }
dd { margin: 0; }
.obs { white-space: pre-wrap; background: var(--papel); padding: 10px 12px; border-radius: var(--r-campo); }
.apagado { color: var(--grafite-3); }
.ultima small { color: var(--grafite-3); }
.formulario { display: grid; gap: 16px; }
fieldset.bloco { margin: 0; }
legend { font-family: var(--fonte-estreita); font-weight: 600; font-size: var(--t1); padding: 0 6px; }
.grade-campos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.dobro { grid-column: span 2; }
.dica { font-size: var(--t-1); color: var(--grafite-3); }
.dica.aviso { color: var(--aviso); }
.contato-form { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1.4fr auto auto; gap: 10px; align-items: end; padding-bottom: 10px; border-bottom: 1px solid var(--linha); }
.tags { display: flex; flex-wrap: wrap; gap: 4px 18px; align-items: center; }
.rotulo { font-size: var(--t-1); color: var(--grafite-2); width: 100%; }
.acoes-form { display: flex; gap: 10px; justify-content: flex-end; position: sticky; bottom: 0; padding: 12px 0; background: var(--papel); }
@media (max-width: 860px) {
  .grade { grid-template-columns: 1fr; }
  .grade-campos { grid-template-columns: 1fr 1fr; }
  .contato-form { grid-template-columns: 1fr 1fr; }
  .acoes-form { bottom: calc(var(--nav-alt) + env(safe-area-inset-bottom)); }
}
</style>

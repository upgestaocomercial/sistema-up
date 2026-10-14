<script setup>
import { onMounted, ref } from 'vue'
import { get, put, post } from '../api.js'

const reps = ref([])
const carregando = ref(true)
const erroLista = ref('')
const editRep = ref(null)
const editCanal = ref(null)
const erros = ref({})
const erro = ref('')
const salvando = ref(false)

const contribuinte = { sim: 'Contribuinte', nao: 'Não contribuinte', ambos: 'Todos' }
const pct = (v) => v === null || v === undefined ? '' : String(v).replace('.', ',') + '%'

async function carregar() {
  carregando.value = true
  try { reps.value = (await get('/representadas')).representadas }
  catch (e) { erroLista.value = e.message }
  finally { carregando.value = false }
}

function abrirRep(r) { editRep.value = { ...r }; erros.value = {}; erro.value = '' }
function abrirCanal(r, c) {
  editCanal.value = c ? { ...c, _rep: r.nome } : { representada_id: r.id, _rep: r.nome, nome: '', contribuinte: 'ambos', casa_construtor: false, desconto_base_pct: 0, comissao_pct: null, ativo: true }
  erros.value = {}; erro.value = ''
}

async function salvarRep() {
  salvando.value = true; erros.value = {}; erro.value = ''
  const r = editRep.value
  try {
    await put(`/config/representadas/${r.id}`, {
      nome: r.nome, comissao_pct: r.comissao_pct, comissao_base: r.comissao_base,
      dias_repasse_comissao: r.dias_repasse_comissao, email_pedidos: r.email_pedidos, ativo: r.ativo,
    })
    editRep.value = null
    await carregar()
  } catch (e) { erro.value = e.message; erros.value = e.detalhes }
  finally { salvando.value = false }
}

async function salvarCanal() {
  salvando.value = true; erros.value = {}; erro.value = ''
  const c = editCanal.value
  const corpo = { nome: c.nome, contribuinte: c.contribuinte, casa_construtor: c.casa_construtor, desconto_base_pct: c.desconto_base_pct, comissao_pct: c.comissao_pct === '' ? null : c.comissao_pct, ativo: c.ativo }
  try {
    if (c.id) await put(`/config/canais/${c.id}`, corpo)
    else await post(`/config/representadas/${c.representada_id}/canais`, corpo)
    editCanal.value = null
    await carregar()
  } catch (e) { erro.value = e.message; erros.value = e.detalhes }
  finally { salvando.value = false }
}

onMounted(carregar)
</script>

<template>
  <section class="tela">
    <header>
      <h1>Representadas e canais</h1>
      <p class="explica">Cada fábrica tem seus canais (Rental, Revenda, Casa do Construtor…). Desativar um canal não apaga vendas nem metas antigas.</p>
    </header>

    <p v-if="erroLista" class="aviso-erro">{{ erroLista }}</p>
    <p v-else-if="carregando" class="vazio">Carregando…</p>

    <article v-for="r in reps" :key="r.id" class="folha rep" :class="{ inativo: !r.ativo }">
      <header class="rep-cabeca">
        <span class="cor" :style="{ background: r.cor || 'var(--linha-forte)' }" aria-hidden="true"></span>
        <h2>{{ r.nome }}</h2>
        <span class="comissao">Comissão {{ pct(r.comissao_pct) }} sobre o {{ r.comissao_base }}</span>
        <button class="botao discreto" @click="abrirRep(r)">Editar</button>
      </header>
      <table class="tabela">
        <thead><tr><th>Canal</th><th>Cliente</th><th class="num">Desconto</th><th class="num">Comissão</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in r.canais" :key="c.id" :class="{ inativo: !c.ativo }">
            <td>{{ c.nome }} <span v-if="c.casa_construtor" class="selo info">CC</span> <span v-if="!c.ativo" class="selo neutro">Inativo</span></td>
            <td>{{ contribuinte[c.contribuinte] }}</td>
            <td class="num">{{ c.desconto_base_pct ? pct(c.desconto_base_pct) : '—' }}</td>
            <td class="num">{{ c.comissao_pct !== null ? pct(c.comissao_pct) : pct(r.comissao_pct) }}</td>
            <td class="num"><button class="botao discreto" @click="abrirCanal(r, c)">Editar</button></td>
          </tr>
        </tbody>
      </table>
      <button class="botao discreto adicionar" @click="abrirCanal(r)">Adicionar canal</button>
    </article>

    <div v-if="editRep" class="painel-lateral" role="dialog" aria-label="Editar representada" @click.self="editRep = null">
      <form class="folha formulario" @submit.prevent="salvarRep">
        <h2>{{ editRep.nome }}</h2>
        <div class="campo" :class="{ 'com-erro': erros.comissao_pct }">
          <label for="r-com">Comissão padrão (%)</label>
          <input id="r-com" v-model="editRep.comissao_pct" inputmode="decimal" />
          <span v-if="erros.comissao_pct" class="erro-campo">{{ erros.comissao_pct }}</span>
        </div>
        <div class="campo">
          <label for="r-base">A fábrica paga a comissão sobre o</label>
          <select id="r-base" v-model="editRep.comissao_base">
            <option value="recebido">Recebido do cliente</option>
            <option value="faturado">Faturado</option>
          </select>
        </div>
        <div class="campo">
          <label for="r-dias">Dias para a fábrica repassar depois que o cliente paga</label>
          <input id="r-dias" v-model="editRep.dias_repasse_comissao" inputmode="numeric" />
          <span class="dica">Usado para prever a entrada no fluxo de caixa.</span>
        </div>
        <div class="campo" :class="{ 'com-erro': erros.email_pedidos }">
          <label for="r-email">E-mail para envio de pedidos</label>
          <input id="r-email" v-model="editRep.email_pedidos" type="email" />
          <span v-if="erros.email_pedidos" class="erro-campo">{{ erros.email_pedidos }}</span>
        </div>
        <label class="check"><input type="checkbox" v-model="editRep.ativo" /> Ativa</label>
        <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>
        <div class="acoes">
          <button type="button" class="botao secundario" @click="editRep = null">Cancelar</button>
          <button class="botao" :disabled="salvando">Salvar alterações</button>
        </div>
      </form>
    </div>

    <div v-if="editCanal" class="painel-lateral" role="dialog" aria-label="Canal" @click.self="editCanal = null">
      <form class="folha formulario" @submit.prevent="salvarCanal">
        <h2>{{ editCanal.id ? 'Editar canal' : 'Adicionar canal' }} — {{ editCanal._rep }}</h2>
        <div class="campo" :class="{ 'com-erro': erros.nome }">
          <label for="c-nome">Nome do canal</label>
          <input id="c-nome" v-model="editCanal.nome" />
          <span v-if="erros.nome" class="erro-campo">{{ erros.nome }}</span>
        </div>
        <div class="campo">
          <label for="c-contr">Tipo de cliente</label>
          <select id="c-contr" v-model="editCanal.contribuinte">
            <option v-for="(r, k) in contribuinte" :key="k" :value="k">{{ r }}</option>
          </select>
        </div>
        <label class="check"><input type="checkbox" v-model="editCanal.casa_construtor" /> Casa do Construtor (meta própria)</label>
        <div class="campo">
          <label for="c-desc">Desconto da tabela (%)</label>
          <input id="c-desc" v-model="editCanal.desconto_base_pct" inputmode="decimal" />
        </div>
        <div class="campo" :class="{ 'com-erro': erros.comissao_pct }">
          <label for="c-com">Comissão do canal (%) — vazio usa a da representada</label>
          <input id="c-com" v-model="editCanal.comissao_pct" inputmode="decimal" />
        </div>
        <label class="check" v-if="editCanal.id"><input type="checkbox" v-model="editCanal.ativo" /> Ativo</label>
        <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>
        <div class="acoes">
          <button type="button" class="botao secundario" @click="editCanal = null">Cancelar</button>
          <button class="botao" :disabled="salvando">{{ editCanal.id ? 'Salvar alterações' : 'Adicionar canal' }}</button>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 16px; max-width: 980px; }
.explica { color: var(--grafite-2); margin-top: 6px; max-width: 70ch; }
.rep { overflow-x: auto; }
.rep.inativo { opacity: .6; }
.rep-cabeca { display: flex; align-items: center; gap: 12px; padding: 14px 12px 10px; flex-wrap: wrap; }
.cor { width: 10px; height: 26px; border-radius: 2px; }
.comissao { color: var(--grafite-2); margin-right: auto; }
.adicionar { margin: 8px 6px 10px; }
.painel-lateral { position: fixed; inset: 0; background: rgb(26 31 39 / 0.35); display: flex; justify-content: flex-end; z-index: 30; }
.formulario { width: min(440px, 100%); height: 100%; overflow-y: auto; border-radius: 0; padding: 22px 20px; display: grid; gap: 14px; align-content: start; }
.dica { font-size: var(--t-1); color: var(--grafite-3); }
.acoes { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
</style>

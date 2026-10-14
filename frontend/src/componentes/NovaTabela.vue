<script setup>
import { computed, ref } from 'vue'
import { post } from '../api.js'

const props = defineProps({ representadas: Array, representadaId: Number })
const emit = defineEmits(['fechar', 'criada'])

const f = ref({ representada_id: props.representadaId, canal_id: '', nome: '', tipo: 'promocional', vigencia_inicio: '', vigencia_fim: '' })
const erro = ref('')
const erros = ref({})
const salvando = ref(false)
const canais = computed(() => props.representadas.find(r => r.id === f.value.representada_id)?.canais.filter(c => c.ativo) || [])
const contr = { sim: 'contribuinte', nao: 'não contribuinte', ambos: '' }

async function salvar() {
  salvando.value = true; erro.value = ''; erros.value = {}
  try {
    const r = await post('/config/tabelas', { ...f.value, canal_id: f.value.canal_id || null })
    emit('criada', r.tabela)
  } catch (e) { erro.value = e.message; erros.value = e.detalhes }
  finally { salvando.value = false }
}
</script>

<template>
  <div class="painel-lateral" role="dialog" aria-label="Nova tabela promocional" @click.self="emit('fechar')">
    <form class="folha formulario" @submit.prevent="salvar">
      <h2>Nova tabela promocional</h2>
      <div class="campo">
        <label for="nt-rep">Representada</label>
        <select id="nt-rep" v-model="f.representada_id">
          <option v-for="r in representadas" :key="r.id" :value="r.id">{{ r.nome }}</option>
        </select>
      </div>
      <div class="campo">
        <label for="nt-canal">Vale para o canal</label>
        <select id="nt-canal" v-model="f.canal_id">
          <option value="">Todos os canais da representada</option>
          <option v-for="c in canais" :key="c.id" :value="c.id">{{ c.nome }} {{ contr[c.contribuinte] }}</option>
        </select>
        <span class="dica">Se o preço muda entre contribuinte e não contribuinte, crie uma tabela para cada canal.</span>
      </div>
      <div class="campo" :class="{ 'com-erro': erros.nome }">
        <label for="nt-nome">Nome</label>
        <input id="nt-nome" v-model="f.nome" placeholder="ex.: Promoção Novembro" />
        <span v-if="erros.nome" class="erro-campo">{{ erros.nome }}</span>
      </div>
      <div class="datas">
        <div class="campo">
          <label for="nt-ini">Começa em</label>
          <input id="nt-ini" v-model="f.vigencia_inicio" type="date" />
        </div>
        <div class="campo" :class="{ 'com-erro': erros.vigencia_fim }">
          <label for="nt-fim">Termina em</label>
          <input id="nt-fim" v-model="f.vigencia_fim" type="date" />
          <span v-if="erros.vigencia_fim" class="erro-campo">{{ erros.vigencia_fim }}</span>
        </div>
      </div>
      <p class="dica">Fora dessas datas o preço promocional deixa de aparecer sozinho. Depois de criar, importe a planilha da promoção.</p>
      <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>
      <div class="acoes">
        <button type="button" class="botao secundario" @click="emit('fechar')">Cancelar</button>
        <button class="botao" :disabled="salvando || !f.nome">Criar tabela</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.painel-lateral { position: fixed; inset: 0; background: rgb(26 31 39 / 0.35); display: flex; justify-content: flex-end; z-index: 40; }
.formulario { width: min(440px, 100%); height: 100%; overflow-y: auto; border-radius: 0; padding: 22px 20px; display: grid; gap: 14px; align-content: start; }
.datas { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.dica { font-size: var(--t-1); color: var(--grafite-3); }
.acoes { display: flex; gap: 10px; justify-content: flex-end; }
</style>

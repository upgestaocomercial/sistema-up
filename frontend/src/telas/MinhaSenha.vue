<script setup>
import { ref } from 'vue'
import { post } from '../api.js'

const atual = ref('')
const nova = ref('')
const confirma = ref('')
const erros = ref({})
const erro = ref('')
const ok = ref(false)
const enviando = ref(false)

async function salvar() {
  erro.value = ''; erros.value = {}; ok.value = false
  if (nova.value !== confirma.value) {
    erros.value = { confirma: 'As senhas não são iguais.' }
    return
  }
  enviando.value = true
  try {
    await post('/auth/senha', { senha_atual: atual.value, senha_nova: nova.value })
    ok.value = true
    atual.value = nova.value = confirma.value = ''
  } catch (e) {
    erro.value = e.message; erros.value = e.detalhes
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <section>
    <h1>Trocar senha</h1>
    <form class="folha formulario" @submit.prevent="salvar">
      <div class="campo" :class="{ 'com-erro': erros.senha_atual }">
        <label for="atual">Senha atual</label>
        <input id="atual" v-model="atual" type="password" autocomplete="current-password" />
        <span v-if="erros.senha_atual" class="erro-campo">{{ erros.senha_atual }}</span>
      </div>
      <div class="campo">
        <label for="nova">Nova senha (mínimo 8 caracteres)</label>
        <input id="nova" v-model="nova" type="password" autocomplete="new-password" />
      </div>
      <div class="campo" :class="{ 'com-erro': erros.confirma }">
        <label for="confirma">Repita a nova senha</label>
        <input id="confirma" v-model="confirma" type="password" autocomplete="new-password" />
        <span v-if="erros.confirma" class="erro-campo">{{ erros.confirma }}</span>
      </div>
      <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>
      <p v-if="ok" class="selo ok" role="status">Senha trocada. Os outros aparelhos vão pedir login de novo.</p>
      <button class="botao" :disabled="enviando || !atual || !nova">Trocar senha</button>
    </form>
  </section>
</template>

<style scoped>
section { display: grid; gap: 16px; max-width: 460px; }
.formulario { padding: 20px; display: grid; gap: 14px; }
</style>

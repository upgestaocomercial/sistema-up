<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { entrar } from '../sessao.js'

const email = ref('')
const senha = ref('')
const erro = ref('')
const enviando = ref(false)
const route = useRoute()
const router = useRouter()

async function enviar() {
  erro.value = ''
  enviando.value = true
  try {
    await entrar(email.value, senha.value)
    router.replace(typeof route.query.volta === 'string' ? route.query.volta : '/')
  } catch (e) {
    erro.value = e.message
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div class="tela-login">
    <form class="talao" @submit.prevent="enviar" novalidate>
      <header class="talao-topo">
        <span class="marca">UP</span>
        <span class="via">Gestão Comercial</span>
      </header>
      <h1>Entrar</h1>
      <p class="sub">Pedidos, clientes, metas e financeiro da UP.</p>

      <div class="campo">
        <label for="email">E-mail</label>
        <input id="email" v-model.trim="email" type="email" autocomplete="username" inputmode="email" required />
      </div>
      <div class="campo">
        <label for="senha">Senha</label>
        <input id="senha" v-model="senha" type="password" autocomplete="current-password" required />
      </div>
      <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>
      <button class="botao" type="submit" :disabled="enviando || !email || !senha">
        {{ enviando ? 'Entrando…' : 'Entrar' }}
      </button>
      <p class="rodape">Esqueceu a senha? Peça para a gestão criar uma nova.</p>
    </form>
  </div>
</template>

<style scoped>
.tela-login {
  min-height: 100dvh; display: grid; place-items: center; padding: 24px var(--gutter);
  /* linhas de pauta do talão */
  background:
    repeating-linear-gradient(to bottom, transparent 0 31px, #E1E6ED 31px 32px),
    var(--papel);
}
.talao {
  width: min(400px, 100%); background: var(--folha); border: 1px solid var(--linha);
  border-radius: var(--r-folha); padding: 28px 24px 22px; display: grid; gap: 16px;
  border-top: 6px solid var(--carbono);
}
.talao-topo { display: flex; align-items: baseline; justify-content: space-between; }
.marca { font-family: var(--fonte-estreita); font-weight: 700; font-size: var(--t4); color: var(--carbono); line-height: 1; letter-spacing: -0.03em; }
.via { color: var(--grafite-2); font-size: var(--t-1); }
h1 { font-size: var(--t2); margin-top: 4px; }
.sub { color: var(--grafite-2); margin-top: -10px; }
.rodape { font-size: var(--t-1); color: var(--grafite-3); }
</style>

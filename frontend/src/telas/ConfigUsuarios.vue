<script setup>
import { onMounted, ref } from 'vue'
import { get, post, put } from '../api.js'
import { sessao } from '../sessao.js'

const usuarios = ref([])
const carregando = ref(true)
const erroLista = ref('')
const editando = ref(null) // objeto do formulário
const erros = ref({})
const erro = ref('')
const salvando = ref(false)

const perfis = {
  vendedor: 'Vendedor',
  administrativo: 'Administrativo',
  gestao: 'Gestão',
  tv: 'TV (painel)',
}

async function carregar() {
  carregando.value = true
  try {
    usuarios.value = (await get('/config/usuarios')).usuarios
  } catch (e) {
    erroLista.value = e.message
  } finally {
    carregando.value = false
  }
}

function novo() {
  editando.value = { nome: '', email: '', telefone: '', perfil: 'vendedor', acesso_financeiro: false, ativo: true, senha: '' }
  erros.value = {}; erro.value = ''
}
function editar(u) {
  editando.value = { ...u, senha: '' }
  erros.value = {}; erro.value = ''
}

async function salvar() {
  salvando.value = true; erros.value = {}; erro.value = ''
  const f = editando.value
  const corpo = { nome: f.nome, email: f.email, telefone: f.telefone, perfil: f.perfil, acesso_financeiro: f.acesso_financeiro, ativo: f.ativo }
  if (f.senha) corpo.senha = f.senha
  try {
    if (f.id) await put(`/config/usuarios/${f.id}`, corpo)
    else await post('/config/usuarios', { ...corpo, senha: f.senha })
    editando.value = null
    await carregar()
  } catch (e) {
    erro.value = e.message; erros.value = e.detalhes
  } finally {
    salvando.value = false
  }
}

const dataHora = (s) => s ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' }).format(new Date(s.replace(' ', 'T') + 'Z')) : 'Nunca entrou'

onMounted(carregar)
</script>

<template>
  <section class="tela">
    <header class="cabeca">
      <h1>Usuários</h1>
      <button class="botao" @click="novo">Cadastrar usuário</button>
    </header>

    <p v-if="erroLista" class="aviso-erro">{{ erroLista }}</p>
    <div v-else class="folha lista">
      <p v-if="carregando" class="vazio">Carregando…</p>
      <table v-else class="tabela">
        <thead>
          <tr><th>Nome</th><th>Perfil</th><th>Financeiro</th><th>Último acesso</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="u in usuarios" :key="u.id" :class="{ inativo: !u.ativo }">
            <td><strong>{{ u.nome }}</strong><br /><small>{{ u.email }}</small></td>
            <td>{{ perfis[u.perfil] }} <span v-if="!u.ativo" class="selo neutro">Inativo</span></td>
            <td><span v-if="u.acesso_financeiro" class="selo info">Acessa</span></td>
            <td>{{ dataHora(u.ultimo_login_em) }}</td>
            <td class="num"><button class="botao discreto" @click="editar(u)">Editar</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="editando" class="painel-lateral" role="dialog" :aria-label="editando.id ? 'Editar usuário' : 'Cadastrar usuário'" @click.self="editando = null">
      <form class="folha formulario" @submit.prevent="salvar">
        <h2>{{ editando.id ? 'Editar usuário' : 'Cadastrar usuário' }}</h2>
        <div class="campo" :class="{ 'com-erro': erros.nome }">
          <label for="u-nome">Nome</label>
          <input id="u-nome" v-model="editando.nome" />
          <span v-if="erros.nome" class="erro-campo">{{ erros.nome }}</span>
        </div>
        <div class="campo" :class="{ 'com-erro': erros.email }">
          <label for="u-email">E-mail (usado no login)</label>
          <input id="u-email" v-model="editando.email" type="email" />
          <span v-if="erros.email" class="erro-campo">{{ erros.email }}</span>
        </div>
        <div class="campo">
          <label for="u-tel">Telefone</label>
          <input id="u-tel" v-model="editando.telefone" inputmode="tel" />
        </div>
        <div class="campo" :class="{ 'com-erro': erros.perfil }">
          <label for="u-perfil">Perfil</label>
          <select id="u-perfil" v-model="editando.perfil">
            <option v-for="(r, k) in perfis" :key="k" :value="k">{{ r }}</option>
          </select>
          <span class="dica">Vendedor vê só os próprios clientes e pedidos. TV só mostra o painel.</span>
        </div>
        <label class="check"><input type="checkbox" v-model="editando.acesso_financeiro" /> Acessa o financeiro</label>
        <label class="check" v-if="editando.id"><input type="checkbox" v-model="editando.ativo" :disabled="editando.id === sessao.usuario.id" /> Ativo</label>
        <div class="campo" :class="{ 'com-erro': erros.senha }">
          <label for="u-senha">{{ editando.id ? 'Nova senha (deixe vazio para manter)' : 'Senha inicial (mínimo 8)' }}</label>
          <input id="u-senha" v-model="editando.senha" type="password" autocomplete="new-password" />
        </div>
        <p v-if="erro" class="aviso-erro" role="alert">{{ erro }}</p>
        <div class="acoes">
          <button type="button" class="botao secundario" @click="editando = null">Cancelar</button>
          <button class="botao" :disabled="salvando">{{ editando.id ? 'Salvar alterações' : 'Cadastrar usuário' }}</button>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.tela { display: grid; gap: 16px; max-width: 980px; }
.cabeca { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.lista { overflow-x: auto; }
small { color: var(--grafite-2); }
.painel-lateral { position: fixed; inset: 0; background: rgb(26 31 39 / 0.35); display: flex; justify-content: flex-end; z-index: 30; }
.formulario { width: min(440px, 100%); height: 100%; overflow-y: auto; border-radius: 0; padding: 22px 20px; display: grid; gap: 14px; align-content: start; }
.dica { font-size: var(--t-1); color: var(--grafite-3); }
.acoes { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
@media (max-width: 860px) {
  .tabela th:nth-child(4), .tabela td:nth-child(4) { display: none; }
}
</style>

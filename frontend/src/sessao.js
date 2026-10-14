import { reactive } from 'vue'
import { get, post } from './api.js'

export const sessao = reactive({
  usuario: null,
  carregada: false,
})

export async function carregarSessao() {
  try {
    const r = await get('/auth/eu')
    sessao.usuario = r.usuario
  } catch {
    sessao.usuario = null
  }
  sessao.carregada = true
}

export async function entrar(email, senha) {
  const tipo = window.matchMedia('(display-mode: standalone)').matches ? 'app' : 'navegador'
  const r = await post('/auth/login', { email, senha, tipo })
  sessao.usuario = r.usuario
}

export async function sair() {
  try { await post('/auth/logout') } finally { sessao.usuario = null }
}

export const ehGestao = () => sessao.usuario?.perfil === 'gestao'
export const temFinanceiro = () => sessao.usuario?.acesso_financeiro === true

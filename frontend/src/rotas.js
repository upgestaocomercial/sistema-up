import { createRouter, createWebHistory } from 'vue-router'
import { sessao, ehGestao, temFinanceiro } from './sessao.js'

const EmConstrucao = () => import('./telas/EmConstrucao.vue')

const rotas = [
  { path: '/entrar', name: 'login', component: () => import('./telas/Login.vue'), meta: { publica: true } },
  { path: '/', name: 'inicio', component: () => import('./telas/Inicio.vue') },
  { path: '/clientes', name: 'clientes', component: EmConstrucao, meta: { titulo: 'Clientes', fase: 2 } },
  { path: '/catalogo', name: 'catalogo', component: EmConstrucao, meta: { titulo: 'Catálogo', fase: 2 } },
  { path: '/pedidos', name: 'pedidos', component: EmConstrucao, meta: { titulo: 'Pedidos', fase: 2 } },
  { path: '/pedidos/novo', name: 'novo-pedido', component: EmConstrucao, meta: { titulo: 'Novo pedido', fase: 2 } },
  { path: '/metas', name: 'metas', component: EmConstrucao, meta: { titulo: 'Metas', fase: 5 } },
  { path: '/agenda', name: 'agenda', component: EmConstrucao, meta: { titulo: 'Agenda de visitas', fase: 6 } },
  { path: '/relatorios', name: 'relatorios', component: EmConstrucao, meta: { titulo: 'Relatórios', fase: 5 } },
  { path: '/financeiro', name: 'financeiro', component: EmConstrucao, meta: { titulo: 'Financeiro', fase: 4, financeiro: true } },
  { path: '/minha-senha', name: 'minha-senha', component: () => import('./telas/MinhaSenha.vue') },
  { path: '/config/usuarios', name: 'usuarios', component: () => import('./telas/ConfigUsuarios.vue'), meta: { gestao: true } },
  { path: '/config/representadas', name: 'representadas', component: () => import('./telas/ConfigRepresentadas.vue'), meta: { gestao: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({ history: createWebHistory(), routes: rotas })

router.beforeEach((to) => {
  if (to.meta.publica) {
    return sessao.usuario && to.name === 'login' ? { name: 'inicio' } : true
  }
  if (!sessao.usuario) return { name: 'login', query: to.fullPath !== '/' ? { volta: to.fullPath } : {} }
  if (to.meta.gestao && !ehGestao()) return { name: 'inicio' }
  if (to.meta.financeiro && !temFinanceiro()) return { name: 'inicio' }
  return true
})

import { createApp } from 'vue'
import '@fontsource/barlow/400.css'
import '@fontsource/barlow/500.css'
import '@fontsource/barlow/600.css'
import '@fontsource/barlow-semi-condensed/500.css'
import '@fontsource/barlow-semi-condensed/600.css'
import '@fontsource/barlow-semi-condensed/700.css'
import './estilo.css'
import App from './App.vue'
import { router } from './rotas.js'
import { carregarSessao } from './sessao.js'
import { quandoPerderSessao } from './api.js'
import { sessao } from './sessao.js'

quandoPerderSessao(() => {
  sessao.usuario = null
  if (router.currentRoute.value.name !== 'login') router.push({ name: 'login' })
})

carregarSessao().then(() => {
  createApp(App).use(router).mount('#app')
})

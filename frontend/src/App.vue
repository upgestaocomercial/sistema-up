<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { sessao, sair, ehGestao, temFinanceiro } from './sessao.js'

const route = useRoute()
const router = useRouter()
const menuAberto = ref(false)

const itens = computed(() => {
  const l = [
    { nome: 'inicio', rotulo: 'Início', icone: 'casa' },
    { nome: 'clientes', rotulo: 'Clientes', icone: 'pessoas' },
    { nome: 'pedidos', rotulo: 'Pedidos', icone: 'pedido' },
    { nome: 'catalogo', rotulo: 'Catálogo', icone: 'caixa' },
    { nome: 'agenda', rotulo: 'Agenda', icone: 'agenda' },
    { nome: 'metas', rotulo: 'Metas', icone: 'alvo' },
    { nome: 'relatorios', rotulo: 'Relatórios', icone: 'grafico' },
  ]
  if (temFinanceiro()) l.push({ nome: 'financeiro', rotulo: 'Financeiro', icone: 'cofre' })
  return l
})
const itensConfig = computed(() => ehGestao()
  ? [{ nome: 'usuarios', rotulo: 'Usuários' }, { nome: 'representadas', rotulo: 'Representadas' }]
  : [])

const mostrarCasca = computed(() => sessao.usuario && route.name !== 'login')
const iniciais = computed(() => (sessao.usuario?.nome || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase())

async function encerrar() {
  menuAberto.value = false
  await sair()
  router.push({ name: 'login' })
}

const icones = {
  casa: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  pessoas: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9c0-3.3 3.1-6 7-6s7 2.7 7 6M17 11a3 3 0 1 0 0-6M22 20c0-2.6-1.8-4.8-4.5-5.6',
  pedido: 'M6 2h9l5 5v15H6zM14 2v6h6M9 13h8M9 17h6',
  caixa: 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
  agenda: 'M4 5h16v16H4zM4 10h16M9 3v4M15 3v4',
  alvo: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-5a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0-3a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
  grafico: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  cofre: 'M3 5h18v14H3zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM6 19v2M18 19v2',
  mais: 'M5 12h.01M12 12h.01M19 12h.01',
  novo: 'M12 5v14M5 12h14',
}
</script>

<template>
  <div v-if="mostrarCasca" class="casca">
    <aside class="lateral" aria-label="Menu principal">
      <RouterLink :to="{ name: 'inicio' }" class="marca" aria-label="Início">
        <img src="/logo-up.png" alt="UP Gestão Comercial" width="118" height="70" />
      </RouterLink>
      <RouterLink :to="{ name: 'novo-pedido' }" class="botao novo-pedido">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones.novo" /></svg>
        Novo pedido
      </RouterLink>
      <nav>
        <RouterLink v-for="i in itens" :key="i.nome" :to="{ name: i.nome }" class="item-menu">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones[i.icone]" /></svg>
          {{ i.rotulo }}
        </RouterLink>
        <template v-if="itensConfig.length">
          <p class="grupo-menu">Configurações</p>
          <RouterLink v-for="i in itensConfig" :key="i.nome" :to="{ name: i.nome }" class="item-menu sub">{{ i.rotulo }}</RouterLink>
        </template>
      </nav>
      <div class="usuario">
        <span class="avatar" aria-hidden="true">{{ iniciais }}</span>
        <div class="usuario-info">
          <strong>{{ sessao.usuario.nome }}</strong>
          <RouterLink :to="{ name: 'minha-senha' }">Trocar senha</RouterLink>
        </div>
        <button class="botao discreto" @click="encerrar">Sair</button>
      </div>
    </aside>

    <main class="conteudo">
      <RouterView />
    </main>

    <!-- celular -->
    <nav class="barra-inferior" aria-label="Menu principal">
      <RouterLink :to="{ name: 'inicio' }"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones.casa" /></svg>Início</RouterLink>
      <RouterLink :to="{ name: 'clientes' }"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones.pessoas" /></svg>Clientes</RouterLink>
      <RouterLink :to="{ name: 'novo-pedido' }" class="central" aria-label="Novo pedido"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones.novo" /></svg></RouterLink>
      <RouterLink :to="{ name: 'pedidos' }"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones.pedido" /></svg>Pedidos</RouterLink>
      <button @click="menuAberto = true" :aria-expanded="menuAberto"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones.mais" /></svg>Mais</button>
    </nav>

    <div v-if="menuAberto" class="folha-mais" role="dialog" aria-label="Mais opções" @click.self="menuAberto = false">
      <div class="folha-mais-corpo">
        <RouterLink v-for="i in itens.slice(3)" :key="i.nome" :to="{ name: i.nome }" @click="menuAberto = false" class="item-menu">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icones[i.icone]" /></svg>{{ i.rotulo }}
        </RouterLink>
        <RouterLink v-for="i in itensConfig" :key="i.nome" :to="{ name: i.nome }" @click="menuAberto = false" class="item-menu">{{ i.rotulo }}</RouterLink>
        <RouterLink :to="{ name: 'minha-senha' }" @click="menuAberto = false" class="item-menu">Trocar senha</RouterLink>
        <button class="item-menu" @click="encerrar">Sair</button>
      </div>
    </div>
  </div>
  <RouterView v-else />
</template>

<style scoped>
.casca { display: grid; grid-template-columns: 248px 1fr; min-height: 100dvh; }
.lateral {
  background: var(--folha); border-right: 1px solid var(--linha);
  display: flex; flex-direction: column; gap: 16px; padding: 20px 14px;
  position: sticky; top: 0; height: 100dvh; overflow-y: auto;
}
.marca { display: block; padding: 0 8px; }
.marca img { display: block; width: 118px; height: auto; }
.novo-pedido svg, .item-menu svg, .barra-inferior svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }
nav { display: grid; gap: 2px; }
.item-menu {
  display: flex; align-items: center; gap: 12px; padding: 9px 10px; border-radius: var(--r-campo);
  color: var(--grafite); text-decoration: none; font-weight: 500; font: inherit; background: none; border: 0; text-align: left; cursor: pointer; width: 100%;
}
.item-menu:hover { background: var(--papel); }
.item-menu.router-link-active { background: var(--carbono-claro); color: var(--carbono); }
.item-menu.sub { padding-left: 42px; }
.grupo-menu { margin-top: 14px; padding: 0 10px 4px; font-size: var(--t-1); color: var(--grafite-3); }
.usuario { margin-top: auto; display: flex; align-items: center; gap: 10px; padding: 12px 8px 0; border-top: 1px solid var(--linha); }
.avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--carbono); color: #fff; display: grid; place-items: center; font-weight: 600; font-size: var(--t-1); flex: none; }
.usuario-info { display: grid; min-width: 0; font-size: var(--t-1); }
.usuario-info strong { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.9rem; }
.conteudo { padding: 28px 32px 48px; min-width: 0; }
.barra-inferior, .folha-mais { display: none; }

@media (max-width: 860px) {
  .casca { grid-template-columns: 1fr; }
  .lateral { display: none; }
  .conteudo { padding: 18px var(--gutter) calc(var(--nav-alt) + 28px + env(safe-area-inset-bottom)); }
  .barra-inferior {
    display: grid; grid-template-columns: repeat(5, 1fr); align-items: center;
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 10;
    height: calc(var(--nav-alt) + env(safe-area-inset-bottom)); padding-bottom: env(safe-area-inset-bottom);
    background: var(--folha); border-top: 1px solid var(--linha);
  }
  .barra-inferior a, .barra-inferior button {
    display: grid; justify-items: center; gap: 2px; font: inherit; font-size: 0.75rem; color: var(--grafite-2);
    text-decoration: none; background: none; border: 0; padding: 8px 0; cursor: pointer;
  }
  .barra-inferior a.router-link-exact-active { color: var(--carbono); }
  .barra-inferior .central { justify-self: center; width: 52px; height: 52px; border-radius: 50%; background: var(--carbono); color: #fff; display: grid; place-items: center; margin-top: -18px; border: 4px solid var(--papel); }
  .barra-inferior .central svg { width: 24px; height: 24px; }
  .folha-mais { display: block; position: fixed; inset: 0; background: rgb(26 31 39 / 0.4); z-index: 20; }
  .folha-mais-corpo { position: absolute; left: 0; right: 0; bottom: 0; background: var(--folha); border-radius: 14px 14px 0 0; padding: 12px 12px calc(16px + env(safe-area-inset-bottom)); display: grid; gap: 2px; }
}
</style>

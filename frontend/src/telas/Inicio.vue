<script setup>
import { computed } from 'vue'
import { sessao, temFinanceiro, ehGestao } from '../sessao.js'

const primeiroNome = computed(() => (sessao.usuario?.nome || '').split(' ')[0])
const hoje = new Intl.DateTimeFormat('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'America/Sao_Paulo' }).format(new Date())

// Andamento da construção — some quando o sistema estiver completo
const fases = [
  { n: 1, nome: 'Base: login, usuários, representadas e canais', pronto: true },
  { n: 2, nome: 'Catálogo, tabelas e clientes (prontos) · pedidos e faturamento', pronto: false },
  { n: 3, nome: 'Importação dos dados do Mercos', pronto: false },
  { n: 4, nome: 'Financeiro: comissões, contas e fluxo de caixa', pronto: false },
  { n: 5, nome: 'Metas, painel da TV, relatórios e fechamento', pronto: false },
  { n: 6, nome: 'App no celular offline, agenda e check-in', pronto: false },
  { n: 7, nome: 'Testes e virada', pronto: false },
]
</script>

<template>
  <section class="inicio">
    <header>
      <p class="data">{{ hoje }}</p>
      <h1>Olá, {{ primeiroNome }}</h1>
    </header>

    <div class="folha andamento">
      <h2>Sistema em construção</h2>
      <p class="explica">O Mercos continua sendo usado normalmente. Este sistema roda em paralelo até estar completo e conferido.</p>
      <ol>
        <li v-for="f in fases" :key="f.n" :class="{ pronto: f.pronto }">
          <span class="n">{{ f.n }}</span>
          <span>{{ f.nome }}</span>
          <span class="selo" :class="f.pronto ? 'ok' : 'neutro'">{{ f.pronto ? 'Pronto' : 'A fazer' }}</span>
        </li>
      </ol>
    </div>

    <div class="atalhos" v-if="ehGestao() || temFinanceiro()">
      <RouterLink v-if="ehGestao()" :to="{ name: 'usuarios' }" class="botao secundario">Cadastrar usuários</RouterLink>
      <RouterLink v-if="ehGestao()" :to="{ name: 'representadas' }" class="botao secundario">Representadas e canais</RouterLink>
    </div>
  </section>
</template>

<style scoped>
.inicio { display: grid; gap: 20px; max-width: 760px; }
.data { color: var(--grafite-2); }
.data::first-letter { text-transform: uppercase; }
h1 { font-size: var(--t4); font-weight: 600; letter-spacing: -0.01em; }
.andamento { padding: 20px; display: grid; gap: 12px; }
.explica { color: var(--grafite-2); max-width: 60ch; }
ol { list-style: none; margin: 4px 0 0; padding: 0; display: grid; }
li { display: grid; grid-template-columns: 28px 1fr auto; gap: 12px; align-items: center; padding: 10px 0; border-top: 1px solid var(--linha); }
li .n { font-family: var(--fonte-estreita); font-weight: 600; color: var(--grafite-3); }
li.pronto .n { color: var(--pago); }
.atalhos { display: flex; flex-wrap: wrap; gap: 10px; }
</style>

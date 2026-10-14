<script setup>
import { useRoute } from 'vue-router'
const route = useRoute()
</script>

<template>
  <section>
    <h1>{{ route.meta.titulo }}</h1>
    <div class="folha vazio">
      <p>Esta parte entra na fase {{ route.meta.fase }} da construção.</p>
      <p>Enquanto isso, continue usando o Mercos.</p>
      <RouterLink :to="{ name: 'inicio' }" class="botao secundario">Ver andamento</RouterLink>
    </div>
  </section>
</template>

<style scoped>
section { display: grid; gap: 16px; max-width: 760px; }
</style>

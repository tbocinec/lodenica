<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
  <div class="card-padded text-center">
    <h1 class="text-2xl font-semibold text-slate-900">404</h1>
    <p class="mt-2 text-sm text-slate-600">Stránka nebola nájdená.</p>
    <RouterLink to="/" class="btn-primary mt-4 inline-flex">Späť na prehľad</RouterLink>
  </div>
</template>

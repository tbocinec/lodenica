<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import AppShell from '@/components/layout/AppShell.vue';

const route = useRoute();

// Some routes (login) want a chrome-less full-bleed layout.
const blank = computed(() => route.meta?.layout === 'blank');
</script>

<template>
  <RouterView v-if="blank" />
  <AppShell v-else>
    <RouterView />
  </AppShell>
</template>

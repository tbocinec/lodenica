<script setup lang="ts">
import type { ResourceType } from '@/api/types';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';

defineProps<{ type: ResourceType }>();
</script>

<template>
  <span
    class="pill"
    :class="{
      'pill-blue': type === 'SEA_KAYAK',
      'pill-amber': type === 'WW_KAYAK' || type === 'ROWING_BOAT' || type === 'INFLATABLE_BOAT',
      'pill-green': type === 'CANOE',
      'pill-slate': type === 'TRAILER' || type === 'KAYAK',
      'pill-red': type === 'BOATHOUSE_SPACE',
    }"
  >
    {{ RESOURCE_TYPE_LABEL[type] }}
  </span>
</template>

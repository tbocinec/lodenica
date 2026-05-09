<script setup lang="ts">
import type { ResourceType } from '@/api/types';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';

defineProps<{ type: ResourceType }>();
</script>

<template>
  <span
    class="pill"
    :class="{
      'pill-blue': type === 'KAYAK',
      'pill-green': type === 'CANOE',
      'pill-amber': type === 'ROWING_BOAT' || type === 'INFLATABLE_BOAT',
      'pill-slate': type === 'TRAILER',
      'pill-red': type === 'BOATHOUSE_SPACE',
    }"
  >
    {{ RESOURCE_TYPE_LABEL[type] }}
  </span>
</template>

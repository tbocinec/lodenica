<script setup lang="ts">
import { computed } from 'vue';

import { colorHex } from '@/utils/colors';

const props = withDefaults(
  defineProps<{ color?: string | null; showLabel?: boolean; size?: number }>(),
  { color: null, showLabel: false, size: 14 },
);

const hex = computed(() => colorHex(props.color));
const known = computed(() => hex.value !== null);
</script>

<template>
  <span v-if="color" class="inline-flex items-center gap-1.5 align-middle" :title="color">
    <span
      class="inline-block shrink-0 rounded-full ring-1 ring-slate-300"
      :style="{
        width: size + 'px',
        height: size + 'px',
        backgroundColor: hex ?? 'transparent',
      }"
      :class="known ? '' : 'border border-dashed border-slate-300'"
    />
    <span v-if="showLabel" class="text-sm text-slate-700">{{ color }}</span>
  </span>
</template>

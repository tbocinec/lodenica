<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{ level: 'green' | 'orange' | 'red'; size?: 'sm' | 'lg' }>(),
  { size: 'sm' },
);

const dotClass = computed(() => (props.size === 'lg' ? 'h-7 w-7' : 'h-4 w-4'));

function lit(color: 'red' | 'orange' | 'green'): boolean {
  return props.level === color;
}
</script>

<template>
  <div
    class="inline-flex flex-col items-center rounded-xl bg-slate-900 ring-1 ring-slate-700"
    :class="size === 'lg' ? 'gap-2.5 p-3' : 'gap-1.5 p-1.5'"
    role="img"
    :aria-label="`Semafor: ${level}`"
  >
    <span
      class="rounded-full transition"
      :class="[dotClass, lit('red') ? 'bg-red-500 shadow-[0_0_10px_2px_rgba(239,68,68,0.7)]' : 'bg-red-900/40']"
    />
    <span
      class="rounded-full transition"
      :class="[dotClass, lit('orange') ? 'bg-amber-400 shadow-[0_0_10px_2px_rgba(251,191,36,0.7)]' : 'bg-amber-900/40']"
    />
    <span
      class="rounded-full transition"
      :class="[dotClass, lit('green') ? 'bg-emerald-500 shadow-[0_0_10px_2px_rgba(16,185,129,0.7)]' : 'bg-emerald-900/40']"
    />
  </div>
</template>

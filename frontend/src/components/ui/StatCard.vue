<script setup lang="ts">
defineProps<{ label: string; value: string | number; tone?: 'blue' | 'green' | 'amber' | 'red' }>();
</script>

<template>
  <div class="card-padded">
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ label }}</p>
    <p
      class="mt-2 text-3xl font-semibold tracking-tight"
      :class="{
        'text-brand-700': !tone || tone === 'blue',
        'text-emerald-700': tone === 'green',
        'text-amber-700': tone === 'amber',
        'text-red-700': tone === 'red',
      }"
    >
      {{ value }}
    </p>
  </div>
</template>

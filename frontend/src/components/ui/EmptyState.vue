<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
  <div class="card-padded text-center">
    <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-hidden="true">
      ✨
    </div>
    <h3 class="text-base font-semibold text-slate-800">{{ title }}</h3>
    <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
    <div class="mt-4">
      <slot />
    </div>
  </div>
</template>

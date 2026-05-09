<script setup lang="ts">
defineProps<{ message?: string | null }>();
</script>

<template>
  <div
    v-if="message"
    class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
    role="alert"
  >
    {{ message }}
  </div>
</template>

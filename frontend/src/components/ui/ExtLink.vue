<script setup lang="ts">
/**
 * External link that degrades to plain text when the site has no URL for
 * it (a club without a published rulebook, say). Keeps consent sentences
 * readable instead of pointing at nothing.
 */
defineProps<{ href: string | null | undefined }>();
</script>

<template>
  <a
    v-if="href"
    :href="href"
    target="_blank"
    rel="noopener noreferrer"
    class="font-medium text-brand-700 hover:underline"
  ><slot /></a>
  <span v-else class="font-medium"><slot /></span>
</template>

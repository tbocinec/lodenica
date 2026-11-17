<script setup lang="ts">
/**
 * Full-screen image viewer. The image is scaled to fit the viewport
 * (object-contain), so it works on a phone held either way as well as on a
 * wide desktop. Closes on backdrop click, the ✕ button or Escape.
 *
 *   <ImageLightbox :src="url" alt="…" @close="open = false" />
 */
import { onBeforeUnmount, onMounted } from 'vue';

defineProps<{ src: string; alt?: string }>();
const emit = defineEmits<{ (e: 'close'): void }>();

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    e.stopPropagation();
    emit('close');
  }
}
// Capture phase: Escape must close the lightbox first, not a dialog under it.
onMounted(() => window.addEventListener('keydown', onKey, true));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true));
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/85 p-3 sm:p-6"
      role="dialog"
      aria-modal="true"
      aria-label="Zväčšená fotka"
      data-testid="image-lightbox"
      @click.self="emit('close')"
    >
      <button
        type="button"
        class="absolute right-3 top-3 rounded-full bg-white/90 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-white"
        @click="emit('close')"
      >
        ✕ Zavrieť
      </button>
      <img
        :src="src"
        :alt="alt ?? ''"
        class="max-h-full max-w-full rounded-lg object-contain"
        @click.self="emit('close')"
      />
    </div>
  </Teleport>
</template>

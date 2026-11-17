<script setup lang="ts">
/**
 * Read-only detail of a boat / resource in a modal — photo, parameters and
 * the admin's note. Opened from the reservation form so the member can check
 * the boat they picked without leaving (and losing) the half-filled form.
 *
 * Bottom sheet on phones, centred card from `sm` up; the body scrolls when the
 * content is taller than the screen (small landscape phones).
 *
 *   <ResourceDetailDialog :resource="selected" @close="detailOpen = false" />
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import type { Resource } from '@/api/types';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';
import { fieldsFor, isBoatType } from '@/utils/resource-fields';
import { resourcePhoto } from '@/utils/resource-photo';

import ColorDot from './ColorDot.vue';
import DamageBadge from './DamageBadge.vue';
import ImageLightbox from './ImageLightbox.vue';

const props = defineProps<{ resource: Resource; backTo?: string }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const photoFailed = ref(false);
const photo = computed(() => (photoFailed.value ? null : resourcePhoto(props.resource)));
const zoomed = ref(false);
const fields = computed(() => fieldsFor(props.resource.type));

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape' && !zoomed.value) emit('close');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
      role="dialog"
      aria-modal="true"
      :aria-label="`Detail: ${resource.identifier}`"
      data-testid="resource-detail-dialog"
      @click.self="emit('close')"
    >
      <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl sm:rounded-2xl sm:p-5">
        <header class="mb-3 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs uppercase tracking-wide text-slate-500">
              {{ RESOURCE_TYPE_LABEL[resource.type] }}
            </p>
            <h3 class="text-lg font-semibold text-slate-900">
              {{ resource.identifier }} · {{ resource.displayName }}
            </h3>
          </div>
          <button
            type="button"
            class="shrink-0 rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
            aria-label="Zavrieť"
            @click="emit('close')"
          >
            ✕
          </button>
        </header>

        <button
          v-if="photo"
          type="button"
          class="mb-4 flex w-full items-center justify-center overflow-hidden rounded-lg bg-slate-100"
          title="Zväčšiť fotku"
          @click="zoomed = true"
        >
          <img
            :src="photo"
            :alt="resource.displayName"
            class="max-h-[40vh] w-full object-contain"
            @error="photoFailed = true"
          />
        </button>

        <div v-if="resource.openDamage" class="mb-4">
          <DamageBadge :damage="resource.openDamage" link-to-detail :back-to="backTo" />
        </div>

        <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
          <template v-if="isBoatType(resource.type)">
            <dt class="text-slate-500">Výrobca</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.name }}</dd>
          </template>

          <template v-if="resource.model && fields.has('model')">
            <dt class="text-slate-500">Model</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.model }}</dd>
          </template>
          <template v-if="resource.color && fields.has('color')">
            <dt class="text-slate-500">Farba</dt>
            <dd class="col-span-2"><ColorDot :color="resource.color" show-label /></dd>
          </template>
          <template v-if="resource.seats && fields.has('seats')">
            <dt class="text-slate-500">Počet miest</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.seats }}</dd>
          </template>
          <template v-if="resource.lengthCm && fields.has('lengthCm')">
            <dt class="text-slate-500">Dĺžka</dt>
            <dd class="col-span-2 text-slate-800">{{ (resource.lengthCm / 100).toFixed(2) }} m</dd>
          </template>
          <template v-if="resource.weightKg && fields.has('weightKg')">
            <dt class="text-slate-500">Hmotnosť</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.weightKg }} kg</dd>
          </template>
          <template v-if="resource.requiresApproval">
            <dt class="text-slate-500">Schvaľovanie</dt>
            <dd class="col-span-2 text-slate-800">🔒 Rezervácia čaká na schválenie.</dd>
          </template>
        </dl>

        <div v-if="resource.note" class="mt-4 rounded-lg bg-slate-50 p-3 text-sm">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Poznámka</p>
          <p class="whitespace-pre-line break-words text-slate-800" data-testid="resource-note">{{ resource.note }}</p>
        </div>

        <p
          v-if="!photo && !resource.note && !resource.requiresApproval && ![...fields].some((f) => resource[f])"
          class="text-sm text-slate-500"
        >
          K tomuto zdroju zatiaľ nie sú žiadne ďalšie údaje.
        </p>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
          <!-- New tab: the half-filled reservation form stays untouched. -->
          <RouterLink
            :to="`/resources/${resource.id}`"
            target="_blank"
            class="text-sm font-medium text-brand-700 hover:underline"
            data-testid="open-resource-page"
          >
            Otvoriť na samostatnej stránke ↗
          </RouterLink>
          <button type="button" class="btn-secondary" @click="emit('close')">Zavrieť</button>
        </div>
      </div>
    </div>

    <ImageLightbox v-if="zoomed && photo" :src="photo" :alt="resource.displayName" @close="zoomed = false" />
  </Teleport>
</template>

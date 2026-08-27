<script setup lang="ts">
/**
 * Searchable resource picker (combobox). Full-text filters across all active
 * resources by identifier / name / model / colour / type label — the same
 * search experience as the reservation form's "find a boat" step — and emits
 * the chosen resource id via v-model.
 *
 *   <ResourceSelect v-model="form.resourceId" />
 */
import { computed, ref } from 'vue';

import type { ResourceType } from '@/api/types';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';

import ColorDot from './ColorDot.vue';
import DamageBadge from './DamageBadge.vue';

/**
 * Tint for a damaged option, by severity. Deliberately lighter than the
 * badge itself — the row has to stay readable, the badge does the shouting.
 */
function damageTint(severity: string | undefined): string {
  if (severity === 'CRITICAL') return 'bg-rose-50 hover:bg-rose-100';
  if (severity === 'MODERATE') return 'bg-amber-50 hover:bg-amber-100';
  if (severity === 'MINOR') return 'bg-slate-50 hover:bg-slate-100';
  return 'hover:bg-brand-50';
}

const props = withDefaults(
  defineProps<{ modelValue: string; placeholder?: string }>(),
  { placeholder: 'Hľadať loď — ID, názov, model, farba, typ…' },
);
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const resources = useResourcesStore();

const TYPE_ICON: Record<ResourceType, string> = {
  KAYAK: '🛶',
  SEA_KAYAK: '🌊',
  WW_KAYAK: '💧',
  CANOE: '🛶',
  ROWING_BOAT: '🚣',
  INFLATABLE_BOAT: '🛟',
  TRAILER: '🚐',
  BOATHOUSE_SPACE: '🏠',
};

const query = ref('');
const open = ref(false);

const selected = computed(() =>
  props.modelValue ? resources.byId.get(props.modelValue) ?? null : null,
);

const results = computed(() => {
  const q = query.value.trim().toLowerCase();
  const active = resources.items.filter((r) => r.isActive);
  const list = !q
    ? active
    : active.filter(
        (r) =>
          r.identifier.toLowerCase().includes(q) ||
          r.name.toLowerCase().includes(q) ||
          (r.model ?? '').toLowerCase().includes(q) ||
          (r.color ?? '').toLowerCase().includes(q) ||
          (RESOURCE_TYPE_LABEL[r.type] ?? '').toLowerCase().includes(q),
      );
  return [...list]
    .sort((a, b) => a.type.localeCompare(b.type) || a.identifier.localeCompare(b.identifier))
    .slice(0, 50);
});

function pick(id: string): void {
  emit('update:modelValue', id);
  query.value = '';
  open.value = false;
}

function clear(): void {
  emit('update:modelValue', '');
  query.value = '';
  open.value = true;
}

// Delay close so a click on a dropdown item registers before blur hides it.
function onBlur(): void {
  setTimeout(() => (open.value = false), 150);
}
</script>

<template>
  <div class="relative">
    <!-- Chosen resource chip -->
    <div
      v-if="selected && !open"
      class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2"
      :class="
        selected.openDamage
          ? 'border-amber-300 bg-amber-50/60'
          : 'border-slate-300 bg-white'
      "
    >
      <span class="flex min-w-0 flex-wrap items-center gap-2 text-sm">
        <span aria-hidden="true">{{ TYPE_ICON[selected.type] ?? '📦' }}</span>
        <span class="font-medium text-slate-900">{{ selected.identifier }}</span>
        <span class="truncate text-slate-500">{{ selected.name }}</span>
        <ColorDot v-if="selected.color" :color="selected.color" :size="11" />
        <DamageBadge v-if="selected.openDamage" :damage="selected.openDamage" link-to-detail />
      </span>
      <button
        type="button"
        class="shrink-0 text-xs text-slate-500 hover:text-slate-700 hover:underline"
        @click="clear"
      >
        Zmeniť
      </button>
    </div>

    <!-- Search input + dropdown -->
    <template v-else>
      <div class="relative">
        <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
        <input
          v-model="query"
          type="search"
          class="input pl-8"
          :placeholder="placeholder"
          maxlength="60"
          @focus="open = true"
          @blur="onBlur"
        />
      </div>
      <ul
        v-if="open"
        class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
      >
        <li v-if="results.length === 0" class="px-3 py-2 text-sm text-slate-400">
          Nič nezodpovedá hľadaniu.
        </li>
        <li v-for="r in results" :key="r.id">
          <button
            type="button"
            class="flex w-full flex-wrap items-center gap-2 px-3 py-2 text-left text-sm"
            :class="damageTint(r.openDamage?.severity)"
            @mousedown.prevent="pick(r.id)"
          >
            <span aria-hidden="true">{{ TYPE_ICON[r.type] ?? '📦' }}</span>
            <span class="font-medium text-slate-900">{{ r.identifier }}</span>
            <span class="truncate text-slate-500">{{ r.name }}</span>
            <span class="ml-auto flex items-center gap-1 text-xs text-slate-400">
              {{ RESOURCE_TYPE_LABEL[r.type] }}
              <ColorDot v-if="r.color" :color="r.color" :size="10" />
            </span>
            <DamageBadge v-if="r.openDamage" :damage="r.openDamage" class="basis-full" />
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>

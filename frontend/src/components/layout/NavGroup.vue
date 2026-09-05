<script setup lang="ts">
/**
 * A collapsible navigation group ("Informácie", "Administrácia"). Opens
 * itself whenever the current route lives inside it; otherwise it stays as
 * the user left it. Subgroups render as small headings, not as nested
 * collapsibles — two levels is as deep as the menu goes.
 */
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

import { groupIsActive, isActivePath, type NavGroup } from './nav';

const props = defineProps<{
  group: NavGroup;
  activePath: string;
}>();

const emit = defineEmits<{ navigate: [] }>();

const open = ref(false);

/** Sum of item badges — shown on the header while the group is collapsed. */
const totalBadge = computed(() =>
  [...props.group.items, ...(props.group.subgroups ?? []).flatMap((s) => s.items)].reduce(
    (sum, item) => sum + (item.badge ?? 0),
    0,
  ),
);

watch(
  () => groupIsActive(props.group, props.activePath),
  (active) => {
    if (active) open.value = true;
  },
  { immediate: true },
);
</script>

<template>
  <div class="pt-1" :data-nav-group="group.key">
    <button
      type="button"
      class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
      :class="groupIsActive(group, activePath) ? 'text-brand-800' : ''"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span aria-hidden="true">{{ group.icon }}</span>
      <span>{{ group.label }}</span>
      <span
        v-if="!open && totalBadge"
        class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
      >{{ totalBadge }}</span>
      <span
        aria-hidden="true"
        class="text-xs text-slate-400 transition-transform"
        :class="[open ? 'rotate-90' : '', !open && totalBadge ? 'ml-2' : 'ml-auto']"
      >▶</span>
    </button>

    <div v-show="open" class="mt-1 space-y-1 border-l border-slate-200 pl-3">
      <template v-for="item in group.items" :key="item.to">
        <a
          v-if="item.external"
          :href="item.to"
          target="_blank"
          rel="noopener noreferrer"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
          @click="emit('navigate')"
        >
          <span aria-hidden="true">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
          <span aria-hidden="true" class="ml-auto text-xs text-slate-400">↗</span>
        </a>
        <RouterLink
          v-else
          :to="item.to"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
          :class="isActivePath(activePath, item.to) ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-100' : ''"
          @click="emit('navigate')"
        >
          <span aria-hidden="true">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
          <span
            v-if="item.badge"
            class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
          >{{ item.badge }}</span>
        </RouterLink>
      </template>

      <template v-for="sub in group.subgroups ?? []" :key="sub.label">
        <p class="mt-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
          {{ sub.label }}
        </p>
        <RouterLink
          v-for="item in sub.items"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
          :class="isActivePath(activePath, item.to) ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-100' : ''"
          @click="emit('navigate')"
        >
          <span aria-hidden="true">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
          <span
            v-if="item.badge"
            class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
          >{{ item.badge }}</span>
        </RouterLink>
      </template>
    </div>
  </div>
</template>

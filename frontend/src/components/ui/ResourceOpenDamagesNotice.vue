<script setup lang="ts">
/**
 * "This boat is already broken" panel, shown under the resource picker in
 * the new-damage form.
 *
 * A member reporting a hole in a hull often reports the same hole someone
 * else already reported. So once a resource is chosen, list its open
 * damages and offer the existing ones first — the detail page is where
 * the description gets extended and the discussion happens. Reporting a
 * separate damage stays one click away; this only warns, never blocks.
 *
 *   <ResourceOpenDamagesNotice :resource-id="form.resourceId" />
 */
import { ref, watch } from 'vue';

import { damagesApi } from '@/api/damages.api';
import { DamageStatus, type Damage } from '@/api/types';
import { formatDate } from '@/utils/format';

import DamageBadge from './DamageBadge.vue';

const props = defineProps<{ resourceId: string }>();

const open = ref<Damage[]>([]);
const loading = ref(false);

/** Guards against a slow answer landing after the member picked another boat. */
let requestToken = 0;

function isOpen(d: Damage): boolean {
  return d.status !== DamageStatus.FIXED;
}

/** 1 poškodenie · 2–4 poškodenia · 5+ poškodení. */
function headline(n: number): string {
  if (n === 1) return 'Tento zdroj už má 1 otvorené poškodenie';
  if (n < 5) return `Tento zdroj už má ${n} otvorené poškodenia`;
  return `Tento zdroj už má ${n} otvorených poškodení`;
}

async function check(resourceId: string): Promise<void> {
  const token = ++requestToken;
  open.value = [];

  if (!resourceId) {
    loading.value = false;
    return;
  }

  loading.value = true;
  try {
    const page = await damagesApi.list({ resourceId, pageSize: 50 });
    if (token !== requestToken) return;
    open.value = page.items.filter(isOpen);
  } catch {
    // The check is a courtesy, not a gate — a failed lookup must never
    // stand between a member and reporting a damage.
    if (token === requestToken) open.value = [];
  } finally {
    if (token === requestToken) loading.value = false;
  }
}

watch(() => props.resourceId, check, { immediate: true });
</script>

<template>
  <div
    v-if="open.length"
    class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm"
  >
    <p class="font-medium text-amber-900">⚠️ {{ headline(open.length) }}</p>
    <p class="mt-0.5 text-xs text-amber-800">
      Ak chceš poškodenie rozšíriť, otvor ho a doplň popis alebo pridaj komentár. Ak chceš
      vytvoriť separátny záznam, pokojne pokračuj nižšie.
    </p>

    <ul class="mt-2 divide-y divide-amber-200 border-t border-amber-200">
      <li v-for="d in open" :key="d.id" class="flex flex-wrap items-center gap-x-2 gap-y-1 py-2">
        <DamageBadge :damage="d" />
        <span class="min-w-0 flex-1 truncate text-slate-700">{{ d.description }}</span>
        <span class="text-xs text-slate-500">
          {{ formatDate(d.reportedAt) }}<template v-if="d.reportedByName">
            · {{ d.reportedByName }}</template>
        </span>
        <RouterLink
          :to="`/damages/${d.id}`"
          target="_blank"
          class="shrink-0 text-xs font-medium text-brand-700 hover:underline"
        >
          Otvoriť ↗
        </RouterLink>
      </li>
    </ul>
  </div>
</template>

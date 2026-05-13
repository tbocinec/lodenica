<script setup lang="ts">
/**
 * Read-only audit log browser. Lists every recorded business change with
 * filters by entity type, action and date range. Each row can be expanded
 * to inspect the raw `changes` JSON — handy for "what exactly changed?"
 * debugging that the one-line `summary` can't answer.
 */
import { computed, onMounted, ref, watch } from 'vue';

import { auditApi } from '@/api/audit.api';
import type { AuditAction, AuditEntityType, AuditLog } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { AUDIT_ACTION_LABEL, AUDIT_ENTITY_TYPE_LABEL } from '@/i18n/labels';
import { formatDateTime } from '@/utils/format';

const items = ref<AuditLog[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(50);
const loading = ref(false);
const error = ref<string | null>(null);
const expanded = ref<Set<string>>(new Set());

const entityTypeFilter = ref<AuditEntityType | ''>('');
const actionFilter = ref<AuditAction | ''>('');
const fromFilter = ref('');
const toFilter = ref('');

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)));

const ENTITY_TYPES: AuditEntityType[] = [
  'RESOURCE',
  'RESERVATION',
  'EVENT',
  'EVENT_PARTICIPANT',
  'DAMAGE',
];

const ACTIONS: AuditAction[] = [
  'CREATE',
  'UPDATE',
  'DELETE',
  'CANCEL',
  'ACTIVATE',
  'DEACTIVATE',
  'ATTACH_RESOURCES',
  'ADD_PARTICIPANT',
  'REMOVE_PARTICIPANT',
];

function actionBadgeClass(a: AuditAction): string {
  switch (a) {
    case 'CREATE':
    case 'ACTIVATE':
    case 'ATTACH_RESOURCES':
    case 'ADD_PARTICIPANT':
      return 'bg-emerald-100 text-emerald-800 ring-emerald-200';
    case 'UPDATE':
      return 'bg-sky-100 text-sky-800 ring-sky-200';
    case 'DELETE':
    case 'CANCEL':
    case 'DEACTIVATE':
    case 'REMOVE_PARTICIPANT':
      return 'bg-rose-100 text-rose-800 ring-rose-200';
    default:
      return 'bg-slate-100 text-slate-800 ring-slate-200';
  }
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const result = await auditApi.list({
      entityType: entityTypeFilter.value || undefined,
      action: actionFilter.value || undefined,
      from: fromFilter.value ? new Date(fromFilter.value).toISOString() : undefined,
      to: toFilter.value ? new Date(toFilter.value).toISOString() : undefined,
      page: page.value,
      pageSize: pageSize.value,
    });
    items.value = result.items;
    total.value = result.total;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

function toggleExpand(id: string) {
  if (expanded.value.has(id)) {
    expanded.value.delete(id);
  } else {
    expanded.value.add(id);
  }
  // Trigger reactivity on Set mutation.
  expanded.value = new Set(expanded.value);
}

function resetFilters() {
  entityTypeFilter.value = '';
  actionFilter.value = '';
  fromFilter.value = '';
  toFilter.value = '';
  page.value = 1;
}

function changePage(delta: number) {
  const next = page.value + delta;
  if (next >= 1 && next <= totalPages.value) {
    page.value = next;
  }
}

// Reload on any filter or page change; debounce isn't worth it for the
// tiny audit-log endpoint.
watch([entityTypeFilter, actionFilter, fromFilter, toFilter], () => {
  page.value = 1;
});
watch([page, pageSize, entityTypeFilter, actionFilter, fromFilter, toFilter], () => load());

onMounted(load);
</script>

<template>
  <PageHeader
    title="História zmien"
    subtitle="Záznam o všetkých zmenách v systéme — pridanie / úprava / zmazanie / zrušenie."
  />

  <div class="mb-4 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-4">
    <div>
      <label class="label" for="filter-entityType">Typ záznamu</label>
      <select id="filter-entityType" v-model="entityTypeFilter" class="input mt-1">
        <option value="">Všetky</option>
        <option v-for="t in ENTITY_TYPES" :key="t" :value="t">
          {{ AUDIT_ENTITY_TYPE_LABEL[t] }}
        </option>
      </select>
    </div>
    <div>
      <label class="label" for="filter-action">Akcia</label>
      <select id="filter-action" v-model="actionFilter" class="input mt-1">
        <option value="">Všetky</option>
        <option v-for="a in ACTIONS" :key="a" :value="a">
          {{ AUDIT_ACTION_LABEL[a] }}
        </option>
      </select>
    </div>
    <div>
      <label class="label" for="filter-from">Od</label>
      <input id="filter-from" v-model="fromFilter" type="datetime-local" class="input mt-1" />
    </div>
    <div>
      <label class="label" for="filter-to">Do</label>
      <input id="filter-to" v-model="toFilter" type="datetime-local" class="input mt-1" />
    </div>
    <div class="sm:col-span-4 flex justify-end">
      <button type="button" class="btn-secondary" @click="resetFilters">Zrušiť filtre</button>
    </div>
  </div>

  <LoadError class="mb-4" :message="error" />

  <div v-if="loading && items.length === 0" class="flex justify-center py-12">
    <Spinner />
  </div>

  <EmptyState
    v-else-if="items.length === 0"
    title="Žiadne zmeny"
    description="Pre vybrané filtre nie sú zaznamenané žiadne zmeny."
  />

  <div v-else class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
        <tr>
          <th class="px-4 py-2.5">Čas</th>
          <th class="px-4 py-2.5">Typ</th>
          <th class="px-4 py-2.5">Akcia</th>
          <th class="px-4 py-2.5">Popis</th>
          <th class="px-4 py-2.5"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <template v-for="row in items" :key="row.id">
          <tr class="hover:bg-slate-50">
            <td class="whitespace-nowrap px-4 py-2 text-slate-600">
              {{ formatDateTime(row.createdAt) }}
            </td>
            <td class="px-4 py-2 text-slate-700">
              {{ AUDIT_ENTITY_TYPE_LABEL[row.entityType] ?? row.entityType }}
            </td>
            <td class="px-4 py-2">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                :class="actionBadgeClass(row.action)"
              >
                {{ AUDIT_ACTION_LABEL[row.action] ?? row.action }}
              </span>
            </td>
            <td class="px-4 py-2 text-slate-800">{{ row.summary }}</td>
            <td class="px-4 py-2 text-right">
              <button
                v-if="row.changes"
                type="button"
                class="text-sky-700 hover:underline"
                @click="toggleExpand(row.id)"
              >
                {{ expanded.has(row.id) ? 'Skryť' : 'Detail' }}
              </button>
            </td>
          </tr>
          <tr v-if="expanded.has(row.id)" class="bg-slate-50">
            <td colspan="5" class="px-4 py-3">
              <pre class="overflow-x-auto whitespace-pre-wrap break-words text-xs text-slate-700">{{ JSON.stringify(row.changes, null, 2) }}</pre>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  <nav v-if="total > 0" class="mt-4 flex items-center justify-between gap-3">
    <p class="text-sm text-slate-500">
      Strana {{ page }} z {{ totalPages }} · spolu {{ total }}
    </p>
    <div class="flex gap-2">
      <button
        type="button"
        class="btn-secondary"
        :disabled="page <= 1 || loading"
        @click="changePage(-1)"
      >
        ← Predchádzajúca
      </button>
      <button
        type="button"
        class="btn-secondary"
        :disabled="page >= totalPages || loading"
        @click="changePage(1)"
      >
        Nasledujúca →
      </button>
    </div>
  </nav>
</template>

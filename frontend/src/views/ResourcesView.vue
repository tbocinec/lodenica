<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

import { resourcesApi } from '@/api/resources.api';
import { ResourceType, type Resource } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { RESOURCE_TYPE_LABEL_PLURAL } from '@/i18n/labels';

const items = ref<Resource[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const search = ref('');
const typeFilter = ref<ResourceType | ''>('');
const onlyActive = ref(true);

const filtered = computed(() => {
  return items.value.filter((r) => {
    if (typeFilter.value && r.type !== typeFilter.value) return false;
    if (onlyActive.value && !r.isActive) return false;
    if (search.value) {
      const q = search.value.toLowerCase();
      return (
        r.name.toLowerCase().includes(q) ||
        r.identifier.toLowerCase().includes(q) ||
        (r.model ?? '').toLowerCase().includes(q)
      );
    }
    return true;
  });
});

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const data = await resourcesApi.list({ pageSize: 200 });
    items.value = data.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

async function toggleActive(r: Resource) {
  try {
    if (r.isActive) await resourcesApi.deactivate(r.id);
    else await resourcesApi.activate(r.id);
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

onMounted(load);
watch([search, typeFilter, onlyActive], () => {
  /* purely client-side filter — no refetch needed */
});
</script>

<template>
  <PageHeader title="Lode" subtitle="Inventár lodí, prívesov a lodeníc.">
    <template #actions>
      <RouterLink to="/resources/new" class="btn-primary">＋ Pridať loď</RouterLink>
    </template>
  </PageHeader>

  <div class="card-padded mb-4 grid gap-3 sm:grid-cols-3">
    <div>
      <label class="label" for="search">Hľadať</label>
      <input
        id="search"
        v-model="search"
        class="input mt-1"
        placeholder="Názov, identifikátor, model…"
      />
    </div>
    <div>
      <label class="label" for="type">Typ</label>
      <select id="type" v-model="typeFilter" class="input mt-1">
        <option value="">Všetky</option>
        <option v-for="t in Object.values(ResourceType)" :key="t" :value="t">
          {{ RESOURCE_TYPE_LABEL_PLURAL[t] }}
        </option>
      </select>
    </div>
    <div class="flex items-end">
      <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
        <input v-model="onlyActive" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
        Iba aktívne
      </label>
    </div>
  </div>

  <LoadError :message="error" />
  <Spinner v-if="loading && !items.length" />

  <EmptyState
    v-else-if="filtered.length === 0"
    title="Žiadne výsledky"
    description="Skús zmeniť filtre alebo pridať nový zdroj."
  >
    <RouterLink to="/resources/new" class="btn-primary">＋ Pridať loď</RouterLink>
  </EmptyState>

  <div v-else class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table-clean">
        <thead class="bg-slate-100/70">
          <tr>
            <th>Identifikátor</th>
            <th>Typ</th>
            <th>Názov</th>
            <th>Model</th>
            <th class="hidden md:table-cell">Detaily</th>
            <th>Stav</th>
            <th class="text-right">Akcie</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filtered" :key="r.id">
            <td class="font-mono text-xs text-slate-500">{{ r.identifier }}</td>
            <td><ResourceTypeBadge :type="r.type" /></td>
            <td class="font-medium">
              <RouterLink :to="`/resources/${r.id}`" class="hover:text-brand-700">
                {{ r.name }}
              </RouterLink>
            </td>
            <td>{{ r.model ?? '—' }}</td>
            <td class="hidden md:table-cell text-xs text-slate-500">
              <span v-if="r.seats">{{ r.seats }} miest</span>
              <span v-if="r.lengthCm"> · {{ r.lengthCm / 100 }} m</span>
              <span v-if="r.weightKg"> · {{ r.weightKg }} kg</span>
            </td>
            <td>
              <span :class="r.isActive ? 'pill-green' : 'pill-slate'">
                {{ r.isActive ? 'Aktívny' : 'Neaktívny' }}
              </span>
            </td>
            <td class="text-right">
              <RouterLink :to="`/resources/${r.id}`" class="btn-secondary mr-2">
                Upraviť
              </RouterLink>
              <button class="btn-secondary" type="button" @click="toggleActive(r)">
                {{ r.isActive ? 'Deaktivovať' : 'Aktivovať' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

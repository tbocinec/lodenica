<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';

import { damagesApi } from '@/api/damages.api';
import { DamageSeverity, DamageStatus, type Damage } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import {
  DAMAGE_SEVERITY_LABEL,
  DAMAGE_STATUS_LABEL,
  RESOURCE_TYPE_LABEL,
} from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import { formatDate } from '@/utils/format';

const resources = useResourcesStore();
const items = ref<Damage[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const showCreate = ref(false);

const form = reactive({
  resourceId: '',
  description: '',
  severity: DamageSeverity.MINOR as DamageSeverity,
  note: '',
});

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [d] = await Promise.all([damagesApi.list({ pageSize: 200 }), resources.fetch()]);
    items.value = d.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

async function changeStatus(d: Damage, status: DamageStatus) {
  try {
    await damagesApi.update(d.id, { status });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function create() {
  error.value = null;
  try {
    await damagesApi.create({
      resourceId: form.resourceId,
      description: form.description,
      severity: form.severity,
      note: form.note || undefined,
    });
    showCreate.value = false;
    Object.assign(form, {
      resourceId: '',
      description: '',
      severity: DamageSeverity.MINOR,
      note: '',
    });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader title="Poškodenia">
    <template #actions>
      <button class="btn-primary" type="button" @click="showCreate = !showCreate">
        ＋ Nahlásiť poškodenie
      </button>
    </template>
  </PageHeader>

  <div v-if="showCreate" class="card-padded mb-4">
    <h2 class="mb-3 text-lg font-semibold">Nové poškodenie</h2>
    <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="create">
      <div>
        <label class="label" for="dres">Zdroj *</label>
        <select id="dres" v-model="form.resourceId" class="input mt-1" required>
          <option value="" disabled>Vyber zdroj…</option>
          <option v-for="r in resources.items" :key="r.id" :value="r.id">
            {{ RESOURCE_TYPE_LABEL[r.type] }} · {{ r.identifier }} · {{ r.name }}
          </option>
        </select>
      </div>
      <div>
        <label class="label" for="dsev">Závažnosť *</label>
        <select id="dsev" v-model="form.severity" class="input mt-1" required>
          <option v-for="s in Object.values(DamageSeverity)" :key="s" :value="s">
            {{ DAMAGE_SEVERITY_LABEL[s] }}
          </option>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="ddesc">Popis *</label>
        <textarea
          id="ddesc"
          v-model="form.description"
          class="input mt-1"
          rows="3"
          required
          maxlength="1000"
        ></textarea>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="dnote">Poznámka</label>
        <textarea
          id="dnote"
          v-model="form.note"
          class="input mt-1"
          rows="2"
          maxlength="1000"
        ></textarea>
      </div>
      <div class="sm:col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreate = false">Zrušiť</button>
        <button type="submit" class="btn-primary">Uložiť</button>
      </div>
    </form>
  </div>

  <LoadError :message="error" />
  <Spinner v-if="loading && !items.length" />
  <EmptyState v-else-if="items.length === 0" title="Žiadne nahlásené poškodenia" />

  <div v-else class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table-clean">
        <thead class="bg-slate-100/70">
          <tr>
            <th>Nahlásené</th>
            <th>Zdroj</th>
            <th>Popis</th>
            <th>Závažnosť</th>
            <th>Stav</th>
            <th class="text-right">Akcie</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in items" :key="d.id">
            <td class="text-slate-500">{{ formatDate(d.reportedAt) }}</td>
            <td class="font-medium">
              {{ resources.byId.get(d.resourceId)?.name ?? '—' }}
            </td>
            <td class="max-w-md whitespace-pre-wrap text-slate-700">{{ d.description }}</td>
            <td>
              <span
                :class="{
                  'pill-slate': d.severity === 'MINOR',
                  'pill-amber': d.severity === 'MODERATE',
                  'pill-red': d.severity === 'CRITICAL',
                }"
              >
                {{ DAMAGE_SEVERITY_LABEL[d.severity] }}
              </span>
            </td>
            <td>
              <span
                :class="{
                  'pill-amber': d.status === 'REPORTED',
                  'pill-blue': d.status === 'IN_REPAIR',
                  'pill-green': d.status === 'FIXED',
                }"
              >
                {{ DAMAGE_STATUS_LABEL[d.status] }}
              </span>
            </td>
            <td class="space-x-2 text-right">
              <button
                v-if="d.status !== 'IN_REPAIR'"
                class="btn-secondary"
                type="button"
                @click="changeStatus(d, DamageStatus.IN_REPAIR)"
              >
                Do opravy
              </button>
              <button
                v-if="d.status !== 'FIXED'"
                class="btn-secondary"
                type="button"
                @click="changeStatus(d, DamageStatus.FIXED)"
              >
                Opravené
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { damagesApi } from '@/api/damages.api';
import { DamageSeverity, DamageStatus, type Damage } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceSelect from '@/components/ui/ResourceSelect.vue';
import Spinner from '@/components/ui/Spinner.vue';
import {
  DAMAGE_SEVERITY_LABEL,
  DAMAGE_STATUS_LABEL,
  RESOURCE_TYPE_LABEL,
} from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import { formatDate } from '@/utils/format';

const router = useRouter();
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

/** File picked in the "Pridať fotku" input; uploaded after the damage row is created. */
const photoFile = ref<File | null>(null);
const photoPreview = ref<string | null>(null);

function resourceLabel(d: Damage): string {
  const r = resources.byId.get(d.resourceId);
  return r ? `${r.identifier} · ${r.name}` : '—';
}

function onPhotoChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0] ?? null;
  if (photoPreview.value) URL.revokeObjectURL(photoPreview.value);
  photoFile.value = file;
  photoPreview.value = file ? URL.createObjectURL(file) : null;
}

function clearPhoto(): void {
  if (photoPreview.value) URL.revokeObjectURL(photoPreview.value);
  photoFile.value = null;
  photoPreview.value = null;
}

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

async function create() {
  error.value = null;
  try {
    const created = await damagesApi.create({
      resourceId: form.resourceId,
      description: form.description,
      severity: form.severity,
      note: form.note || undefined,
    });
    if (photoFile.value) {
      try {
        await damagesApi.uploadPhoto(created.id, photoFile.value);
      } catch (e) {
        error.value =
          'Poškodenie nahlásené, ale fotku sa nepodarilo nahrať: ' + (e as Error).message;
      }
    }
    showCreate.value = false;
    Object.assign(form, {
      resourceId: '',
      description: '',
      severity: DamageSeverity.MINOR,
      note: '',
    });
    clearPhoto();
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

// ── Intelligent table: search, filters, pagination ────────────────────────
const searchQuery = ref('');
const statusFilter = ref<DamageStatus | ''>('');
const severityFilter = ref<DamageSeverity | ''>('');
const page = ref(1);
const PAGE_SIZE = 15;

const filtered = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return items.value.filter((d) => {
    if (statusFilter.value && d.status !== statusFilter.value) return false;
    if (severityFilter.value && d.severity !== severityFilter.value) return false;
    if (q) {
      const r = resources.byId.get(d.resourceId);
      const hay = [
        d.description,
        d.note ?? '',
        r?.identifier ?? '',
        r?.name ?? '',
        r?.model ?? '',
        r ? RESOURCE_TYPE_LABEL[r.type] : '',
        DAMAGE_SEVERITY_LABEL[d.severity],
        DAMAGE_STATUS_LABEL[d.status],
      ]
        .join(' ')
        .toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  });
});

const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / PAGE_SIZE)));
const paged = computed(() =>
  filtered.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE),
);

// Reset to the first page whenever the result set changes.
watch([searchQuery, statusFilter, severityFilter], () => {
  page.value = 1;
});
watch(pageCount, (n) => {
  if (page.value > n) page.value = n;
});

function clearFilters(): void {
  searchQuery.value = '';
  statusFilter.value = '';
  severityFilter.value = '';
}

function openDetail(d: Damage): void {
  router.push(`/damages/${d.id}`);
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
      <div class="sm:col-span-2">
        <label class="label">Zdroj *</label>
        <div class="mt-1">
          <ResourceSelect v-model="form.resourceId" />
        </div>
        <p v-if="!form.resourceId" class="mt-1 text-xs text-slate-400">
          Vyhľadaj loď naprieč všetkými kategóriami.
        </p>
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
          rows="8"
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
          rows="4"
          maxlength="1000"
        ></textarea>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="dphoto">
          Fotka <span class="text-xs font-normal text-slate-500">(nepovinné, max 5 MB)</span>
        </label>
        <input
          id="dphoto"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-800 hover:file:bg-brand-100"
          @change="onPhotoChange"
        />
        <div v-if="photoPreview" class="mt-2 flex items-center gap-3">
          <img :src="photoPreview" alt="" class="h-24 w-24 rounded-lg object-cover ring-1 ring-slate-200" />
          <button type="button" class="text-xs text-rose-700 hover:underline" @click="clearPhoto">
            Odstrániť výber
          </button>
        </div>
      </div>
      <div class="sm:col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreate = false">Zrušiť</button>
        <button type="submit" class="btn-primary" :disabled="!form.resourceId">Uložiť</button>
      </div>
    </form>
  </div>

  <LoadError :message="error" />
  <Spinner v-if="loading && !items.length" />
  <EmptyState v-else-if="items.length === 0" title="Žiadne nahlásené poškodenia" />

  <template v-else>
    <!-- Filter bar -->
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <div class="relative grow sm:grow-0">
        <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
        <input
          v-model="searchQuery"
          type="search"
          class="input pl-8 text-sm sm:w-72"
          placeholder="Hľadať zdroj, popis, stav…"
        />
      </div>
      <select v-model="statusFilter" class="input py-1.5 text-sm sm:w-44">
        <option value="">Všetky stavy</option>
        <option v-for="s in Object.values(DamageStatus)" :key="s" :value="s">
          {{ DAMAGE_STATUS_LABEL[s] }}
        </option>
      </select>
      <select v-model="severityFilter" class="input py-1.5 text-sm sm:w-44">
        <option value="">Všetky závažnosti</option>
        <option v-for="s in Object.values(DamageSeverity)" :key="s" :value="s">
          {{ DAMAGE_SEVERITY_LABEL[s] }}
        </option>
      </select>
      <span class="text-xs text-slate-500">{{ filtered.length }} / {{ items.length }}</span>
      <button
        v-if="searchQuery || statusFilter || severityFilter"
        type="button"
        class="text-xs text-slate-500 hover:text-slate-700 hover:underline"
        @click="clearFilters"
      >
        Zrušiť filtre
      </button>
    </div>

    <EmptyState
      v-if="filtered.length === 0"
      title="Nič nezodpovedá filtru"
      description="Skús zmeniť hľadanie alebo zrušiť filtre."
    />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="table-clean">
          <thead class="bg-slate-100/70">
            <tr>
              <th>Nahlásené</th>
              <th>Zdroj</th>
              <th>Foto</th>
              <th>Popis</th>
              <th>Závažnosť</th>
              <th>Stav</th>
              <th class="text-right">Akcie</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="d in paged"
              :key="d.id"
              class="cursor-pointer hover:bg-slate-50"
              @click="openDetail(d)"
            >
              <td class="text-slate-500">{{ formatDate(d.reportedAt) }}</td>
              <td class="font-medium">{{ resourceLabel(d) }}</td>
              <td>
                <img
                  v-if="d.photoUrl"
                  :src="d.photoUrl"
                  alt=""
                  class="h-12 w-12 rounded-md object-cover ring-1 ring-slate-200"
                />
                <span v-else class="text-xs text-slate-300">—</span>
              </td>
              <td class="max-w-md truncate text-slate-700">{{ d.description }}</td>
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
              <td class="text-right whitespace-nowrap">
                <RouterLink
                  :to="`/damages/${d.id}`"
                  class="text-brand-700 hover:underline"
                  @click.stop
                >
                  Detail →
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="pageCount > 1"
        class="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5 text-sm"
      >
        <span class="text-slate-500">Strana {{ page }} z {{ pageCount }}</span>
        <div class="flex gap-2">
          <button
            type="button"
            class="btn-secondary py-1"
            :disabled="page <= 1"
            @click="page--"
          >
            ← Predošlá
          </button>
          <button
            type="button"
            class="btn-secondary py-1"
            :disabled="page >= pageCount"
            @click="page++"
          >
            Ďalšia →
          </button>
        </div>
      </div>
    </div>
  </template>
</template>

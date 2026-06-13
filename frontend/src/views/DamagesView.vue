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

/** File picked in the "Pridať fotku" input; uploaded after the damage row is created. */
const photoFile = ref<File | null>(null);
const photoPreview = ref<string | null>(null);
/** Lightbox open for a particular damage's photo (full-size view). */
const lightboxUrl = ref<string | null>(null);

function onPhotoChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0] ?? null;
  // Revoke the previous preview to avoid leaking blob URLs on rapid re-pick.
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
    const created = await damagesApi.create({
      resourceId: form.resourceId,
      description: form.description,
      severity: form.severity,
      note: form.note || undefined,
    });
    // Optional photo upload — runs only if the user picked a file.
    // We swallow upload errors with a warning so the damage row itself
    // is still considered "reported"; the user can re-attach later.
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

async function deletePhoto(d: Damage): Promise<void> {
  if (!window.confirm('Naozaj odstrániť fotku?')) return;
  try {
    await damagesApi.removePhoto(d.id);
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
            <th>Foto</th>
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
            <td>
              <button
                v-if="d.photoUrl"
                type="button"
                :title="'Otvoriť fotku · ' + d.description.slice(0, 80)"
                class="block h-14 w-14 overflow-hidden rounded-md ring-1 ring-slate-200 hover:ring-brand-400"
                @click="lightboxUrl = d.photoUrl"
              >
                <img :src="d.photoUrl" alt="" class="h-full w-full object-cover" />
              </button>
              <span v-else class="text-xs text-slate-300">—</span>
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
              <button
                v-if="d.photoUrl"
                class="btn-secondary"
                type="button"
                @click="deletePhoto(d)"
              >
                Zmazať fotku
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Photo lightbox — click overlay or ✕ to close. -->
  <div
    v-if="lightboxUrl"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 p-4"
    role="dialog"
    aria-modal="true"
    @click.self="lightboxUrl = null"
  >
    <button
      type="button"
      class="absolute right-4 top-4 rounded-full bg-white/90 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-white"
      @click="lightboxUrl = null"
    >
      ✕ Zavrieť
    </button>
    <img :src="lightboxUrl" alt="" class="max-h-full max-w-full rounded-lg object-contain" />
  </div>
</template>

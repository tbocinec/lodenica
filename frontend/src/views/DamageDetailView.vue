<script setup lang="ts">
/**
 * Damage detail page — all actions live here (status changes, edit of
 * description / severity / status / note, photo upload/replace/remove and
 * delete). The list view only links here; it no longer carries action
 * buttons.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { damagesApi } from '@/api/damages.api';
import { DamageSeverity, DamageStatus, type Damage } from '@/api/types';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import {
  DAMAGE_SEVERITY_LABEL,
  DAMAGE_STATUS_LABEL,
  RESOURCE_TYPE_LABEL,
} from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import { formatDateTime } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const resources = useResourcesStore();

const id = route.params.id as string;
const damage = ref<Damage | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const saving = ref(false);
const busy = ref(false);

const form = reactive({
  description: '',
  severity: DamageSeverity.MINOR as DamageSeverity,
  status: DamageStatus.REPORTED as DamageStatus,
  note: '',
});

const photoInput = ref<HTMLInputElement | null>(null);
const lightbox = ref(false);

const resourceLabel = computed(() => {
  const d = damage.value;
  if (!d) return '';
  const r = resources.byId.get(d.resourceId);
  if (!r) return '—';
  return `${RESOURCE_TYPE_LABEL[r.type]} · ${r.identifier} · ${r.name}`;
});

function fill(d: Damage): void {
  damage.value = d;
  form.description = d.description;
  form.severity = d.severity;
  form.status = d.status;
  form.note = d.note ?? '';
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const [d] = await Promise.all([damagesApi.get(id), resources.fetch()]);
    fill(d);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  error.value = null;
  saving.value = true;
  try {
    const updated = await damagesApi.update(id, {
      description: form.description,
      severity: form.severity,
      status: form.status,
      note: form.note || undefined,
    });
    fill(updated);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    saving.value = false;
  }
}

async function setStatus(status: DamageStatus): Promise<void> {
  error.value = null;
  busy.value = true;
  try {
    fill(await damagesApi.update(id, { status }));
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}

async function onPhotoSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  error.value = null;
  busy.value = true;
  try {
    fill(await damagesApi.uploadPhoto(id, file));
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
    if (photoInput.value) photoInput.value.value = '';
  }
}

async function removePhoto(): Promise<void> {
  if (!window.confirm('Naozaj odstrániť fotku?')) return;
  error.value = null;
  busy.value = true;
  try {
    await damagesApi.removePhoto(id);
    if (damage.value) damage.value = { ...damage.value, photoUrl: null };
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}

async function remove(): Promise<void> {
  if (!window.confirm('Naozaj vymazať toto poškodenie? Akcia sa nedá vrátiť.')) return;
  error.value = null;
  busy.value = true;
  try {
    await damagesApi.remove(id);
    await router.replace('/damages');
  } catch (e) {
    error.value = (e as Error).message;
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader title="Detail poškodenia" :subtitle="resourceLabel">
    <template #actions>
      <RouterLink to="/damages" class="btn-secondary">← Späť na zoznam</RouterLink>
    </template>
  </PageHeader>

  <Spinner v-if="loading" />
  <LoadError v-else-if="!damage" :message="error ?? 'Poškodenie sa nenašlo.'" />

  <template v-else>
    <LoadError class="mb-4" :message="error" />

    <div class="grid gap-4 lg:grid-cols-3">
      <!-- Photo + meta -->
      <div class="space-y-4 lg:col-span-1">
        <div class="card-padded">
          <button
            v-if="damage.photoUrl"
            type="button"
            class="block w-full overflow-hidden rounded-lg ring-1 ring-slate-200 hover:ring-brand-400"
            @click="lightbox = true"
          >
            <img :src="damage.photoUrl" alt="" class="max-h-72 w-full object-cover" />
          </button>
          <div
            v-else
            class="flex h-40 items-center justify-center rounded-lg border-2 border-dashed border-slate-200 text-sm text-slate-400"
          >
            Bez fotky
          </div>

          <input
            ref="photoInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="onPhotoSelected"
          />
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn-secondary text-xs" :disabled="busy" @click="photoInput?.click()">
              {{ damage.photoUrl ? '📷 Zmeniť fotku' : '📷 Nahrať fotku' }}
            </button>
            <button
              v-if="damage.photoUrl"
              type="button"
              class="text-xs text-rose-700 hover:underline disabled:text-slate-300"
              :disabled="busy"
              @click="removePhoto"
            >
              Odstrániť fotku
            </button>
          </div>
        </div>

        <dl class="card-padded grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
          <dt class="text-slate-500">Nahlásené</dt>
          <dd class="text-slate-800">{{ formatDateTime(damage.reportedAt) }}</dd>
          <dt class="text-slate-500">Opravené</dt>
          <dd class="text-slate-800">
            <template v-if="damage.fixedAt">{{ formatDateTime(damage.fixedAt) }}</template>
            <span v-else class="text-slate-400">—</span>
          </dd>
          <dt class="text-slate-500">Zdroj</dt>
          <dd class="text-slate-800">{{ resourceLabel }}</dd>
        </dl>
      </div>

      <!-- Edit form + actions -->
      <div class="space-y-4 lg:col-span-2">
        <!-- Quick status actions -->
        <div class="card-padded flex flex-wrap items-center gap-2">
          <span class="text-sm font-medium text-slate-600">Stav:</span>
          <button
            type="button"
            class="rounded-full px-3 py-1 text-sm font-medium ring-1"
            :class="damage.status === 'REPORTED' ? 'bg-amber-100 text-amber-800 ring-amber-200' : 'text-slate-600 ring-slate-200 hover:bg-slate-50'"
            :disabled="busy || damage.status === 'REPORTED'"
            @click="setStatus(DamageStatus.REPORTED)"
          >
            {{ DAMAGE_STATUS_LABEL.REPORTED }}
          </button>
          <button
            type="button"
            class="rounded-full px-3 py-1 text-sm font-medium ring-1"
            :class="damage.status === 'IN_REPAIR' ? 'bg-sky-100 text-sky-800 ring-sky-200' : 'text-slate-600 ring-slate-200 hover:bg-slate-50'"
            :disabled="busy || damage.status === 'IN_REPAIR'"
            @click="setStatus(DamageStatus.IN_REPAIR)"
          >
            {{ DAMAGE_STATUS_LABEL.IN_REPAIR }}
          </button>
          <button
            type="button"
            class="rounded-full px-3 py-1 text-sm font-medium ring-1"
            :class="damage.status === 'FIXED' ? 'bg-emerald-100 text-emerald-800 ring-emerald-200' : 'text-slate-600 ring-slate-200 hover:bg-slate-50'"
            :disabled="busy || damage.status === 'FIXED'"
            @click="setStatus(DamageStatus.FIXED)"
          >
            {{ DAMAGE_STATUS_LABEL.FIXED }}
          </button>
        </div>

        <form class="card-padded grid gap-3" @submit.prevent="save">
          <div>
            <label class="label" for="dd-desc">Popis *</label>
            <textarea id="dd-desc" v-model="form.description" class="input mt-1" rows="3" required maxlength="1000"></textarea>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="label" for="dd-sev">Závažnosť *</label>
              <select id="dd-sev" v-model="form.severity" class="input mt-1" required>
                <option v-for="s in Object.values(DamageSeverity)" :key="s" :value="s">
                  {{ DAMAGE_SEVERITY_LABEL[s] }}
                </option>
              </select>
            </div>
            <div>
              <label class="label" for="dd-status">Stav *</label>
              <select id="dd-status" v-model="form.status" class="input mt-1" required>
                <option v-for="s in Object.values(DamageStatus)" :key="s" :value="s">
                  {{ DAMAGE_STATUS_LABEL[s] }}
                </option>
              </select>
            </div>
          </div>
          <div>
            <label class="label" for="dd-note">Poznámka</label>
            <textarea id="dd-note" v-model="form.note" class="input mt-1" rows="2" maxlength="1000"></textarea>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-2">
            <button type="button" class="btn-danger" :disabled="busy || saving" @click="remove">
              🗑 Vymazať
            </button>
            <button type="submit" class="btn-primary" :disabled="saving || busy">
              {{ saving ? 'Ukladám…' : 'Uložiť zmeny' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Lightbox -->
    <div
      v-if="lightbox && damage.photoUrl"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 p-4"
      role="dialog"
      aria-modal="true"
      @click.self="lightbox = false"
    >
      <button
        type="button"
        class="absolute right-4 top-4 rounded-full bg-white/90 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-white"
        @click="lightbox = false"
      >
        ✕ Zavrieť
      </button>
      <img :src="damage.photoUrl" alt="" class="max-h-full max-w-full rounded-lg object-contain" />
    </div>
  </template>
</template>

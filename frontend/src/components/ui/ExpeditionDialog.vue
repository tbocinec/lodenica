<script setup lang="ts">
/**
 * Create / edit an expedition. Self-contained: a small Leaflet picker map
 * (click to drop the pin → sets latitude/longitude), the metadata form, and
 * — once the entry exists — a photo gallery uploader.
 *
 * Opens when `open` is true. With `expedition` set it edits; otherwise it
 * creates, then stays open in edit mode so photos can be attached right away.
 */
import { nextTick, reactive, ref, watch } from 'vue';

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import { expeditionsApi, type Expedition, type WaterType } from '@/api/expeditions.api';
import { WATER_TYPES, WATER_TYPE_LABEL, waterColor } from '@/utils/expeditions';

import LoadError from './LoadError.vue';
import Spinner from './Spinner.vue';

const props = defineProps<{ open: boolean; expedition: Expedition | null }>();
const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', value: Expedition): void;
}>();

const form = reactive({
  title: '',
  place: '',
  latitude: null as number | null,
  longitude: null as number | null,
  year: null as number | null,
  waterType: '' as WaterType | '',
  country: '',
  participants: '',
  distanceKm: null as number | null,
  detail: '',
  publishConsent: false,
});

const working = ref<Expedition | null>(null); // the saved entity (drives the photo gallery)
const error = ref<string | null>(null);
const submitting = ref(false);
const photoUploading = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

const pickerEl = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let marker: L.Marker | null = null;

function pinIcon(color: string): L.DivIcon {
  return L.divIcon({
    className: 'exp-pin',
    html: `<span style="display:block;width:18px;height:18px;border-radius:9999px;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></span>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  });
}

function setPoint(lat: number, lng: number): void {
  form.latitude = Math.round(lat * 1e6) / 1e6;
  form.longitude = Math.round(lng * 1e6) / 1e6;
  if (!map) return;
  const icon = pinIcon(waterColor(form.waterType || null));
  if (marker) marker.setLatLng([lat, lng]).setIcon(icon);
  else marker = L.marker([lat, lng], { icon }).addTo(map);
}

function initMap(): void {
  if (!pickerEl.value || map) return;
  const hasPoint = form.latitude !== null && form.longitude !== null;
  map = L.map(pickerEl.value, { worldCopyJump: true }).setView(
    hasPoint ? [form.latitude as number, form.longitude as number] : [30, 10],
    hasPoint ? 6 : 2,
  );
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19,
  }).addTo(map);
  if (hasPoint) setPoint(form.latitude as number, form.longitude as number);
  map.on('click', (e: L.LeafletMouseEvent) => setPoint(e.latlng.lat, e.latlng.lng));
  // The dialog animates/sizes after mount — Leaflet needs a nudge.
  setTimeout(() => map?.invalidateSize(), 60);
}

function destroyMap(): void {
  if (map) {
    map.remove();
    map = null;
    marker = null;
  }
}

watch(
  () => props.open,
  async (open) => {
    if (!open) {
      destroyMap();
      return;
    }
    error.value = null;
    submitting.value = false;
    const e = props.expedition;
    working.value = e ? { ...e } : null;
    Object.assign(form, {
      title: e?.title ?? '',
      place: e?.place ?? '',
      latitude: e?.latitude ?? null,
      longitude: e?.longitude ?? null,
      year: e?.year ?? null,
      waterType: e?.waterType ?? '',
      country: e?.country ?? '',
      participants: e?.participants ?? '',
      distanceKm: e?.distanceKm ?? null,
      detail: e?.detail ?? '',
      publishConsent: !!e, // existing entries are already published
    });
    await nextTick();
    initMap();
  },
  { immediate: true },
);

async function submit(): Promise<void> {
  error.value = null;
  if (!form.title.trim() || !form.place.trim()) {
    error.value = 'Vyplňte názov a miesto.';
    return;
  }
  if (form.latitude === null || form.longitude === null) {
    error.value = 'Vyberte miesto na mape kliknutím.';
    return;
  }
  if (!working.value && !form.publishConsent) {
    error.value = 'Pre uloženie musíte súhlasiť so zverejnením záznamu členom klubu.';
    return;
  }
  submitting.value = true;
  try {
    const payload = {
      title: form.title.trim(),
      place: form.place.trim(),
      latitude: form.latitude,
      longitude: form.longitude,
      year: form.year ?? null,
      waterType: (form.waterType || null) as WaterType | null,
      country: form.country.trim() || null,
      participants: form.participants.trim() || null,
      distanceKm: form.distanceKm ?? null,
      detail: form.detail.trim() || null,
    };
    const saved = working.value
      ? await expeditionsApi.update(working.value.id, payload)
      : await expeditionsApi.create({ ...payload, publishConsent: true });
    working.value = saved;
    emit('saved', saved);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

async function onPhoto(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file || !working.value) return;
  photoUploading.value = true;
  error.value = null;
  try {
    const updated = await expeditionsApi.uploadPhoto(working.value.id, file);
    working.value = updated;
    emit('saved', updated);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    photoUploading.value = false;
    if (photoInput.value) photoInput.value.value = '';
  }
}

async function removePhoto(photoId: string): Promise<void> {
  if (!working.value || !window.confirm('Odstrániť fotku?')) return;
  try {
    await expeditionsApi.removePhoto(working.value.id, photoId);
    working.value = {
      ...working.value,
      photos: working.value.photos.filter((p) => p.id !== photoId),
    };
    emit('saved', working.value);
  } catch (e) {
    error.value = (e as Error).message;
  }
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-40 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="emit('close')"
  >
    <div class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
      <header class="mb-4 flex items-start justify-between gap-3">
        <h3 class="text-lg font-semibold text-slate-900">
          {{ working ? 'Upraviť expedíciu' : 'Pridať expedíciu' }}
        </h3>
        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" aria-label="Zavrieť" @click="emit('close')">✕</button>
      </header>

      <div class="grid gap-4 md:grid-cols-2">
        <!-- Form -->
        <form class="grid gap-3" @submit.prevent="submit">
          <div>
            <label class="label" for="exp-title">Názov expedície *</label>
            <input id="exp-title" v-model="form.title" class="input mt-1" required maxlength="200" placeholder="napr. Dunajský maratón" />
          </div>
          <div>
            <label class="label" for="exp-place">Miesto *</label>
            <input id="exp-place" v-model="form.place" class="input mt-1" required maxlength="200" placeholder="napr. Vltava, Česko" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="exp-year">Rok</label>
              <input id="exp-year" v-model.number="form.year" type="number" class="input mt-1" min="1900" :max="new Date().getFullYear() + 1" placeholder="2024" />
            </div>
            <div>
              <label class="label" for="exp-water">Typ vody</label>
              <select id="exp-water" v-model="form.waterType" class="input mt-1">
                <option value="">—</option>
                <option v-for="t in WATER_TYPES" :key="t" :value="t">{{ WATER_TYPE_LABEL[t] }}</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="exp-country">Krajina</label>
              <input id="exp-country" v-model="form.country" class="input mt-1" maxlength="120" placeholder="Slovensko" />
            </div>
            <div>
              <label class="label" for="exp-dist">Vzdialenosť (km)</label>
              <input id="exp-dist" v-model.number="form.distanceKm" type="number" class="input mt-1" min="0" step="0.1" />
            </div>
          </div>
          <div>
            <label class="label" for="exp-part">Členovia výpravy <span class="text-xs font-normal text-slate-400">(dobrovoľné)</span></label>
            <input id="exp-part" v-model="form.participants" class="input mt-1" maxlength="500" placeholder="Kto bol na vode" />
            <p class="mt-1 text-xs text-slate-400">Mená uvádzaj dobrovoľne a len so súhlasom dotknutých osôb.</p>
          </div>
          <div>
            <label class="label" for="exp-detail">Detail / príbeh</label>
            <textarea id="exp-detail" v-model="form.detail" class="input mt-1" rows="3" maxlength="5000"></textarea>
          </div>
        </form>

        <!-- Location picker -->
        <div>
          <span class="label">Miesto na mape *</span>
          <div ref="pickerEl" class="mt-1 h-64 w-full overflow-hidden rounded-lg ring-1 ring-slate-200"></div>
          <p class="mt-1 text-xs text-slate-500">
            Klikni na mapu a nastav značku.
            <span v-if="form.latitude !== null" class="font-medium text-slate-700">
              {{ form.latitude?.toFixed(4) }}, {{ form.longitude?.toFixed(4) }}
            </span>
            <span v-else class="text-rose-600">zatiaľ nevybrané</span>
          </p>

          <!-- Photo gallery — only once the entry exists -->
          <div v-if="working" class="mt-4">
            <span class="label">Fotky <span class="text-xs font-normal text-slate-400">(max 12)</span></span>
            <div class="mt-1 flex flex-wrap gap-2">
              <div v-for="p in working.photos" :key="p.id" class="relative h-16 w-16">
                <img :src="p.url" alt="" class="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200" />
                <button
                  type="button"
                  class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-xs text-white shadow"
                  title="Odstrániť"
                  @click="removePhoto(p.id)"
                >✕</button>
              </div>
              <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPhoto" />
              <button
                type="button"
                class="flex h-16 w-16 items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 hover:border-brand-400 hover:text-brand-500 disabled:opacity-50"
                :disabled="photoUploading || working.photos.length >= 12"
                @click="photoInput?.click()"
              >
                <Spinner v-if="photoUploading" />
                <span v-else class="text-2xl leading-none">＋</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Publish consent — mandatory on create -->
      <label v-if="!working" class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-700 ring-1 ring-slate-200">
        <input v-model="form.publishConsent" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded" />
        <span>
          Súhlasím so zverejnením tohto záznamu v klube a beriem na vedomie, že
          údaje (vrátane mien členov výpravy) <strong>uvidia všetci členovia klubu</strong>.
          Údaje o ďalších osobách uvádzam dobrovoľne a s ich súhlasom.
          <span class="text-rose-600">*</span>
        </span>
      </label>

      <LoadError class="mt-4" :message="error" />

      <div class="mt-5 flex items-center justify-between gap-2">
        <p v-if="working" class="text-xs text-emerald-700">Uložené ✓ — môžeš pridať fotky alebo zavrieť.</p>
        <span v-else></span>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" @click="emit('close')">
            {{ working ? 'Hotovo' : 'Zrušiť' }}
          </button>
          <button type="button" class="btn-primary" :disabled="submitting || (!working && !form.publishConsent)" @click="submit">
            <Spinner v-if="submitting" class="mr-2" />
            {{ working ? 'Uložiť zmeny' : 'Uložiť' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

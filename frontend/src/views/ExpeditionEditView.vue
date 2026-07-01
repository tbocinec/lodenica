<script setup lang="ts">
/**
 * Full-page expedition editor (its own route: /expeditions/new and
 * /expeditions/:id/edit). Form + a big map for placing the pin and drawing
 * the route (trace / GPX import / snap-to-river), plus a photo gallery once
 * the entry exists. The map shows only the pin/route being edited — no other
 * expeditions.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import { expeditionsApi, type Expedition, type WaterType } from '@/api/expeditions.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { countryNames } from '@/utils/countries';
import { WATER_TYPES, WATER_TYPE_LABEL, waterColor } from '@/utils/expeditions';
import { parseGpx } from '@/utils/gpx';
import { snapRiverPath } from '@/utils/riverRoute';

const countries = countryNames();
const countryInput = ref('');

function addCountry(): void {
  const c = countryInput.value.trim();
  if (c && !form.countries.includes(c)) form.countries.push(c);
  countryInput.value = '';
}

function removeCountry(c: string): void {
  form.countries = form.countries.filter((x) => x !== c);
}

const route = useRoute();
const router = useRouter();
const editId = route.params.id as string | undefined;

const form = reactive({
  title: '',
  place: '',
  latitude: null as number | null,
  longitude: null as number | null,
  year: null as number | null,
  waterType: '' as WaterType | '',
  countries: [] as string[],
  participants: '',
  distanceKm: null as number | null,
  detail: '',
  publishConsent: false,
});

// Route drawing model: an ordered list of segments. A "portage" (prenáška)
// segment is carried overland — drawn straight (dashed) and NEVER snapped to
// the river; river segments are snapped independently, each between its own
// first + last point. The stored/displayed route is the flat concatenation.
// `points` is the drawn/stored path; `waypoints` (river segments) are the
// user's clicks, kept so snap can run per consecutive pair and leave only the
// failing pair straight.
type RouteSegment = {
  portage: boolean;
  points: [number, number][];
  waypoints?: [number, number][];
  snapped?: boolean;
};
const segments = ref<RouteSegment[]>([]);
const drawKind = ref<'river' | 'portage'>('river');
// Junction points where snapping failed (drawn as red warnings on the map).
const problemPoints = ref<[number, number][]>([]);

const isRiver = computed(() => form.waterType === 'river');
const totalPoints = computed(() => segments.value.reduce((n, s) => n + s.points.length, 0));
function flatRoute(): [number, number][] {
  return segments.value.flatMap((s) => s.points);
}

const placeLabel = computed(() => {
  switch (form.waterType) {
    case 'river':
      return 'Názov rieky *';
    case 'lake':
      return 'Názov jazera *';
    case 'sea':
      return 'Názov mora *';
    default:
      return 'Miesto *';
  }
});

const working = ref<Expedition | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const submitting = ref(false);
const photoUploading = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

const pickerEl = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let marker: L.Marker | null = null;
let routeLayers: L.Polyline[] = [];
let problemLayer: L.LayerGroup | null = null;

// Geocoding (OpenStreetMap Nominatim).
interface GeoResult {
  display_name: string;
  lat: string;
  lon: string;
}
const geoQuery = ref('');
const geoResults = ref<GeoResult[]>([]);
const geoLoading = ref(false);
const geoError = ref<string | null>(null);

// Route drawing.
const traceMode = ref(false);
const routeError = ref<string | null>(null);
const snapping = ref(false);
const snapDone = ref(0);
const snapTotal = ref(0);
const snapPct = computed(() => {
  if (!snapTotal.value) return 0;
  const inFlight = snapping.value && snapDone.value < snapTotal.value ? 0.5 : 0;
  return Math.min(100, Math.round(((snapDone.value + inFlight) / snapTotal.value) * 100));
});
const gpxInput = ref<HTMLInputElement | null>(null);

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

function drawRoute(): void {
  if (!map) return;
  routeLayers.forEach((l) => l.remove());
  routeLayers = [];
  for (const s of segments.value) {
    if (s.points.length < 2) continue;
    const layer = L.polyline(
      s.points as L.LatLngExpression[],
      s.portage
        ? { color: '#92400e', weight: 3, opacity: 0.85, dashArray: '6 7' } // portage: brown, dashed
        : { color: waterColor(form.waterType || null), weight: 4, opacity: 0.85 },
    ).addTo(map);
    routeLayers.push(layer);
  }
}

function drawProblems(): void {
  if (!map) return;
  if (!problemLayer) problemLayer = L.layerGroup().addTo(map);
  else problemLayer.clearLayers();
  for (const p of problemPoints.value) {
    L.circleMarker(p as L.LatLngExpression, {
      radius: 7,
      color: '#dc2626',
      weight: 2,
      fillColor: '#fecaca',
      fillOpacity: 0.9,
    })
      .bindTooltip('Tu sa nepodarilo prichytiť na rieku — úsek ostal priamy', { direction: 'top' })
      .addTo(problemLayer);
  }
}

function appendRoutePoint(lat: number, lng: number): void {
  const wantPortage = drawKind.value === 'portage';
  const pt: [number, number] = [Math.round(lat * 1e6) / 1e6, Math.round(lng * 1e6) / 1e6];
  let last = segments.value[segments.value.length - 1];
  // Continue the last segment only if it matches the current mode and — for a
  // river segment — is still open (not snapped, and has a waypoint list).
  const canContinue =
    !!last &&
    last.portage === wantPortage &&
    (wantPortage || (!last.snapped && !!last.waypoints));
  if (!canContinue) {
    last = wantPortage
      ? { portage: true, points: [] }
      : { portage: false, points: [], waypoints: [], snapped: false };
    segments.value.push(last);
  }
  last.points.push(pt);
  if (last.waypoints) last.waypoints.push(pt);
  if (totalPoints.value === 1) setPoint(lat, lng);
  drawRoute();
}

function undoRoutePoint(): void {
  const last = segments.value[segments.value.length - 1];
  if (!last) return;
  last.points.pop();
  if (last.waypoints) last.waypoints.pop();
  if (last.points.length === 0) segments.value.pop();
  problemPoints.value = [];
  drawProblems();
  drawRoute();
}

function clearRoute(): void {
  segments.value = [];
  routeError.value = null;
  problemPoints.value = [];
  drawProblems();
  drawRoute();
}

function fitRoute(): void {
  const all = flatRoute();
  if (map && all.length >= 2) {
    map.fitBounds(L.latLngBounds(all as L.LatLngExpression[]).pad(0.2));
  }
}

async function onGpx(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  routeError.value = null;
  try {
    const pts = parseGpx(await file.text());
    // GPX is already a precise track — store as-is, no waypoints (not snapped).
    segments.value = [{ portage: false, points: pts }];
    problemPoints.value = [];
    if (pts.length) setPoint(pts[0][0], pts[0][1]);
    drawProblems();
    drawRoute();
    fitRoute();
  } catch (e) {
    routeError.value = (e as Error).message;
  } finally {
    if (gpxInput.value) gpxInput.value.value = '';
  }
}

async function snapRiver(): Promise<void> {
  routeError.value = null;
  problemPoints.value = [];
  const riverSegs = segments.value.filter((s) => !s.portage && s.waypoints && s.waypoints.length >= 2);
  if (riverSegs.length === 0) {
    routeError.value = 'Vyznač aspoň 2 body riečnej časti (prenášky sa neprichytávajú).';
    return;
  }
  snapping.value = true;
  snapTotal.value = riverSegs.length;
  snapDone.value = 0;
  let failedPairs = 0;
  let fatalMsg: string | null = null;
  const problems: [number, number][] = [];
  try {
    // One Overpass request per river segment (not per pair) → stays within
    // rate limits. Portage segments are left as drawn.
    for (const s of segments.value) {
      if (s.portage || !s.waypoints || s.waypoints.length < 2) continue;
      try {
        const { path, failedPairs: f, problems: p } = await snapRiverPath(s.waypoints);
        s.points = path;
        s.snapped = true;
        failedPairs += f;
        problems.push(...p);
      } catch (e) {
        // Whole-segment failure (rate limit / too long) — keep it manual.
        fatalMsg = (e as Error).message;
      }
      snapDone.value++;
    }
    problemPoints.value = problems;
    const first = flatRoute()[0];
    if (first) setPoint(first[0], first[1]);
    drawRoute();
    drawProblems();
    fitRoute();
    if (fatalMsg) {
      routeError.value = fatalMsg;
    } else if (failedPairs > 0) {
      routeError.value = `${failedPairs} úsek(ov) sa nepodarilo prichytiť — vyznačené na mape (ostali priame). Ostatné časti sú prichytené na rieku.`;
    }
  } finally {
    snapping.value = false;
  }
}

function setDrawKind(kind: 'river' | 'portage'): void {
  drawKind.value = kind;
  traceMode.value = true;
}

async function searchPlace(): Promise<void> {
  const q = geoQuery.value.trim();
  if (q.length < 3) {
    geoError.value = 'Zadaj aspoň 3 znaky.';
    return;
  }
  geoLoading.value = true;
  geoError.value = null;
  geoResults.value = [];
  try {
    const res = await fetch(
      `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&accept-language=sk&q=${encodeURIComponent(q)}`,
    );
    if (!res.ok) throw new Error('Vyhľadávanie miesta zlyhalo.');
    geoResults.value = (await res.json()) as GeoResult[];
    if (geoResults.value.length === 0) geoError.value = 'Nič sa nenašlo.';
  } catch (e) {
    geoError.value = (e as Error).message;
  } finally {
    geoLoading.value = false;
  }
}

function pickResult(r: GeoResult): void {
  const lat = parseFloat(r.lat);
  const lng = parseFloat(r.lon);
  setPoint(lat, lng);
  map?.setView([lat, lng], 10);
  if (!form.place.trim()) form.place = r.display_name.split(',').slice(0, 2).join(',').trim();
  geoResults.value = [];
  geoQuery.value = r.display_name.split(',')[0] ?? geoQuery.value;
}

function initMap(): void {
  if (!pickerEl.value || map) return;
  const hasPoint = form.latitude !== null && form.longitude !== null;
  map = L.map(pickerEl.value, { worldCopyJump: true, fadeAnimation: false }).setView(
    hasPoint ? [form.latitude as number, form.longitude as number] : [30, 10],
    hasPoint ? 8 : 2,
  );
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19,
  }).addTo(map);
  if (hasPoint) setPoint(form.latitude as number, form.longitude as number);
  drawRoute();
  fitRoute();
  map.on('click', (e: L.LeafletMouseEvent) => {
    if (traceMode.value) appendRoutePoint(e.latlng.lat, e.latlng.lng);
    else setPoint(e.latlng.lat, e.latlng.lng);
  });
  map.whenReady(() => setTimeout(() => map?.invalidateSize(), 60));
}

async function loadForEdit(): Promise<void> {
  if (!editId) return;
  loading.value = true;
  error.value = null;
  try {
    const all = await expeditionsApi.list();
    const e = all.find((x) => x.id === editId);
    if (!e) {
      error.value = 'Expedícia sa nenašla.';
      return;
    }
    working.value = { ...e };
    Object.assign(form, {
      title: e.title,
      place: e.place,
      latitude: e.latitude,
      longitude: e.longitude,
      year: e.year,
      waterType: e.waterType ?? '',
      countries: e.countries ? [...e.countries] : [],
      participants: e.participants ?? '',
      distanceKm: e.distanceKm,
      detail: e.detail ?? '',
      publishConsent: true,
    });
    // Existing route loads as a single river segment (portage breaks aren't
    // persisted; re-draw if you need to re-snap with portages).
    segments.value = e.route && e.route.length
      ? [{ portage: false, points: e.route.map((p) => [p[0], p[1]] as [number, number]) }]
      : [];
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

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
      countries: form.countries.length ? form.countries : null,
      participants: form.participants.trim() || null,
      distanceKm: form.distanceKm ?? null,
      detail: form.detail.trim() || null,
      route: flatRoute().length >= 2 ? flatRoute() : null,
    };
    working.value = working.value
      ? await expeditionsApi.update(working.value.id, payload)
      : await expeditionsApi.create({ ...payload, publishConsent: true });
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
    working.value = await expeditionsApi.uploadPhoto(working.value.id, file);
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
  } catch (e) {
    error.value = (e as Error).message;
  }
}

function done(): void {
  router.push('/expeditions');
}

onMounted(async () => {
  await loadForEdit();
  await nextTick();
  initMap();
});

onBeforeUnmount(() => {
  if (map) {
    map.remove();
    map = null;
    marker = null;
    routeLayers = [];
    problemLayer = null;
  }
});
</script>

<template>
  <PageHeader :title="editId ? 'Upraviť expedíciu' : 'Nová expedícia'">
    <template #actions>
      <RouterLink to="/expeditions" class="btn-secondary">← Späť na zoznam</RouterLink>
    </template>
  </PageHeader>

  <p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
    💻 Kreslenie trasy a prácu s mapou odporúčame robiť na <strong>notebooku/počítači</strong> — na mobile sa mapa ovláda ťažšie.
  </p>

  <div v-if="loading" class="flex justify-center py-12"><Spinner /></div>

  <div v-else class="space-y-5">
    <!-- Form fields -->
    <form class="card-padded grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
      <div class="sm:col-span-2">
        <label class="label" for="exp-water">Typ vody</label>
        <select id="exp-water" v-model="form.waterType" class="input mt-1">
          <option value="">—</option>
          <option v-for="t in WATER_TYPES" :key="t" :value="t">{{ WATER_TYPE_LABEL[t] }}</option>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="exp-place">{{ placeLabel }}</label>
        <input id="exp-place" v-model="form.place" class="input mt-1" required maxlength="200" placeholder="napr. Vltava, Česko" />
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="exp-title">Názov expedície *</label>
        <input id="exp-title" v-model="form.title" class="input mt-1" required maxlength="200" placeholder="napr. Dunajský maratón" />
      </div>
      <div>
        <label class="label" for="exp-year">Rok</label>
        <input id="exp-year" v-model.number="form.year" type="number" class="input mt-1" min="1900" :max="new Date().getFullYear() + 1" placeholder="2024" />
      </div>
      <div>
        <label class="label" for="exp-dist">Vzdialenosť (km)</label>
        <input id="exp-dist" v-model.number="form.distanceKm" type="number" class="input mt-1" min="0" step="0.1" />
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="exp-country">Krajiny <span class="text-xs font-normal text-slate-400">(môžeš vybrať viac)</span></label>
        <div class="mt-1 flex gap-2">
          <input
            id="exp-country"
            v-model="countryInput"
            class="input"
            maxlength="120"
            list="country-list"
            placeholder="Vyber krajinu zo zoznamu a pridaj…"
            @keydown.enter.prevent="addCountry"
            @change="addCountry"
          />
          <button type="button" class="btn-secondary shrink-0" @click="addCountry">Pridať</button>
          <datalist id="country-list">
            <option v-for="c in countries" :key="c" :value="c" />
          </datalist>
        </div>
        <div v-if="form.countries.length" class="mt-2 flex flex-wrap gap-1.5">
          <span
            v-for="c in form.countries"
            :key="c"
            class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-800 ring-1 ring-brand-200"
          >
            {{ c }}
            <button type="button" class="text-brand-500 hover:text-brand-800" @click="removeCountry(c)">✕</button>
          </span>
        </div>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="exp-part">Členovia výpravy <span class="text-xs font-normal text-slate-400">(dobrovoľné)</span></label>
        <input id="exp-part" v-model="form.participants" class="input mt-1" maxlength="500" placeholder="Kto bol na vode" />
        <p class="mt-1 text-xs text-slate-400">Mená uvádzaj dobrovoľne a len so súhlasom dotknutých osôb.</p>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="exp-detail">Detail / príbeh</label>
        <textarea id="exp-detail" v-model="form.detail" class="input mt-1" rows="3" maxlength="5000"></textarea>
      </div>
    </form>

    <!-- Location + route (full-width, big map) -->
    <div class="card-padded">
      <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
        <span class="label">Miesto na mape *</span>
        <span v-if="form.latitude !== null" class="text-xs font-medium text-slate-600">
          📍 {{ form.latitude?.toFixed(4) }}, {{ form.longitude?.toFixed(4) }}
        </span>
        <span v-else class="text-xs text-rose-600">zatiaľ nevybrané — klikni do mapy</span>
      </div>

      <div class="relative mt-1">
        <div class="flex gap-2">
          <input
            v-model="geoQuery"
            type="search"
            class="input text-sm"
            placeholder="Hľadať miesto (mesto, rieka, jazero…)"
            @keydown.enter.prevent="searchPlace"
          />
          <button type="button" class="btn-secondary shrink-0" :disabled="geoLoading" @click="searchPlace">
            <Spinner v-if="geoLoading" />
            <span v-else>Hľadať</span>
          </button>
        </div>
        <ul
          v-if="geoResults.length"
          class="absolute z-[500] mt-1 max-h-48 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg"
        >
          <li
            v-for="(r, i) in geoResults"
            :key="i"
            class="cursor-pointer px-3 py-2 text-xs text-slate-700 hover:bg-brand-50"
            @click="pickResult(r)"
          >
            {{ r.display_name }}
          </li>
        </ul>
        <p v-if="geoError" class="mt-1 text-xs text-rose-600">{{ geoError }}</p>
      </div>

      <div ref="pickerEl" class="mt-2 h-[62vh] min-h-[380px] w-full overflow-hidden rounded-lg ring-1 ring-slate-200"></div>
      <p class="mt-1 text-xs text-slate-500">Vyhľadaj miesto vyššie, alebo klikni priamo do mapy a nastav značku.</p>

      <!-- Route (optional) -->
      <div class="mt-4 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-700">Trasa <span class="font-normal text-slate-400">(nepovinné)</span></span>
          <span v-if="totalPoints" class="text-xs text-slate-500">{{ totalPoints }} bodov</span>
        </div>

        <div class="mt-2 flex flex-wrap gap-1.5">
          <button type="button" class="btn-secondary text-xs" :class="traceMode ? 'ring-2 ring-brand-400' : ''" @click="traceMode = !traceMode">
            {{ traceMode ? '✓ Kreslím… klikaj do mapy' : '✏️ Kresliť trasu' }}
          </button>
          <button type="button" class="btn-secondary text-xs" :disabled="!totalPoints" @click="undoRoutePoint">↶ Späť</button>
          <button type="button" class="btn-secondary text-xs" :disabled="!totalPoints" @click="clearRoute">🗑 Vymazať</button>
          <button v-if="isRiver" type="button" class="btn-secondary text-xs" :disabled="snapping" @click="snapRiver">
            <Spinner v-if="snapping" class="mr-1" />🌊 Prichytiť na rieku
          </button>
          <input ref="gpxInput" type="file" accept=".gpx,application/gpx+xml,application/xml,text/xml" class="hidden" @change="onGpx" />
          <button type="button" class="btn-secondary text-xs" @click="gpxInput?.click()">⬆ GPX</button>
        </div>

        <!-- River vs portage drawing mode (portage only makes sense on rivers) -->
        <div v-if="isRiver && traceMode" class="mt-2 flex items-center gap-1.5 text-xs">
          <span class="text-slate-500">Kreslím:</span>
          <button
            type="button"
            class="rounded-full px-2.5 py-1 font-medium transition"
            :class="drawKind === 'river' ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'"
            @click="setDrawKind('river')"
          >🌊 Rieka</button>
          <button
            type="button"
            class="rounded-full px-2.5 py-1 font-medium transition"
            :class="drawKind === 'portage' ? 'bg-amber-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'"
            @click="setDrawKind('portage')"
          >🥾 Prenáška</button>
        </div>

        <div v-if="snapping" class="mt-2">
          <div class="flex justify-between text-xs text-slate-500">
            <span>Prichytávam na rieku…</span>
            <span>{{ snapDone }}/{{ snapTotal }}</span>
          </div>
          <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div
              class="h-full rounded-full bg-brand-500 transition-all duration-300"
              :class="{ 'animate-pulse': snapDone < snapTotal }"
              :style="{ width: snapPct + '%' }"
            ></div>
          </div>
        </div>

        <p v-if="routeError" class="mt-1 text-xs text-rose-600">{{ routeError }}</p>
        <p class="mt-1 text-xs text-slate-400">
          „Kresliť trasu" → klikaj po mape.
          <template v-if="isRiver">
            Pri <strong>rieke</strong> môžeš prepínať medzi <em>Rieka</em> a <em>Prenáška</em> (prenos po súši — kreslí sa priamo a
            <strong>neprichytáva</strong> na rieku). „Prichytiť na rieku" prichytí každú riečnu časť zvlášť (OSM).
          </template>
          GPX = presná trasa z hodiniek/appky.
        </p>
      </div>
    </div>

    <!-- Photos — once the entry exists -->
    <div v-if="working" class="card-padded">
      <span class="label">Fotky <span class="text-xs font-normal text-slate-400">(max 12)</span></span>
      <div class="mt-2 flex flex-wrap gap-2">
        <div v-for="p in working.photos" :key="p.id" class="relative h-20 w-20">
          <img :src="p.url" alt="" class="h-20 w-20 rounded-lg object-cover ring-1 ring-slate-200" />
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
          class="flex h-20 w-20 items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 hover:border-brand-400 hover:text-brand-500 disabled:opacity-50"
          :disabled="photoUploading || working.photos.length >= 12"
          @click="photoInput?.click()"
        >
          <Spinner v-if="photoUploading" />
          <span v-else class="text-2xl leading-none">＋</span>
        </button>
      </div>
    </div>

    <!-- Publish consent — mandatory on create -->
    <label v-if="!working" class="flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-700 ring-1 ring-slate-200">
      <input v-model="form.publishConsent" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded" />
      <span>
        Súhlasím so zverejnením tohto záznamu v klube a beriem na vedomie, že údaje
        (vrátane mien členov výpravy) <strong>uvidia všetci členovia klubu</strong>.
        Údaje o ďalších osobách uvádzam dobrovoľne a s ich súhlasom.
        <span class="text-rose-600">*</span>
      </span>
    </label>

    <LoadError :message="error" />

    <div class="flex items-center justify-between gap-2">
      <p v-if="working" class="text-xs text-emerald-700">Uložené ✓ — môžeš pridať fotky alebo sa vrátiť.</p>
      <span v-else></span>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary" @click="done">{{ working ? 'Hotovo' : 'Zrušiť' }}</button>
        <button type="button" class="btn-primary" :disabled="submitting || (!working && !form.publishConsent)" @click="submit">
          <Spinner v-if="submitting" class="mr-2" />
          {{ working ? 'Uložiť zmeny' : 'Uložiť' }}
        </button>
      </div>
    </div>
  </div>
</template>

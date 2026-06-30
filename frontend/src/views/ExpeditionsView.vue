<script setup lang="ts">
/**
 * "Expedície" — a world map of the places club members have paddled. Members
 * pin a spot (river / lake / sea), tell the story and attach photos. Below the
 * map a searchable, paginated table lists every expedition; rows expand to show
 * the detail + photo gallery, and (for the author/admin) edit + delete.
 *
 * Map: Leaflet + OpenStreetMap tiles (both open-source / open-data).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import { expeditionsApi, type Expedition } from '@/api/expeditions.api';
import EmptyState from '@/components/ui/EmptyState.vue';
import ExpeditionDialog from '@/components/ui/ExpeditionDialog.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { waterColor, waterLabel, WATER_TYPE_EMOJI } from '@/utils/expeditions';

const items = ref<Expedition[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

// Dialog state
const dialogOpen = ref(false);
const editing = ref<Expedition | null>(null);

// Lightbox
const lightbox = ref<string | null>(null);

// Table state
const search = ref('');
const sort = ref<'newest' | 'year' | 'title'>('year');
const page = ref(1);
const pageSize = 10;
const expandedId = ref<string | null>(null);

const mapEl = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let markerGroup: L.LayerGroup | null = null;
const markers = new Map<string, L.Marker>();

const stats = computed(() => {
  const countries = new Set(items.value.map((e) => (e.country ?? '').trim().toLowerCase()).filter(Boolean));
  return { places: items.value.length, countries: countries.size };
});

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  let list = items.value.filter((e) => {
    if (!q) return true;
    return `${e.title} ${e.place} ${e.country ?? ''} ${e.participants ?? ''} ${e.year ?? ''} ${waterLabel(e.waterType)}`
      .toLowerCase()
      .includes(q);
  });
  list = [...list].sort((a, b) => {
    if (sort.value === 'title') return a.title.localeCompare(b.title, 'sk');
    if (sort.value === 'newest') return b.createdAt.localeCompare(a.createdAt);
    return (b.year ?? 0) - (a.year ?? 0); // year desc
  });
  return list;
});

const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)));
const paged = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize));

watch([search, sort], () => {
  page.value = 1;
});

function esc(s: string | number | null | undefined): string {
  return String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c,
  );
}

function pinIcon(e: Expedition): L.DivIcon {
  const color = waterColor(e.waterType);
  return L.divIcon({
    className: 'exp-pin',
    html: `<span style="display:block;width:18px;height:18px;border-radius:9999px;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></span>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  });
}

function popupHtml(e: Expedition): string {
  const bits = [`<strong style="font-size:13px">${esc(e.title)}</strong>`];
  bits.push(`<div style="color:#475569">${esc(e.place)}${e.year ? ' · ' + e.year : ''}</div>`);
  if (e.participants) bits.push(`<div style="color:#64748b;font-size:11px">👥 ${esc(e.participants)}</div>`);
  if (e.photos.length) {
    bits.push(`<img src="${esc(e.photos[0].url)}" style="margin-top:6px;width:180px;height:110px;object-fit:cover;border-radius:8px" alt="">`);
  }
  return `<div style="min-width:170px">${bits.join('')}</div>`;
}

function renderMarkers(): void {
  if (!map || !markerGroup) return;
  markerGroup.clearLayers();
  markers.clear();
  for (const e of items.value) {
    if (e.route && e.route.length >= 2) {
      L.polyline(e.route as L.LatLngExpression[], {
        color: waterColor(e.waterType),
        weight: 4,
        opacity: 0.7,
      }).addTo(markerGroup);
    }
    const m = L.marker([e.latitude, e.longitude], { icon: pinIcon(e) }).bindPopup(popupHtml(e));
    m.addTo(markerGroup);
    markers.set(e.id, m);
  }
  // Frame all pins + routes on first render.
  if (items.value.length > 0) {
    const group = L.featureGroup(markerGroup.getLayers() as L.Layer[]);
    map.fitBounds(group.getBounds().pad(0.2), { maxZoom: 6 });
  }
}

function flyTo(e: Expedition): void {
  if (!map) return;
  if (e.route && e.route.length >= 2) {
    map.fitBounds(L.latLngBounds(e.route as L.LatLngExpression[]).pad(0.2));
  } else {
    map.flyTo([e.latitude, e.longitude], 7, { duration: 0.6 });
  }
  markers.get(e.id)?.openPopup();
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    items.value = await expeditionsApi.list();
    renderMarkers();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

function openCreate(): void {
  editing.value = null;
  dialogOpen.value = true;
}

function openEdit(e: Expedition): void {
  editing.value = e;
  dialogOpen.value = true;
}

async function onSaved(): Promise<void> {
  // Reload so the map + table reflect the new/edited entry (and photos).
  await load();
}

async function remove(e: Expedition): Promise<void> {
  if (!window.confirm(`Naozaj zmazať expedíciu „${e.title}"? Akcia sa nedá vrátiť.`)) return;
  try {
    await expeditionsApi.remove(e.id);
    if (expandedId.value === e.id) expandedId.value = null;
    await load();
  } catch (err) {
    error.value = (err as Error).message;
  }
}

onMounted(async () => {
  // Create the map AFTER a tick so the container is fully laid out (mirrors
  // the working picker map in the dialog). fadeAnimation:false avoids the
  // Leaflet quirk where tiles stay `visibility:hidden` if the fade/ready
  // cycle is interrupted — which left the map blank despite tiles loading.
  await nextTick();
  if (mapEl.value) {
    map = L.map(mapEl.value, { worldCopyJump: true, fadeAnimation: false }).setView([30, 10], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap',
      maxZoom: 19,
    }).addTo(map);
    markerGroup = L.layerGroup().addTo(map);
    setTimeout(() => map?.invalidateSize(), 120);
  }
  void load();
});

onBeforeUnmount(() => {
  if (map) {
    map.remove();
    map = null;
  }
});
</script>

<template>
  <PageHeader title="Expedície" subtitle="Kam až nás zaviedla voda.">
    <template #actions>
      <button type="button" class="btn-primary" @click="openCreate">＋ Pridať expedíciu</button>
    </template>
  </PageHeader>

  <!-- Intro / purpose -->
  <div class="mb-4 rounded-2xl bg-gradient-to-br from-brand-50 to-cyan-50 p-5 ring-1 ring-brand-100">
    <p class="text-sm leading-relaxed text-slate-700">
      🗺️ Mapa miest, kde už <strong>členovia klubu</strong> pádlovali — rieky, jazerá a moria po celom svete.
      Pridaj svoju expedíciu, priviaž k nej fotky a príbeh a ukáž ostatným, kam ťa voda zaviedla.
      Sme hrdí na to, kde všade sme už boli. 🚣
    </p>
    <div class="mt-3 flex flex-wrap gap-2 text-sm">
      <span class="rounded-full bg-white px-3 py-1 font-medium text-brand-800 ring-1 ring-brand-200">
        📍 {{ stats.places }} {{ stats.places === 1 ? 'miesto' : (stats.places >= 2 && stats.places <= 4 ? 'miesta' : 'miest') }}
      </span>
      <span v-if="stats.countries" class="rounded-full bg-white px-3 py-1 font-medium text-brand-800 ring-1 ring-brand-200">
        🌍 {{ stats.countries }} {{ stats.countries === 1 ? 'krajina' : (stats.countries >= 2 && stats.countries <= 4 ? 'krajiny' : 'krajín') }}
      </span>
    </div>
  </div>

  <LoadError class="mb-4" :message="error" />

  <!-- Map -->
  <div class="card overflow-hidden">
    <div ref="mapEl" class="h-[420px] w-full" :class="{ 'opacity-60': loading }"></div>
  </div>

  <!-- Smart table -->
  <div class="mt-6">
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <div class="relative grow sm:grow-0">
        <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
        <input v-model="search" type="search" class="input pl-8 text-sm sm:w-72" placeholder="Hľadať názov, miesto, krajinu, účastníka…" />
      </div>
      <select v-model="sort" class="input py-1.5 text-sm sm:w-44">
        <option value="year">Podľa roku</option>
        <option value="newest">Najnovšie pridané</option>
        <option value="title">Podľa názvu</option>
      </select>
      <span class="text-xs text-slate-500">{{ filtered.length }} záznamov</span>
    </div>

    <div v-if="loading && items.length === 0" class="flex justify-center py-12"><Spinner /></div>
    <EmptyState
      v-else-if="items.length === 0"
      title="Zatiaľ žiadne expedície"
      description="Buď prvý/á a pridaj miesto, kde si pádloval/a."
    />
    <EmptyState v-else-if="filtered.length === 0" title="Nič nezodpovedá hľadaniu" />

    <div v-else class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
          <tr>
            <th class="px-4 py-2.5">Expedícia</th>
            <th class="px-4 py-2.5">Miesto</th>
            <th class="px-4 py-2.5">Rok</th>
            <th class="hidden px-4 py-2.5 sm:table-cell">Typ</th>
            <th class="hidden px-4 py-2.5 md:table-cell">Pridal</th>
            <th class="px-4 py-2.5 text-right">Foto</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template v-for="e in paged" :key="e.id">
            <tr
              class="cursor-pointer hover:bg-brand-50/40"
              @click="expandedId = expandedId === e.id ? null : e.id"
            >
              <td class="px-4 py-2.5 font-medium text-slate-900">
                <span class="mr-1" aria-hidden="true">{{ e.waterType ? WATER_TYPE_EMOJI[e.waterType] : '📍' }}</span>
                {{ e.title }}
              </td>
              <td class="px-4 py-2.5 text-slate-700">
                {{ e.place }}<span v-if="e.country" class="text-slate-400"> · {{ e.country }}</span>
              </td>
              <td class="px-4 py-2.5 text-slate-600">{{ e.year ?? '—' }}</td>
              <td class="hidden px-4 py-2.5 sm:table-cell">
                <span
                  v-if="e.waterType"
                  class="rounded-full px-2 py-0.5 text-xs font-medium text-white"
                  :style="{ background: waterColor(e.waterType) }"
                >{{ waterLabel(e.waterType) }}</span>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="hidden px-4 py-2.5 text-slate-500 md:table-cell">{{ e.createdByName ?? '—' }}</td>
              <td class="px-4 py-2.5 text-right text-slate-500">
                <span v-if="e.photos.length">📷 {{ e.photos.length }}</span>
                <span v-else class="text-slate-300">—</span>
              </td>
            </tr>
            <!-- Expanded detail -->
            <tr v-if="expandedId === e.id" class="bg-slate-50/60">
              <td colspan="6" class="px-4 py-4">
                <div class="grid gap-4 md:grid-cols-[1fr_auto]">
                  <div class="space-y-2">
                    <p v-if="e.detail" class="whitespace-pre-wrap text-sm text-slate-700">{{ e.detail }}</p>
                    <p v-else class="text-sm text-slate-400">Bez popisu.</p>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                      <span v-if="e.participants">👥 {{ e.participants }}</span>
                      <span v-if="e.distanceKm != null">📏 {{ e.distanceKm }} km</span>
                      <span v-if="e.route && e.route.length >= 2">🛟 trasa: {{ e.route.length }} bodov</span>
                      <span>🧭 {{ e.latitude.toFixed(3) }}, {{ e.longitude.toFixed(3) }}</span>
                    </div>
                    <div v-if="e.photos.length" class="flex flex-wrap gap-2 pt-1">
                      <button
                        v-for="p in e.photos"
                        :key="p.id"
                        type="button"
                        class="h-20 w-20 overflow-hidden rounded-lg ring-1 ring-slate-200 hover:ring-brand-400"
                        @click="lightbox = p.url"
                      >
                        <img :src="p.url" alt="" class="h-full w-full object-cover" />
                      </button>
                    </div>
                  </div>
                  <div class="flex flex-row gap-2 md:flex-col">
                    <button type="button" class="btn-secondary text-xs" @click="flyTo(e)">📍 Na mape</button>
                    <button v-if="e.canEdit" type="button" class="btn-secondary text-xs" @click="openEdit(e)">✏️ Upraviť</button>
                    <button v-if="e.canEdit" type="button" class="btn-danger text-xs" @click="remove(e)">🗑 Zmazať</button>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="pageCount > 1" class="mt-3 flex items-center justify-center gap-2 text-sm">
      <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--">‹</button>
      <span class="text-slate-600">{{ page }} / {{ pageCount }}</span>
      <button type="button" class="btn-secondary" :disabled="page === pageCount" @click="page++">›</button>
    </div>
  </div>

  <ExpeditionDialog
    :open="dialogOpen"
    :expedition="editing"
    @close="dialogOpen = false"
    @saved="onSaved"
  />

  <!-- Lightbox -->
  <div
    v-if="lightbox"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 p-4"
    role="dialog"
    aria-modal="true"
    @click.self="lightbox = null"
  >
    <button type="button" class="absolute right-4 top-4 rounded-full bg-white/90 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-white" @click="lightbox = null">✕ Zavrieť</button>
    <img :src="lightbox" alt="" class="max-h-full max-w-full rounded-lg object-contain" />
  </div>
</template>

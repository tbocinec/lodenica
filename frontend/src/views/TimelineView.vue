<script setup lang="ts">
/**
 * Timeline view — Gantt-style scheduling grid.
 *
 *  ┌────────────┬───┬───┬───┬───┬───┬───┬───┐
 *  │ K50 Dag    │   │   │ ▓▓▓▓▓▓▓ │   │   │  ← reservations rendered as
 *  │ K51 Nifty  │   │   │   │ ████│   │   │     positioned blocks
 *  │ K52 Rainbow│   │ ▓ │   │   │   │   │   │
 *  └────────────┴───┴───┴───┴───┴───┴───┴───┘
 *      6   7   8   9  10  11  12  13  14  15   ← hours (daily) or days (weekly)
 *
 * Two interactions:
 *  1. Click an empty cell to open the reservation form pre-filled with a
 *     1-hour (day mode) or 1-day (week mode) slot for that resource.
 *  2. Drag across cells to select a multi-cell window — the form opens with
 *     the dragged range as the proposed start/end.
 *  3. Click a reservation block → flyout with details + cancel.
 */
import { parseISO, subDays } from 'date-fns';
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import { ResourceType, type Reservation, type Resource } from '@/api/types';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ReservationEditDialog from '@/components/ui/ReservationEditDialog.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { RESOURCE_TYPE_LABEL_PLURAL } from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import {
  addDaysUtc,
  dayLabel,
  formatTime,
  shortDate,
  todayUtc,
  toIsoDate,
} from '@/utils/format';

type Mode = 'day' | 'week';

const router = useRouter();
const resources = useResourcesStore();

const mode = ref<Mode>('day');
const cursor = ref<Date>(todayUtc());
const typeFilter = ref<ResourceType | ''>(ResourceType.SEA_KAYAK);
const search = ref('');
const onlyBooked = ref(false);
const reservations = ref<Reservation[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const selected = ref<Reservation | null>(null);

const DAY_HOUR_START = 6;
const DAY_HOUR_END = 22; // exclusive
const HOURS = computed(() => {
  const list: number[] = [];
  for (let h = DAY_HOUR_START; h < DAY_HOUR_END; h++) list.push(h);
  return list;
});
const DAYS_IN_WEEK = 7;
const cellCount = computed(() => (mode.value === 'day' ? HOURS.value.length : DAYS_IN_WEEK));

/**
 * `dataWindow` — what we query the API for (full day / full week).
 * `visualWindow` — what the cells row actually covers (06:00–22:00 on day,
 * full week on week). Reservations outside the visual window get clipped.
 */
const dataWindow = computed(() => {
  if (mode.value === 'day') {
    return { start: cursor.value, end: addDaysUtc(cursor.value, 1) };
  }
  const dayOfWeek = (cursor.value.getUTCDay() + 6) % 7; // Mon=0
  const start = addDaysUtc(cursor.value, -dayOfWeek);
  return { start, end: addDaysUtc(start, DAYS_IN_WEEK) };
});

const visualWindow = computed(() => {
  if (mode.value === 'day') {
    const start = new Date(cursor.value);
    start.setUTCHours(DAY_HOUR_START, 0, 0, 0);
    const end = new Date(cursor.value);
    end.setUTCHours(DAY_HOUR_END, 0, 0, 0);
    return { start, end };
  }
  return dataWindow.value;
});

const resourceRows = computed<Resource[]>(() => {
  const q = search.value.trim().toLowerCase();
  return resources.items
    .filter((r) => r.isActive && r.type !== ResourceType.BOATHOUSE_SPACE)
    .filter((r) => (typeFilter.value ? r.type === typeFilter.value : true))
    .filter((r) => {
      if (!q) return true;
      return (
        r.identifier.toLowerCase().includes(q) ||
        r.name.toLowerCase().includes(q) ||
        (r.model ?? '').toLowerCase().includes(q) ||
        (r.color ?? '').toLowerCase().includes(q)
      );
    })
    .filter((r) => (onlyBooked.value ? reservationsByResource.value.has(r.id) : true))
    .sort((a, b) => {
      if (a.type !== b.type) return a.type.localeCompare(b.type);
      return a.identifier.localeCompare(b.identifier);
    });
});

const reservationsByResource = computed(() => {
  const map = new Map<string, Reservation[]>();
  for (const r of reservations.value) {
    const list = map.get(r.resourceId) ?? [];
    list.push(r);
    map.set(r.resourceId, list);
  }
  return map;
});

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const { items } = await reservationsApi.list({
      from: dataWindow.value.start.toISOString(),
      to: dataWindow.value.end.toISOString(),
      pageSize: 500,
      status: 'CONFIRMED',
    });
    reservations.value = items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

function shift(direction: 1 | -1) {
  cursor.value = addDaysUtc(cursor.value, direction * (mode.value === 'day' ? 1 : DAYS_IN_WEEK));
}
function goToday() {
  cursor.value = todayUtc();
}

watch([mode, cursor, typeFilter], load, { immediate: false });
onMounted(async () => {
  await Promise.all([load(), resources.fetch()]);
});

// ────────────────────────────────────────────────────────────────────
// Block layout (existing reservations)
// ────────────────────────────────────────────────────────────────────

interface Block {
  reservation: Reservation;
  leftPct: number;
  widthPct: number;
}

function blocksFor(resourceId: string): Block[] {
  const list = reservationsByResource.value.get(resourceId) ?? [];
  const win = visualWindow.value;
  const winStart = win.start.getTime();
  const winEnd = win.end.getTime();
  const winSpan = winEnd - winStart;
  return list
    .map((r) => {
      const s = parseISO(r.startsAt).getTime();
      const e = parseISO(r.endsAt).getTime();
      const cs = Math.max(s, winStart);
      const ce = Math.min(e, winEnd);
      if (ce <= cs) return null;
      return {
        reservation: r,
        leftPct: ((cs - winStart) / winSpan) * 100,
        widthPct: ((ce - cs) / winSpan) * 100,
      };
    })
    .filter((b): b is Block => b !== null);
}

// ────────────────────────────────────────────────────────────────────
// Drag-to-select reservation window
// ────────────────────────────────────────────────────────────────────

interface DragState {
  resourceId: string;
  startIndex: number; // inclusive cell index, 0..cellCount-1
  currentIndex: number; // inclusive
  moved: boolean;
}

const drag = ref<DragState | null>(null);

function cellIndexFromEvent(area: HTMLElement, ev: PointerEvent): number {
  const rect = area.getBoundingClientRect();
  if (rect.width <= 0) return 0;
  const x = ev.clientX - rect.left;
  const idx = Math.floor((x / rect.width) * cellCount.value);
  return Math.max(0, Math.min(cellCount.value - 1, idx));
}

function onCellsPointerDown(resource: Resource, ev: PointerEvent): void {
  if (ev.button !== 0) return;
  const area = ev.currentTarget as HTMLElement;
  area.setPointerCapture(ev.pointerId);
  const idx = cellIndexFromEvent(area, ev);
  drag.value = {
    resourceId: resource.id,
    startIndex: idx,
    currentIndex: idx,
    moved: false,
  };
}

function onCellsPointerMove(ev: PointerEvent): void {
  if (!drag.value) return;
  const area = ev.currentTarget as HTMLElement;
  const idx = cellIndexFromEvent(area, ev);
  if (idx !== drag.value.currentIndex) {
    drag.value.currentIndex = idx;
    drag.value.moved = true;
  }
}

function onCellsPointerUp(resource: Resource, ev: PointerEvent): void {
  const area = ev.currentTarget as HTMLElement;
  try {
    area.releasePointerCapture(ev.pointerId);
  } catch {
    /* pointer capture may have been lost already */
  }
  if (!drag.value || drag.value.resourceId !== resource.id) {
    drag.value = null;
    return;
  }
  const lo = Math.min(drag.value.startIndex, drag.value.currentIndex);
  const hi = Math.max(drag.value.startIndex, drag.value.currentIndex);
  drag.value = null;
  openCreateForRange(resource, lo, hi);
}

function onCellsPointerCancel(): void {
  drag.value = null;
}

function dragOverlayFor(resourceId: string): { leftPct: number; widthPct: number; label: string } | null {
  if (!drag.value || drag.value.resourceId !== resourceId) return null;
  const lo = Math.min(drag.value.startIndex, drag.value.currentIndex);
  const hi = Math.max(drag.value.startIndex, drag.value.currentIndex);
  const total = cellCount.value;
  return {
    leftPct: (lo / total) * 100,
    widthPct: ((hi - lo + 1) / total) * 100,
    label: rangeLabelForCells(lo, hi),
  };
}

function rangeLabelForCells(lo: number, hi: number): string {
  if (mode.value === 'day') {
    const startHour = DAY_HOUR_START + lo;
    const endHour = DAY_HOUR_START + hi + 1;
    return `${pad2(startHour)}:00–${pad2(endHour)}:00`;
  }
  const startDay = addDaysUtc(visualWindow.value.start, lo);
  const endDay = addDaysUtc(visualWindow.value.start, hi);
  if (lo === hi) return shortDate(startDay);
  return `${shortDate(startDay)} – ${shortDate(endDay)}`;
}

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

function openCreateForRange(resource: Resource, lo: number, hi: number): void {
  if (mode.value === 'day') {
    const startHour = DAY_HOUR_START + lo;
    const endHour = DAY_HOUR_START + hi + 1; // hi inclusive → +1 for cell end
    const date = toIsoDate(cursor.value);
    void router.push({
      path: '/reservations/new',
      query: {
        resourceId: resource.id,
        startDate: date,
        startTime: `${pad2(startHour)}:00`,
        endDate: date,
        endTime: `${pad2(endHour)}:00`,
      },
    });
    return;
  }
  // Week mode → range from start day 00:00 to (end day + 1) 00:00.
  const startDay = addDaysUtc(visualWindow.value.start, lo);
  const endDayInclusive = addDaysUtc(visualWindow.value.start, hi);
  const endDayExclusive = addDaysUtc(endDayInclusive, 1);
  void router.push({
    path: '/reservations/new',
    query: {
      resourceId: resource.id,
      startDate: toIsoDate(startDay),
      startTime: '00:00',
      endDate: toIsoDate(endDayExclusive),
      endTime: '00:00',
    },
  });
}

// ────────────────────────────────────────────────────────────────────
// Block visuals + helpers
// ────────────────────────────────────────────────────────────────────

function rangeLabel(): string {
  if (mode.value === 'day') {
    return `${dayLabel(cursor.value)} ${shortDate(cursor.value)} ${cursor.value.getUTCFullYear()}`;
  }
  const start = dataWindow.value.start;
  const end = subDays(dataWindow.value.end, 1);
  return `${shortDate(start)} – ${shortDate(end)} ${end.getUTCFullYear()}`;
}

function blockLabel(r: Reservation): string {
  // If the booking covers an entire day or longer at midnight boundaries,
  // omit the redundant 00:00 markers.
  const start = parseISO(r.startsAt);
  const end = parseISO(r.endsAt);
  const startsAtMidnight =
    start.getUTCHours() === 0 && start.getUTCMinutes() === 0 && start.getUTCSeconds() === 0;
  const endsAtMidnight =
    end.getUTCHours() === 0 && end.getUTCMinutes() === 0 && end.getUTCSeconds() === 0;
  if (startsAtMidnight && endsAtMidnight) return r.customerName;
  return `${formatTime(r.startsAt)}–${formatTime(r.endsAt)} ${r.customerName}`;
}

function blockColor(r: Reservation): string {
  let hash = 0;
  for (const ch of r.customerName) hash = (hash * 31 + ch.charCodeAt(0)) | 0;
  const palette = [
    'bg-brand-200 text-brand-900 ring-brand-300',
    'bg-emerald-200 text-emerald-900 ring-emerald-300',
    'bg-amber-200 text-amber-900 ring-amber-300',
    'bg-rose-200 text-rose-900 ring-rose-300',
    'bg-violet-200 text-violet-900 ring-violet-300',
    'bg-cyan-200 text-cyan-900 ring-cyan-300',
    'bg-orange-200 text-orange-900 ring-orange-300',
  ];
  const idx = ((hash % palette.length) + palette.length) % palette.length;
  return palette[idx]!;
}

function selectedResourceName(): string | undefined {
  return selected.value
    ? resources.byId.get(selected.value.resourceId)?.name
    : undefined;
}

async function onReservationSaved(): Promise<void> {
  selected.value = null;
  await load();
}

async function onReservationDeleted(): Promise<void> {
  selected.value = null;
  await load();
}
</script>

<template>
  <PageHeader
    title="Časová os"
    subtitle="Zdroje v riadkoch, čas v stĺpcoch. Klikni na bunku alebo ťahaj cez viaceré bunky a vyznač okno rezervácie."
  >
    <template #actions>
      <div class="inline-flex rounded-lg ring-1 ring-slate-300">
        <button
          type="button"
          class="px-3 py-2 text-sm font-medium"
          :class="mode === 'day' ? 'bg-brand-600 text-white' : 'text-slate-700'"
          @click="mode = 'day'"
        >
          Deň
        </button>
        <button
          type="button"
          class="px-3 py-2 text-sm font-medium"
          :class="mode === 'week' ? 'bg-brand-600 text-white' : 'text-slate-700'"
          @click="mode = 'week'"
        >
          Týždeň
        </button>
      </div>
      <button class="btn-secondary" type="button" @click="shift(-1)">‹</button>
      <button class="btn-secondary" type="button" @click="goToday">Dnes</button>
      <button class="btn-secondary" type="button" @click="shift(1)">›</button>
    </template>
  </PageHeader>

  <div class="card-padded mb-3">
    <div class="grid gap-3 sm:grid-cols-12">
      <div class="sm:col-span-4">
        <label class="label" for="search">Hľadať loď</label>
        <div class="relative mt-1">
          <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400" aria-hidden="true">⌕</span>
          <input
            id="search"
            v-model="search"
            class="input pl-8"
            type="search"
            placeholder="K50, Pyranha, červená…"
            autocomplete="off"
          />
        </div>
      </div>
      <div class="sm:col-span-3">
        <label class="label" for="type-filter">Typ</label>
        <select id="type-filter" v-model="typeFilter" class="input mt-1">
          <option value="">Všetky (okrem lodeníc)</option>
          <option
            v-for="t in [ResourceType.SEA_KAYAK, ResourceType.WW_KAYAK, ResourceType.CANOE, ResourceType.ROWING_BOAT, ResourceType.INFLATABLE_BOAT, ResourceType.TRAILER]"
            :key="t"
            :value="t"
          >
            {{ RESOURCE_TYPE_LABEL_PLURAL[t] }}
          </option>
        </select>
      </div>
      <div class="sm:col-span-5 flex items-end justify-between gap-3">
        <label class="inline-flex items-center gap-2 pb-2 text-sm font-medium text-slate-700">
          <input v-model="onlyBooked" type="checkbox" class="h-4 w-4 rounded" />
          Iba s rezerváciami
        </label>
        <div class="flex flex-col items-end gap-0.5 pb-1 text-right">
          <p class="text-sm font-medium text-slate-700">{{ rangeLabel() }}</p>
          <p class="text-xs text-slate-500">
            <span class="pill-blue">{{ resourceRows.length }} zdrojov</span>
            <span class="ml-1 pill-green">{{ reservations.length }} rezervácií v okne</span>
          </p>
        </div>
      </div>
    </div>
  </div>

  <LoadError :message="error" />
  <Spinner v-if="loading && !resources.items.length" />

  <div v-else class="card overflow-hidden">
    <div
      class="max-h-[80vh] overflow-auto [--cell-min:2.5rem] [--resource-col:8rem] sm:[--cell-min:0px] sm:[--resource-col:12rem]"
    >
      <div :style="{ minWidth: `calc(var(--resource-col) + ${cellCount} * var(--cell-min))` }">
        <!-- Header row: hours / days -->
        <div
          class="sticky top-0 z-20 grid border-b border-slate-200 bg-slate-100/90 backdrop-blur"
          :style="{ gridTemplateColumns: 'var(--resource-col) 1fr' }"
        >
          <div class="sticky left-0 z-30 border-r border-slate-200 bg-slate-100/95 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 backdrop-blur">
            Zdroj
          </div>
          <div class="grid" :style="{ gridTemplateColumns: `repeat(${cellCount}, minmax(var(--cell-min), 1fr))` }">
            <template v-if="mode === 'day'">
              <div
                v-for="h in HOURS"
                :key="h"
                class="border-r border-slate-200 px-1 py-2 text-center text-[11px] font-semibold text-slate-600"
              >
                {{ pad2(h) }}:00
              </div>
            </template>
            <template v-else>
              <div
                v-for="i in DAYS_IN_WEEK"
                :key="i"
                class="border-r border-slate-200 px-1 py-2 text-center text-[11px] font-semibold text-slate-600"
              >
                {{ dayLabel(addDaysUtc(visualWindow.start, i - 1)) }}
                <span class="ml-1 text-slate-400">{{ addDaysUtc(visualWindow.start, i - 1).getUTCDate() }}.</span>
              </div>
            </template>
          </div>
        </div>

        <!-- Body rows -->
        <div v-if="!resourceRows.length" class="p-6 text-center text-sm text-slate-500">
          Žiadne aktívne zdroje pre tento filter.
        </div>

        <div
          v-for="r in resourceRows"
          :key="r.id"
          class="grid border-b border-slate-100"
          :style="{ gridTemplateColumns: 'var(--resource-col) 1fr' }"
        >
          <!-- Resource label cell (sticky on horizontal scroll) -->
          <div class="sticky left-0 z-10 flex flex-col gap-0.5 border-r border-slate-200 bg-white px-3 py-2">
            <div class="flex items-center gap-2">
              <ResourceTypeBadge :type="r.type" />
              <span class="font-mono text-[11px] text-slate-500">{{ r.identifier }}</span>
            </div>
            <span class="truncate text-sm font-medium text-slate-800" :title="r.name">{{ r.name }}</span>
          </div>

          <!-- Cells area: explicit block-formatting container with fixed height
               so that absolutely-positioned reservation blocks always render at
               the right size. Background cells form a CSS grid in the lower
               layer; reservation blocks and the drag overlay live on top. -->
          <div
            class="relative touch-pan-y select-none"
            :class="mode === 'day' ? 'h-14' : 'h-16'"
            @pointerdown="onCellsPointerDown(r, $event)"
            @pointermove="onCellsPointerMove($event)"
            @pointerup="onCellsPointerUp(r, $event)"
            @pointercancel="onCellsPointerCancel"
          >
            <!-- Layer 1: background grid (cells, hover hint) -->
            <div
              class="absolute inset-0 grid"
              :style="{ gridTemplateColumns: `repeat(${cellCount}, minmax(var(--cell-min), 1fr))` }"
            >
              <div
                v-for="i in cellCount"
                :key="i"
                class="border-r border-slate-100 hover:bg-brand-50/70"
              ></div>
            </div>

            <!-- Layer 2: reservation blocks -->
            <button
              v-for="b in blocksFor(r.id)"
              :key="b.reservation.id"
              type="button"
              class="absolute top-1 bottom-1 z-[5] truncate rounded px-1.5 text-[11px] font-medium shadow-sm ring-1 hover:brightness-95"
              :class="blockColor(b.reservation)"
              :style="{ left: b.leftPct + '%', width: 'max(' + b.widthPct + '%, 1.5rem)' }"
              :title="`${b.reservation.customerName} · ${formatTime(b.reservation.startsAt)} – ${formatTime(b.reservation.endsAt)}`"
              @pointerdown.stop
              @click.stop="selected = b.reservation"
            >
              {{ blockLabel(b.reservation) }}
            </button>

            <!-- Drag-select overlay -->
            <div
              v-if="dragOverlayFor(r.id)"
              class="pointer-events-none absolute top-0 bottom-0 z-[6] flex items-center justify-center rounded bg-brand-400/40 text-xs font-semibold text-brand-950 ring-2 ring-brand-600"
              :style="{ left: dragOverlayFor(r.id)!.leftPct + '%', width: dragOverlayFor(r.id)!.widthPct + '%' }"
            >
              {{ dragOverlayFor(r.id)!.label }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <p class="mt-3 text-xs text-slate-500">
    Tip: klik na bunku = 1 hodina (alebo 1 deň v týždennom zobrazení); ťahaním cez viaceré bunky vyznačíš dlhšie okno. Klik na existujúcu rezerváciu otvorí editáciu.
  </p>

  <ReservationEditDialog
    :reservation="selected"
    :resource-name="selectedResourceName()"
    @close="selected = null"
    @saved="onReservationSaved"
    @deleted="onReservationDeleted"
  />
</template>

<script setup lang="ts">
/**
 * Two side-by-side strips that visualize when the selected resource is busy:
 *
 *  • A 30-day calendar (rows of 7) with each day shaded by busy hours.
 *    Click a day → emit `pick-day(yyyy-MM-dd)`.
 *
 *  • A 06:00–22:00 hour strip for the currently picked date with reservation
 *    blocks rendered to scale. Click an empty hour → emit `pick-hour(hh:mm)`.
 *    The user's proposed [startTime, endTime) is overlaid in brand color so
 *    they see immediately whether it intersects an existing booking.
 *
 * The component fetches reservations itself (debounced) when `resourceId`
 * changes, so it can drop into any form.
 */
import { addDays, parseISO } from 'date-fns';
import { computed, onUnmounted, ref, watch } from 'vue';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import { dayLabel, formatTime, isoFromDateTime, todayUtc, toIsoDate } from '@/utils/format';

const props = defineProps<{
  resourceId: string;
  date: string; // yyyy-MM-dd, the day the user is editing
  startTime?: string; // HH:mm
  endTime?: string; // HH:mm
  /** Number of days to show in the calendar grid. */
  days?: number;
}>();

const emit = defineEmits<{
  (e: 'pick-day', date: string): void;
  (e: 'pick-hour', hour: string): void;
  /** Click-and-drag across hour cells emits a full range. `endHour`
   *  is EXCLUSIVE, in "HH:MM" form — matches reservation convention
   *  (endsAt is exclusive). A single-cell drag becomes a 1-hour range. */
  (e: 'pick-range', startHour: string, endHour: string): void;
}>();

const HOUR_START = 6;
const HOUR_END = 22;
const HOUR_SPAN = HOUR_END - HOUR_START;
const DAYS = computed(() => props.days ?? 28);

const reservations = ref<Reservation[]>([]);
const loading = ref(false);
let debounceHandle: ReturnType<typeof setTimeout> | null = null;

async function load() {
  if (!props.resourceId) {
    reservations.value = [];
    return;
  }
  loading.value = true;
  try {
    const from = todayUtc();
    const to = addDays(from, DAYS.value + 2);
    const { items } = await reservationsApi.list({
      resourceId: props.resourceId,
      status: 'CONFIRMED',
      from: from.toISOString(),
      to: to.toISOString(),
      pageSize: 200,
    });
    reservations.value = items;
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.resourceId,
  () => {
    if (debounceHandle) clearTimeout(debounceHandle);
    debounceHandle = setTimeout(load, 100);
  },
  { immediate: true },
);

onUnmounted(() => {
  if (debounceHandle) clearTimeout(debounceHandle);
});

// ────────────────────────────────────────────────────────────────────
// Day calendar
// ────────────────────────────────────────────────────────────────────

interface DayCell {
  iso: string;
  date: Date;
  busyHours: number; // sum of overlapping hours within visible 06–22 window
  isToday: boolean;
  isSelected: boolean;
  isPast: boolean;
}

// Slovak / European convention: weeks start on Monday. Grid is aligned
// to the Monday of the current week so the day-of-week column matches
// across all rows (otherwise a chronological 30-day strip looks ordered
// "from-Sunday" when today happens to be a Sunday). Days before today
// are still rendered but dimmed and non-clickable.
const dayCells = computed<DayCell[]>(() => {
  const today = todayUtc();
  const todayIso = toIsoDate(today);
  // Monday of the current week: Carbon-free version of startOfWeek.
  // JS getUTCDay returns 0 (Sun) … 6 (Sat); convert to Mon-based.
  const dowFromMonday = (today.getUTCDay() + 6) % 7;
  const weekStart = addDays(today, -dowFromMonday);

  // Always show whole weeks. Round the requested window up to the next
  // multiple of 7 days so the grid stays rectangular.
  const totalCells = Math.ceil((DAYS.value + dowFromMonday) / 7) * 7;

  const cells: DayCell[] = [];
  for (let i = 0; i < totalCells; i++) {
    const d = addDays(weekStart, i);
    const iso = toIsoDate(d);
    cells.push({
      iso,
      date: d,
      busyHours: busyHoursOnDay(d),
      isToday: iso === todayIso,
      isSelected: iso === props.date,
      isPast: d.getTime() < today.getTime(),
    });
  }
  return cells;
});

// Weekday header labels (Mon … Sun) used above the day-cell grid.
const WEEKDAY_HEADERS = ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'] as const;

function busyHoursOnDay(day: Date): number {
  const dayStart = day.getTime();
  const dayEnd = dayStart + 24 * 60 * 60 * 1000;
  let busyMs = 0;
  for (const r of reservations.value) {
    const s = parseISO(r.startsAt).getTime();
    const e = parseISO(r.endsAt).getTime();
    const cs = Math.max(s, dayStart);
    const ce = Math.min(e, dayEnd);
    if (ce > cs) busyMs += ce - cs;
  }
  return busyMs / (1000 * 60 * 60);
}

function dayShade(busyHours: number): string {
  if (busyHours <= 0) return 'bg-emerald-50 ring-emerald-200 hover:bg-emerald-100';
  if (busyHours >= 16) return 'bg-rose-200 ring-rose-300 text-rose-900 hover:bg-rose-300';
  if (busyHours >= 8) return 'bg-amber-200 ring-amber-300 text-amber-900 hover:bg-amber-300';
  return 'bg-amber-100 ring-amber-200 text-amber-800 hover:bg-amber-200';
}

// ────────────────────────────────────────────────────────────────────
// Hour strip for the selected day
// ────────────────────────────────────────────────────────────────────

interface HourBlock {
  reservation: Reservation;
  leftPct: number;
  widthPct: number;
}

const hourBlocks = computed<HourBlock[]>(() => {
  if (!props.date) return [];
  const dayStart = parseISO(`${props.date}T00:00:00.000Z`).getTime();
  const visStart = dayStart + HOUR_START * 60 * 60 * 1000;
  const visEnd = dayStart + HOUR_END * 60 * 60 * 1000;
  const visSpan = visEnd - visStart;

  return reservations.value
    .map((r) => {
      const s = parseISO(r.startsAt).getTime();
      const e = parseISO(r.endsAt).getTime();
      const cs = Math.max(s, visStart);
      const ce = Math.min(e, visEnd);
      if (ce <= cs) return null;
      return {
        reservation: r,
        leftPct: ((cs - visStart) / visSpan) * 100,
        widthPct: ((ce - cs) / visSpan) * 100,
      };
    })
    .filter((b): b is HourBlock => b !== null);
});

const proposedBlock = computed(() => {
  if (!props.startTime || !props.endTime || !props.date) return null;
  const startIso = isoFromDateTime(props.date, props.startTime);
  const endIso = isoFromDateTime(props.date, props.endTime);
  const startMs = parseISO(startIso).getTime();
  const endMs = parseISO(endIso).getTime();
  if (endMs <= startMs) return null;

  const dayStart = parseISO(`${props.date}T00:00:00.000Z`).getTime();
  const visStart = dayStart + HOUR_START * 60 * 60 * 1000;
  const visEnd = dayStart + HOUR_END * 60 * 60 * 1000;
  const visSpan = visEnd - visStart;

  const cs = Math.max(startMs, visStart);
  const ce = Math.min(endMs, visEnd);
  if (ce <= cs) return null;

  // Conflict check against existing blocks on this day.
  let conflict = false;
  for (const r of reservations.value) {
    const s = parseISO(r.startsAt).getTime();
    const e = parseISO(r.endsAt).getTime();
    if (s < endMs && startMs < e) {
      conflict = true;
      break;
    }
  }

  return {
    leftPct: ((cs - visStart) / visSpan) * 100,
    widthPct: ((ce - cs) / visSpan) * 100,
    conflict,
  };
});

const hourTicks = computed(() => {
  const list: number[] = [];
  for (let h = HOUR_START; h <= HOUR_END; h++) list.push(h);
  return list;
});

/* ────────────────────────  Drag-to-select  ──────────────────────── */
/**
 * Click + drag horizontally across the hour strip to pick a multi-hour
 * range in one gesture. Works for mouse and touch via the unified
 * Pointer Events API:
 *
 *   pointerdown on a cell  → start drag, record `dragStartHour`
 *   pointermove on the doc → track cell under pointer via
 *                            document.elementFromPoint (which works for
 *                            both mouse hover AND touch slide; pointer
 *                            capture would route events to the source
 *                            button and skip neighbours, so we don't
 *                            capture here)
 *   pointerup    on the doc → emit `pick-range` and reset
 *
 * A single-cell gesture (down + up on the same hour) still falls back
 * to the legacy single-hour `pick-hour` event so existing parent
 * handlers don't need to special-case the "click" case.
 */
const isDragging = ref(false);
const dragStartHour = ref<number | null>(null);
const dragCurrentHour = ref<number | null>(null);

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

function isHourSelected(h: number): boolean {
  if (!isDragging.value || dragStartHour.value === null || dragCurrentHour.value === null) {
    return false;
  }
  const lo = Math.min(dragStartHour.value, dragCurrentHour.value);
  const hi = Math.max(dragStartHour.value, dragCurrentHour.value);
  return h >= lo && h <= hi;
}

function hourFromEvent(ev: PointerEvent): number | null {
  const el = document.elementFromPoint(ev.clientX, ev.clientY);
  const target = (el as HTMLElement | null)?.closest<HTMLElement>('[data-hour]');
  if (!target) return null;
  const v = Number(target.dataset.hour);
  return Number.isFinite(v) ? v : null;
}

function onHourPointerDown(hour: number, ev: PointerEvent): void {
  // Only left mouse button / primary touch.
  if (ev.button !== undefined && ev.button !== 0) return;
  ev.preventDefault();
  isDragging.value = true;
  dragStartHour.value = hour;
  dragCurrentHour.value = hour;
  document.addEventListener('pointermove', onDocPointerMove);
  document.addEventListener('pointerup', onDocPointerUp, { once: true });
  document.addEventListener('pointercancel', onDocPointerUp, { once: true });
}

function onDocPointerMove(ev: PointerEvent): void {
  if (!isDragging.value) return;
  const h = hourFromEvent(ev);
  if (h !== null) dragCurrentHour.value = h;
}

function onDocPointerUp(): void {
  document.removeEventListener('pointermove', onDocPointerMove);
  if (!isDragging.value || dragStartHour.value === null || dragCurrentHour.value === null) {
    isDragging.value = false;
    return;
  }
  const lo = Math.min(dragStartHour.value, dragCurrentHour.value);
  const hi = Math.max(dragStartHour.value, dragCurrentHour.value);
  isDragging.value = false;
  dragStartHour.value = null;
  dragCurrentHour.value = null;
  if (lo === hi) {
    // Pure click → keep existing behaviour: set startTime, leave duration.
    emit('pick-hour', `${pad2(lo)}:00`);
  } else {
    // Range: end is exclusive (e.g. drag 9 → 11 means [09:00, 12:00)).
    emit('pick-range', `${pad2(lo)}:00`, `${pad2(hi + 1)}:00`);
  }
}
</script>

<template>
  <div v-if="resourceId" class="space-y-3">
    <!-- Day calendar -->
    <div>
      <div class="mb-1.5 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Voľnosť — najbližších {{ DAYS }} dní
        </span>
        <div class="flex items-center gap-3 text-[10px] text-slate-500">
          <span class="inline-flex items-center gap-1">
            <span class="inline-block h-3 w-3 rounded bg-emerald-100 ring-1 ring-emerald-200"></span>
            voľný
          </span>
          <span class="inline-flex items-center gap-1">
            <span class="inline-block h-3 w-3 rounded bg-amber-200 ring-1 ring-amber-300"></span>
            čiastočne
          </span>
          <span class="inline-flex items-center gap-1">
            <span class="inline-block h-3 w-3 rounded bg-rose-200 ring-1 ring-rose-300"></span>
            celý deň
          </span>
        </div>
      </div>
      <!-- Weekday headers (Po … Ne). Aligned with the cell grid below so
           the column for each day-of-week stays consistent. -->
      <div class="mb-1 grid grid-cols-7 gap-1 text-center text-[9px] font-semibold uppercase tracking-wide text-slate-400">
        <span v-for="h in WEEKDAY_HEADERS" :key="h">{{ h }}</span>
      </div>
      <div class="grid grid-cols-7 gap-1">
        <button
          v-for="cell in dayCells"
          :key="cell.iso"
          type="button"
          :disabled="cell.isPast"
          class="flex flex-col items-center justify-center rounded-md px-1 py-1.5 text-[11px] ring-1 transition-colors disabled:cursor-not-allowed"
          :class="[
            cell.isPast ? 'bg-slate-50 text-slate-300 ring-slate-100' : dayShade(cell.busyHours),
            cell.isSelected ? 'outline outline-2 outline-brand-600 outline-offset-1' : '',
            cell.isToday && !cell.isSelected ? 'font-bold' : '',
          ]"
          :title="cell.isPast
            ? 'Minulý deň'
            : (cell.busyHours > 0 ? `Obsadené ${cell.busyHours.toFixed(0)}h` : 'Voľný celý deň')"
          @click="cell.isPast || emit('pick-day', cell.iso)"
        >
          <span class="text-[9px] font-medium uppercase opacity-70">{{ dayLabel(cell.date) }}</span>
          <span class="font-semibold">{{ cell.date.getUTCDate() }}.{{ cell.date.getUTCMonth() + 1 }}.</span>
        </button>
      </div>
    </div>

    <!-- Hour strip for the selected day -->
    <div v-if="date">
      <div class="mb-1.5 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Hodiny dňa {{ date.split('-').reverse().join('.') }}
        </span>
        <span v-if="proposedBlock?.conflict" class="text-xs font-medium text-rose-700">
          ⚠ Vybraný čas sa kryje s inou rezerváciou
        </span>
      </div>
      <div class="relative" :class="isDragging ? 'cursor-col-resize select-none' : ''">
        <!-- Tick row — click for single hour, click+drag for multi-hour range. -->
        <div
          class="grid touch-none"
          :style="{ gridTemplateColumns: `repeat(${HOUR_SPAN}, minmax(0, 1fr))` }"
        >
          <button
            v-for="h in hourTicks.slice(0, -1)"
            :key="h"
            type="button"
            :data-hour="h"
            class="h-10 border-r border-slate-100 hover:bg-brand-50"
            :class="isHourSelected(h) ? 'bg-brand-200/70 ring-1 ring-brand-500 ring-inset' : ''"
            :title="`Klikni pre začiatok ${String(h).padStart(2, '0')}:00 — alebo potiahni pre viac hodín`"
            @pointerdown="onHourPointerDown(h, $event)"
            @click.prevent
          />
        </div>
        <!-- Existing reservation blocks (read-only) -->
        <div class="pointer-events-none absolute inset-0">
          <div
            v-for="b in hourBlocks"
            :key="b.reservation.id"
            class="absolute top-1 bottom-1 truncate rounded bg-slate-300/70 px-1.5 text-[10px] font-medium text-slate-800 ring-1 ring-slate-400"
            :style="{ left: b.leftPct + '%', width: b.widthPct + '%' }"
            :title="`${b.reservation.customerName} · ${formatTime(b.reservation.startsAt)}–${formatTime(b.reservation.endsAt)}`"
          >
            {{ b.reservation.customerName }}
          </div>
        </div>
        <!-- Proposed slot overlay -->
        <div
          v-if="proposedBlock"
          class="pointer-events-none absolute top-0 bottom-0 rounded ring-2"
          :class="proposedBlock.conflict ? 'bg-rose-200/60 ring-rose-500' : 'bg-brand-300/60 ring-brand-600'"
          :style="{ left: proposedBlock.leftPct + '%', width: proposedBlock.widthPct + '%' }"
        ></div>
        <!-- Hour scale labels -->
        <div class="mt-0.5 grid text-[9px] text-slate-400" :style="{ gridTemplateColumns: `repeat(${HOUR_SPAN}, minmax(0, 1fr))` }">
          <span v-for="h in hourTicks.slice(0, -1)" :key="h" class="text-center">
            {{ h }}
          </span>
        </div>
      </div>
    </div>

    <p v-if="loading" class="text-xs text-slate-400">Načítavam dostupnosť…</p>
  </div>
</template>

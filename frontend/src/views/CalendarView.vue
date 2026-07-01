<script setup lang="ts">
import {
  addDays,
  addMonths,
  addWeeks,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  isSameMonth,
  parseISO,
  startOfMonth,
  startOfWeek,
  subDays,
} from 'date-fns';
import { sk } from 'date-fns/locale';
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ReservationEditDialog from '@/components/ui/ReservationEditDialog.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange, formatTime } from '@/utils/format';

type View = 'week' | 'month';

const view = ref<View>('week');
const cursor = ref(new Date());
const reservations = ref<Reservation[]>([]);
const error = ref<string | null>(null);

const resources = useResourcesStore();
const auth = useAuthStore();
const router = useRouter();

// Day whose detail modal is open, and the reservation open in the edit dialog.
const dayDetail = ref<Date | null>(null);
const editing = ref<Reservation | null>(null);

const range = computed(() => {
  if (view.value === 'week') {
    const start = startOfWeek(cursor.value, { weekStartsOn: 1, locale: sk });
    const end = endOfWeek(cursor.value, { weekStartsOn: 1, locale: sk });
    return { start, end };
  }
  const start = startOfWeek(startOfMonth(cursor.value), { weekStartsOn: 1, locale: sk });
  const end = endOfWeek(endOfMonth(cursor.value), { weekStartsOn: 1, locale: sk });
  return { start, end };
});

const days = computed(() => eachDayOfInterval({ start: range.value.start, end: range.value.end }));

const reservationsByDay = computed(() => {
  const map = new Map<string, Reservation[]>();
  for (const r of reservations.value) {
    // endsAt is exclusive. If end is on midnight, that day is NOT covered;
    // otherwise (intra-day end), the end day IS covered. This handles both
    // "Mon→Thu midnight" (covers Mon/Tue/Wed) and "Mon 18:00→Wed 12:00".
    const start = parseISO(r.startsAt);
    const endExclusive = parseISO(r.endsAt);
    const endsOnMidnight =
      endExclusive.getUTCHours() === 0 &&
      endExclusive.getUTCMinutes() === 0 &&
      endExclusive.getUTCSeconds() === 0;
    const lastCoveredDay = subDays(endExclusive, endsOnMidnight ? 1 : 0);
    const fromDay = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), start.getUTCDate()));
    const toDay = new Date(
      Date.UTC(lastCoveredDay.getUTCFullYear(), lastCoveredDay.getUTCMonth(), lastCoveredDay.getUTCDate()),
    );
    for (const d of eachDayOfInterval({ start: fromDay, end: toDay })) {
      const key = format(d, 'yyyy-MM-dd');
      const list = map.get(key) ?? [];
      list.push(r);
      map.set(key, list);
    }
  }
  return map;
});

async function load() {
  error.value = null;
  try {
    const { items } = await reservationsApi.list({
      from: range.value.start.toISOString(),
      to: addDays(range.value.end, 1).toISOString(),
      pageSize: 500,
      status: 'CONFIRMED',
    });
    reservations.value = items;
  } catch (e) {
    error.value = (e as Error).message;
  }
}

function shift(direction: 1 | -1) {
  cursor.value =
    view.value === 'week' ? addWeeks(cursor.value, direction) : addMonths(cursor.value, direction);
}

function today() {
  cursor.value = new Date();
}

function resLabel(r: Reservation): string {
  const res = resources.byId.get(r.resourceId);
  return res ? `${res.identifier} · ${res.name}` : '—';
}

/** Rich hover tooltip (desktop). */
function tooltip(r: Reservation): string {
  const lines = [
    resLabel(r),
    formatReservationRange(r.startsAt, r.endsAt),
    r.customerName ?? '** rezervácia',
  ];
  if (r.note) lines.push(r.note);
  return lines.join('\n');
}

/** Reservations for the day open in the detail modal, sorted by start. */
const dayReservations = computed<Reservation[]>(() => {
  if (!dayDetail.value) return [];
  const key = format(dayDetail.value, 'yyyy-MM-dd');
  return [...(reservationsByDay.value.get(key) ?? [])].sort(
    (a, b) => a.startsAt.localeCompare(b.startsAt),
  );
});

function openDay(d: Date): void {
  dayDetail.value = d;
}

/** Open a reservation's detail: edit dialog for members, boat detail otherwise. */
function openReservation(r: Reservation): void {
  dayDetail.value = null;
  if (auth.isMember) {
    editing.value = r;
  } else {
    router.push(`/resources/${r.resourceId}`);
  }
}

function editingResourceLabel(): string | undefined {
  return editing.value ? resLabel(editing.value) : undefined;
}

function onReservationChanged(): void {
  editing.value = null;
  void load();
}

watch([view, cursor], load, { immediate: false });
onMounted(async () => {
  await Promise.all([load(), resources.fetch()]);
});
</script>

<template>
  <PageHeader title="Kalendár" subtitle="Týždenný a mesačný prehľad rezervácií.">
    <template #actions>
      <div class="inline-flex rounded-lg ring-1 ring-slate-300">
        <button
          class="px-3 py-2 text-sm font-medium"
          :class="view === 'week' ? 'bg-brand-600 text-white' : 'text-slate-700'"
          @click="view = 'week'"
        >
          Týždeň
        </button>
        <button
          class="px-3 py-2 text-sm font-medium"
          :class="view === 'month' ? 'bg-brand-600 text-white' : 'text-slate-700'"
          @click="view = 'month'"
        >
          Mesiac
        </button>
      </div>
      <button class="btn-secondary" type="button" @click="shift(-1)">‹</button>
      <button class="btn-secondary" type="button" @click="today">Dnes</button>
      <button class="btn-secondary" type="button" @click="shift(1)">›</button>
    </template>
  </PageHeader>

  <p class="mb-3 text-sm font-medium text-slate-700">
    {{ format(range.start, 'd. MMM yyyy', { locale: sk }) }} –
    {{ format(range.end, 'd. MMM yyyy', { locale: sk }) }}
  </p>

  <LoadError :message="error" />

  <div class="card overflow-hidden">
    <!-- Weekday header: shown for month always, and for week only on md+
         (on mobile the week view stacks into rows that carry their own day). -->
    <div
      class="grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-semibold uppercase tracking-wide text-slate-500"
      :class="view === 'week' ? 'hidden md:grid' : 'grid'"
    >
      <div v-for="d in days.slice(0, 7)" :key="d.toISOString()" class="px-2 py-2">
        {{ format(d, 'EEE', { locale: sk }) }}
      </div>
    </div>

    <!-- Week view on mobile: one full-width row per day (columns are too
         narrow to read on a phone). -->
    <div v-if="view === 'week'" class="divide-y divide-slate-100 md:hidden">
      <div
        v-for="d in days"
        :key="'m' + d.toISOString()"
        class="cursor-pointer p-3 transition hover:bg-slate-50"
        :class="{ 'bg-brand-50/60': isSameDay(d, new Date()) }"
        @click="openDay(d)"
      >
        <div class="mb-1 flex items-center justify-between">
          <span class="text-sm font-semibold capitalize text-slate-700">
            {{ format(d, 'EEEE d.M.', { locale: sk }) }}
          </span>
          <span
            v-if="(reservationsByDay.get(format(d, 'yyyy-MM-dd'))?.length ?? 0) > 0"
            class="rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white"
          >
            {{ reservationsByDay.get(format(d, 'yyyy-MM-dd'))!.length }}
          </span>
        </div>
        <ul v-if="(reservationsByDay.get(format(d, 'yyyy-MM-dd'))?.length ?? 0) > 0" class="space-y-1">
          <li
            v-for="r in reservationsByDay.get(format(d, 'yyyy-MM-dd')) ?? []"
            :key="r.id + 'm' + d.toISOString()"
            class="cursor-pointer truncate rounded bg-brand-100 px-2 py-1 text-xs text-brand-900 hover:bg-brand-200"
            :title="tooltip(r)"
            @click.stop="openReservation(r)"
          >
            <span class="font-medium text-brand-700">{{ formatTime(r.startsAt) }}</span>
            {{ resources.byId.get(r.resourceId)?.identifier ?? '?' }} ·
            {{ r.customerName ?? '** rezervácia' }}
          </li>
        </ul>
        <p v-else class="text-xs text-slate-400">Žiadne rezervácie</p>
      </div>
    </div>

    <!-- Month view (all sizes) + week view on md+: the 7-column grid. -->
    <div
      class="grid-cols-7"
      :class="view === 'week' ? 'hidden md:grid' : 'grid'"
    >
      <div
        v-for="d in days"
        :key="d.toISOString()"
        class="min-h-[6rem] cursor-pointer border-b border-r border-slate-100 p-2 transition hover:bg-slate-50"
        :class="{
          'bg-slate-50/40': view === 'month' && !isSameMonth(d, cursor),
          'bg-brand-50/60 ring-1 ring-inset ring-brand-200': isSameDay(d, new Date()),
        }"
        @click="openDay(d)"
      >
        <div class="mb-1 flex items-center justify-between text-xs">
          <span class="font-semibold text-slate-700">{{ format(d, 'd.M.') }}</span>
          <span
            v-if="(reservationsByDay.get(format(d, 'yyyy-MM-dd'))?.length ?? 0) > 0"
            class="rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white"
          >
            {{ reservationsByDay.get(format(d, 'yyyy-MM-dd'))!.length }}
          </span>
        </div>
        <ul class="space-y-1">
          <li
            v-for="r in reservationsByDay.get(format(d, 'yyyy-MM-dd')) ?? []"
            :key="r.id + d.toISOString()"
            class="cursor-pointer truncate rounded bg-brand-100 px-1.5 py-0.5 text-[11px] text-brand-900 hover:bg-brand-200"
            :title="tooltip(r)"
            @click.stop="openReservation(r)"
          >
            <span class="font-medium text-brand-700">
              {{ formatTime(r.startsAt) }}
            </span>
            {{ resources.byId.get(r.resourceId)?.identifier ?? '?' }} ·
            {{ r.customerName ?? '** rezervácia' }}
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Day detail: reservations (which boats are booked) on the tapped day. -->
  <div
    v-if="dayDetail"
    class="fixed inset-0 z-40 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="dayDetail = null"
  >
    <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
      <header class="mb-3 flex items-start justify-between gap-3">
        <h3 class="text-lg font-semibold capitalize text-slate-900">
          {{ format(dayDetail, 'EEEE d. MMMM yyyy', { locale: sk }) }}
        </h3>
        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" aria-label="Zavrieť" @click="dayDetail = null">✕</button>
      </header>

      <p v-if="dayReservations.length === 0" class="py-6 text-center text-sm text-slate-500">
        V tento deň nie sú žiadne rezervácie.
      </p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="r in dayReservations" :key="r.id" class="py-3">
          <component
            :is="auth.isMember ? 'button' : 'div'"
            type="button"
            class="w-full text-left"
            :class="auth.isMember ? '-mx-2 rounded-lg px-2 py-1 transition hover:bg-brand-50/50' : ''"
            @click="auth.isMember && openReservation(r)"
          >
            <div class="flex items-center gap-2">
              <ResourceTypeBadge v-if="resources.byId.get(r.resourceId)" :type="resources.byId.get(r.resourceId)!.type" />
              <span class="font-mono text-sm font-semibold text-slate-900">{{ resources.byId.get(r.resourceId)?.identifier ?? '?' }}</span>
              <span class="text-slate-600">{{ resources.byId.get(r.resourceId)?.name ?? '' }}</span>
              <span v-if="auth.isMember" aria-hidden="true" class="ml-auto text-slate-300">✏️</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">
              {{ r.customerName ?? '** rezervácia' }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
            </p>
            <p v-if="r.note" class="mt-0.5 text-xs text-slate-500">{{ r.note }}</p>
          </component>
          <RouterLink
            :to="`/resources/${r.resourceId}`"
            class="mt-1 inline-block text-xs font-medium text-brand-700 hover:underline"
            @click="dayDetail = null"
          >
            Detail lode →
          </RouterLink>
        </li>
      </ul>

      <div class="mt-4 flex justify-end">
        <button type="button" class="btn-secondary" @click="dayDetail = null">Zavrieť</button>
      </div>
    </div>
  </div>

  <ReservationEditDialog
    :reservation="editing"
    :resource-label="editingResourceLabel()"
    @close="editing = null"
    @saved="onReservationChanged"
    @deleted="onReservationChanged"
  />
</template>

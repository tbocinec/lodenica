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

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useResourcesStore } from '@/stores/resources.store';
import { formatTime } from '@/utils/format';

type View = 'week' | 'month';

const view = ref<View>('week');
const cursor = ref(new Date());
const reservations = ref<Reservation[]>([]);
const error = ref<string | null>(null);

const resources = useResourcesStore();

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
    <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
      <div v-for="d in days.slice(0, 7)" :key="d.toISOString()" class="px-2 py-2">
        {{ format(d, 'EEE', { locale: sk }) }}
      </div>
    </div>
    <div class="grid grid-cols-7">
      <div
        v-for="d in days"
        :key="d.toISOString()"
        class="min-h-[6rem] border-b border-r border-slate-100 p-2"
        :class="{
          'bg-slate-50/40': view === 'month' && !isSameMonth(d, cursor),
          'bg-brand-50/60 ring-1 ring-inset ring-brand-200': isSameDay(d, new Date()),
        }"
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
            class="truncate rounded bg-brand-100 px-1.5 py-0.5 text-[11px] text-brand-900"
            :title="`${r.customerName} · ${resources.byId.get(r.resourceId)?.name ?? ''}`"
          >
            <span class="font-medium text-brand-700">
              {{ formatTime(r.startsAt) }}
            </span>
            {{ resources.byId.get(r.resourceId)?.identifier ?? '?' }} · {{ r.customerName }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

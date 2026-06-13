<script setup lang="ts">
/**
 * Admin-only usage dashboard. Five panels:
 *
 *   1. Headline tiles — totals (reservations all-time, active resources,
 *      open damages).
 *   2. Top resources — horizontal bar chart of the 10 most-booked
 *      resources in the 90-day window, with hours used.
 *   3. Cold resources — table of under-used active boats so the club
 *      can spot what to sell / re-deploy.
 *   4. Monthly trend — line chart of confirmed reservations per month
 *      for the last 6 months.
 *   5. Peak hours heatmap — 24×7 grid (rows = hours, columns = days)
 *      coloured by how often the slot is booked.
 *   6. Damages by type — pill list.
 *
 * Chart.js is registered locally (tree-shaken) so we only pay for the
 * controllers we actually use.
 */
import { onMounted, ref, computed } from 'vue';

import {
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  Legend,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Title,
  Tooltip,
} from 'chart.js';
import { Bar, Line } from 'vue-chartjs';

import { usageStatsApi, type UsageStats } from '@/api/usage-stats.api';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';

Chart.register(
  BarController,
  BarElement,
  CategoryScale,
  LinearScale,
  LineController,
  LineElement,
  PointElement,
  Title,
  Tooltip,
  Legend,
);

const stats = ref<UsageStats | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    stats.value = await usageStatsApi.get();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const DOW_LABELS = ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'] as const;
const HOURS = Array.from({ length: 24 }, (_, i) => i);

/** Compact list of "interesting" hours to display in the heatmap.
 *  06-23 covers practical paddling hours; the empty 00-05 band is hidden. */
const VISIBLE_HOURS = HOURS.slice(6, 23);

function heatColor(value: number, max: number): string {
  if (!value) return 'bg-slate-50';
  const ratio = max > 0 ? value / max : 0;
  if (ratio < 0.2) return 'bg-emerald-100';
  if (ratio < 0.4) return 'bg-emerald-200';
  if (ratio < 0.6) return 'bg-amber-300';
  if (ratio < 0.8) return 'bg-orange-400';
  return 'bg-rose-500';
}

const topChartData = computed(() => {
  if (!stats.value) return null;
  return {
    labels: stats.value.topResources.map((r) => `${r.identifier} · ${r.name}`),
    datasets: [
      {
        label: 'Počet rezervácií',
        data: stats.value.topResources.map((r) => r.count),
        backgroundColor: 'rgba(21, 91, 193, 0.65)',
        borderRadius: 6,
      },
    ],
  };
});

const topChartOptions = {
  indexAxis: 'y' as const,
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    x: { beginAtZero: true, ticks: { precision: 0 } },
  },
};

const monthLabel = (iso: string): string => {
  const [y, m] = iso.split('-');
  const months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec',
  ];
  const month = months[Number(m) - 1] ?? m;
  return `${month} ${y}`;
};

const trendChartData = computed(() => {
  if (!stats.value) return null;
  return {
    labels: stats.value.monthlyTrend.map((m) => monthLabel(m.monthIso)),
    datasets: [
      {
        label: 'Rezervácie / mesiac',
        data: stats.value.monthlyTrend.map((m) => m.count),
        borderColor: 'rgb(21, 91, 193)',
        backgroundColor: 'rgba(21, 91, 193, 0.15)',
        tension: 0.3,
        fill: true,
        pointRadius: 4,
      },
    ],
  };
});

const trendChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    y: { beginAtZero: true, ticks: { precision: 0 } },
  },
};

const damagesTotal = computed(() =>
  (stats.value?.damagesByType ?? []).reduce((sum, x) => sum + x.count, 0),
);
</script>

<template>
  <PageHeader
    title="Štatistiky používania"
    subtitle="Posledných 90 dní · ako sa flotila reálne využíva."
  />

  <LoadError class="mb-4" :message="error" />

  <div v-if="loading" class="flex justify-center py-12">
    <Spinner />
  </div>

  <div v-else-if="stats" class="space-y-6">
    <!-- 1. Headline tiles -->
    <section class="grid gap-4 sm:grid-cols-3">
      <StatCard label="Rezervácie celkom" :value="stats.totals.reservationsAllTime" hint="CONFIRMED za celý čas" />
      <StatCard label="Aktívne zdroje" :value="stats.totals.activeResources" hint="Lode, prívesy, priestory" />
      <StatCard label="Otvorené poškodenia" :value="stats.totals.openDamages" hint="Nahlásené / v oprave" />
    </section>

    <!-- 2. Top resources -->
    <section class="card-padded">
      <h2 class="mb-3 text-lg font-semibold">Top 10 najvyťaženejších</h2>
      <EmptyState
        v-if="stats.topResources.length === 0"
        title="Žiadne rezervácie za posledných 90 dní"
      />
      <div v-else class="h-80">
        <Bar :data="topChartData!" :options="topChartOptions" />
      </div>
      <div v-if="stats.topResources.length" class="mt-3 grid gap-1.5 text-xs text-slate-500 sm:grid-cols-2">
        <div v-for="r in stats.topResources" :key="r.resourceId" class="flex justify-between gap-2">
          <span class="truncate">{{ r.identifier }} — {{ RESOURCE_TYPE_LABEL[r.type] }}</span>
          <span>{{ r.totalHours.toFixed(1) }}h</span>
        </div>
      </div>
    </section>

    <!-- 3. Monthly trend -->
    <section class="card-padded">
      <h2 class="mb-3 text-lg font-semibold">Trend posledných 6 mesiacov</h2>
      <div class="h-64">
        <Line :data="trendChartData!" :options="trendChartOptions" />
      </div>
    </section>

    <!-- 4. Peak-hour heatmap -->
    <section class="card-padded">
      <h2 class="mb-3 text-lg font-semibold">Špičky podľa dňa a hodiny</h2>
      <p class="mb-3 text-xs text-slate-500">
        Koľko rezervácií prešlo cez danú hodinu × deň v týždni za posledných 90 dní.
        Hodiny 00–05 vynechané (nuly).
      </p>
      <div class="overflow-x-auto">
        <table class="text-xs">
          <thead>
            <tr>
              <th class="px-2 py-1"></th>
              <th v-for="hour in VISIBLE_HOURS" :key="hour" class="px-1 py-1 text-center font-medium text-slate-600">
                {{ String(hour).padStart(2, '0') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(label, dow) in DOW_LABELS" :key="dow">
              <th class="px-2 py-1 text-right font-medium text-slate-600">{{ label }}</th>
              <td
                v-for="hour in VISIBLE_HOURS"
                :key="hour"
                :class="['h-7 w-7 rounded-sm text-center align-middle', heatColor(stats.peakHours.counts[dow][hour], stats.peakHours.max)]"
                :title="`${label} ${String(hour).padStart(2, '0')}:00 — ${stats.peakHours.counts[dow][hour]} rezervácií`"
              >
                <span v-if="stats.peakHours.counts[dow][hour] > 0" class="text-[10px] text-slate-700">
                  {{ stats.peakHours.counts[dow][hour] }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- 5. Cold resources -->
    <section class="card-padded">
      <h2 class="mb-3 text-lg font-semibold">Najmenej využívané (90 dní)</h2>
      <p class="mb-3 text-xs text-slate-500">
        Aktívne lode s 0-málo rezerváciami za okno — kandidáti na presun do
        druhej lodenice alebo na vyradenie z evidencie.
      </p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="r in stats.coldResources" :key="r.resourceId" class="flex items-center justify-between py-2">
          <div>
            <span class="font-medium">{{ r.identifier }}</span>
            <span class="ml-1 text-slate-500">· {{ r.name }} · {{ RESOURCE_TYPE_LABEL[r.type] }}</span>
          </div>
          <span
            class="rounded-full px-2 py-0.5 text-xs font-medium ring-1"
            :class="r.count === 0 ? 'bg-rose-50 text-rose-800 ring-rose-200' : 'bg-amber-50 text-amber-800 ring-amber-200'"
          >
            {{ r.count }} rez.
          </span>
        </li>
      </ul>
    </section>

    <!-- 6. Damages by type -->
    <section class="card-padded">
      <h2 class="mb-3 text-lg font-semibold">Otvorené poškodenia podľa typu</h2>
      <EmptyState
        v-if="damagesTotal === 0"
        title="Žiadne otvorené poškodenia"
        description="Všetko v prevádzke."
      />
      <ul v-else class="flex flex-wrap gap-2">
        <li
          v-for="d in stats.damagesByType"
          :key="d.type"
          class="rounded-full bg-amber-50 px-3 py-1 text-sm font-medium text-amber-900 ring-1 ring-amber-200"
        >
          {{ RESOURCE_TYPE_LABEL[d.type] }}
          <span class="ml-1 text-amber-700">· {{ d.count }}</span>
        </li>
      </ul>
    </section>

    <p class="text-right text-xs text-slate-400">
      Generované {{ new Date(stats.generatedAt).toLocaleString('sk') }} · okno
      {{ stats.window.days }} dní
    </p>
  </div>
</template>

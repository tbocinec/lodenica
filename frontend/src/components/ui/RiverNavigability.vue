<script setup lang="ts">
/**
 * One "Splavnosť" area card: a horizontal band bar (red / orange / green /
 * orange / red) with a marker at the current Danube level, plus the numeric
 * limits and a status pill. Falls back to a "limits to be added" note when
 * the area has no bands defined yet.
 */
import { computed } from 'vue';

import {
  NAV_GAUGE_LABEL,
  NAV_LEVEL_HEX,
  NAV_LEVEL_LABEL,
  type NavArea,
  type NavLevel,
  type NavSegment,
} from '@/config/navigability';

const props = defineProps<{ area: NavArea; level: number | null }>();

const hasLimits = computed(() => props.area.segments.length > 0);

// Bar domain: span the finite boundaries with a margin, and always include
// the current level so its marker stays visible even when out of range.
const domain = computed(() => {
  const bounds = props.area.segments
    .flatMap((s) => [s.from, s.to])
    .filter((n): n is number => n !== null);
  if (bounds.length === 0) return { min: 0, max: 1 };
  let min = Math.min(...bounds);
  let max = Math.max(...bounds);
  const pad = Math.max(20, (max - min) * 0.12);
  min -= pad;
  max += pad;
  if (props.level !== null) {
    min = Math.min(min, props.level);
    max = Math.max(max, props.level);
  }
  return { min, max };
});

function pct(value: number): number {
  const { min, max } = domain.value;
  if (max === min) return 0;
  return Math.max(0, Math.min(100, ((value - min) / (max - min)) * 100));
}

const bands = computed(() =>
  props.area.segments.map((s) => {
    const start = s.from ?? domain.value.min;
    const end = s.to ?? domain.value.max;
    const left = pct(start);
    return { left, width: pct(end) - left, hex: NAV_LEVEL_HEX[s.level] };
  }),
);

const markerPct = computed(() => (props.level === null ? null : pct(props.level)));

const current = computed<NavSegment | null>(() => {
  if (props.level === null) return null;
  return (
    props.area.segments.find(
      (s) => (s.from === null || props.level! >= s.from) && (s.to === null || props.level! <= s.to),
    ) ?? null
  );
});

function formatRange(s: NavSegment): string {
  if (s.from === null) return `do ${s.to}`;
  if (s.to === null) return `od ${s.from}`;
  return `${s.from} – ${s.to}`;
}

// Group ranges by level for the legend.
const legend = computed(() =>
  (['green', 'orange', 'red'] as NavLevel[])
    .map((lvl) => ({
      level: lvl,
      label: NAV_LEVEL_LABEL[lvl],
      hex: NAV_LEVEL_HEX[lvl],
      ranges: props.area.segments
        .filter((s) => s.level === lvl)
        .map(formatRange)
        .join(', '),
    }))
    .filter((g) => g.ranges),
);
</script>

<template>
  <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
    <div class="flex items-start justify-between gap-2">
      <div>
        <p class="font-medium text-slate-900">{{ area.name }}</p>
        <p class="text-xs text-slate-400">podľa stavu v {{ NAV_GAUGE_LABEL[area.gauge] }}</p>
      </div>
      <span
        v-if="hasLimits && current"
        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
        :style="{ backgroundColor: NAV_LEVEL_HEX[current.level] }"
      >
        {{ NAV_LEVEL_LABEL[current.level] }}
      </span>
      <span
        v-else-if="hasLimits"
        class="shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200"
      >
        stav nedostupný
      </span>
    </div>

    <!-- Limits not defined yet -->
    <p
      v-if="!hasLimits"
      class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200"
    >
      Limity splavnosti sa doplnia.
    </p>

    <template v-else>
      <!-- Band bar with current-level marker -->
      <div class="relative mt-4 mb-1 h-3 w-full overflow-hidden rounded-full bg-slate-100">
        <div
          v-for="(b, i) in bands"
          :key="i"
          class="absolute top-0 h-full"
          :style="{ left: b.left + '%', width: b.width + '%', backgroundColor: b.hex }"
        />
      </div>
      <!-- Marker -->
      <div v-if="markerPct !== null" class="relative h-5">
        <div
          class="absolute -top-3 flex -translate-x-1/2 flex-col items-center"
          :style="{ left: markerPct + '%' }"
        >
          <span class="h-3 w-0.5 bg-slate-900"></span>
          <span class="mt-0.5 whitespace-nowrap rounded bg-slate-900 px-1.5 py-0.5 text-[10px] font-semibold text-white">
            {{ level }} cm
          </span>
        </div>
      </div>
      <p v-else class="mt-1 text-xs text-slate-400">Aktuálny stav ({{ NAV_GAUGE_LABEL[area.gauge] }}) je nedostupný.</p>

      <!-- Numeric limits -->
      <ul class="mt-3 space-y-1 text-xs text-slate-600">
        <li v-for="g in legend" :key="g.level" class="flex items-center gap-2">
          <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: g.hex }" />
          <span><strong>{{ g.label }}:</strong> {{ g.ranges }} cm</span>
        </li>
      </ul>
    </template>
  </div>
</template>

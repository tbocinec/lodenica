<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';

import TrafficLight from '@/components/ui/TrafficLight.vue';
import {
  hhmm,
  LEVEL_LABEL,
  usePaddlingTrafficLight,
  weatherIconUrl,
} from '@/composables/usePaddlingTrafficLight';

const { response, loading, error, load } = usePaddlingTrafficLight();
const d = computed(() => response.value?.data ?? null);

const accent = computed(() => {
  switch (d.value?.recommendation.level) {
    case 'red':
      return 'border-rose-200 bg-rose-50/50';
    case 'orange':
      return 'border-amber-200 bg-amber-50/50';
    default:
      return 'border-emerald-200 bg-emerald-50/50';
  }
});

onMounted(load);
</script>

<template>
  <!-- Hidden entirely while loading the first time or if the upstream is
       down — it's a nice-to-have, never blocks the dashboard. -->
  <section
    v-if="d"
    class="mb-6 flex flex-wrap items-center gap-4 rounded-2xl border p-4"
    :class="accent"
  >
    <RouterLink to="/vodacky-semafor" class="shrink-0" title="Vodácky semafor — detail">
      <TrafficLight :level="d.recommendation.level" size="lg" />
    </RouterLink>

    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-baseline gap-x-2">
        <h2 class="text-base font-semibold text-slate-900">Vodácky semafor</h2>
        <span class="text-sm font-medium" :style="{ color: d.recommendation.color?.hex }">
          {{ d.recommendation.label || LEVEL_LABEL[d.recommendation.level] }}
        </span>
      </div>
      <p class="text-sm text-slate-600">
        {{ d.location.name }}<span v-if="d.location.river"> — {{ d.location.river }}</span>
      </p>

      <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-700">
        <span class="inline-flex items-center gap-1">
          <img
            :src="weatherIconUrl(d.weather.icon)"
            alt=""
            width="28"
            height="28"
            class="-my-1"
          />
          {{ Math.round(d.weather.temperature.celsius) }}°C {{ d.weather.description }},
          vietor {{ Math.round(d.weather.wind.speed_mps) }} m/s
        </span>
        <span>🌊 Dunaj: {{ d.danube.water_level.value }}{{ d.danube.water_level.unit }}
          · {{ Math.round(d.danube.water_temperature.value) }}°C</span>
        <span>🌇 Západ slnka o {{ hhmm(d.daylight.sunset) }}</span>
      </div>
    </div>

    <RouterLink
      to="/vodacky-semafor"
      class="btn-secondary shrink-0 text-xs"
    >
      Detail →
    </RouterLink>
  </section>

  <!-- Soft failure: a tiny inline note instead of the widget, only if the
       call errored (not while still loading). -->
  <p
    v-else-if="error && !loading"
    class="mb-6 text-xs text-slate-400"
  >
    Vodácky semafor je momentálne nedostupný.
  </p>
</template>

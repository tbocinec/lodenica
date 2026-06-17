<script setup lang="ts">
import { computed, onMounted } from 'vue';

import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import TrafficLight from '@/components/ui/TrafficLight.vue';
import {
  hhmm,
  LEVEL_LABEL,
  usePaddlingTrafficLight,
  weatherIconUrl,
} from '@/composables/usePaddlingTrafficLight';

const { response, loading, error, load } = usePaddlingTrafficLight();
const d = computed(() => response.value?.data ?? null);
const sourceUrl = computed(() => response.value?.sourceUrl ?? 'https://www.dunajcik.sk/vodacky-semafor');

onMounted(load);
</script>

<template>
  <PageHeader
    title="Vodácky semafor"
    subtitle="Orientačné podmienky na splavovanie Dunaja v Bratislave."
  />

  <LoadError class="mb-4" :message="error" />
  <div v-if="loading && !d" class="flex justify-center py-12"><Spinner /></div>

  <template v-if="d">
    <!-- Current state -->
    <section class="mb-6 flex flex-wrap items-center gap-5 rounded-2xl bg-white p-5 ring-1 ring-slate-200">
      <TrafficLight :level="d.recommendation.level" size="lg" />
      <div class="min-w-0 flex-1">
        <p class="text-lg font-semibold" :style="{ color: d.recommendation.color?.hex }">
          {{ d.recommendation.label || LEVEL_LABEL[d.recommendation.level] }}
        </p>
        <p class="text-sm text-slate-600">{{ d.recommendation.message }}</p>
        <ul v-if="d.recommendation.reasons?.length" class="mt-2 list-disc pl-5 text-sm text-slate-500">
          <li v-for="(r, i) in d.recommendation.reasons" :key="i">{{ r }}</li>
        </ul>
      </div>
    </section>

    <!-- Current conditions -->
    <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wide text-slate-400">Miesto</p>
        <p class="mt-1 font-medium text-slate-900">{{ d.location.name }}</p>
        <p class="text-sm text-slate-500">{{ d.location.river }}</p>
      </div>
      <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wide text-slate-400">Počasie</p>
        <p class="mt-1 flex items-center gap-1 font-medium text-slate-900">
          <img :src="weatherIconUrl(d.weather.icon)" alt="" width="32" height="32" class="-my-1" />
          {{ Math.round(d.weather.temperature.celsius) }}°C
        </p>
        <p class="text-sm text-slate-500">
          {{ d.weather.description }}, vietor {{ Math.round(d.weather.wind.speed_mps) }} m/s
        </p>
      </div>
      <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wide text-slate-400">Dunaj</p>
        <p class="mt-1 font-medium text-slate-900">
          {{ d.danube.water_level.value }} {{ d.danube.water_level.unit }}
        </p>
        <p class="text-sm text-slate-500">
          {{ Math.round(d.danube.water_temperature.value) }} °C vody · {{ d.danube.source }}
        </p>
      </div>
      <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <p class="text-xs uppercase tracking-wide text-slate-400">Slnko</p>
        <p class="mt-1 font-medium text-slate-900">🌇 {{ hhmm(d.daylight.sunset) }}</p>
        <p class="text-sm text-slate-500">🌅 východ {{ hhmm(d.daylight.sunrise) }}</p>
      </div>
    </section>

    <!-- What it means -->
    <section class="mb-6 rounded-2xl bg-white p-5 ring-1 ring-slate-200">
      <p class="text-sm text-slate-600">
        Vodácky semafor má len <strong>orientačný charakter</strong> a môže ti pomôcť
        rozhodnúť sa, či sú podmienky vhodné na splavovanie Dunaja. Vždy je však potrebné
        zohľadniť svoje schopnosti a aktuálne podmienky na rieke.
      </p>

      <h2 class="mt-5 mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
        Hraničné hodnoty
      </h2>
      <ul class="space-y-3">
        <li class="flex gap-3">
          <span class="mt-1.5 h-3.5 w-3.5 shrink-0 rounded-full bg-emerald-500" />
          <p class="text-sm text-slate-700">
            <strong>Vhodné podmienky.</strong> Výška hladiny pod
            {{ d.recommendation.thresholds.orange.water_level_cm }} cm. Rýchlosť vetra pod
            {{ d.recommendation.thresholds.orange.wind_mps }} m/s.
          </p>
        </li>
        <li class="flex gap-3">
          <span class="mt-1.5 h-3.5 w-3.5 shrink-0 rounded-full bg-amber-400" />
          <p class="text-sm text-slate-700">
            <strong>Náročné podmienky.</strong> Výška hladiny nad
            {{ d.recommendation.thresholds.orange.water_level_cm }} cm, alebo rýchlosť vetra nad
            {{ d.recommendation.thresholds.orange.wind_mps }} m/s, alebo dážď, sneh, hmla a podobne.
          </p>
        </li>
        <li class="flex gap-3">
          <span class="mt-1.5 h-3.5 w-3.5 shrink-0 rounded-full bg-rose-500" />
          <p class="text-sm text-slate-700">
            <strong>Nevhodné podmienky.</strong> 30 min po západe slnka, alebo výška hladiny nad
            {{ d.recommendation.thresholds.red.water_level_cm }} cm, alebo rýchlosť vetra nad
            {{ d.recommendation.thresholds.red.wind_mps }} m/s, alebo búrka, víchrica a podobne.
          </p>
        </li>
      </ul>
    </section>

    <p class="text-xs text-slate-400">
      Údaje a vyhodnotenie poskytuje
      <a :href="sourceUrl" target="_blank" rel="noopener noreferrer" class="underline hover:text-slate-600">
        {{ response?.source ?? 'dunajcik.sk' }}
      </a>. Aktualizované každých pár minút.
    </p>
  </template>
</template>

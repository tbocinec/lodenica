<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { availabilityApi } from '@/api/availability.api';
import type { DashboardSnapshot } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import StatCard from '@/components/ui/StatCard.vue';
import { DAMAGE_STATUS_LABEL, RESOURCE_TYPE_LABEL } from '@/i18n/labels';
import { formatReservationRange } from '@/utils/format';

const snapshot = ref<DashboardSnapshot | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    snapshot.value = await availabilityApi.dashboard();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader title="Prehľad" subtitle="Aktuálny stav rezervácií a priestorov.">
    <template #actions>
      <button class="btn-secondary" type="button" @click="load">Obnoviť</button>
      <RouterLink to="/reservations/new" class="btn-primary">Vytvoriť rezerváciu</RouterLink>
    </template>
  </PageHeader>

  <LoadError :message="error" />
  <Spinner v-if="loading && !snapshot" />

  <template v-if="snapshot">
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3">
      <StatCard label="Dnes obsadené" :value="snapshot.occupiedToday.length" tone="amber" />
      <StatCard
        label="Nadchádzajúcich rezervácií"
        :value="snapshot.totals.upcomingReservations"
      />
      <StatCard label="Otvorené poškodenia" :value="snapshot.totals.openDamages" tone="red" />
    </section>

    <section class="mt-6 grid gap-6 lg:grid-cols-2">
      <div class="card-padded">
        <h2 class="mb-3 text-lg font-semibold">Dnes obsadené</h2>
        <EmptyState
          v-if="snapshot.occupiedToday.length === 0"
          title="Žiadne rezervácie na dnes"
        />
        <ul v-else class="divide-y divide-slate-100">
          <li
            v-for="r in snapshot.occupiedToday"
            :key="r.id"
            class="flex items-start justify-between gap-3 py-3"
          >
            <div>
              <div class="flex items-center gap-2">
                <ResourceTypeBadge :type="r.resource.type" />
                <span class="font-medium text-slate-800">{{ r.resource.name }}</span>
              </div>
              <p class="mt-1 text-sm text-slate-500">
                {{ r.customerName }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
              </p>
            </div>
          </li>
        </ul>
      </div>

      <div class="card-padded">
        <h2 class="mb-3 text-lg font-semibold">Zajtra obsadené</h2>
        <EmptyState
          v-if="snapshot.occupiedTomorrow.length === 0"
          title="Žiadne rezervácie na zajtra"
        />
        <ul v-else class="divide-y divide-slate-100">
          <li
            v-for="r in snapshot.occupiedTomorrow"
            :key="r.id"
            class="flex items-start justify-between gap-3 py-3"
          >
            <div>
              <div class="flex items-center gap-2">
                <ResourceTypeBadge :type="r.resource.type" />
                <span class="font-medium text-slate-800">{{ r.resource.name }}</span>
              </div>
              <p class="mt-1 text-sm text-slate-500">
                {{ r.customerName }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
              </p>
            </div>
          </li>
        </ul>
      </div>

      <div class="card-padded">
        <h2 class="mb-3 text-lg font-semibold">Aktuálne dostupné</h2>
        <EmptyState
          v-if="snapshot.available.length === 0"
          title="Momentálne nie sú dostupné žiadne lode"
        />
        <ul v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <li
            v-for="r in snapshot.available"
            :key="r.id"
            class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/40 px-3 py-2"
          >
            <ResourceTypeBadge :type="r.type" />
            <span class="font-medium text-slate-800">{{ r.name }}</span>
            <span class="ml-auto text-xs text-slate-500">{{ r.identifier }}</span>
          </li>
        </ul>
      </div>

      <div class="card-padded">
        <h2 class="mb-3 text-lg font-semibold">Priestory</h2>
        <EmptyState
          v-if="snapshot.spaceReservations.length === 0"
          title="Žiadne nadchádzajúce rezervácie priestorov"
        />
        <ul v-else class="divide-y divide-slate-100">
          <li
            v-for="r in snapshot.spaceReservations"
            :key="r.id"
            class="flex items-start justify-between gap-3 py-3"
          >
            <div>
              <p class="font-medium text-slate-800">{{ r.resource.name }}</p>
              <p class="mt-1 text-sm text-slate-500">
                {{ r.customerName }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
              </p>
            </div>
          </li>
        </ul>
      </div>

      <div class="card-padded lg:col-span-2">
        <h2 class="mb-3 text-lg font-semibold">Poškodenia v stave nahlásené / v oprave</h2>
        <EmptyState v-if="snapshot.damaged.length === 0" title="Žiadne otvorené poškodenia" />
        <ul v-else class="divide-y divide-slate-100">
          <li
            v-for="d in snapshot.damaged"
            :key="d.damageId"
            class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:gap-4"
          >
            <div class="flex items-center gap-2">
              <span class="pill-amber">{{ DAMAGE_STATUS_LABEL[d.status] }}</span>
              <span class="text-sm font-medium text-slate-800">
                {{ RESOURCE_TYPE_LABEL[d.resource.type] }} · {{ d.resource.name }}
              </span>
            </div>
            <p class="text-sm text-slate-600 sm:flex-1">{{ d.description }}</p>
          </li>
        </ul>
      </div>
    </section>
  </template>
</template>

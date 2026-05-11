<script setup lang="ts">
import { parseISO } from 'date-fns';
import { computed, onMounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';

import { damagesApi } from '@/api/damages.api';
import { reservationsApi } from '@/api/reservations.api';
import { resourcesApi } from '@/api/resources.api';
import {
  DamageStatus,
  ReservationStatus,
  type Damage,
  type Reservation,
  type Resource,
} from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import {
  DAMAGE_SEVERITY_LABEL,
  DAMAGE_STATUS_LABEL,
  RESOURCE_TYPE_LABEL,
} from '@/i18n/labels';
import { formatDateTime, formatReservationRange } from '@/utils/format';

const route = useRoute();
const id = computed(() => route.params.id as string);

const resource = ref<Resource | null>(null);
const reservations = ref<Reservation[]>([]);
const damages = ref<Damage[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [r, rsv, dmg] = await Promise.all([
      resourcesApi.get(id.value),
      reservationsApi.list({ resourceId: id.value, pageSize: 200 }),
      damagesApi.list({ resourceId: id.value, pageSize: 200 }),
    ]);
    resource.value = r;
    reservations.value = rsv.items;
    damages.value = dmg.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

const now = new Date();

const reservedNow = computed<Reservation | null>(() => {
  return (
    reservations.value.find(
      (r) =>
        r.status === ReservationStatus.CONFIRMED &&
        parseISO(r.startsAt) <= now &&
        parseISO(r.endsAt) > now,
    ) ?? null
  );
});

const upcomingReservations = computed<Reservation[]>(() =>
  reservations.value
    .filter(
      (r) => r.status === ReservationStatus.CONFIRMED && parseISO(r.startsAt) > now,
    )
    .sort((a, b) => parseISO(a.startsAt).getTime() - parseISO(b.startsAt).getTime())
    .slice(0, 5),
);

const openDamages = computed<Damage[]>(() =>
  damages.value.filter((d) => d.status !== DamageStatus.FIXED),
);

const fixedDamages = computed<Damage[]>(() =>
  damages.value.filter((d) => d.status === DamageStatus.FIXED),
);

onMounted(load);
</script>

<template>
  <Spinner v-if="loading && !resource" />
  <LoadError :message="error" />

  <template v-if="resource">
    <PageHeader
      :title="resource.name"
      :subtitle="`${RESOURCE_TYPE_LABEL[resource.type]} · ${resource.identifier}`"
    >
      <template #actions>
        <RouterLink :to="`/reservations/new?resourceId=${resource.id}`" class="btn-primary">
          ＋ Rezervovať
        </RouterLink>
        <RouterLink :to="`/resources/${resource.id}/edit`" class="btn-secondary">
          Upraviť
        </RouterLink>
      </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <ResourceTypeBadge :type="resource.type" />
      <span v-if="!resource.isActive" class="pill-slate">Neaktívny</span>
    </div>

    <!-- Today / status banner -->
    <section
      class="card-padded mb-4"
      :class="reservedNow ? 'bg-amber-50/60 ring-1 ring-amber-200' : 'bg-emerald-50/60 ring-1 ring-emerald-200'"
    >
      <template v-if="reservedNow">
        <p class="text-sm font-semibold text-amber-800">⏰ Práve obsadené</p>
        <p class="mt-1 text-sm text-amber-900">
          {{ reservedNow.customerName }} ·
          {{ formatReservationRange(reservedNow.startsAt, reservedNow.endsAt) }}
        </p>
      </template>
      <template v-else>
        <p class="text-sm font-semibold text-emerald-800">✅ Voľná</p>
        <p class="mt-1 text-sm text-emerald-900">
          Loď nie je momentálne rezervovaná.
        </p>
      </template>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
      <!-- Info card -->
      <section class="card-padded">
        <h2 class="mb-3 text-lg font-semibold text-slate-900">Informácie</h2>
        <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
          <dt class="text-slate-500">Typ</dt>
          <dd class="col-span-2 text-slate-800">{{ RESOURCE_TYPE_LABEL[resource.type] }}</dd>

          <dt class="text-slate-500">Identifikátor</dt>
          <dd class="col-span-2 font-mono text-xs text-slate-700">{{ resource.identifier }}</dd>

          <template v-if="resource.model">
            <dt class="text-slate-500">Model</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.model }}</dd>
          </template>

          <template v-if="resource.color">
            <dt class="text-slate-500">Farba</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.color }}</dd>
          </template>

          <template v-if="resource.seats">
            <dt class="text-slate-500">Počet miest</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.seats }}</dd>
          </template>

          <template v-if="resource.lengthCm">
            <dt class="text-slate-500">Dĺžka</dt>
            <dd class="col-span-2 text-slate-800">{{ (resource.lengthCm / 100).toFixed(2) }} m</dd>
          </template>

          <template v-if="resource.weightKg">
            <dt class="text-slate-500">Hmotnosť</dt>
            <dd class="col-span-2 text-slate-800">{{ resource.weightKg }} kg</dd>
          </template>

          <template v-if="resource.note">
            <dt class="text-slate-500">Poznámka</dt>
            <dd class="col-span-2 whitespace-pre-line text-slate-700">{{ resource.note }}</dd>
          </template>
        </dl>

        <img
          v-if="resource.imageUrl"
          :src="resource.imageUrl"
          :alt="resource.name"
          class="mt-4 max-h-60 w-full rounded-lg object-cover"
        />
      </section>

      <!-- Upcoming reservations -->
      <section class="card-padded">
        <header class="mb-3 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-slate-900">Najbližšie rezervácie</h2>
          <RouterLink :to="`/reservations/new?resourceId=${resource.id}`" class="btn-secondary text-xs">
            ＋ Nová
          </RouterLink>
        </header>
        <EmptyState
          v-if="upcomingReservations.length === 0"
          title="Žiadne nadchádzajúce rezervácie"
        />
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="r in upcomingReservations" :key="r.id" class="py-3">
            <p class="font-medium text-slate-800">{{ r.customerName }}</p>
            <p class="text-xs text-slate-500">
              {{ formatReservationRange(r.startsAt, r.endsAt) }}
            </p>
            <p v-if="r.note" class="mt-1 text-xs text-slate-500">{{ r.note }}</p>
          </li>
        </ul>
      </section>

      <!-- Open damages -->
      <section class="card-padded lg:col-span-2">
        <header class="mb-3 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-slate-900">Poškodenia</h2>
          <RouterLink to="/damages" class="btn-secondary text-xs">Otvoriť modul</RouterLink>
        </header>

        <div v-if="openDamages.length > 0" class="mb-4">
          <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-red-700">
            Otvorené ({{ openDamages.length }})
          </p>
          <ul class="divide-y divide-slate-100">
            <li v-for="d in openDamages" :key="d.id" class="py-3">
              <div class="flex flex-wrap items-center gap-2">
                <span class="pill-amber">{{ DAMAGE_STATUS_LABEL[d.status] }}</span>
                <span class="pill-slate">{{ DAMAGE_SEVERITY_LABEL[d.severity] }}</span>
                <span class="ml-auto text-xs text-slate-500">
                  Nahlásené {{ formatDateTime(d.reportedAt) }}
                </span>
              </div>
              <p class="mt-1 text-sm text-slate-700">{{ d.description }}</p>
              <p v-if="d.note" class="mt-1 text-xs text-slate-500">{{ d.note }}</p>
            </li>
          </ul>
        </div>

        <EmptyState
          v-else-if="fixedDamages.length === 0"
          title="Žiadne poškodenia"
          description="Pre túto loď nie sú zaznamenané žiadne poškodenia."
        />

        <details v-if="fixedDamages.length > 0" class="mt-2">
          <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">
            Opravené ({{ fixedDamages.length }})
          </summary>
          <ul class="mt-2 divide-y divide-slate-100">
            <li v-for="d in fixedDamages" :key="d.id" class="py-3">
              <div class="flex flex-wrap items-center gap-2">
                <span class="pill-green">{{ DAMAGE_STATUS_LABEL[d.status] }}</span>
                <span class="pill-slate">{{ DAMAGE_SEVERITY_LABEL[d.severity] }}</span>
                <span v-if="d.fixedAt" class="ml-auto text-xs text-slate-500">
                  Opravené {{ formatDateTime(d.fixedAt) }}
                </span>
              </div>
              <p class="mt-1 text-sm text-slate-700">{{ d.description }}</p>
            </li>
          </ul>
        </details>
      </section>
    </div>
  </template>
</template>

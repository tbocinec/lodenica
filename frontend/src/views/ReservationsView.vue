<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import { ReservationStatus, type Reservation } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ReservationEditDialog from '@/components/ui/ReservationEditDialog.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { RESERVATION_STATUS_LABEL } from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange } from '@/utils/format';

const reservations = ref<Reservation[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const showCancelled = ref(false);
const editing = ref<Reservation | null>(null);

const resourcesStore = useResourcesStore();
const filtered = computed(() =>
  reservations.value.filter((r) =>
    showCancelled.value ? true : r.status === ReservationStatus.CONFIRMED,
  ),
);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [data] = await Promise.all([
      reservationsApi.list({ pageSize: 200 }),
      resourcesStore.fetch(),
    ]);
    reservations.value = data.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

function editingResourceName(): string | undefined {
  return editing.value
    ? resourcesStore.byId.get(editing.value.resourceId)?.name
    : undefined;
}

async function onSaved(): Promise<void> {
  editing.value = null;
  await load();
}

async function onDeleted(): Promise<void> {
  editing.value = null;
  await load();
}

onMounted(load);
</script>

<template>
  <PageHeader title="Rezervácie">
    <template #actions>
      <RouterLink to="/reservations/new" class="btn-primary">＋ Vytvoriť rezerváciu</RouterLink>
    </template>
  </PageHeader>

  <!-- Hub cards — three paths into the booking flow, ordered by
       expected mental model. Members open this page first, so it's
       also the natural place to surface Časová os / Kalendár (which
       used to live in the top nav). -->
  <section class="mb-5 grid gap-3 sm:grid-cols-3">
    <RouterLink
      to="/reservations/new"
      class="group flex items-start gap-3 rounded-2xl border border-brand-200 bg-brand-50/60 p-4 ring-1 ring-transparent transition hover:border-brand-400 hover:ring-brand-200"
    >
      <span class="text-2xl" aria-hidden="true">🛶</span>
      <div class="flex-1">
        <p class="font-semibold text-brand-900">Začínam loďou</p>
        <p class="mt-0.5 text-xs text-brand-800">
          Viem akú loď chcem — vyberiem si ju a doplním voľný termín.
        </p>
        <p class="mt-2 text-xs font-medium text-brand-700 group-hover:underline">
          ＋ Vytvoriť rezerváciu →
        </p>
      </div>
    </RouterLink>

    <RouterLink
      to="/timeline"
      class="group flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-brand-400 hover:shadow-sm"
    >
      <span class="text-2xl" aria-hidden="true">⏱️</span>
      <div class="flex-1">
        <p class="font-semibold text-slate-900">Viem dátum, hľadám loď</p>
        <p class="mt-0.5 text-xs text-slate-600">
          Pozriem si všetky lode na konkrétny deň po hodinách —
          kde je voľno, tam kliknem.
        </p>
        <p class="mt-2 text-xs font-medium text-slate-700 group-hover:underline">
          Časová os →
        </p>
      </div>
    </RouterLink>

    <RouterLink
      to="/calendar"
      class="group flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-brand-400 hover:shadow-sm"
    >
      <span class="text-2xl" aria-hidden="true">🗓️</span>
      <div class="flex-1">
        <p class="font-semibold text-slate-900">Mesačný prehľad</p>
        <p class="mt-0.5 text-xs text-slate-600">
          Plánovanie víkendov a viacdňových akcií — vidím obsadenosť
          celého mesiaca naraz.
        </p>
        <p class="mt-2 text-xs font-medium text-slate-700 group-hover:underline">
          Kalendár →
        </p>
      </div>
    </RouterLink>
  </section>

  <div class="mb-3 flex items-center gap-3">
    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
      <input v-model="showCancelled" type="checkbox" class="h-4 w-4 rounded" />
      Zobraziť zrušené
    </label>
  </div>

  <LoadError :message="error" />
  <Spinner v-if="loading && !reservations.length" />

  <EmptyState
    v-else-if="filtered.length === 0"
    title="Žiadne rezervácie"
    description="Vytvor prvú rezerváciu — proces je rovnaký pre lode aj priestory."
  >
    <RouterLink to="/reservations/new" class="btn-primary">＋ Vytvoriť rezerváciu</RouterLink>
  </EmptyState>

  <div v-else class="card overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table-clean">
        <thead class="bg-slate-100/70">
          <tr>
            <th>Termín</th>
            <th>Zdroj</th>
            <th>Meno</th>
            <th class="hidden md:table-cell">Kontakt</th>
            <th>Stav</th>
            <th class="text-right">Akcie</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in filtered"
            :key="r.id"
            class="cursor-pointer hover:bg-brand-50/40"
            @click="editing = r"
          >
            <td class="font-medium">{{ formatReservationRange(r.startsAt, r.endsAt) }}</td>
            <td>
              <div class="flex items-center gap-2">
                <ResourceTypeBadge
                  v-if="resourcesStore.byId.get(r.resourceId)"
                  :type="resourcesStore.byId.get(r.resourceId)!.type"
                />
                <span class="text-slate-700">
                  {{ resourcesStore.byId.get(r.resourceId)?.name ?? '—' }}
                </span>
              </div>
            </td>
            <td>{{ r.customerName }}</td>
            <td class="hidden md:table-cell text-slate-500">{{ r.customerContact ?? '—' }}</td>
            <td>
              <span :class="r.status === 'CONFIRMED' ? 'pill-green' : 'pill-slate'">
                {{ RESERVATION_STATUS_LABEL[r.status] }}
              </span>
            </td>
            <td class="text-right">
              <button class="btn-secondary" type="button" @click.stop="editing = r">
                Upraviť
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <ReservationEditDialog
    :reservation="editing"
    :resource-name="editingResourceName()"
    @close="editing = null"
    @saved="onSaved"
    @deleted="onDeleted"
  />
</template>

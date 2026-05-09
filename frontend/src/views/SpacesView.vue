<script setup lang="ts">
import { addDays } from 'date-fns';
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import { ResourceType, type Reservation } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange, todayUtc } from '@/utils/format';

const resources = useResourcesStore();
const reservations = ref<Reservation[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

function reservationsFor(spaceId: string) {
  return reservations.value.filter((r) => r.resourceId === spaceId);
}

async function load() {
  loading.value = true;
  error.value = null;
  try {
    await resources.fetch({ type: ResourceType.BOATHOUSE_SPACE });
    const today = todayUtc();
    const horizon = addDays(today, 60);
    const data = await reservationsApi.list({
      from: today.toISOString(),
      to: horizon.toISOString(),
      status: 'CONFIRMED',
      pageSize: 200,
    });
    reservations.value = data.items.filter(
      (r) => resources.byId.get(r.resourceId)?.type === ResourceType.BOATHOUSE_SPACE,
    );
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader
    title="Priestory"
    subtitle="Prehľad obsadenosti priestorov."
  >
    <template #actions>
      <RouterLink to="/reservations/new" class="btn-primary">＋ Vytvoriť rezerváciu</RouterLink>
    </template>
  </PageHeader>

  <LoadError :message="error" />
  <Spinner v-if="loading && !reservations.length" />

  <div class="grid gap-6 lg:grid-cols-2">
    <section v-for="space in resources.spaces" :key="space.id" class="card-padded">
      <header class="mb-3 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">{{ space.name }}</h2>
          <p v-if="space.note" class="text-sm text-slate-500">{{ space.note }}</p>
        </div>
        <span class="pill-blue">{{ reservationsFor(space.id).length }} rezervácií</span>
      </header>

      <EmptyState
        v-if="reservationsFor(space.id).length === 0"
        title="Žiadne nadchádzajúce rezervácie"
      />
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="r in reservationsFor(space.id)" :key="r.id" class="py-3">
          <div class="flex items-baseline justify-between gap-3">
            <p class="font-medium text-slate-800">{{ r.customerName }}</p>
            <span class="text-xs text-slate-500">
              {{ formatReservationRange(r.startsAt, r.endsAt) }}
            </span>
          </div>
          <p v-if="r.note" class="mt-1 text-sm text-slate-600">{{ r.note }}</p>
        </li>
      </ul>
    </section>
  </div>
</template>

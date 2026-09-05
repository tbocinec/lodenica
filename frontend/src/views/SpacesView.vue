<script setup lang="ts">
import { addDays } from 'date-fns';
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import { RESERVATION_BLOCKING_STATUSES, ResourceType, type Reservation } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ReservationEditDialog from '@/components/ui/ReservationEditDialog.vue';
import ReservationStatusPill from '@/components/ui/ReservationStatusPill.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange, todayUtc } from '@/utils/format';

const auth = useAuthStore();
const resources = useResourcesStore();
const reservations = ref<Reservation[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
// Reservation open in the edit dialog (members only).
const editing = ref<Reservation | null>(null);

function reservationsFor(spaceId: string) {
  return reservations.value.filter((r) => r.resourceId === spaceId);
}

function editingResourceLabel(): string | undefined {
  if (!editing.value) return undefined;
  const r = resources.byId.get(editing.value.resourceId);
  return r ? `${r.identifier} · ${r.name}` : undefined;
}

function onReservationChanged(): void {
  editing.value = null;
  void load();
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
      status: [...RESERVATION_BLOCKING_STATUSES],
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
          <RouterLink
            :to="`/resources/${space.id}`"
            class="group inline-flex items-center gap-1 text-lg font-semibold text-slate-900 hover:text-brand-700"
          >
            {{ space.name }}
            <span aria-hidden="true" class="text-slate-300 transition group-hover:text-brand-400">›</span>
          </RouterLink>
          <p v-if="space.note" class="text-sm text-slate-500">{{ space.note }}</p>
        </div>
        <span class="pill-blue">{{ reservationsFor(space.id).length }} rezervácií</span>
      </header>

      <EmptyState
        v-if="reservationsFor(space.id).length === 0"
        title="Žiadne nadchádzajúce rezervácie"
      />
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="r in reservationsFor(space.id)" :key="r.id">
          <component
            :is="auth.isMember ? 'button' : 'div'"
            type="button"
            class="w-full py-3 text-left"
            :class="auth.isMember ? '-mx-2 rounded-lg px-2 transition hover:bg-brand-50/50' : ''"
            @click="auth.isMember && (editing = r)"
          >
            <div class="flex items-baseline justify-between gap-3">
              <p class="flex flex-wrap items-center gap-2 font-medium text-slate-800">
                {{ r.customerName ?? '** rezervácia' }}
                <ReservationStatusPill :status="r.status" />
              </p>
              <span class="shrink-0 text-xs text-slate-500">
                {{ formatReservationRange(r.startsAt, r.endsAt) }}
              </span>
            </div>
            <p v-if="r.note" class="mt-1 text-sm text-slate-600">{{ r.note }}</p>
          </component>
        </li>
      </ul>
    </section>
  </div>

  <ReservationEditDialog
    :reservation="editing"
    :resource-label="editingResourceLabel()"
    @close="editing = null"
    @saved="onReservationChanged"
    @deleted="onReservationChanged"
  />
</template>

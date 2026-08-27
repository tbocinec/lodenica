<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { availabilityApi } from '@/api/availability.api';
import { reservationsApi } from '@/api/reservations.api';
import type { DashboardSnapshot, Reservation } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';
import { useResourcesStore } from '@/stores/resources.store';
import PaddlingTrafficLightWidget from '@/components/PaddlingTrafficLightWidget.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ReservationEditDialog from '@/components/ui/ReservationEditDialog.vue';
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
const auth = useAuthStore();
const resources = useResourcesStore();

// Scroll-to targets for the clickable stat cards.
const todayRef = ref<HTMLElement | null>(null);
const damagesRef = ref<HTMLElement | null>(null);
function scrollTo(el: HTMLElement | null): void {
  el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// My reservations (logged-in users only).
const myReservations = ref<Reservation[]>([]);
const editing = ref<Reservation | null>(null);

async function onMyReservationChanged(): Promise<void> {
  editing.value = null;
  await loadMine();
}

function resourceLabel(resourceId: string): string {
  const r = resources.items.find((x) => x.id === resourceId);
  return r ? `${r.identifier} · ${r.name}` : 'Zdroj';
}

async function loadMine() {
  if (!auth.isAuthenticated) return;
  try {
    const [mine] = await Promise.all([
      reservationsApi.mine({ pageSize: 50 }),
      resources.items.length ? Promise.resolve() : resources.fetch(),
    ]);
    myReservations.value = mine.items;
  } catch {
    // Non-fatal for the dashboard — leave the section empty.
  }
}

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

onMounted(() => {
  load();
  loadMine();
});
</script>

<template>
  <PageHeader title="Prehľad" subtitle="Aktuálny stav rezervácií a priestorov.">
    <template #actions>
      <button class="btn-secondary" type="button" @click="load">Obnoviť</button>
      <RouterLink to="/reservations/new" class="btn-primary">Vytvoriť rezerváciu</RouterLink>
    </template>
  </PageHeader>

  <LoadError :message="error" />

  <!-- PENDING accounts see a permanent banner explaining their state.
       Permissions are otherwise identical to anonymous (no names, no
       edits) — see docs/AUTH-AND-PERMISSIONS.md. -->
  <div
    v-if="auth.isPending"
    class="mb-5 rounded-2xl border border-amber-200 bg-amber-50/60 p-5"
  >
    <div class="flex items-start gap-3">
      <span class="text-2xl" aria-hidden="true">⏳</span>
      <div>
        <h2 class="text-base font-semibold text-amber-900">
          Tvoj účet čaká na potvrdenie
        </h2>
        <p class="mt-1 text-sm text-amber-800">
          Registrácia prebehla úspešne, ale ešte sa nemôžeš zúčastniť
          klubových aktivít cez tento systém. Administrátor klubu tvoj
          účet posúdi a po potvrdení budeš môcť rezervovať lode, vidieť
          mená rezervujúcich a upravovať rezervácie.
        </p>
        <p class="mt-2 text-xs text-amber-700">
          V prípade otázok napíš na
          <a class="font-medium underline" href="mailto:rezervacie@lodenicakvs.sk">rezervacie@lodenicakvs.sk</a>.
        </p>
      </div>
    </div>
  </div>

  <!-- My reservations — logged-in users see their own bookings up top. -->
  <section
    v-if="auth.isAuthenticated"
    class="mb-6 card-padded"
  >
    <div class="mb-3 flex items-center justify-between">
      <h2 class="text-lg font-semibold">Moje rezervácie</h2>
      <RouterLink to="/reservations/new" class="btn-secondary text-xs">＋ Nová</RouterLink>
    </div>
    <EmptyState
      v-if="myReservations.length === 0"
      title="Zatiaľ nemáš žiadne rezervácie"
      description="Vytvor si rezerváciu a objaví sa tu."
    />
    <ul v-else class="divide-y divide-slate-100">
      <li
        v-for="r in myReservations"
        :key="r.id"
        class="flex items-center justify-between gap-3 py-3"
      >
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-slate-900">{{ resourceLabel(r.resourceId) }}</p>
          <p class="text-sm text-slate-500">{{ formatReservationRange(r.startsAt, r.endsAt) }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <!-- Only cancelled bookings get a marker; confirmed ones don't
               need a badge. -->
          <span
            v-if="r.status === 'CANCELLED'"
            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200"
          >
            Zrušená
          </span>
          <!-- Editing is confirmed-member only (the API gates it). -->
          <button
            v-if="auth.isMember && r.status !== 'CANCELLED'"
            type="button"
            class="btn-secondary text-xs"
            @click="editing = r"
          >
            Upraviť
          </button>
        </div>
      </li>
    </ul>
  </section>

  <ReservationEditDialog
    :reservation="editing"
    :resource-name="editing ? resourceLabel(editing.resourceId) : undefined"
    @close="editing = null"
    @saved="onMyReservationChanged"
    @deleted="onMyReservationChanged"
  />

  <!-- Top summary: paddling traffic light + key counts side by side on
       desktop (xl), stacked on mobile/tablet (unchanged there). The light
       self-hides if its upstream feed is down; the counts wait for the
       dashboard snapshot. -->
  <div class="mb-6 grid items-stretch gap-4 xl:grid-cols-2">
    <PaddlingTrafficLightWidget />
    <section v-if="snapshot" class="grid grid-cols-2 gap-3">
      <button type="button" class="block w-full text-left" @click="scrollTo(todayRef)">
        <StatCard label="Dnes obsadené" :value="snapshot.occupiedToday.length" tone="amber" />
      </button>
      <button type="button" class="block w-full text-left" @click="scrollTo(damagesRef)">
        <StatCard label="Aktuálne poškodenia" :value="snapshot.totals.openDamages" tone="red" />
      </button>
    </section>
  </div>

  <Spinner v-if="loading && !snapshot" />

  <template v-if="snapshot">
    <section class="grid gap-6 lg:grid-cols-2">
      <div ref="todayRef" class="card-padded scroll-mt-24">
        <h2 class="mb-3 text-lg font-semibold">Dnes obsadené</h2>
        <EmptyState
          v-if="snapshot.occupiedToday.length === 0"
          title="Žiadne rezervácie na dnes"
        />
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="r in snapshot.occupiedToday" :key="r.id">
            <RouterLink
              :to="`/resources/${r.resource.id}`"
              class="-mx-2 flex items-start justify-between gap-3 rounded-lg px-2 py-3 transition hover:bg-slate-50"
            >
              <div>
                <div class="flex items-center gap-2">
                  <ResourceTypeBadge :type="r.resource.type" />
                  <span class="font-mono text-sm font-semibold text-slate-900">{{ r.resource.identifier }}</span>
                  <span class="text-slate-600">{{ r.resource.name }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-500">
                  {{ r.customerName ?? '** meno skryté' }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
                </p>
              </div>
              <span aria-hidden="true" class="text-slate-300">›</span>
            </RouterLink>
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
          <li v-for="r in snapshot.occupiedTomorrow" :key="r.id">
            <RouterLink
              :to="`/resources/${r.resource.id}`"
              class="-mx-2 flex items-start justify-between gap-3 rounded-lg px-2 py-3 transition hover:bg-slate-50"
            >
              <div>
                <div class="flex items-center gap-2">
                  <ResourceTypeBadge :type="r.resource.type" />
                  <span class="font-mono text-sm font-semibold text-slate-900">{{ r.resource.identifier }}</span>
                  <span class="text-slate-600">{{ r.resource.name }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-500">
                  {{ r.customerName ?? '** meno skryté' }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
                </p>
              </div>
              <span aria-hidden="true" class="text-slate-300">›</span>
            </RouterLink>
          </li>
        </ul>
      </div>

      <div class="card-padded">
        <h2 class="mb-3 text-lg font-semibold">Aktuálne dostupné</h2>
        <EmptyState
          v-if="snapshot.available.length === 0"
          title="Momentálne nie sú dostupné žiadne lode"
        />
        <p class="mb-2 text-xs text-slate-500">
          Klikni na loď a otvorí sa rezervačný formulár s predvyplneným zdrojom.
        </p>
        <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <li v-for="r in snapshot.available" :key="r.id">
            <RouterLink
              :to="{ path: '/reservations/new', query: { resourceId: r.id } }"
              class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/40 px-3 py-2 transition hover:border-brand-400 hover:bg-brand-50 hover:shadow-sm"
            >
              <ResourceTypeBadge :type="r.type" />
              <span class="font-mono text-sm font-semibold text-slate-900">{{ r.identifier }}</span>
              <span class="truncate text-slate-600">{{ r.name }}</span>
              <span aria-hidden="true" class="ml-auto text-slate-300">›</span>
            </RouterLink>
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
          <li v-for="r in snapshot.spaceReservations" :key="r.id">
            <RouterLink
              :to="`/resources/${r.resource.id}`"
              class="-mx-2 flex items-start justify-between gap-3 rounded-lg px-2 py-3 transition hover:bg-slate-50"
            >
              <div>
                <p class="font-medium text-slate-800">{{ r.resource.name }}</p>
                <p class="mt-1 text-sm text-slate-500">
                  {{ r.customerName ?? '** meno skryté' }} · {{ formatReservationRange(r.startsAt, r.endsAt) }}
                </p>
              </div>
              <span aria-hidden="true" class="text-slate-300">›</span>
            </RouterLink>
          </li>
        </ul>
      </div>

      <div ref="damagesRef" class="card-padded scroll-mt-24 lg:col-span-2">
        <div class="mb-3 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">Aktuálne poškodenia</h2>
          <RouterLink to="/damages" class="text-sm font-medium text-brand-700 hover:underline">
            Všetky poškodenia →
          </RouterLink>
        </div>
        <EmptyState v-if="snapshot.damaged.length === 0" title="Žiadne aktuálne poškodenia" />
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="d in snapshot.damaged" :key="d.damageId">
            <RouterLink
              :to="`/damages/${d.damageId}`"
              class="-mx-2 flex flex-col gap-1 rounded-lg px-2 py-3 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:gap-4"
            >
              <div class="flex items-center gap-2">
                <span class="pill-amber">{{ DAMAGE_STATUS_LABEL[d.status] }}</span>
                <span class="font-mono text-xs text-slate-500">{{ d.resource.identifier }}</span>
                <span class="text-sm font-medium text-slate-800">
                  {{ RESOURCE_TYPE_LABEL[d.resource.type] }} · {{ d.resource.name }}
                </span>
              </div>
              <p class="text-sm text-slate-600 sm:flex-1">{{ d.description }}</p>
              <span aria-hidden="true" class="hidden text-slate-300 sm:inline">›</span>
            </RouterLink>
          </li>
        </ul>
      </div>
    </section>

    <footer
      class="mt-10 rounded-2xl bg-slate-50 px-6 py-5 text-center text-sm text-slate-600 ring-1 ring-slate-200"
    >
      Ak niečo nefunguje alebo máte návrh na zlepšenie, napíšte na
      <a
        class="font-medium text-brand-700 hover:underline"
        href="mailto:rezervacie@lodenicakvs.sk?subject=Lodenica%20KVS%20%E2%80%94%20feedback"
      >rezervacie@lodenicakvs.sk</a>.
    </footer>
  </template>
</template>

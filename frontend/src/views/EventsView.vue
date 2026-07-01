<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { eventsApi } from '@/api/events.api';
import type { Event } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';
import { formatReservationRange } from '@/utils/format';

const auth = useAuthStore();
const events = ref<Event[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const showPast = ref(false);

// Upcoming = still running or in the future (soonest first); past = already
// ended (most recent first).
const upcoming = computed(() =>
  events.value
    .filter((e) => new Date(e.endsAt).getTime() >= Date.now())
    .sort((a, b) => new Date(a.startsAt).getTime() - new Date(b.startsAt).getTime()),
);
const past = computed(() =>
  events.value
    .filter((e) => new Date(e.endsAt).getTime() < Date.now())
    .sort((a, b) => new Date(b.startsAt).getTime() - new Date(a.startsAt).getTime()),
);

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const data = await eventsApi.list({ pageSize: 200 });
    events.value = data.items;
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
    title="Lodenicné udalosti"
    subtitle="Plánované akcie — splavy, tréningy, brigády. Na udalosť sa dajú zarezervovať lode a prihlásiť účastníci."
  >
    <template #actions>
      <RouterLink v-if="auth.isMember" to="/events/new" class="btn-primary">＋ Nová udalosť</RouterLink>
    </template>
  </PageHeader>

  <LoadError :message="error" />
  <Spinner v-if="loading && !events.length" />

  <EmptyState
    v-else-if="events.length === 0"
    title="Žiadne udalosti"
    description="Vytvor prvú udalosť — pridáš k nej lode a účastníkov."
  >
    <RouterLink v-if="auth.isMember" to="/events/new" class="btn-primary">＋ Nová udalosť</RouterLink>
  </EmptyState>

  <template v-else>
    <!-- Toggle for past events -->
    <div class="mb-3 flex items-center justify-end">
      <label class="flex items-center gap-2 text-sm text-slate-600">
        <input v-model="showPast" type="checkbox" class="h-4 w-4 rounded" />
        Zobraziť minulé udalosti
        <span v-if="past.length" class="text-slate-400">({{ past.length }})</span>
      </label>
    </div>

    <!-- Upcoming / current -->
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Aktuálne a nadchádzajúce</h2>
    <EmptyState v-if="upcoming.length === 0" title="Žiadne aktuálne udalosti" />
    <div v-else class="grid gap-3">
      <RouterLink
        v-for="e in upcoming"
        :key="e.id"
        :to="`/events/${e.id}`"
        class="card-padded flex flex-col gap-1 transition hover:ring-2 hover:ring-brand-200"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="text-lg font-semibold text-slate-900">{{ e.title }}</h3>
          <span class="text-sm text-slate-500">{{ formatReservationRange(e.startsAt, e.endsAt) }}</span>
        </div>
        <p v-if="e.location" class="text-sm text-slate-600">📍 {{ e.location }}</p>
        <p v-if="e.description" class="text-sm text-slate-500 line-clamp-2">{{ e.description }}</p>
      </RouterLink>
    </div>

    <!-- Past (only when toggled on) -->
    <template v-if="showPast">
      <h2 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wide text-slate-500">Minulé udalosti</h2>
      <EmptyState v-if="past.length === 0" title="Žiadne minulé udalosti" />
      <div v-else class="grid gap-3">
        <RouterLink
          v-for="e in past"
          :key="e.id"
          :to="`/events/${e.id}`"
          class="card-padded flex flex-col gap-1 opacity-75 transition hover:opacity-100 hover:ring-2 hover:ring-brand-200"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-lg font-semibold text-slate-900">{{ e.title }}</h3>
            <span class="text-sm text-slate-500">{{ formatReservationRange(e.startsAt, e.endsAt) }}</span>
          </div>
          <p v-if="e.location" class="text-sm text-slate-600">📍 {{ e.location }}</p>
          <p v-if="e.description" class="text-sm text-slate-500 line-clamp-2">{{ e.description }}</p>
        </RouterLink>
      </div>
    </template>
  </template>
</template>

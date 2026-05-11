<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { eventsApi } from '@/api/events.api';
import type { Event } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { formatReservationRange } from '@/utils/format';

const events = ref<Event[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

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
    subtitle="Plánované akcie — splavy, tréningy, regaty. Na udalosť sa dajú zarezervovať lode a prihlásiť účastníci."
  >
    <template #actions>
      <RouterLink to="/events/new" class="btn-primary">＋ Nová udalosť</RouterLink>
    </template>
  </PageHeader>

  <LoadError :message="error" />
  <Spinner v-if="loading && !events.length" />

  <EmptyState
    v-else-if="events.length === 0"
    title="Žiadne udalosti"
    description="Vytvor prvú udalosť — pridáš k nej lode a účastníkov."
  >
    <RouterLink to="/events/new" class="btn-primary">＋ Nová udalosť</RouterLink>
  </EmptyState>

  <div v-else class="grid gap-3">
    <RouterLink
      v-for="e in events"
      :key="e.id"
      :to="`/events/${e.id}`"
      class="card-padded flex flex-col gap-1 transition hover:ring-2 hover:ring-brand-200"
    >
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-lg font-semibold text-slate-900">{{ e.title }}</h2>
        <span class="text-sm text-slate-500">
          {{ formatReservationRange(e.startsAt, e.endsAt) }}
        </span>
      </div>
      <p v-if="e.location" class="text-sm text-slate-600">📍 {{ e.location }}</p>
      <p v-if="e.description" class="text-sm text-slate-500 line-clamp-2">{{ e.description }}</p>
    </RouterLink>
  </div>
</template>

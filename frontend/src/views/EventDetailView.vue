<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter, RouterLink } from 'vue-router';

import { eventsApi } from '@/api/events.api';
import { reservationsApi } from '@/api/reservations.api';
import {
  ReservationStatus,
  ResourceType,
  type Event,
  type EventParticipant,
  type Reservation,
  type Resource,
} from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const id = computed(() => route.params.id as string);

const resources = useResourcesStore();

const event = ref<Event | null>(null);
const reservations = ref<Reservation[]>([]);
const participants = ref<EventParticipant[]>([]);
/** All confirmed reservations overlapping the event window, used for conflict detection. */
const overlapping = ref<Reservation[]>([]);

const loading = ref(false);
const error = ref<string | null>(null);

const newParticipant = reactive({ name: '', contact: '', note: '' });
const addingParticipant = ref(false);

const pickerOpen = ref(false);
const selectedResourceIds = ref<Set<string>>(new Set());
const attaching = ref(false);

const BOAT_TYPES: ResourceType[] = [
  ResourceType.SEA_KAYAK,
  ResourceType.WW_KAYAK,
  ResourceType.CANOE,
  ResourceType.ROWING_BOAT,
  ResourceType.INFLATABLE_BOAT,
];

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const ev = await eventsApi.get(id.value);
    event.value = ev;
    const [rsv, parts, overlap] = await Promise.all([
      reservationsApi.list({ eventId: id.value, pageSize: 200 }),
      eventsApi.listParticipants(id.value),
      reservationsApi.list({
        from: ev.startsAt,
        to: ev.endsAt,
        status: ReservationStatus.CONFIRMED,
        pageSize: 500,
      }),
      resources.fetch(),
    ]);
    reservations.value = rsv.items;
    participants.value = parts;
    overlapping.value = overlap.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

const attachedResourceIds = computed(() => {
  return new Set(
    reservations.value
      .filter((r) => r.status === ReservationStatus.CONFIRMED)
      .map((r) => r.resourceId),
  );
});

/**
 * Resources that are reserved in the event window by a reservation that is
 * NOT part of this event. They cannot be added.
 */
const conflictingResourceIds = computed(() => {
  const set = new Set<string>();
  for (const r of overlapping.value) {
    if (r.eventId !== id.value) set.add(r.resourceId);
  }
  return set;
});

interface PickerRow {
  resource: Resource;
  conflicting: boolean;
}

const pickerRows = computed<PickerRow[]>(() => {
  return resources.items
    .filter((r) => r.isActive)
    .filter((r) => BOAT_TYPES.includes(r.type))
    .filter((r) => !attachedResourceIds.value.has(r.id))
    .map((r) => ({ resource: r, conflicting: conflictingResourceIds.value.has(r.id) }))
    .sort((a, b) => {
      if (a.conflicting !== b.conflicting) return a.conflicting ? 1 : -1;
      if (a.resource.type !== b.resource.type) {
        return a.resource.type.localeCompare(b.resource.type);
      }
      return a.resource.identifier.localeCompare(b.resource.identifier);
    });
});

function togglePicker() {
  pickerOpen.value = !pickerOpen.value;
  if (!pickerOpen.value) selectedResourceIds.value = new Set();
}

function toggleSelection(resourceId: string) {
  const next = new Set(selectedResourceIds.value);
  if (next.has(resourceId)) next.delete(resourceId);
  else next.add(resourceId);
  selectedResourceIds.value = next;
}

async function attachSelected() {
  if (selectedResourceIds.value.size === 0) return;
  attaching.value = true;
  error.value = null;
  try {
    await eventsApi.attachResources(id.value, [...selectedResourceIds.value]);
    selectedResourceIds.value = new Set();
    pickerOpen.value = false;
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    attaching.value = false;
  }
}

async function detachReservation(reservationId: string) {
  if (!confirm('Odobrať loď z udalosti?')) return;
  try {
    await reservationsApi.remove(reservationId);
    reservations.value = reservations.value.filter((r) => r.id !== reservationId);
    overlapping.value = overlapping.value.filter((r) => r.id !== reservationId);
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function addParticipant() {
  if (!newParticipant.name.trim()) return;
  addingParticipant.value = true;
  error.value = null;
  try {
    const created = await eventsApi.addParticipant(id.value, {
      name: newParticipant.name,
      contact: newParticipant.contact || undefined,
      note: newParticipant.note || undefined,
    });
    participants.value.push(created);
    newParticipant.name = '';
    newParticipant.contact = '';
    newParticipant.note = '';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    addingParticipant.value = false;
  }
}

async function removeParticipant(participantId: string) {
  if (!confirm('Odstrániť účastníka?')) return;
  try {
    await eventsApi.removeParticipant(id.value, participantId);
    participants.value = participants.value.filter((p) => p.id !== participantId);
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function removeEvent() {
  if (!confirm('Naozaj zmazať udalosť? Pripojené rezervácie zostanú, len sa od nej odpoja.')) return;
  try {
    await eventsApi.remove(id.value);
    await router.push('/events');
  } catch (e) {
    error.value = (e as Error).message;
  }
}

function reservationOverlapName(resourceId: string): string | null {
  const conflict = overlapping.value.find(
    (r) => r.resourceId === resourceId && r.eventId !== id.value,
  );
  if (!conflict) return null;
  return `${conflict.customerName} · ${formatReservationRange(conflict.startsAt, conflict.endsAt)}`;
}

onMounted(load);
</script>

<template>
  <Spinner v-if="loading && !event" />
  <LoadError :message="error" />

  <template v-if="event">
    <PageHeader :title="event.title" :subtitle="formatReservationRange(event.startsAt, event.endsAt)">
      <template #actions>
        <RouterLink :to="`/events/${event.id}/edit`" class="btn-secondary">Upraviť</RouterLink>
        <button class="btn-secondary text-red-700" type="button" @click="removeEvent">Zmazať</button>
      </template>
    </PageHeader>

    <div v-if="event.location || event.description" class="card-padded mb-6 grid gap-3 sm:grid-cols-2">
      <div v-if="event.location">
        <span class="label">Miesto</span>
        <p class="text-slate-800">📍 {{ event.location }}</p>
      </div>
      <div v-if="event.description" class="sm:col-span-2">
        <span class="label">Popis</span>
        <p class="whitespace-pre-line text-slate-700">{{ event.description }}</p>
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <!-- Reservations linked to this event -->
      <section class="card-padded">
        <header class="mb-3 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-slate-900">Lode na udalosti</h2>
          <button
            type="button"
            class="btn-primary text-xs"
            @click="togglePicker"
          >
            {{ pickerOpen ? 'Zavrieť' : '＋ Pridať lode' }}
          </button>
        </header>

        <!-- Picker -->
        <div
          v-if="pickerOpen"
          class="mb-4 rounded-lg border border-brand-200 bg-brand-50/40 p-3"
        >
          <p class="mb-2 text-xs text-slate-600">
            Vyber lode — termín sa použije podľa udalosti
            ({{ formatReservationRange(event.startsAt, event.endsAt) }}).
            Obsadené lode v tomto čase sú zašednuté.
          </p>

          <EmptyState
            v-if="pickerRows.length === 0"
            title="Žiadne dostupné lode"
            description="Všetky aktívne lode sú už pridané na udalosť alebo neexistujú."
          />

          <ul v-else class="grid max-h-72 gap-1 overflow-auto sm:grid-cols-2">
            <li v-for="row in pickerRows" :key="row.resource.id">
              <label
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-white"
                :class="row.conflicting ? 'cursor-not-allowed opacity-60' : ''"
              >
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-slate-300"
                  :checked="selectedResourceIds.has(row.resource.id)"
                  :disabled="row.conflicting"
                  @change="toggleSelection(row.resource.id)"
                />
                <ResourceTypeBadge :type="row.resource.type" />
                <span class="font-mono text-[11px] text-slate-500">{{ row.resource.identifier }}</span>
                <span class="truncate font-medium text-slate-800">{{ row.resource.name }}</span>
                <span v-if="row.conflicting" class="ml-auto text-[11px] text-red-700">
                  obsadená
                </span>
              </label>
              <p
                v-if="row.conflicting"
                class="pl-7 pr-2 text-[11px] text-slate-500"
                :title="reservationOverlapName(row.resource.id) ?? ''"
              >
                {{ reservationOverlapName(row.resource.id) }}
              </p>
            </li>
          </ul>

          <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="btn-secondary text-xs" @click="togglePicker">
              Zrušiť
            </button>
            <button
              type="button"
              class="btn-primary text-xs"
              :disabled="attaching || selectedResourceIds.size === 0"
              @click="attachSelected"
            >
              {{ attaching ? 'Pridávam…' : `Pridať ${selectedResourceIds.size || ''}` }}
            </button>
          </div>
        </div>

        <EmptyState
          v-if="reservations.length === 0 && !pickerOpen"
          title="Žiadne lode"
          description="Pridaj prvé lode na udalosť cez tlačidlo vyššie."
        />
        <ul v-else-if="reservations.length > 0" class="divide-y divide-slate-100">
          <li
            v-for="r in reservations"
            :key="r.id"
            class="flex items-center gap-2 py-3"
          >
            <ResourceTypeBadge
              v-if="resources.byId.get(r.resourceId)"
              :type="resources.byId.get(r.resourceId)!.type"
            />
            <span class="font-mono text-[11px] text-slate-500">
              {{ resources.byId.get(r.resourceId)?.identifier }}
            </span>
            <span class="truncate font-medium text-slate-800">
              {{ resources.byId.get(r.resourceId)?.name ?? '—' }}
            </span>
            <button
              type="button"
              class="ml-auto rounded-md px-2 py-1 text-xs text-red-700 hover:bg-red-50"
              aria-label="Odobrať loď z udalosti"
              @click="detachReservation(r.id)"
            >
              ✕
            </button>
          </li>
        </ul>

        <p v-if="reservations.length > 0" class="mt-3 text-xs text-slate-500">
          Termín pre všetky lode:
          <strong>{{ formatReservationRange(event.startsAt, event.endsAt) }}</strong>
        </p>
      </section>

      <!-- Participants -->
      <section class="card-padded">
        <header class="mb-3">
          <h2 class="text-lg font-semibold text-slate-900">Účastníci</h2>
          <p class="text-xs text-slate-500">{{ participants.length }} prihlásených</p>
        </header>

        <form class="mb-4 grid gap-2 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="addParticipant">
          <input
            v-model="newParticipant.name"
            class="input"
            placeholder="Meno"
            maxlength="200"
            required
          />
          <input
            v-model="newParticipant.contact"
            class="input"
            placeholder="Kontakt (voliteľné)"
            maxlength="200"
          />
          <button class="btn-primary" type="submit" :disabled="addingParticipant || !newParticipant.name.trim()">
            ＋ Pridať
          </button>
          <textarea
            v-model="newParticipant.note"
            class="input sm:col-span-3"
            rows="2"
            placeholder="Poznámka (voliteľné)"
            maxlength="1000"
          ></textarea>
        </form>

        <EmptyState
          v-if="participants.length === 0"
          title="Zatiaľ nikto"
          description="Pridaj prvého účastníka cez formulár vyššie."
        />
        <ul v-else class="divide-y divide-slate-100">
          <li
            v-for="p in participants"
            :key="p.id"
            class="flex items-start justify-between gap-3 py-3"
          >
            <div class="min-w-0">
              <p class="font-medium text-slate-800">{{ p.name }}</p>
              <p v-if="p.contact" class="text-sm text-slate-600">{{ p.contact }}</p>
              <p v-if="p.note" class="text-xs text-slate-500">{{ p.note }}</p>
            </div>
            <button
              type="button"
              class="btn-secondary text-xs text-red-700"
              aria-label="Odstrániť účastníka"
              @click="removeParticipant(p.id)"
            >
              ✕
            </button>
          </li>
        </ul>
      </section>
    </div>
  </template>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter, RouterLink } from 'vue-router';

import { eventsApi } from '@/api/events.api';
import {
  reservationGoogleCalendarUrl,
  reservationIcsUrl,
  reservationsApi,
} from '@/api/reservations.api';
import { ResourceType, type Event, type Reservation } from '@/api/types';
import AvailabilityHints from '@/components/ui/AvailabilityHints.vue';
import ColorDot from '@/components/ui/ColorDot.vue';
import DateInput from '@/components/ui/DateInput.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { RESOURCE_TYPE_LABEL, RESOURCE_TYPE_LABEL_PLURAL } from '@/i18n/labels';
import { useAuthStore } from '@/stores/auth.store';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange, isoFromDateTime, toIsoDate } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const resources = useResourcesStore();
const auth = useAuthStore();

/**
 * When logged in, the booking defaults to the member themselves — their
 * name + contact prefill the customer fields. Ticking "for someone else"
 * clears them for manual entry. Anonymous visitors always type the name.
 */
const bookingForSomeoneElse = ref(false);

function fillSelf(): void {
  if (auth.user) {
    form.customerName = auth.user.name;
    form.customerContact = auth.user.email;
  }
}

watch(bookingForSomeoneElse, (forOther) => {
  if (forOther) {
    form.customerName = '';
    form.customerContact = '';
  } else {
    fillSelf();
  }
});

const today = toIsoDate(new Date());

// Pre-fill from query params (used by Timeline drag-create or Event detail).
const q = route.query;
const initialResourceId = typeof q.resourceId === 'string' ? q.resourceId : '';
const initialEventId = typeof q.eventId === 'string' ? q.eventId : '';

// `quick=3h` (used by the per-boat QR code): default to a 3-hour window
// starting now (rounded up to the next 15 min), same day.
function quickThreeHours(): { startDate: string; startTime: string; endTime: string } | null {
  if (q.quick !== '3h') return null;
  const pad = (n: number) => String(n).padStart(2, '0');
  const now = new Date();
  now.setMinutes(Math.ceil(now.getMinutes() / 15) * 15, 0, 0);
  let endH = now.getHours() + 3;
  let endM = now.getMinutes();
  if (endH > 23 || (endH === 23 && endM > 59)) {
    endH = 23;
    endM = 59;
  }
  return {
    startDate: toIsoDate(now),
    startTime: `${pad(now.getHours())}:${pad(now.getMinutes())}`,
    endTime: `${pad(endH)}:${pad(endM)}`,
  };
}
const quick = quickThreeHours();

const initialStartDate = typeof q.startDate === 'string' ? q.startDate : (quick?.startDate ?? today);
const initialEndDate = typeof q.endDate === 'string' ? q.endDate : initialStartDate;
const initialStartTime = typeof q.startTime === 'string' ? q.startTime : (quick?.startTime ?? '09:00');
const initialEndTime = typeof q.endTime === 'string' ? q.endTime : (quick?.endTime ?? '12:00');

const form = reactive({
  resourceId: initialResourceId,
  eventId: initialEventId,
  customerName: '',
  customerContact: '',
  startDate: initialStartDate,
  startTime: initialStartTime,
  endDate: initialEndDate,
  endTime: initialEndTime,
  note: '',
});

const error = ref<string | null>(null);
const submitting = ref(false);
// Mandatory club-rules acknowledgement. Resets to false on every
// page open so it's never auto-accepted just because the previous
// booking session had it checked.
const acceptedTerms = ref(false);

/**
 * After a successful POST we switch the form view to a "saved" card
 * with a "Pridať do kalendára" button. Keeping the user on this page
 * (instead of an immediate redirect) lets them add the booking to
 * their phone in one tap — the click target is hot, the slot is fresh
 * in their head.
 */
const createdReservation = ref<Reservation | null>(null);
const icsHref = computed(() =>
  createdReservation.value ? reservationIcsUrl(createdReservation.value.id) : null,
);

/**
 * Google Calendar template URL with the booking pre-filled. Opens
 * directly to the "new event" editor (browser tab on desktop, the
 * native GCal app on Android via Universal Links) — no file download,
 * no manual import step.
 */
const googleCalendarHref = computed(() => {
  if (!createdReservation.value || !selectedResource.value) return null;
  return reservationGoogleCalendarUrl({
    title: `Lodenica KVŠ: ${selectedResource.value.identifier} – ${selectedResource.value.name}`,
    startsAt: createdReservation.value.startsAt,
    endsAt: createdReservation.value.endsAt,
    customerName: createdReservation.value.customerName ?? form.customerName,
    resourceLabel: `${selectedResource.value.identifier} ${selectedResource.value.name}`,
    note: createdReservation.value.note,
  });
});

function finishAndLeave(): void {
  router.push(form.eventId ? `/events/${form.eventId}` : '/reservations');
}

/**
 * Two-step resource picker — first the user picks a TYPE (kajak, kanoe,
 * pramica, …) from visual tiles, then the concrete boat/space inside
 * that type. The legacy `KAYAK` enum value is hidden because the club
 * has migrated all kayaks to SEA_KAYAK / WW_KAYAK.
 */
const pickedType = ref<ResourceType | null>(null);

/** Display order of the tiles — most-used types first. */
const TYPE_ORDER: readonly ResourceType[] = [
  ResourceType.SEA_KAYAK,
  ResourceType.WW_KAYAK,
  ResourceType.CANOE,
  ResourceType.ROWING_BOAT,
  ResourceType.INFLATABLE_BOAT,
  ResourceType.TRAILER,
  ResourceType.BOATHOUSE_SPACE,
];

const TYPE_ICON: Record<ResourceType, string> = {
  KAYAK: '🛶',
  SEA_KAYAK: '🌊',
  WW_KAYAK: '💧',
  CANOE: '🛶',
  ROWING_BOAT: '🚣',
  INFLATABLE_BOAT: '🛟',
  TRAILER: '🚐',
  BOATHOUSE_SPACE: '🏠',
};

const grouped = computed(() => {
  const groups = new Map<ResourceType, typeof resources.items>();
  for (const r of resources.items) {
    if (!r.isActive) continue;
    const list = groups.get(r.type) ?? [];
    list.push(r);
    groups.set(r.type, list);
  }
  return groups;
});

/** Type tiles in the picker — only types that actually have stock. */
const typeTiles = computed(() =>
  TYPE_ORDER
    .filter((t) => (grouped.value.get(t)?.length ?? 0) > 0)
    .map((t) => ({
      type: t,
      label: RESOURCE_TYPE_LABEL_PLURAL[t] ?? RESOURCE_TYPE_LABEL[t],
      icon: TYPE_ICON[t] ?? '📦',
      count: grouped.value.get(t)?.length ?? 0,
    })),
);

/** Resources within the currently-picked type, ordered by identifier. */
const resourcesInPickedType = computed(() => {
  if (!pickedType.value) return [];
  const list = grouped.value.get(pickedType.value) ?? [];
  return [...list].sort((a, b) => a.identifier.localeCompare(b.identifier));
});

/** Free-text filter for the resource grid (step 2). Only matters for
 *  types with enough stock that scrolling is annoying — for the 3-
 *  trailers / 2-spaces types the input is hidden (see `showResourceFilter`). */
const resourceSearch = ref('');
const RESOURCE_FILTER_THRESHOLD = 6;

const showResourceFilter = computed(
  () => resourcesInPickedType.value.length > RESOURCE_FILTER_THRESHOLD,
);

const filteredResourcesInPickedType = computed(() => {
  const all = resourcesInPickedType.value;
  const q = resourceSearch.value.trim().toLowerCase();
  if (!q) return all;
  return all.filter(
    (r) =>
      r.identifier.toLowerCase().includes(q) ||
      r.name.toLowerCase().includes(q) ||
      (r.model ?? '').toLowerCase().includes(q) ||
      (r.color ?? '').toLowerCase().includes(q),
  );
});

const selectedResource = computed(() =>
  resources.items.find((r) => r.id === form.resourceId),
);

/**
 * Global full-text search at the type-tile level — search across ALL active
 * resources (any type) without first picking a category. When the box has
 * text we show matching resources directly instead of the type tiles.
 */
const globalSearch = ref('');
const globalResults = computed(() => {
  const q = globalSearch.value.trim().toLowerCase();
  if (!q) return [];
  return resources.items
    .filter((r) => r.isActive)
    .filter(
      (r) =>
        r.identifier.toLowerCase().includes(q) ||
        r.name.toLowerCase().includes(q) ||
        (r.model ?? '').toLowerCase().includes(q) ||
        (r.color ?? '').toLowerCase().includes(q) ||
        (RESOURCE_TYPE_LABEL[r.type] ?? '').toLowerCase().includes(q) ||
        (RESOURCE_TYPE_LABEL_PLURAL[r.type] ?? '').toLowerCase().includes(q),
    )
    .sort((a, b) => {
      if (a.type !== b.type) return a.type.localeCompare(b.type);
      return a.identifier.localeCompare(b.identifier);
    })
    .slice(0, 50);
});

function pickResourceGlobal(id: string, type: ResourceType): void {
  pickedType.value = type;
  form.resourceId = id;
  globalSearch.value = '';
}

function pickType(t: ResourceType): void {
  pickedType.value = t;
  // Clear any previously-picked resource so the user has to make a fresh
  // choice; the resource grid handles selection below.
  form.resourceId = '';
  resourceSearch.value = '';
}

function pickResource(id: string): void {
  form.resourceId = id;
}

function changeType(): void {
  pickedType.value = null;
  form.resourceId = '';
  resourceSearch.value = '';
}

function changeResource(): void {
  // Keep the picked type so the user just sees the same grid again.
  form.resourceId = '';
}

/**
 * ISO `startsAt` + `endsAt` composed from form fields. Used both for
 * validation hints and to preview the slot in AvailabilityHints.
 */
const composed = computed(() => ({
  startsAt: isoFromDateTime(form.startDate, form.startTime),
  endsAt: isoFromDateTime(form.endDate, form.endTime),
}));

const rangeIsValid = computed(() => composed.value.endsAt > composed.value.startsAt);
const isMultiDay = computed(() => form.startDate !== form.endDate);

// Keep endDate >= startDate while the user adjusts startDate.
watch(
  () => form.startDate,
  (newStart, oldStart) => {
    if (form.endDate < newStart) form.endDate = newStart;
    // Carry the user along when they kept end == start before.
    if (oldStart && form.endDate === oldStart) form.endDate = newStart;
  },
);

function applyPreset(preset: 'threeHours' | 'morning' | 'afternoon' | 'fullDay'): void {
  const date = form.startDate;
  switch (preset) {
    case 'threeHours': {
      // start + 3h on the same day, clamped at 23:59 so a 21:00 start
      // doesn't quietly roll over midnight.
      form.endDate = date;
      const [h, m] = form.startTime.split(':').map(Number) as [number, number];
      const totalMin = Math.min(h * 60 + m + 3 * 60, 23 * 60 + 59);
      const endH = Math.floor(totalMin / 60);
      const endM = totalMin % 60;
      form.endTime = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
      return;
    }
    case 'morning':
      form.endDate = date;
      form.startTime = '08:00';
      form.endTime = '14:00';
      return;
    case 'afternoon':
      form.endDate = date;
      form.startTime = '14:00';
      form.endTime = '20:00';
      return;
    case 'fullDay': {
      // Whole day as a half-open range [00:00 today, 00:00 tomorrow). This
      // keeps both times on the 15-min step the inputs enforce (00:01/23:59
      // are NOT valid for step=900, which silently blocked the submit), and
      // the half-open end at midnight means a next-day booking can start at
      // 00:00 without overlapping.
      form.startTime = '00:00';
      form.endTime = '00:00';
      const next = new Date(`${date}T00:00:00.000Z`);
      next.setUTCDate(next.getUTCDate() + 1);
      form.endDate = toIsoDate(next);
      return;
    }
  }
}

async function submit(): Promise<void> {
  if (!form.resourceId) {
    error.value = 'Vyber zdroj (typ a konkrétny kus).';
    return;
  }
  if (!rangeIsValid.value) {
    error.value = 'Koniec rezervácie musí byť po jej začiatku.';
    return;
  }
  if (!acceptedTerms.value) {
    error.value = 'Pre vytvorenie rezervácie potvrď súhlas s pravidlami a lodeničným poriadkom.';
    return;
  }
  error.value = null;
  submitting.value = true;
  try {
    const created = await reservationsApi.create({
      resourceId: form.resourceId,
      eventId: form.eventId || undefined,
      customerName: form.customerName,
      customerContact: form.customerContact || undefined,
      startsAt: composed.value.startsAt,
      endsAt: composed.value.endsAt,
      note: form.note || undefined,
    });
    // Stay on the page and show the success card so the user can tap
    // "Pridať do kalendára" without losing context. finishAndLeave()
    // navigates away once they're done.
    createdReservation.value = created;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

function pickDay(iso: string): void {
  // Move the booking to the picked day, preserving the original duration in
  // calendar days (so a 3-day booking stays 3 days when the user re-anchors).
  const dayMs = 24 * 60 * 60 * 1000;
  const oldStart = new Date(`${form.startDate}T00:00:00.000Z`).getTime();
  const oldEnd = new Date(`${form.endDate}T00:00:00.000Z`).getTime();
  const lengthDays = Math.max(0, Math.round((oldEnd - oldStart) / dayMs));
  form.startDate = iso;
  const newEnd = new Date(`${iso}T00:00:00.000Z`);
  newEnd.setUTCDate(newEnd.getUTCDate() + lengthDays);
  form.endDate = toIsoDate(newEnd);
}

function pickRange(startHour: string, endHour: string): void {
  // Multi-hour drag → set both bounds on the picked day. Both come as
  // already-padded "HH:MM" strings. We deliberately don't preserve any
  // previous duration here — the gesture itself defined the duration.
  form.endDate = form.startDate;
  form.startTime = startHour;
  form.endTime = endHour;
}

function pickHour(hour: string): void {
  // Hour-pick implies a same-day window; preserve duration but cap inside the
  // 06–22 visible window.
  const [oldStartH, oldStartM] = form.startTime.split(':').map(Number) as [number, number];
  const [oldEndH, oldEndM] = form.endTime.split(':').map(Number) as [number, number];
  const durationMin = Math.max(60, (oldEndH - oldStartH) * 60 + (oldEndM - oldStartM));
  form.endDate = form.startDate;
  form.startTime = hour;
  const [newH, newM] = hour.split(':').map(Number) as [number, number];
  let endMinutes = newH * 60 + newM + durationMin;
  if (endMinutes > 22 * 60) endMinutes = 22 * 60;
  const endH = Math.floor(endMinutes / 60);
  const endM = endMinutes % 60;
  form.endTime = `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}

const linkedEvent = ref<Event | null>(null);

onMounted(async () => {
  // Default the booking to the logged-in member (they can switch to
  // "for someone else"). Don't clobber a name already passed in.
  if (auth.isAuthenticated && !form.customerName) {
    fillSelf();
  }

  await resources.fetch();
  // Pre-fill from ?resourceId=… (timeline drag-create, event detail).
  // Surface the type tile so "Iný kus" returns to the right grid.
  if (form.resourceId) {
    const pre = resources.items.find((r) => r.id === form.resourceId);
    if (pre) pickedType.value = pre.type;
  }
  if (form.eventId) {
    try {
      linkedEvent.value = await eventsApi.get(form.eventId);
    } catch (e) {
      // Non-fatal — show the form even if the event lookup fails.
      console.warn('Failed to load linked event', e);
    }
  }
});
</script>

<template>
  <PageHeader
    title="Vytvoriť rezerváciu"
    subtitle="Vyber zdroj a presný termín — od dátumu+času po dátum+čas. Konflikty sú overené automaticky."
  />

  <div
    v-if="linkedEvent"
    class="mb-3 flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-brand-200 bg-brand-50/60 px-4 py-3"
  >
    <div>
      <p class="text-xs uppercase tracking-wide text-brand-700">Rezervácia patrí k udalosti</p>
      <p class="font-semibold text-brand-900">{{ linkedEvent.title }}</p>
      <p class="text-xs text-brand-700">
        {{ formatReservationRange(linkedEvent.startsAt, linkedEvent.endsAt) }}
      </p>
    </div>
    <RouterLink :to="`/events/${linkedEvent.id}`" class="btn-secondary text-xs">
      Otvoriť udalosť
    </RouterLink>
  </div>

  <!-- Success card: shown after a successful POST so the user can add
       the booking to their PERSONAL calendar (not the boathouse one).
       Two options: Google Calendar (opens editor directly, no download)
       and .ics (Apple Calendar, Outlook, Thunderbird). -->
  <div
    v-if="createdReservation"
    class="card-padded grid gap-4 border border-emerald-200 bg-emerald-50/40"
  >
    <div class="flex items-start gap-3">
      <span class="text-3xl" aria-hidden="true">✅</span>
      <div class="flex-1">
        <h2 class="text-lg font-semibold text-emerald-900">Rezervácia vytvorená</h2>
        <p class="mt-1 text-sm text-emerald-800">
          {{ formatReservationRange(createdReservation.startsAt, createdReservation.endsAt) }}
          <template v-if="selectedResource">
            · {{ selectedResource.identifier }} · {{ selectedResource.name }}
          </template>
        </p>
      </div>
    </div>

    <div class="border-t border-emerald-200 pt-3">
      <p class="text-sm font-medium text-emerald-900">
        Chceš si rezerváciu uložiť do osobného kalendára?
      </p>
      <p class="mt-1 text-xs text-emerald-700">
        Pridá sa do TVOJHO mobilného / desktop kalendára (nie do klubového rozvrhu).
        Miesto: <strong>Klub vodných športov Karlova Ves</strong>, Botanická 20/59, 841 04 Bratislava-Karlova Ves.
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <a
          v-if="googleCalendarHref"
          :href="googleCalendarHref"
          target="_blank"
          rel="noopener noreferrer"
          class="btn-primary"
        >
          📅 Google Calendar
        </a>
        <a
          v-if="icsHref"
          :href="icsHref"
          class="btn-secondary"
          :download="`rezervacia-${createdReservation.id.slice(0, 8)}.ics`"
        >
          🍎 Apple Calendar / .ics
        </a>
      </div>
    </div>

    <div class="flex justify-end pt-2">
      <button type="button" class="btn-secondary" @click="finishAndLeave">Hotovo</button>
    </div>
  </div>

  <form v-else class="card-padded grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
    <!-- ────────────────────────────────────────────────────────────
         Step 1: pick a TYPE (tiles).
         Step 2: pick a concrete resource of that type (grid).
         When a resource is selected, show a compact summary + "Zmeniť".
         ──────────────────────────────────────────────────────────── -->
    <div class="sm:col-span-2">
      <p class="label mb-2">Zdroj *</p>

      <!-- Picked state — compact summary + change buttons. -->
      <div
        v-if="selectedResource"
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50/60 px-4 py-3"
      >
        <div class="flex items-center gap-3">
          <span class="text-2xl" aria-hidden="true">{{ TYPE_ICON[selectedResource.type] ?? '📦' }}</span>
          <div>
            <p class="text-xs uppercase tracking-wide text-emerald-700">
              {{ RESOURCE_TYPE_LABEL[selectedResource.type] }}
            </p>
            <p class="font-semibold text-emerald-900">
              {{ selectedResource.identifier }} · {{ selectedResource.name }}
            </p>
          </div>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary text-xs" @click="changeResource">
            Iný kus
          </button>
          <button type="button" class="btn-secondary text-xs" @click="changeType">
            Iný typ
          </button>
        </div>
      </div>

      <!-- Step 1: search across everything OR pick a TYPE. -->
      <div v-else-if="!pickedType" class="space-y-3">
        <!-- Global full-text search across all resources (any type). -->
        <div class="relative">
          <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
          <input
            v-model="globalSearch"
            type="search"
            class="input pl-8"
            placeholder="Hľadať loď naprieč všetkými kategóriami — ID, názov, model, farba, typ…"
            maxlength="60"
          />
        </div>

        <!-- Search results (across all types). -->
        <template v-if="globalSearch.trim()">
          <p
            v-if="globalResults.length === 0"
            class="rounded-lg bg-slate-50 px-3 py-3 text-center text-sm text-slate-500"
          >
            Nič nezodpovedá hľadaniu „<strong>{{ globalSearch }}</strong>“.
          </p>
          <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <button
              v-for="r in globalResults"
              :key="r.id"
              type="button"
              class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-left transition hover:border-brand-400 hover:bg-brand-50 hover:shadow-sm"
              @click="pickResourceGlobal(r.id, r.type)"
            >
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-slate-900">
                  <span aria-hidden="true">{{ TYPE_ICON[r.type] ?? '📦' }}</span>
                  {{ r.identifier }}
                </p>
                <p class="truncate text-xs text-slate-500">{{ r.name }}</p>
                <p class="flex items-center gap-1 truncate text-xs text-slate-400">
                  <span>{{ RESOURCE_TYPE_LABEL[r.type] }}</span>
                  <template v-if="r.color"><span>·</span><ColorDot :color="r.color" :size="11" /></template>
                </p>
              </div>
              <span aria-hidden="true" class="text-slate-300">›</span>
            </button>
          </div>
        </template>

        <!-- Type tiles (when not searching). -->
        <div v-else class="grid gap-3 sm:grid-cols-3">
          <button
            v-for="tile in typeTiles"
            :key="tile.type"
            type="button"
            class="flex flex-col items-center gap-1 rounded-xl border border-slate-200 bg-white px-4 py-5 text-center transition hover:border-brand-400 hover:bg-brand-50 hover:shadow-sm"
            @click="pickType(tile.type)"
          >
            <span class="text-3xl" aria-hidden="true">{{ tile.icon }}</span>
            <span class="text-sm font-medium text-slate-900">{{ tile.label }}</span>
            <span class="text-xs text-slate-500">{{ tile.count }} k dispozícii</span>
          </button>
        </div>
      </div>

      <!-- Step 2: pick CONCRETE resource. -->
      <div v-else class="space-y-3">
        <div class="flex items-center justify-between gap-3">
          <p class="text-sm text-slate-700">
            <span aria-hidden="true">{{ TYPE_ICON[pickedType] ?? '📦' }}</span>
            <strong class="ml-1">{{ RESOURCE_TYPE_LABEL_PLURAL[pickedType] }}</strong>
            <span class="text-slate-500"> — vyber konkrétny kus:</span>
          </p>
          <button type="button" class="btn-secondary text-xs" @click="changeType">
            ← Zmeniť typ
          </button>
        </div>

        <!-- Inline filter: only when there's enough stock to warrant it
             (e.g. 18 sea kayaks). Stays out of the way for 3-trailer
             types where scrolling isn't a problem. -->
        <div v-if="showResourceFilter" class="relative">
          <span
            aria-hidden="true"
            class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400"
          >🔍</span>
          <input
            v-model="resourceSearch"
            type="search"
            class="input pl-8 text-sm"
            :placeholder="`Filtrovať ${resourcesInPickedType.length} ks — ID, názov, model, farba…`"
            maxlength="60"
          />
        </div>

        <p
          v-if="filteredResourcesInPickedType.length === 0"
          class="rounded-lg bg-slate-50 px-3 py-3 text-center text-sm text-slate-500"
        >
          Žiadny zdroj nezodpovedá filtru „<strong>{{ resourceSearch }}</strong>“.
        </p>

        <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <button
            v-for="r in filteredResourcesInPickedType"
            :key="r.id"
            type="button"
            class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-left transition hover:border-brand-400 hover:bg-brand-50 hover:shadow-sm"
            @click="pickResource(r.id)"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-slate-900">{{ r.identifier }}</p>
              <p class="truncate text-xs text-slate-500">{{ r.name }}</p>
              <p v-if="r.seats || r.color" class="flex items-center gap-1 truncate text-xs text-slate-400">
                <span v-if="r.seats">{{ r.seats }}-miestny</span>
                <span v-if="r.seats && r.color">·</span>
                <ColorDot v-if="r.color" :color="r.color" :size="11" />
              </p>
            </div>
            <span aria-hidden="true" class="text-slate-300">›</span>
          </button>
        </div>
      </div>

      <!-- Hidden mirror so HTML5 form validation still considers the
           field required even though we render no <select>. -->
      <input type="hidden" :value="form.resourceId" required />
    </div>

    <fieldset class="sm:col-span-2 rounded-lg border border-slate-200 p-4">
      <legend class="px-1 text-sm font-semibold text-slate-700">Rezervácia pre</legend>

      <!-- Logged-in members book for themselves by default; tick this to
           book on behalf of someone else (different name + contact). -->
      <label
        v-if="auth.isAuthenticated"
        class="mb-3 flex items-center gap-2 text-sm text-slate-700"
      >
        <input v-model="bookingForSomeoneElse" type="checkbox" class="h-4 w-4 rounded" />
        Rezervujem pre niekoho iného (iné meno a kontakt)
      </label>

      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="label" for="name">
            Meno <span class="text-rose-700">*</span>
            <span class="text-slate-400">**</span>
          </label>
          <input
            id="name"
            v-model="form.customerName"
            class="input mt-1"
            required
            maxlength="200"
          />
        </div>
        <div>
          <label class="label" for="contact">
            Kontakt (e-mail alebo telefón)
            <span class="text-slate-400">**</span>
          </label>
          <input
            id="contact"
            v-model="form.customerContact"
            class="input mt-1"
            maxlength="200"
          />
        </div>
      </div>
      <!-- Visibility legend. Per docs/AUTH-AND-PERMISSIONS.md both the
           name AND the contact are private to confirmed members —
           anonymous + PENDING viewers see "**" in their place. -->
      <p class="mt-3 text-xs text-slate-500">
        <span class="text-rose-700">*</span> Povinné pole. &nbsp;
        <span class="text-slate-400">**</span> Meno aj kontakt sú viditeľné
        <strong>len pre prihlásených členov klubu</strong>.
      </p>
    </fieldset>

    <fieldset class="sm:col-span-2 rounded-lg border border-slate-200 p-4">
      <legend class="px-1 text-sm font-semibold text-slate-700">Termín</legend>

      <div class="mb-3 flex flex-wrap gap-2">
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('threeHours')">+3 hodiny</button>
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('morning')">Doobeda 08:00–14:00</button>
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('afternoon')">Poobede 14:00–20:00</button>
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('fullDay')">Celý deň (00:00–24:00)</button>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="label" for="start-date">Od dátum *</label>
          <DateInput id="start-date" v-model="form.startDate" class="mt-1" required />
        </div>
        <div>
          <label class="label" for="start-time">Od čas *</label>
          <input
            id="start-time"
            v-model="form.startTime"
            type="time"
            class="input mt-1"
            step="900"
            required
          />
        </div>
        <div>
          <label class="label" for="end-date">Do dátum *</label>
          <DateInput
            id="end-date"
            v-model="form.endDate"
            class="mt-1"
            :min="form.startDate"
            required
          />
        </div>
        <div>
          <label class="label" for="end-time">Do čas *</label>
          <input
            id="end-time"
            v-model="form.endTime"
            type="time"
            class="input mt-1"
            step="900"
            required
          />
        </div>
      </div>

      <p class="mt-3 text-xs" :class="rangeIsValid ? 'text-slate-500' : 'font-medium text-red-700'">
        <template v-if="!rangeIsValid">⚠ Koniec musí byť po začiatku.</template>
        <template v-else-if="isMultiDay">
          Viacdňová rezervácia — drží zdroj od {{ form.startDate }} {{ form.startTime }} do
          {{ form.endDate }} {{ form.endTime }}.
        </template>
        <template v-else>
          Rezervácia v rámci jedného dňa. Hneď po skončení môže nasledovať iná
          (handover na rovnakej hodine je povolený).
        </template>
      </p>

      <div v-if="form.resourceId" class="mt-4 border-t border-slate-200 pt-4">
        <AvailabilityHints
          :resource-id="form.resourceId"
          :date="form.startDate"
          :start-time="form.startTime"
          :end-time="form.endTime"
          @pick-day="pickDay"
          @pick-hour="pickHour"
          @pick-range="pickRange"
        />
      </div>
    </fieldset>

    <div class="sm:col-span-2">
      <label class="label" for="note">Poznámka</label>
      <textarea
        id="note"
        v-model="form.note"
        class="input mt-1"
        rows="3"
        maxlength="1000"
      ></textarea>
    </div>

    <!-- Mandatory club-rules acknowledgement. We force it as a fresh
         opt-in on every booking (no localStorage memoisation) so a
         person who skim-reads the rules once still has to consciously
         tick the box each time they book. -->
    <label
      class="sm:col-span-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/40 p-3 text-sm text-slate-700"
    >
      <input
        v-model="acceptedTerms"
        type="checkbox"
        class="mt-0.5 h-4 w-4 rounded"
        required
      />
      <span>
        Súhlasím s
        <RouterLink to="/rules" target="_blank" class="font-medium text-brand-700 hover:underline">
          Pravidlami rezervácie
        </RouterLink>
        a som si vedomý/á
        <a
          href="https://www.lodenicakvs.sk/?page_id=4578"
          target="_blank"
          rel="noopener noreferrer"
          class="font-medium text-brand-700 hover:underline"
        >
          Lodeničného poriadku KVŠ
        </a>.
        <span class="text-rose-700">*</span>
      </span>
    </label>

    <LoadError class="sm:col-span-2" :message="error" />

    <div class="sm:col-span-2 flex flex-wrap items-center justify-end gap-2">
      <span v-if="selectedResource" class="mr-auto text-xs text-slate-500">
        Vybraný zdroj:
        <strong>{{ selectedResource.identifier }} · {{ selectedResource.name }}</strong>
      </span>
      <button type="button" class="btn-secondary" @click="$router.back()">Zrušiť</button>
      <button
        type="submit"
        class="btn-primary"
        :disabled="submitting || !rangeIsValid || !form.resourceId || !acceptedTerms"
      >
        {{ submitting ? 'Ukladám…' : 'Vytvoriť rezerváciu' }}
      </button>
    </div>
  </form>
</template>

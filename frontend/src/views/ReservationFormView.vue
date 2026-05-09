<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import { ResourceType } from '@/api/types';
import AvailabilityHints from '@/components/ui/AvailabilityHints.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import { isoFromDateTime, toIsoDate } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const resources = useResourcesStore();

const today = toIsoDate(new Date());

// Pre-fill from query params (used by Timeline drag-create).
const q = route.query;
const initialResourceId = typeof q.resourceId === 'string' ? q.resourceId : '';
const initialStartDate = typeof q.startDate === 'string' ? q.startDate : today;
const initialEndDate = typeof q.endDate === 'string' ? q.endDate : initialStartDate;
const initialStartTime = typeof q.startTime === 'string' ? q.startTime : '09:00';
const initialEndTime = typeof q.endTime === 'string' ? q.endTime : '12:00';

const form = reactive({
  resourceId: initialResourceId,
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

const grouped = computed(() => {
  const groups = new Map<ResourceType, typeof resources.items>();
  for (const r of resources.items) {
    if (!r.isActive) continue;
    const list = groups.get(r.type) ?? [];
    list.push(r);
    groups.set(r.type, list);
  }
  return [...groups.entries()];
});

const selectedResource = computed(() =>
  resources.items.find((r) => r.id === form.resourceId),
);

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

function applyPreset(preset: 'oneHour' | 'morning' | 'afternoon' | 'fullDay'): void {
  const date = form.startDate;
  switch (preset) {
    case 'oneHour': {
      form.endDate = date;
      const [h, m] = form.startTime.split(':').map(Number) as [number, number];
      const endH = Math.min(h + 1, 23);
      form.endTime = `${String(endH).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
      return;
    }
    case 'morning':
      form.endDate = date;
      form.startTime = '08:00';
      form.endTime = '12:00';
      return;
    case 'afternoon':
      form.endDate = date;
      form.startTime = '13:00';
      form.endTime = '18:00';
      return;
    case 'fullDay': {
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
  if (!rangeIsValid.value) {
    error.value = 'Koniec rezervácie musí byť po jej začiatku.';
    return;
  }
  error.value = null;
  submitting.value = true;
  try {
    await reservationsApi.create({
      resourceId: form.resourceId,
      customerName: form.customerName,
      customerContact: form.customerContact || undefined,
      startsAt: composed.value.startsAt,
      endsAt: composed.value.endsAt,
      note: form.note || undefined,
    });
    await router.push('/reservations');
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

onMounted(() => resources.fetch());
</script>

<template>
  <PageHeader
    title="Vytvoriť rezerváciu"
    subtitle="Vyber zdroj a presný termín — od dátumu+času po dátum+čas. Konflikty sú overené automaticky."
  />

  <form class="card-padded grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
    <div class="sm:col-span-2">
      <label class="label" for="resource">Zdroj *</label>
      <select id="resource" v-model="form.resourceId" class="input mt-1" required>
        <option value="" disabled>Vyber zdroj…</option>
        <optgroup
          v-for="[type, list] in grouped"
          :key="type"
          :label="RESOURCE_TYPE_LABEL[type]"
        >
          <option v-for="r in list" :key="r.id" :value="r.id">
            {{ r.identifier }} · {{ r.name }}
          </option>
        </optgroup>
      </select>
    </div>

    <div>
      <label class="label" for="name">Meno *</label>
      <input
        id="name"
        v-model="form.customerName"
        class="input mt-1"
        required
        maxlength="200"
      />
    </div>
    <div>
      <label class="label" for="contact">Kontakt (e-mail alebo telefón)</label>
      <input
        id="contact"
        v-model="form.customerContact"
        class="input mt-1"
        maxlength="200"
      />
    </div>

    <fieldset class="sm:col-span-2 rounded-lg border border-slate-200 p-4">
      <legend class="px-1 text-sm font-semibold text-slate-700">Termín</legend>

      <div class="mb-3 flex flex-wrap gap-2">
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('oneHour')">+1 hodina</button>
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('morning')">Doobeda 08:00–12:00</button>
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('afternoon')">Poobede 13:00–18:00</button>
        <button type="button" class="btn-secondary text-xs" @click="applyPreset('fullDay')">Celý deň</button>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="label" for="start-date">Od dátum *</label>
          <input id="start-date" v-model="form.startDate" type="date" class="input mt-1" required />
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
          <input
            id="end-date"
            v-model="form.endDate"
            type="date"
            class="input mt-1"
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

    <LoadError class="sm:col-span-2" :message="error" />

    <div class="sm:col-span-2 flex flex-wrap items-center justify-end gap-2">
      <span v-if="selectedResource" class="mr-auto text-xs text-slate-500">
        Vybraný zdroj:
        <strong>{{ selectedResource.identifier }} · {{ selectedResource.name }}</strong>
      </span>
      <button type="button" class="btn-secondary" @click="$router.back()">Zrušiť</button>
      <button type="submit" class="btn-primary" :disabled="submitting || !rangeIsValid">
        {{ submitting ? 'Ukladám…' : 'Vytvoriť rezerváciu' }}
      </button>
    </div>
  </form>
</template>

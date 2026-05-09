<script setup lang="ts">
/**
 * Modal dialog for editing or deleting an existing reservation.
 *
 * Opens when `reservation` prop becomes non-null. Loads the reservation
 * fields into a local form, lets the user edit time, name, contact and
 * note, and exposes Save / Delete buttons. Resource is intentionally
 * read-only — moving a reservation between resources is rare and would
 * normally be done by deleting and recreating.
 *
 *   <ReservationEditDialog
 *     :reservation="selected"
 *     :resource-name="..."
 *     @close="selected = null"
 *     @saved="onSaved"
 *     @deleted="onDeleted"
 *   />
 */
import { reactive, ref, watch } from 'vue';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import { isoFromDateTime } from '@/utils/format';

import LoadError from './LoadError.vue';

const props = defineProps<{
  reservation: Reservation | null;
  resourceName?: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', updated: Reservation): void;
  (e: 'deleted', id: string): void;
}>();

const form = reactive({
  customerName: '',
  customerContact: '',
  startDate: '',
  startTime: '',
  endDate: '',
  endTime: '',
  note: '',
});

const error = ref<string | null>(null);
const submitting = ref(false);
const deleting = ref(false);

watch(
  () => props.reservation,
  (r) => {
    if (!r) return;
    error.value = null;
    const start = new Date(r.startsAt);
    const end = new Date(r.endsAt);
    form.customerName = r.customerName;
    form.customerContact = r.customerContact ?? '';
    form.startDate = utcDate(start);
    form.startTime = utcTime(start);
    form.endDate = utcDate(end);
    form.endTime = utcTime(end);
    form.note = r.note ?? '';
  },
  { immediate: true },
);

function utcDate(d: Date): string {
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
}
function utcTime(d: Date): string {
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}
function pad(n: number): string {
  return String(n).padStart(2, '0');
}

async function save(): Promise<void> {
  if (!props.reservation) return;
  const startsAt = isoFromDateTime(form.startDate, form.startTime);
  const endsAt = isoFromDateTime(form.endDate, form.endTime);
  if (endsAt <= startsAt) {
    error.value = 'Koniec musí byť po začiatku.';
    return;
  }
  error.value = null;
  submitting.value = true;
  try {
    const updated = await reservationsApi.update(props.reservation.id, {
      customerName: form.customerName,
      customerContact: form.customerContact || undefined,
      startsAt,
      endsAt,
      note: form.note || undefined,
    });
    emit('saved', updated);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

async function remove(): Promise<void> {
  if (!props.reservation) return;
  if (!confirm('Naozaj vymazať túto rezerváciu? Akcia sa nedá vrátiť.')) return;
  error.value = null;
  deleting.value = true;
  try {
    const id = props.reservation.id;
    await reservationsApi.remove(id);
    emit('deleted', id);
  } catch (e) {
    error.value = (e as Error).message;
    deleting.value = false;
  }
}
</script>

<template>
  <div
    v-if="reservation"
    class="fixed inset-0 z-40 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="emit('close')"
  >
    <div class="w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
      <header class="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 class="text-lg font-semibold text-slate-900">Upraviť rezerváciu</h3>
          <p v-if="resourceName" class="text-sm text-slate-500">{{ resourceName }}</p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
          aria-label="Zavrieť"
          @click="emit('close')"
        >
          ✕
        </button>
      </header>

      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
        <div class="sm:col-span-2">
          <label class="label" for="ed-name">Meno *</label>
          <input
            id="ed-name"
            v-model="form.customerName"
            class="input mt-1"
            required
            maxlength="200"
          />
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="ed-contact">Kontakt</label>
          <input
            id="ed-contact"
            v-model="form.customerContact"
            class="input mt-1"
            maxlength="200"
          />
        </div>

        <div>
          <label class="label" for="ed-sd">Od dátum *</label>
          <input id="ed-sd" v-model="form.startDate" type="date" class="input mt-1" required />
        </div>
        <div>
          <label class="label" for="ed-st">Od čas *</label>
          <input
            id="ed-st"
            v-model="form.startTime"
            type="time"
            step="900"
            class="input mt-1"
            required
          />
        </div>
        <div>
          <label class="label" for="ed-ed">Do dátum *</label>
          <input
            id="ed-ed"
            v-model="form.endDate"
            type="date"
            class="input mt-1"
            :min="form.startDate"
            required
          />
        </div>
        <div>
          <label class="label" for="ed-et">Do čas *</label>
          <input
            id="ed-et"
            v-model="form.endTime"
            type="time"
            step="900"
            class="input mt-1"
            required
          />
        </div>

        <div class="sm:col-span-2">
          <label class="label" for="ed-note">Poznámka</label>
          <textarea
            id="ed-note"
            v-model="form.note"
            class="input mt-1"
            rows="2"
            maxlength="1000"
          ></textarea>
        </div>

        <LoadError class="sm:col-span-2" :message="error" />

        <div class="sm:col-span-2 mt-2 flex flex-wrap items-center justify-between gap-2">
          <button
            type="button"
            class="btn-danger"
            :disabled="deleting || submitting"
            @click="remove"
          >
            {{ deleting ? 'Mažem…' : '🗑 Vymazať' }}
          </button>
          <div class="flex gap-2">
            <button type="button" class="btn-secondary" @click="emit('close')">
              Zavrieť
            </button>
            <button type="submit" class="btn-primary" :disabled="submitting || deleting">
              {{ submitting ? 'Ukladám…' : 'Uložiť zmeny' }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

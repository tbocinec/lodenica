<script setup lang="ts">
/**
 * Approve / Reject with an optional note, shared by the approvals page and
 * the reservation dialog. Emits the updated reservation and refreshes the
 * approvals store so badges and lists follow.
 */
import { ref } from 'vue';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import { useApprovalsStore } from '@/stores/approvals.store';

import LoadError from './LoadError.vue';

const props = defineProps<{ reservation: Reservation }>();
const emit = defineEmits<{ (e: 'decided', updated: Reservation): void }>();

const approvals = useApprovalsStore();
const note = ref('');
const busy = ref<'approve' | 'reject' | null>(null);
const error = ref<string | null>(null);

async function decide(kind: 'approve' | 'reject'): Promise<void> {
  error.value = null;
  busy.value = kind;
  try {
    const trimmed = note.value.trim() || undefined;
    const updated =
      kind === 'approve'
        ? await reservationsApi.approve(props.reservation.id, trimmed)
        : await reservationsApi.reject(props.reservation.id, trimmed);
    note.value = '';
    emit('decided', updated);
    void approvals.refresh();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = null;
  }
}
</script>

<template>
  <div class="grid gap-2">
    <label class="label" :for="`decision-note-${reservation.id}`">
      Poznámka pre rezervujúceho <span class="font-normal text-slate-400">(nepovinná)</span>
    </label>
    <textarea
      :id="`decision-note-${reservation.id}`"
      v-model="note"
      class="input"
      rows="2"
      maxlength="1000"
      placeholder="Napr. dôvod zamietnutia alebo pokyny k prevzatiu…"
    ></textarea>
    <LoadError :message="error" />
    <div class="flex flex-wrap justify-end gap-2">
      <button type="button" class="btn-danger" :disabled="busy !== null" data-testid="reject" @click="decide('reject')">
        {{ busy === 'reject' ? 'Zamietam…' : '✕ Zamietnuť' }}
      </button>
      <button type="button" class="btn-primary" :disabled="busy !== null" data-testid="approve" @click="decide('approve')">
        {{ busy === 'approve' ? 'Schvaľujem…' : '✓ Schváliť' }}
      </button>
    </div>
  </div>
</template>

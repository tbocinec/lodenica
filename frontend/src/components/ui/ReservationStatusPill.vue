<script setup lang="ts">
/**
 * One place for a reservation status' wording and colour. Confirmed is the
 * normal case and stays silent unless `show-confirmed` is set, so lists only
 * light up for the exceptions (waiting, rejected, cancelled).
 */
import { computed } from 'vue';

import { ReservationStatus } from '@/api/types';
import { RESERVATION_STATUS_LABEL } from '@/i18n/labels';

const props = withDefaults(
  defineProps<{ status: ReservationStatus; showConfirmed?: boolean }>(),
  { showConfirmed: false },
);

const PILL_CLASS: Record<ReservationStatus, string> = {
  CONFIRMED: 'pill-green',
  PENDING_APPROVAL: 'pill-amber',
  CANCELLED: 'pill-slate',
  REJECTED: 'pill-red',
};

const ICON: Record<ReservationStatus, string> = {
  CONFIRMED: '✅',
  PENDING_APPROVAL: '⏳',
  CANCELLED: '—',
  REJECTED: '✕',
};

const visible = computed(() => props.status !== ReservationStatus.CONFIRMED || props.showConfirmed);
</script>

<template>
  <span v-if="visible" :class="PILL_CLASS[status]" :title="RESERVATION_STATUS_LABEL[status]">
    <span aria-hidden="true">{{ ICON[status] }}</span>
    {{ RESERVATION_STATUS_LABEL[status] }}
  </span>
</template>

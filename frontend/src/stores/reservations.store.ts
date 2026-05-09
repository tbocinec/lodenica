import { defineStore } from 'pinia';
import { ref } from 'vue';

import { reservationsApi, type ListReservationsParams } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';

export const useReservationsStore = defineStore('reservations', () => {
  const items = ref<Reservation[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetch(params: ListReservationsParams = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const data = await reservationsApi.list({ pageSize: 200, ...params });
      items.value = data.items;
      total.value = data.total;
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      loading.value = false;
    }
  }

  return { items, total, loading, error, fetch };
});

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

/**
 * Waiting requests the current user may decide — drives the "Na schválenie"
 * page, the nav badge and the dashboard banner. Refreshed when membership
 * changes (AppShell), on the dashboard/approvals page, and after every
 * decision (ApprovalDecisionButtons). Non-members never hit the endpoint.
 */
export const useApprovalsStore = defineStore('approvals', () => {
  const items = ref<Reservation[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  const pendingCount = computed(() => total.value);

  async function refresh(): Promise<void> {
    const auth = useAuthStore();
    if (!auth.isMember) {
      clear();
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      const data = await reservationsApi.approvals({ pageSize: 100 });
      items.value = data.items;
      total.value = data.total;
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      loading.value = false;
    }
  }

  function clear(): void {
    items.value = [];
    total.value = 0;
    error.value = null;
  }

  return { items, total, pendingCount, loading, error, refresh, clear };
});

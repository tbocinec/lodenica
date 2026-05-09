import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { resourcesApi, type ListResourcesParams } from '@/api/resources.api';
import type { Resource } from '@/api/types';

export const useResourcesStore = defineStore('resources', () => {
  const items = ref<Resource[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetch(params: ListResourcesParams = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const data = await resourcesApi.list({ pageSize: 200, ...params });
      items.value = data.items;
      total.value = data.total;
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      loading.value = false;
    }
  }

  const byId = computed(() => {
    const map = new Map<string, Resource>();
    for (const item of items.value) map.set(item.id, item);
    return map;
  });

  const boats = computed(() =>
    items.value.filter((r) => r.type !== 'BOATHOUSE_SPACE' && r.type !== 'TRAILER'),
  );

  const spaces = computed(() => items.value.filter((r) => r.type === 'BOATHOUSE_SPACE'));

  return { items, total, loading, error, fetch, byId, boats, spaces };
});

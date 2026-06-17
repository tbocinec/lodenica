import { ref } from 'vue';

import { paddlingApi, type PaddlingTrafficLightResponse } from '@/api/paddling.api';

/**
 * Loads the (proxied + cached) Dunajčík paddling traffic light. Shared by
 * the dashboard widget and the full page so formatting stays in one place.
 */
export function usePaddlingTrafficLight() {
  const response = ref<PaddlingTrafficLightResponse | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      response.value = await paddlingApi.get();
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      loading.value = false;
    }
  }

  return { response, loading, error, load };
}

export const LEVEL_LABEL: Record<'green' | 'orange' | 'red', string> = {
  green: 'Vhodné podmienky',
  orange: 'Náročné podmienky',
  red: 'Nevhodné podmienky',
};

/** OpenWeatherMap icon code (e.g. "04d") → its hosted icon URL. */
export function weatherIconUrl(icon: string): string {
  return `https://openweathermap.org/img/wn/${icon}@2x.png`;
}

/** "20:55:49" → "20:55". */
export function hhmm(time: string): string {
  return (time ?? '').slice(0, 5);
}

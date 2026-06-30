import type { WaterType } from '@/api/expeditions.api';

export const WATER_TYPES: WaterType[] = ['river', 'lake', 'sea', 'other'];

export const WATER_TYPE_LABEL: Record<WaterType, string> = {
  river: 'Rieka',
  lake: 'Jazero',
  sea: 'More',
  other: 'Iné',
};

export const WATER_TYPE_EMOJI: Record<WaterType, string> = {
  river: '🌊',
  lake: '🏞️',
  sea: '⛵',
  other: '📍',
};

/** Marker / accent colour per water type. */
export const WATER_TYPE_HEX: Record<WaterType, string> = {
  river: '#2563eb',
  lake: '#0d9488',
  sea: '#4f46e5',
  other: '#64748b',
};

export function waterColor(t: WaterType | null | undefined): string {
  return t ? WATER_TYPE_HEX[t] : WATER_TYPE_HEX.other;
}

export function waterLabel(t: WaterType | null | undefined): string {
  return t ? WATER_TYPE_LABEL[t] : '—';
}

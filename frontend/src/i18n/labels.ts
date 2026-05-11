// Centralized Slovak labels — single source of truth for UI copy.
// Keep it close to translation keys so adding a second language later is easy.

import type { DamageSeverity, DamageStatus, ReservationStatus, ResourceType } from '@/api/types';

export const RESOURCE_TYPE_LABEL: Record<ResourceType, string> = {
  KAYAK: 'Kajak',
  CANOE: 'Kanoe',
  ROWING_BOAT: 'Pramica',
  INFLATABLE_BOAT: 'Nafukovací čln',
  TRAILER: 'Príves',
  BOATHOUSE_SPACE: 'Priestor',
};

export const RESOURCE_TYPE_LABEL_PLURAL: Record<ResourceType, string> = {
  KAYAK: 'Kajaky',
  CANOE: 'Kanoe',
  ROWING_BOAT: 'Pramice',
  INFLATABLE_BOAT: 'Nafukovacie člny',
  TRAILER: 'Prívesy',
  BOATHOUSE_SPACE: 'Priestory',
};

export const DAMAGE_STATUS_LABEL: Record<DamageStatus, string> = {
  REPORTED: 'Nahlásené',
  IN_REPAIR: 'V oprave',
  FIXED: 'Opravené',
};

export const DAMAGE_SEVERITY_LABEL: Record<DamageSeverity, string> = {
  MINOR: 'Drobné',
  MODERATE: 'Stredné',
  CRITICAL: 'Kritické',
};

export const RESERVATION_STATUS_LABEL: Record<ReservationStatus, string> = {
  CONFIRMED: 'Potvrdená',
  CANCELLED: 'Zrušená',
};

export const NAV_LABELS = {
  dashboard: 'Prehľad',
  resources: 'Lode',
  reservations: 'Rezervácie',
  timeline: 'Časová os',
  calendar: 'Kalendár',
  events: 'Udalosti',
  damages: 'Poškodenia',
  spaces: 'Priestory',
};

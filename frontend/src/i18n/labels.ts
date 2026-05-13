// Centralized Slovak labels — single source of truth for UI copy.
// Keep it close to translation keys so adding a second language later is easy.

import type {
  AuditAction,
  AuditEntityType,
  DamageSeverity,
  DamageStatus,
  ReservationStatus,
  ResourceType,
} from '@/api/types';

export const RESOURCE_TYPE_LABEL: Record<ResourceType, string> = {
  KAYAK: 'Kajak (legacy)',
  SEA_KAYAK: 'Morský kajak',
  WW_KAYAK: 'WW kajak',
  CANOE: 'Kanoe',
  ROWING_BOAT: 'Pramica',
  INFLATABLE_BOAT: 'Nafukovací čln',
  TRAILER: 'Príves',
  BOATHOUSE_SPACE: 'Priestor',
};

export const RESOURCE_TYPE_LABEL_PLURAL: Record<ResourceType, string> = {
  KAYAK: 'Kajaky (legacy)',
  SEA_KAYAK: 'Morské kajaky',
  WW_KAYAK: 'WW kajaky',
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
  audit: 'História zmien',
};

export const AUDIT_ENTITY_TYPE_LABEL: Record<AuditEntityType, string> = {
  RESOURCE: 'Loď / zdroj',
  RESERVATION: 'Rezervácia',
  EVENT: 'Udalosť',
  EVENT_PARTICIPANT: 'Účastník udalosti',
  DAMAGE: 'Poškodenie',
};

export const AUDIT_ACTION_LABEL: Record<AuditAction, string> = {
  CREATE: 'Pridanie',
  UPDATE: 'Úprava',
  DELETE: 'Zmazanie',
  CANCEL: 'Zrušenie',
  ACTIVATE: 'Aktivácia',
  DEACTIVATE: 'Deaktivácia',
  ATTACH_RESOURCES: 'Pripojenie zdrojov',
  ADD_PARTICIPANT: 'Pridanie účastníka',
  REMOVE_PARTICIPANT: 'Odobratie účastníka',
};

// Mirror backend enums and DTO shapes. Keep this file the single source of
// truth for the wire contract on the frontend side.

export const ResourceType = {
  KAYAK: 'KAYAK',
  CANOE: 'CANOE',
  ROWING_BOAT: 'ROWING_BOAT',
  INFLATABLE_BOAT: 'INFLATABLE_BOAT',
  TRAILER: 'TRAILER',
  BOATHOUSE_SPACE: 'BOATHOUSE_SPACE',
} as const;
export type ResourceType = (typeof ResourceType)[keyof typeof ResourceType];

export const DamageSeverity = {
  MINOR: 'MINOR',
  MODERATE: 'MODERATE',
  CRITICAL: 'CRITICAL',
} as const;
export type DamageSeverity = (typeof DamageSeverity)[keyof typeof DamageSeverity];

export const DamageStatus = {
  REPORTED: 'REPORTED',
  IN_REPAIR: 'IN_REPAIR',
  FIXED: 'FIXED',
} as const;
export type DamageStatus = (typeof DamageStatus)[keyof typeof DamageStatus];

export const ReservationStatus = {
  CONFIRMED: 'CONFIRMED',
  CANCELLED: 'CANCELLED',
} as const;
export type ReservationStatus = (typeof ReservationStatus)[keyof typeof ReservationStatus];

export interface Resource {
  id: string;
  identifier: string;
  type: ResourceType;
  name: string;
  model: string | null;
  color: string | null;
  seats: number | null;
  lengthCm: number | null;
  weightKg: number | null;
  note: string | null;
  imageUrl: string | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface Reservation {
  id: string;
  resourceId: string;
  eventId: string | null;
  customerName: string;
  customerContact: string | null;
  /** ISO datetime, inclusive lower bound. */
  startsAt: string;
  /** ISO datetime, exclusive upper bound. */
  endsAt: string;
  note: string | null;
  status: ReservationStatus;
  createdAt: string;
  updatedAt: string;
}

export interface Event {
  id: string;
  title: string;
  description: string | null;
  location: string | null;
  startsAt: string;
  endsAt: string;
  createdAt: string;
  updatedAt: string;
}

export interface EventParticipant {
  id: string;
  eventId: string;
  name: string;
  contact: string | null;
  note: string | null;
  createdAt: string;
}

export interface Damage {
  id: string;
  resourceId: string;
  description: string;
  severity: DamageSeverity;
  status: DamageStatus;
  reportedAt: string;
  fixedAt: string | null;
  note: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface Paginated<T> {
  items: T[];
  total: number;
  page: number;
  pageSize: number;
}

export interface ReservationWithResource extends Reservation {
  resource: Resource;
}

export interface DamagedResource {
  resourceId: string;
  resource: Resource;
  damageId: string;
  description: string;
  severity: string;
  status: DamageStatus;
  reportedAt: string;
}

export interface DashboardSnapshot {
  generatedAt: string;
  today: string;
  occupiedToday: ReservationWithResource[];
  occupiedTomorrow: ReservationWithResource[];
  upcoming: ReservationWithResource[];
  spaceReservations: ReservationWithResource[];
  available: Resource[];
  damaged: DamagedResource[];
  totals: {
    activeResources: number;
    upcomingReservations: number;
    openDamages: number;
  };
}

export interface ApiErrorBody {
  statusCode: number;
  error: string;
  code?: string;
  message: string | string[];
  details?: unknown;
  path?: string;
  timestamp?: string;
}

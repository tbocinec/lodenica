// Mirror backend enums and DTO shapes. Keep this file the single source of
// truth for the wire contract on the frontend side.

export const ResourceType = {
  /** Legacy generic kayak — kept for historical rows only. New rows use SEA_KAYAK / WW_KAYAK. */
  KAYAK: 'KAYAK',
  SEA_KAYAK: 'SEA_KAYAK',
  WW_KAYAK: 'WW_KAYAK',
  CANOE: 'CANOE',
  ROWING_BOAT: 'ROWING_BOAT',
  INFLATABLE_BOAT: 'INFLATABLE_BOAT',
  TRAILER: 'TRAILER',
  BOATHOUSE_SPACE: 'BOATHOUSE_SPACE',
} as const;
export type ResourceType = (typeof ResourceType)[keyof typeof ResourceType];

/** Types shown in user-facing pickers (KAYAK omitted — it's deprecated). */
export const RESOURCE_TYPE_VALUES: ResourceType[] = [
  ResourceType.SEA_KAYAK,
  ResourceType.WW_KAYAK,
  ResourceType.CANOE,
  ResourceType.ROWING_BOAT,
  ResourceType.INFLATABLE_BOAT,
  ResourceType.TRAILER,
  ResourceType.BOATHOUSE_SPACE,
];

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
  /** Booked on a resource that requires approval; waiting for an approver. Holds the slot. */
  PENDING_APPROVAL: 'PENDING_APPROVAL',
  CANCELLED: 'CANCELLED',
  /** An approver turned the request down. Frees the slot like CANCELLED. */
  REJECTED: 'REJECTED',
} as const;
export type ReservationStatus = (typeof ReservationStatus)[keyof typeof ReservationStatus];

/** Statuses that occupy the slot — what schedule views ask the API for. Mirrors ReservationStatus::blocking() on the backend. */
export const RESERVATION_BLOCKING_STATUSES: ReservationStatus[] = [
  ReservationStatus.CONFIRMED,
  ReservationStatus.PENDING_APPROVAL,
];

/** A member who may approve bookings of a resource. Member-only (null for others). */
export interface ResourceApprover {
  id: string;
  name: string;
}

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
  /** Backend-served URL of the uploaded photo, or null. Distinct from
   *  imageUrl (a manually-entered external URL). */
  photoUrl: string | null;
  isActive: boolean;
  /** Bookings of this resource wait for an approver (REZ-050). Public. */
  requiresApproval: boolean;
  /** Who may approve — confirmed members only see this; null otherwise. */
  approvers: ResourceApprover[] | null;
  /** Worst damage still open on this boat (reported or in repair), or null. */
  openDamage: OpenDamage | null;
  openDamageCount: number;
  createdAt: string;
  updatedAt: string;
}

/** Damage summary carried inline on a Resource — enough to warn and link. */
export interface OpenDamage {
  id: string;
  status: DamageStatus;
  severity: DamageSeverity;
  description: string;
  reportedAt: string | null;
}

export interface Reservation {
  id: string;
  resourceId: string;
  eventId: string | null;
  /** Null for non-member callers (anonymous + PENDING). Backend strips
   *  the value at the API boundary; see docs/AUTH-AND-PERMISSIONS.md. */
  customerName: string | null;
  customerContact: string | null;
  /** Id of the logged-in user who created the booking, or null for
   *  anonymous bookings. Lets the SPA flag "my reservations". */
  createdById: string | null;
  /** Internal member ID the booking maps to — admin-only (null otherwise). */
  memberId?: string | null;
  /** ISO datetime, inclusive lower bound. */
  startsAt: string;
  /** ISO datetime, exclusive upper bound. */
  endsAt: string;
  note: string | null;
  status: ReservationStatus;
  /** Approval record — set once an approver decided. */
  decidedById?: string | null;
  decidedAt?: string | null;
  /** Approver's note. Member-only (null for anonymous/PENDING viewers). */
  decisionNote?: string | null;
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
  /** Who reported it. Personal name — null for anonymous/PENDING callers. */
  reportedByName: string | null;
  /** Who is fixing it. Personal name — null for anonymous/PENDING callers. */
  assigneeName: string | null;
  /** Backend-served URL of the attached photo, or null. */
  photoUrl: string | null;
  createdAt: string;
  updatedAt: string;
}

/** One message in a damage's discussion thread. Confirmed members only. */
export interface DamageComment {
  id: string;
  damageId: string;
  /** Null once the account is gone; authorName still holds the name. */
  authorId: string | null;
  authorName: string;
  body: string;
  createdAt: string;
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

export type UserRole = 'ADMIN' | 'MEMBER' | 'PENDING';

export interface User {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  isActive: boolean;
  /** Internal club member ID. Admin-only — null for non-admin viewers
   *  (backend strips it at the API boundary). */
  memberId: string | null;
  /** GDPR consents — admin-only (null otherwise). */
  privacyAck?: boolean | null;
  dataConsent?: boolean | null;
  /** Prevádzkový poriadok + stanovy acknowledgement — admin-only. */
  rulesAck?: boolean | null;
  /** When the user first set their own password. Null = invitation not yet
   *  accepted. Admin-only. */
  passwordSetAt?: string | null;
  /** When the GDPR consents were recorded. Admin-only. */
  gdprConsentAt?: string | null;
  /** When the prevádzkový poriadok acknowledgement was recorded. Admin-only. */
  rulesAckAt?: string | null;
  /** Linked social logins — present only on the admin user-detail fetch. */
  identities?: UserIdentity[] | null;
  createdAt: string;
  updatedAt: string;
}

export interface LoginResponse {
  token: string;
  user: User;
}

/** A member-roster ("číselník") entry — drives self-registration approval. */
export interface MemberRosterEntry {
  id: string;
  email: string;
  memberId: string | null;
  name: string | null;
  registeredUserId: string | null;
  registeredAt: string | null;
  createdAt: string;
}

/** One of the user's own e-mail switches (profile screen). */
export interface NotificationPreference {
  key: string;
  label: string;
  description: string;
  enabled: boolean;
}

/** A social login linked to the current account (profile screen). */
export interface UserIdentity {
  provider: string;
  providerLabel: string;
  email: string | null;
  createdAt: string;
}

/** A live OAuth provider, as advertised by GET /auth/providers. Empty list
 *  while OAuth is dormant (no keys configured). */
export interface OAuthProviderInfo {
  provider: string;
  label: string;
  url: string;
}

/** A captcha challenge issued for the forgot-password form. */
export interface CaptchaChallenge {
  token: string;
  /** Inline SVG markup to render the puzzle. */
  svg: string;
}

export interface BulkImportResult {
  createdCount: number;
  skippedCount: number;
  invalidCount: number;
  created: Array<{ name: string; email: string }>;
  skipped: string[];
  invalid: string[];
}

export type AuditEntityType =
  | 'RESOURCE'
  | 'RESERVATION'
  | 'EVENT'
  | 'EVENT_PARTICIPANT'
  | 'DAMAGE'
  | 'USER';

export type AuditAction =
  | 'CREATE'
  | 'UPDATE'
  | 'DELETE'
  | 'CANCEL'
  | 'APPROVE'
  | 'REJECT'
  | 'ACTIVATE'
  | 'DEACTIVATE'
  | 'ATTACH_RESOURCES'
  | 'ADD_PARTICIPANT'
  | 'REMOVE_PARTICIPANT';

export interface AuditLog {
  id: string;
  entityType: AuditEntityType;
  entityId: string;
  action: AuditAction;
  summary: string;
  changes: Record<string, unknown> | null;
  actor: string | null;
  createdAt: string;
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

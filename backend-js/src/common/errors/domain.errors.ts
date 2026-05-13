/**
 * Domain-level errors. Thrown by the application/domain layers and translated
 * to HTTP responses by the global exception filter.
 *
 * Keeping these decoupled from `@nestjs/common` keeps the domain layer free of
 * framework imports and makes use cases unit-testable in isolation.
 */

export type DomainErrorCode =
  | 'RESOURCE_NOT_FOUND'
  | 'RESOURCE_CONFLICT'
  | 'RESERVATION_OVERLAP'
  | 'RESERVATION_INVALID_RANGE'
  | 'RESERVATION_RESOURCE_INACTIVE'
  | 'VALIDATION_ERROR';

export class DomainError extends Error {
  public readonly code: DomainErrorCode;
  public readonly details?: Record<string, unknown>;

  constructor(code: DomainErrorCode, message: string, details?: Record<string, unknown>) {
    super(message);
    this.name = 'DomainError';
    this.code = code;
    this.details = details;
  }
}

export class NotFoundDomainError extends DomainError {
  constructor(entity: string, id: string) {
    super('RESOURCE_NOT_FOUND', `${entity} with id "${id}" was not found.`, { entity, id });
  }
}

export class ConflictDomainError extends DomainError {
  constructor(message: string, details?: Record<string, unknown>) {
    super('RESOURCE_CONFLICT', message, details);
  }
}

export class ReservationOverlapError extends DomainError {
  constructor(resourceId: string, conflictingReservationIds: string[]) {
    super(
      'RESERVATION_OVERLAP',
      'Vybraný zdroj je v zadanom termíne už rezervovaný.',
      { resourceId, conflictingReservationIds },
    );
  }
}

export class InvalidDateRangeError extends DomainError {
  constructor(message = 'Dátum konca rezervácie nesmie predchádzať dátumu začiatku.') {
    super('RESERVATION_INVALID_RANGE', message);
  }
}

export class InactiveResourceError extends DomainError {
  constructor(resourceId: string) {
    super(
      'RESERVATION_RESOURCE_INACTIVE',
      'Zdroj je neaktívny a nemôže byť rezervovaný.',
      { resourceId },
    );
  }
}

import { ReservationStatus } from '@prisma/client';

import { TimeRange } from './time-range.value';

export { ReservationStatus };

export interface ReservationProps {
  id: string;
  resourceId: string;
  customerName: string;
  customerContact: string | null;
  startsAt: Date;
  endsAt: Date;
  note: string | null;
  status: ReservationStatus;
  createdAt: Date;
  updatedAt: Date;
}

export class Reservation {
  private constructor(private readonly props: ReservationProps) {}

  static fromPersistence(props: ReservationProps): Reservation {
    return new Reservation(props);
  }

  get id(): string {
    return this.props.id;
  }

  get resourceId(): string {
    return this.props.resourceId;
  }

  get status(): ReservationStatus {
    return this.props.status;
  }

  get range(): TimeRange {
    return TimeRange.fromInstants(this.props.startsAt, this.props.endsAt);
  }

  isActive(): boolean {
    return this.props.status === ReservationStatus.CONFIRMED;
  }

  toJSON(): ReservationProps {
    return { ...this.props };
  }
}

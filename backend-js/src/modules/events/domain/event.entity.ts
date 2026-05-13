export interface EventParticipantProps {
  id: string;
  eventId: string;
  name: string;
  contact: string | null;
  note: string | null;
  createdAt: Date;
}

export class EventParticipant {
  private constructor(private readonly props: EventParticipantProps) {}

  static fromPersistence(props: EventParticipantProps): EventParticipant {
    return new EventParticipant(props);
  }

  get id(): string {
    return this.props.id;
  }

  get eventId(): string {
    return this.props.eventId;
  }

  toJSON(): EventParticipantProps {
    return { ...this.props };
  }
}

export interface EventProps {
  id: string;
  title: string;
  description: string | null;
  location: string | null;
  startsAt: Date;
  endsAt: Date;
  createdAt: Date;
  updatedAt: Date;
}

export class Event {
  private constructor(private readonly props: EventProps) {}

  static fromPersistence(props: EventProps): Event {
    return new Event(props);
  }

  get id(): string {
    return this.props.id;
  }

  toJSON(): EventProps {
    return { ...this.props };
  }
}

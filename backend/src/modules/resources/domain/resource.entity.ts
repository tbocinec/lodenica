import { ResourceType } from '@prisma/client';

export { ResourceType };

export interface ResourceProps {
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
  createdAt: Date;
  updatedAt: Date;
}

/**
 * Domain entity. Holds invariants for a reservable resource.
 * Boats track physical attributes (seats, length, weight); spaces and
 * trailers may leave them null.
 */
export class Resource {
  private constructor(private readonly props: ResourceProps) {}

  static fromPersistence(props: ResourceProps): Resource {
    return new Resource(props);
  }

  get id(): string {
    return this.props.id;
  }

  get identifier(): string {
    return this.props.identifier;
  }

  get type(): ResourceType {
    return this.props.type;
  }

  get isActive(): boolean {
    return this.props.isActive;
  }

  get name(): string {
    return this.props.name;
  }

  isBoat(): boolean {
    return (
      this.props.type === ResourceType.KAYAK ||
      this.props.type === ResourceType.CANOE ||
      this.props.type === ResourceType.ROWING_BOAT ||
      this.props.type === ResourceType.INFLATABLE_BOAT
    );
  }

  isBoathouseSpace(): boolean {
    return this.props.type === ResourceType.BOATHOUSE_SPACE;
  }

  toJSON(): ResourceProps {
    return { ...this.props };
  }
}

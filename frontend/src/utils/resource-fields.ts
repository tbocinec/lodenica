import { ResourceType } from '@/api/types';

/**
 * Which parameters make sense for which kind of resource.
 *
 *  - boats (kayaks, canoe, rowing/inflatable boat): manufacturer, model,
 *    colour, seats, length, weight, note
 *  - trailer: name, seats, weight, note
 *  - boathouse space: name, note
 *
 * The form only offers the fields of the chosen type and the detail views
 * only show them. Values already stored in a hidden field are never erased.
 */
export type ResourceField = 'model' | 'color' | 'seats' | 'lengthCm' | 'weightKg';

const ALL_BOAT_FIELDS: ResourceField[] = ['model', 'color', 'seats', 'lengthCm', 'weightKg'];

export function isBoatType(type: ResourceType): boolean {
  return type !== ResourceType.TRAILER && type !== ResourceType.BOATHOUSE_SPACE;
}

export function fieldsFor(type: ResourceType): ReadonlySet<ResourceField> {
  if (type === ResourceType.TRAILER) return new Set<ResourceField>(['seats', 'weightKg']);
  if (type === ResourceType.BOATHOUSE_SPACE) return new Set<ResourceField>();
  return new Set<ResourceField>(ALL_BOAT_FIELDS);
}

/** Label of the `name` input: boats store the manufacturer there. */
export function nameLabel(type: ResourceType): string {
  return isBoatType(type) ? 'Výrobca' : 'Názov';
}

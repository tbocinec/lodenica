import { describe, expect, it } from 'vitest';

import { ResourceType } from '@/api/types';

import { fieldsFor, isBoatType, nameLabel } from './resource-fields';

describe('resource fields per type', () => {
  it.each([
    ResourceType.SEA_KAYAK,
    ResourceType.WW_KAYAK,
    ResourceType.CANOE,
    ResourceType.ROWING_BOAT,
    ResourceType.INFLATABLE_BOAT,
  ])('%s is a boat with manufacturer + model', (t) => {
    expect(isBoatType(t)).toBe(true);
    expect(nameLabel(t)).toBe('Výrobca');
    expect([...fieldsFor(t)].sort()).toEqual(['color', 'lengthCm', 'model', 'seats', 'weightKg']);
  });

  it('trailer: name, seats, weight (+ note, always)', () => {
    expect(nameLabel(ResourceType.TRAILER)).toBe('Názov');
    expect([...fieldsFor(ResourceType.TRAILER)].sort()).toEqual(['seats', 'weightKg']);
  });

  it('space: name only (+ note, always)', () => {
    expect(nameLabel(ResourceType.BOATHOUSE_SPACE)).toBe('Názov');
    expect(fieldsFor(ResourceType.BOATHOUSE_SPACE).size).toBe(0);
  });
});

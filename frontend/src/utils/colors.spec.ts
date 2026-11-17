import { describe, expect, it } from 'vitest';

import { BOAT_COLORS, colorHex, isHexColor } from './colors';

describe('boat colours', () => {
  it('includes purple and maroon in the palette', () => {
    expect(colorHex('fialová')).toBe('#7c3aed');
    expect(colorHex('Bordová')).toBe('#7f1d1d');
    expect(BOAT_COLORS.map((c) => c.value)).toEqual(
      expect.arrayContaining(['fialová', 'bordová']),
    );
  });

  it('renders a custom #rrggbb value as-is', () => {
    expect(isHexColor('#A1b2C3')).toBe(true);
    expect(colorHex('#A1b2C3')).toBe('#a1b2c3');
  });

  it('keeps unknown names unresolved', () => {
    expect(isHexColor('tyrkysová')).toBe(false);
    expect(colorHex('tyrkysová')).toBeNull();
  });
});

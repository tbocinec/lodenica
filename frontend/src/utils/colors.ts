/**
 * Canonical boat-colour palette. The stored `color` value is the Slovak
 * colour name (lowercase); the dot is rendered from the matching hex.
 * A user-picked custom colour is stored as a `#rrggbb` hex string and is
 * rendered as-is. Other values outside the palette still display (neutral
 * dot + the raw text), so legacy values aren't lost.
 */
export interface BoatColor {
  value: string;
  label: string;
  hex: string;
}

export const BOAT_COLORS: BoatColor[] = [
  { value: 'červená', label: 'Červená', hex: '#dc2626' },
  { value: 'čierna', label: 'Čierna', hex: '#1f2937' },
  { value: 'zelená', label: 'Zelená', hex: '#16a34a' },
  { value: 'žltá', label: 'Žltá', hex: '#eab308' },
  { value: 'oranžová', label: 'Oranžová', hex: '#f97316' },
  { value: 'modrá', label: 'Modrá', hex: '#2563eb' },
  { value: 'biela', label: 'Biela', hex: '#ffffff' },
  { value: 'sivá', label: 'Sivá', hex: '#6b7280' },
  { value: 'hnedá', label: 'Hnedá', hex: '#92400e' },
  { value: 'fialová', label: 'Fialová', hex: '#7c3aed' },
  { value: 'bordová', label: 'Bordová', hex: '#7f1d1d' },
  { value: 'strieborná', label: 'Strieborná', hex: '#cbd5e1' },
];

const HEX_RE = /^#[0-9a-f]{6}$/i;

/** True when the stored value is a custom `#rrggbb` colour. */
export function isHexColor(color: string | null | undefined): boolean {
  return !!color && HEX_RE.test(color.trim());
}

/** Hex for a stored colour value (case-insensitive), or null if unknown. */
export function colorHex(color: string | null | undefined): string | null {
  if (!color) return null;
  const needle = color.trim().toLowerCase();
  if (HEX_RE.test(needle)) return needle;
  return BOAT_COLORS.find((c) => c.value === needle)?.hex ?? null;
}

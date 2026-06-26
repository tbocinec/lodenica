/**
 * "Splavnosť" — navigability of paddling areas keyed off the Danube water
 * level at the Devín gauge (SHMÚ). Each area has colour bands; green is the
 * usable window, orange the cautious shoulders, red the no-go extremes (too
 * low OR too high). Add new areas here as the club defines their limits.
 *
 * Bands are inclusive ranges in cm; `from: null` = no lower bound, `to: null`
 * = no upper bound.
 */
export type NavLevel = 'green' | 'orange' | 'red';

export interface NavSegment {
  from: number | null;
  to: number | null;
  level: NavLevel;
}

export interface NavArea {
  key: string;
  name: string;
  /** Which gauge the thresholds are read against (shown to the user). */
  gauge: string;
  /** Colour bands; empty = limits not defined yet ("Limity sa doplnia"). */
  segments: NavSegment[];
}

export const NAVIGABILITY_AREAS: NavArea[] = [
  {
    key: 'rakuske-rameno',
    name: 'Rakúske rameno',
    gauge: 'Devín',
    // Limity zatiaľ neznáme — doplnia sa. Keď budú, vlož pásma napr. takto:
    //   { from: null, to: 334, level: 'red' },
    //   { from: 335, to: 349, level: 'orange' },
    //   { from: 350, to: 449, level: 'green' },
    //   { from: 450, to: 599, level: 'orange' },
    //   { from: 600, to: null, level: 'red' },
    segments: [],
  },
  { key: 'velky-okruh', name: 'Veľký okruh', gauge: 'Devín', segments: [] },
  { key: 'rameno-lido', name: 'Rameno Lido', gauge: 'Devín', segments: [] },
  { key: 'rameno-zuzana', name: 'Rameno Zuzana', gauge: 'Devín', segments: [] },
  // Limity doplň do `segments` pri každej oblasti, keď budú známe.
];

export const NAV_LEVEL_HEX: Record<NavLevel, string> = {
  green: '#16a34a',
  orange: '#f59e0b',
  red: '#dc2626',
};

export const NAV_LEVEL_LABEL: Record<NavLevel, string> = {
  green: 'Splavné',
  orange: 'Splavné – opatrne',
  red: 'Nesplavné',
};

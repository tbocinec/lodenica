/**
 * Colour themes. Tailwind's `brand-*` utilities read CSS variables
 * (`--brand-50` … `--brand-950`, RGB triplets) so a theme is applied by
 * swapping the variables on <html>, no rebuild needed. `ocean` is the
 * palette the app shipped with; `main.css` sets it as the `:root` default
 * so the very first paint (before any script runs) already looks right.
 *
 * THEME-001: a user's own theme wins over the site default. The last applied
 * theme is cached in localStorage only to avoid a flash on reload.
 */
export type Shade = 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950;

export const SHADES: readonly Shade[] = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

export interface ThemeDefinition {
  /** Slovak label shown in pickers. */
  label: string;
  /** `<meta name="theme-color">` value (the 700 shade as hex). */
  themeColor: string;
  /** RGB triplets ("r g b") per shade — consumed as `rgb(var(--brand-N) / alpha)`. */
  colors: Record<Shade, string>;
}

export const THEMES = {
  ocean: {
    label: 'Oceán',
    themeColor: '#155bc1',
    colors: {
      50: '238 247 255', 100: '217 236 255', 200: '188 223 255', 300: '142 203 255',
      400: '89 175 255', 500: '45 142 255', 600: '25 113 236', 700: '21 91 193',
      800: '22 75 150', 900: '22 63 120', 950: '14 36 71',
    },
  },
  forest: {
    label: 'Les',
    themeColor: '#15803d',
    colors: {
      50: '240 253 244', 100: '220 252 231', 200: '187 247 208', 300: '134 239 172',
      400: '74 222 128', 500: '34 197 94', 600: '22 163 74', 700: '21 128 61',
      800: '22 101 52', 900: '20 83 45', 950: '5 46 22',
    },
  },
  sunset: {
    label: 'Západ slnka',
    themeColor: '#c2410c',
    colors: {
      50: '255 247 237', 100: '255 237 213', 200: '254 215 170', 300: '253 186 116',
      400: '251 146 60', 500: '249 115 22', 600: '234 88 12', 700: '194 65 12',
      800: '154 52 18', 900: '124 45 18', 950: '67 20 7',
    },
  },
  berry: {
    label: 'Čučoriedka',
    themeColor: '#6d28d9',
    colors: {
      50: '245 243 255', 100: '237 233 254', 200: '221 214 254', 300: '196 181 253',
      400: '167 139 250', 500: '139 92 246', 600: '124 58 237', 700: '109 40 217',
      800: '91 33 182', 900: '76 29 149', 950: '46 16 101',
    },
  },
  graphite: {
    label: 'Grafit',
    themeColor: '#334155',
    colors: {
      50: '248 250 252', 100: '241 245 249', 200: '226 232 240', 300: '203 213 225',
      400: '148 163 184', 500: '100 116 139', 600: '71 85 105', 700: '51 65 85',
      800: '30 41 59', 900: '15 23 42', 950: '2 6 23',
    },
  },
} as const satisfies Record<string, ThemeDefinition>;

export type ThemeKey = keyof typeof THEMES;

export const DEFAULT_THEME: ThemeKey = 'ocean';

export const THEME_KEYS = Object.keys(THEMES) as ThemeKey[];

export function isThemeKey(value: unknown): value is ThemeKey {
  return typeof value === 'string' && Object.prototype.hasOwnProperty.call(THEMES, value);
}

/** The user's own theme when set and known, otherwise the site default, otherwise `ocean`. */
export function resolveTheme(userTheme: string | null | undefined, siteTheme: string): ThemeKey {
  if (isThemeKey(userTheme)) return userTheme;
  return isThemeKey(siteTheme) ? siteTheme : DEFAULT_THEME;
}

/** Swap the brand variables on <html>; returns the key actually applied. */
export function applyTheme(key: string | null | undefined): ThemeKey {
  const resolved = isThemeKey(key) ? key : DEFAULT_THEME;
  if (typeof document === 'undefined') return resolved;

  const root = document.documentElement;
  root.dataset.theme = resolved;
  const theme: ThemeDefinition = THEMES[resolved];
  for (const shade of SHADES) {
    root.style.setProperty(`--brand-${shade}`, theme.colors[shade]);
  }
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.themeColor);
  return resolved;
}

const CACHE_KEY = 'app.theme';

export function readCachedTheme(): string | null {
  try {
    return localStorage.getItem(CACHE_KEY);
  } catch {
    return null;
  }
}

export function writeCachedTheme(key: ThemeKey): void {
  try {
    localStorage.setItem(CACHE_KEY, key);
  } catch {
    // storage unavailable (private mode) — the theme still applies for this load
  }
}

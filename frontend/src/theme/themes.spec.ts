import { beforeEach, describe, expect, it } from 'vitest';

import {
  applyTheme,
  DEFAULT_THEME,
  isThemeKey,
  readCachedTheme,
  resolveTheme,
  SHADES,
  THEMES,
  writeCachedTheme,
} from './themes';

describe('themes', () => {
  beforeEach(() => {
    localStorage.clear();
    document.head.innerHTML = '<meta name="theme-color" content="#000000">';
    document.documentElement.removeAttribute('style');
  });

  it('knows its keys and every theme has every shade', () => {
    expect(isThemeKey('ocean')).toBe(true);
    expect(isThemeKey('neon')).toBe(false);
    expect(isThemeKey(null)).toBe(false);
    expect(Object.keys(THEMES)).toContain(DEFAULT_THEME);
    for (const theme of Object.values(THEMES)) {
      for (const shade of SHADES) {
        expect(theme.colors[shade]).toMatch(/^\d{1,3} \d{1,3} \d{1,3}$/);
      }
    }
  });

  it('user theme wins over the site theme, unknown values fall back (THEME-001)', () => {
    expect(resolveTheme('forest', 'sunset')).toBe('forest');
    expect(resolveTheme(null, 'sunset')).toBe('sunset');
    expect(resolveTheme(undefined, 'sunset')).toBe('sunset');
    expect(resolveTheme('bogus', 'sunset')).toBe('sunset');
    expect(resolveTheme(undefined, 'bogus')).toBe(DEFAULT_THEME);
  });

  it('applies CSS variables, the data attribute and theme-color', () => {
    expect(applyTheme('berry')).toBe('berry');
    expect(document.documentElement.dataset.theme).toBe('berry');
    expect(document.documentElement.style.getPropertyValue('--brand-600')).toBe(THEMES.berry.colors[600]);
    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe(
      THEMES.berry.themeColor,
    );

    expect(applyTheme('bogus')).toBe(DEFAULT_THEME);
    expect(document.documentElement.dataset.theme).toBe(DEFAULT_THEME);
  });

  it('caches the last applied theme', () => {
    expect(readCachedTheme()).toBeNull();
    writeCachedTheme('graphite');
    expect(readCachedTheme()).toBe('graphite');
  });
});

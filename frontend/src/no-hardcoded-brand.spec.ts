import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * SITE-004: the SPA serves any club. Every occurrence of the original
 * club's identity must come from the site store, never from a literal.
 * Extend FORBIDDEN when a new client is onboarded with values that must not
 * leak into the shared code either.
 */
const SRC = __dirname;
const FORBIDDEN = /KVŠ|KVS-|lodenicakvs|Lodenica KVŠ|t\.bocinec@|maps\.app\.goo\.gl/;

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });
}

describe('SITE-004: no hard-coded club identity in the SPA', () => {
  it('finds no forbidden literal in src/', () => {
    const offenders = walk(SRC)
      .filter((p) => /\.(vue|ts|css)$/.test(p) && !p.endsWith('no-hardcoded-brand.spec.ts'))
      .filter((p) => FORBIDDEN.test(readFileSync(p, 'utf8')));

    expect(offenders).toEqual([]);
  });

  it('index.html carries no club name either', () => {
    const html = readFileSync(join(SRC, '..', 'index.html'), 'utf8');
    expect(FORBIDDEN.test(html)).toBe(false);
  });
});

import { describe, expect, it } from 'vitest';

import { backLabel, safeBackPath } from './back-link';

describe('back link', () => {
  it('accepts in-app paths with a query string', () => {
    expect(safeBackPath('/reservations/new?resourceId=abc&startDate=2026-07-01')).toBe(
      '/reservations/new?resourceId=abc&startDate=2026-07-01',
    );
  });

  it.each(['https://evil.example/', '//evil.example', 'javascript:alert(1)', '/\\evil', '', undefined, ['/x']])(
    'rejects %j',
    (v) => {
      expect(safeBackPath(v)).toBeNull();
    },
  );

  it('labels the reservation form', () => {
    expect(backLabel('/reservations/new?resourceId=a')).toBe('← Späť na rezerváciu');
    expect(backLabel('/timeline')).toBe('← Späť');
  });
});

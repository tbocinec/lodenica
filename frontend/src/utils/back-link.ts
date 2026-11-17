/**
 * "Back to where I came from" for detail pages that can be reached from more
 * than one place (e.g. a damage opened from the reservation form instead of
 * the damages list). The origin travels in `?back=<path>`.
 */

/** Only same-app absolute paths — never an external URL (open-redirect guard). */
export function safeBackPath(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  if (!value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return null;
  return value;
}

/** Button label for the page we came from. */
export function backLabel(path: string): string {
  if (path.startsWith('/reservations/new')) return '← Späť na rezerváciu';
  if (path.startsWith('/damages/new')) return '← Späť na nahlásenie';
  return '← Späť';
}

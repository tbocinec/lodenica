import type { Resource } from '@/api/types';

type PhotoFields = Pick<Resource, 'photoUrl'>;

/** The uploaded photo of a boat, or null. */
export function resourcePhoto(r: PhotoFields): string | null {
  return r.photoUrl ?? null;
}

/**
 * Small version of the photo for lists. The backend downsizes uploaded
 * photos on request (`?w=`, see PhotoThumbnail).
 */
export function resourceThumb(r: PhotoFields, width = 160): string | null {
  if (!r.photoUrl) return null;
  return `${r.photoUrl}${r.photoUrl.includes('?') ? '&' : '?'}w=${width}`;
}

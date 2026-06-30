/**
 * Minimal client-side GPX parser → ordered [lat, lng] pairs. Reads track
 * points (preferred), then route points, then waypoints. Downsamples very
 * dense tracks so the stored route stays reasonable.
 */
export function parseGpx(text: string): [number, number][] {
  const doc = new DOMParser().parseFromString(text, 'application/xml');
  if (doc.querySelector('parsererror')) {
    throw new Error('Neplatný GPX súbor.');
  }
  const nodes = Array.from(doc.querySelectorAll('trkpt, rtept, wpt'));
  const out: [number, number][] = [];
  for (const n of nodes) {
    const lat = parseFloat(n.getAttribute('lat') ?? '');
    const lon = parseFloat(n.getAttribute('lon') ?? '');
    if (Number.isFinite(lat) && Number.isFinite(lon)) out.push([lat, lon]);
  }
  if (out.length === 0) {
    throw new Error('GPX neobsahuje žiadne body trasy.');
  }
  if (out.length > 2000) {
    const step = Math.ceil(out.length / 2000);
    const reduced = out.filter((_, i) => i % step === 0);
    if (reduced[reduced.length - 1] !== out[out.length - 1]) reduced.push(out[out.length - 1]);
    return reduced;
  }
  return out;
}

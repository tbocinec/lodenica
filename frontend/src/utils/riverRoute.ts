/**
 * Best-effort "snap to river" routing using OpenStreetMap data via the
 * Overpass API. Given two points, it downloads the waterway lines around them,
 * builds a graph (shared OSM nodes have identical coordinates, so we merge by
 * rounded lat/lon), and runs Dijkstra to find the path along the river between
 * the nearest graph nodes.
 *
 * It's approximate: OSM rivers are split into many segments, can branch or have
 * gaps, so it may fail or look off — callers should fall back to manual tracing
 * / GPX. Worldwide and free; no API key.
 */
type LatLng = [number, number];

const OVERPASS = 'https://overpass-api.de/api/interpreter';
const WATERWAY_RE = '^(river|canal|stream|tidal_channel|riverbank)$';

function haversine(a: LatLng, b: LatLng): number {
  const R = 6371000;
  const dLat = ((b[0] - a[0]) * Math.PI) / 180;
  const dLon = ((b[1] - a[1]) * Math.PI) / 180;
  const la1 = (a[0] * Math.PI) / 180;
  const la2 = (b[0] * Math.PI) / 180;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(la1) * Math.cos(la2) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

const key = (lat: number, lon: number): string => `${lat.toFixed(6)},${lon.toFixed(6)}`;

export class RiverRouteError extends Error {}

export async function snapToRiver(a: LatLng, b: LatLng): Promise<LatLng[]> {
  // Straight-line distance gates the query size (Overpass + client graph).
  const straight = haversine(a, b);
  if (straight > 250_000) {
    throw new RiverRouteError('Body sú príliš ďaleko od seba (nad 250 km) pre auto‑trasu.');
  }

  const pad = Math.max(0.02, (Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1])) * 0.3);
  const south = Math.min(a[0], b[0]) - pad;
  const north = Math.max(a[0], b[0]) + pad;
  const west = Math.min(a[1], b[1]) - pad;
  const east = Math.max(a[1], b[1]) + pad;

  const q =
    `[out:json][timeout:25];` +
    `way["waterway"~"${WATERWAY_RE}"](${south},${west},${north},${east});` +
    `out geom;`;

  let json: { elements?: Array<{ geometry?: Array<{ lat: number; lon: number }> }> };
  try {
    const res = await fetch(OVERPASS, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'data=' + encodeURIComponent(q),
    });
    if (!res.ok) throw new RiverRouteError('Server OSM (Overpass) nedostupný, skús neskôr.');
    json = await res.json();
  } catch (e) {
    if (e instanceof RiverRouteError) throw e;
    throw new RiverRouteError('Nepodarilo sa načítať dáta riek z OSM.');
  }

  const ways = (json.elements ?? []).filter((el) => Array.isArray(el.geometry) && el.geometry.length > 1);
  if (ways.length === 0) {
    throw new RiverRouteError('V okolí sa nenašla žiadna rieka. Skús ručne alebo GPX.');
  }

  // Build an undirected weighted graph from way vertices (merged by coords).
  const adj = new Map<string, Array<{ to: string; w: number }>>();
  const coord = new Map<string, LatLng>();
  const addEdge = (k1: string, k2: string, w: number) => {
    if (!adj.has(k1)) adj.set(k1, []);
    if (!adj.has(k2)) adj.set(k2, []);
    adj.get(k1)!.push({ to: k2, w });
    adj.get(k2)!.push({ to: k1, w });
  };
  for (const w of ways) {
    const g = w.geometry!;
    for (let i = 0; i < g.length; i++) {
      const k = key(g[i].lat, g[i].lon);
      if (!coord.has(k)) coord.set(k, [g[i].lat, g[i].lon]);
      if (i > 0) {
        const kp = key(g[i - 1].lat, g[i - 1].lon);
        addEdge(kp, k, haversine([g[i - 1].lat, g[i - 1].lon], [g[i].lat, g[i].lon]));
      }
    }
  }
  if (coord.size > 60_000) {
    throw new RiverRouteError('Oblasť je príliš veľká/hustá. Skús bližšie body alebo GPX.');
  }

  // Snap endpoints to the nearest graph node.
  const nearest = (p: LatLng): string => {
    let best = '';
    let bestD = Infinity;
    for (const [k, c] of coord) {
      const d = haversine(p, c);
      if (d < bestD) {
        bestD = d;
        best = k;
      }
    }
    return best;
  };
  const start = nearest(a);
  const end = nearest(b);
  if (!start || !end) throw new RiverRouteError('Nepodarilo sa prichytiť body na rieku.');

  // Dijkstra (naive min-scan; node counts here are modest).
  const dist = new Map<string, number>();
  const prev = new Map<string, string>();
  const visited = new Set<string>();
  dist.set(start, 0);
  while (true) {
    let u = '';
    let ud = Infinity;
    for (const [k, d] of dist) {
      if (!visited.has(k) && d < ud) {
        ud = d;
        u = k;
      }
    }
    if (u === '' || u === end) break;
    visited.add(u);
    for (const e of adj.get(u) ?? []) {
      if (visited.has(e.to)) continue;
      const nd = ud + e.w;
      if (nd < (dist.get(e.to) ?? Infinity)) {
        dist.set(e.to, nd);
        prev.set(e.to, u);
      }
    }
  }
  if (!dist.has(end)) {
    throw new RiverRouteError('Medzi bodmi nevedie súvislá rieka v dátach OSM. Skús ručne alebo GPX.');
  }

  // Reconstruct path.
  const path: LatLng[] = [];
  let cur: string | undefined = end;
  while (cur) {
    const c = coord.get(cur);
    if (c) path.push(c);
    if (cur === start) break;
    cur = prev.get(cur);
  }
  path.reverse();

  // Downsample very long paths to keep the payload reasonable.
  if (path.length > 2000) {
    const step = Math.ceil(path.length / 2000);
    const reduced = path.filter((_, i) => i % step === 0);
    if (reduced[reduced.length - 1] !== path[path.length - 1]) reduced.push(path[path.length - 1]);
    return reduced;
  }
  return path;
}

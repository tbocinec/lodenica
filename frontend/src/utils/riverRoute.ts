/**
 * Best-effort "snap to river" using OpenStreetMap data via the Overpass API.
 *
 * To stay within Overpass rate limits (a single public server, ~429 on abuse),
 * we download the waterway network for a whole segment ONCE, build a graph in
 * memory, and route every consecutive waypoint pair locally (Dijkstra) — no
 * per-pair network calls. It's approximate (OSM rivers are split, can branch or
 * have gaps), so callers fall back to the straight line + a warning.
 */
type LatLng = [number, number];

const OVERPASS = 'https://overpass-api.de/api/interpreter';
const WATERWAY_RE = '^(river|canal|stream|tidal_channel|riverbank)$';

export class RiverRouteError extends Error {}

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
const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

interface Graph {
  adj: Map<string, Array<{ to: string; w: number }>>;
  coord: Map<string, LatLng>;
}

/** Download the waterway network in a bbox and build a routing graph (1 request). */
async function fetchGraph(south: number, west: number, north: number, east: number): Promise<Graph> {
  const q =
    `[out:json][timeout:25];` +
    `way["waterway"~"${WATERWAY_RE}"](${south},${west},${north},${east});` +
    `out geom;`;

  let json: { elements?: Array<{ geometry?: Array<{ lat: number; lon: number }> }> } | null = null;
  // One polite retry on 429/5xx (Overpass asks for ~1 req/s).
  for (let attempt = 0; attempt < 2; attempt++) {
    let res: Response;
    try {
      res = await fetch(OVERPASS, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'data=' + encodeURIComponent(q),
      });
    } catch {
      throw new RiverRouteError('Nepodarilo sa spojiť s OSM (Overpass).');
    }
    if (res.status === 429 || res.status === 504 || res.status === 503) {
      if (attempt === 0) {
        await sleep(1500);
        continue;
      }
      throw new RiverRouteError('OSM server (Overpass) je momentálne preťažený (429). Skús o chvíľu, alebo použi GPX / nakresli ručne.');
    }
    if (!res.ok) throw new RiverRouteError('Server OSM (Overpass) vrátil chybu.');
    json = await res.json();
    break;
  }

  const ways = (json?.elements ?? []).filter((el) => Array.isArray(el.geometry) && el.geometry.length > 1);
  if (ways.length === 0) {
    throw new RiverRouteError('V okolí sa nenašla žiadna rieka. Skús ručne alebo GPX.');
  }

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
  if (coord.size > 80_000) {
    throw new RiverRouteError('Oblasť je príliš veľká/hustá. Skús kratšie úseky alebo GPX.');
  }
  return { adj, coord };
}

function nearestNode(graph: Graph, p: LatLng): string {
  let best = '';
  let bestD = Infinity;
  for (const [k, c] of graph.coord) {
    const d = haversine(p, c);
    if (d < bestD) {
      bestD = d;
      best = k;
    }
  }
  return best;
}

/** Dijkstra between two graph nodes; returns coord path or null if unreachable. */
function shortestPath(graph: Graph, start: string, end: string): LatLng[] | null {
  const dist = new Map<string, number>();
  const prev = new Map<string, string>();
  const visited = new Set<string>();
  dist.set(start, 0);
  for (;;) {
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
    for (const e of graph.adj.get(u) ?? []) {
      if (visited.has(e.to)) continue;
      const nd = ud + e.w;
      if (nd < (dist.get(e.to) ?? Infinity)) {
        dist.set(e.to, nd);
        prev.set(e.to, u);
      }
    }
  }
  if (!dist.has(end)) return null;
  const path: LatLng[] = [];
  let cur: string | undefined = end;
  while (cur) {
    const c = graph.coord.get(cur);
    if (c) path.push(c);
    if (cur === start) break;
    cur = prev.get(cur);
  }
  path.reverse();
  return path;
}

/**
 * Snap a whole segment's waypoints to the river using ONE Overpass request.
 * Routes each consecutive pair on the downloaded graph; a pair with no river
 * path stays a straight line and its endpoints are reported as problems.
 */
export async function snapRiverPath(
  waypoints: LatLng[],
): Promise<{ path: LatLng[]; failedPairs: number; problems: LatLng[] }> {
  if (waypoints.length < 2) {
    throw new RiverRouteError('Potrebné sú aspoň 2 body.');
  }
  const lats = waypoints.map((p) => p[0]);
  const lons = waypoints.map((p) => p[1]);
  const span = Math.max(...lats) - Math.min(...lats) + (Math.max(...lons) - Math.min(...lons));
  if (haversine([Math.min(...lats), Math.min(...lons)], [Math.max(...lats), Math.max(...lons)]) > 250_000) {
    throw new RiverRouteError('Úsek je príliš dlhý pre auto‑prichytenie (nad ~250 km). Použi GPX alebo kratšie časti.');
  }
  const pad = Math.max(0.02, span * 0.15);
  const graph = await fetchGraph(
    Math.min(...lats) - pad,
    Math.min(...lons) - pad,
    Math.max(...lats) + pad,
    Math.max(...lons) + pad,
  );

  const path: LatLng[] = [];
  const problems: LatLng[] = [];
  let failedPairs = 0;
  const push = (seg: LatLng[]) => {
    if (path.length && seg.length && path[path.length - 1][0] === seg[0][0] && path[path.length - 1][1] === seg[0][1]) {
      path.push(...seg.slice(1));
    } else {
      path.push(...seg);
    }
  };
  for (let i = 0; i < waypoints.length - 1; i++) {
    const a = waypoints[i];
    const b = waypoints[i + 1];
    const routed = shortestPath(graph, nearestNode(graph, a), nearestNode(graph, b));
    if (routed && routed.length >= 2) {
      push(routed);
    } else {
      push([a, b]);
      failedPairs++;
      problems.push(a, b);
    }
  }

  // Downsample very long paths to keep the payload reasonable.
  let out = path;
  if (out.length > 2000) {
    const step = Math.ceil(out.length / 2000);
    out = out.filter((_, i) => i % step === 0);
    if (out[out.length - 1] !== path[path.length - 1]) out.push(path[path.length - 1]);
  }
  return { path: out, failedPairs, problems };
}

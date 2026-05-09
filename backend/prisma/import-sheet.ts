/**
 * Import inventory from the club's Google Sheet.
 *
 *   pnpm tsx prisma/import-sheet.ts
 *
 * The sheet has columns: Číslo, Typ, Farba, Miesta, cm, kg, Poznámka,
 * with section headers (Morské kajaky, Ostatné kajaky, Kánoe, Nafukovačky,
 * Pramice, Prívesy) on their own rows. Resources without an identifier in
 * the sheet ("nema" or empty) get a generated one with section prefix so
 * we keep referential integrity.
 *
 * The import deletes existing resources (and via cascade their damages)
 * and recreates them. Reservations are detached too — see RESET below —
 * because changing the resource set invalidates them. Boathouse spaces
 * (Nová / Stará lodenica) are recreated unconditionally.
 */

import {
  PrismaClient,
  ReservationStatus,
  ResourceType,
  type Prisma,
} from '@prisma/client';
import { parse } from 'csv-parse/sync';

const SHEET_ID = '1PfWpT2bBr37j2W8G_JBrMCXskL-906kyDQ17oQocFCY';
const SHEET_GID = '2002765643';
const SHEET_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/export?format=csv&gid=${SHEET_GID}`;

const prisma = new PrismaClient();

interface InventoryRow {
  identifier: string;
  type: ResourceType;
  name: string;
  model: string | null;
  color: string | null;
  seats: number | null;
  lengthCm: number | null;
  weightKg: number | null;
  note: string | null;
}

const SECTION_TO_TYPE: Record<string, { type: ResourceType; tag: string }> = {
  'Morské kajaky': { type: ResourceType.KAYAK, tag: 'morský' },
  'Ostatné kajaky': { type: ResourceType.KAYAK, tag: 'riečny' },
  'Kánoe': { type: ResourceType.CANOE, tag: 'kanoe' },
  'Nafukovačky': { type: ResourceType.INFLATABLE_BOAT, tag: 'nafukovačka' },
  'Pramice': { type: ResourceType.ROWING_BOAT, tag: 'pramica' },
  'Prívesy': { type: ResourceType.TRAILER, tag: 'príves' },
};

function isSectionHeader(cells: string[]): string | null {
  const [first, ...rest] = cells.map((c) => c.trim());
  if (!first) return null;
  if (rest.some((c) => c.length > 0)) return null;
  return SECTION_TO_TYPE[first] ? first : null;
}

function parseInt0(value: string): number | null {
  const trimmed = value.replace(/\s/g, '');
  if (!trimmed) return null;
  const n = Number.parseInt(trimmed, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

async function fetchCsv(): Promise<string> {
  const res = await fetch(SHEET_URL, { redirect: 'follow' });
  if (!res.ok) {
    throw new Error(`Failed to fetch sheet: HTTP ${res.status}`);
  }
  return res.text();
}

function parseInventory(csv: string): InventoryRow[] {
  const rows = parse(csv, { relax_column_count: true, skip_empty_lines: false }) as string[][];
  const out: InventoryRow[] = [];

  let currentSection: { type: ResourceType; tag: string } | null = null;
  let sectionCounter = 0;
  // Track per-section auto-numbering when sheet identifier is missing.
  const usedIds = new Set<string>();

  for (let i = 0; i < rows.length; i++) {
    const cells = (rows[i] ?? []).slice(0, 7).map((c) => (c ?? '').trim());
    const [rawId, rawTyp, rawColor, rawSeats, rawCm, rawKg, rawNote] = cells;

    if (!cells.some((c) => c)) continue;
    if (i === 0 && rawId === 'Číslo') continue;

    const sectionName = isSectionHeader(cells);
    if (sectionName) {
      currentSection = SECTION_TO_TYPE[sectionName]!;
      sectionCounter = 0;
      continue;
    }

    if (!currentSection) continue;
    if (!rawTyp && !rawId) continue;

    let identifier = rawId;
    let lengthCm = parseInt0(rawCm);
    let model: string | null = rawTyp || null;

    // Sheet quirk: K78 has the model written in the `cm` column instead of `Typ`.
    if (!model && rawCm && Number.isNaN(Number.parseInt(rawCm, 10))) {
      model = rawCm;
      lengthCm = null;
    }

    // Synthesize an identifier when the sheet has none ("nema" or empty).
    if (!identifier || identifier.toLowerCase() === 'nema') {
      sectionCounter += 1;
      const prefix: string = (() => {
        switch (currentSection.type) {
          case ResourceType.ROWING_BOAT:
            return 'P';
          case ResourceType.TRAILER:
            return 'T';
          case ResourceType.INFLATABLE_BOAT:
            return 'N';
          case ResourceType.CANOE:
            return 'C-X';
          case ResourceType.KAYAK:
          default:
            return 'K-X';
        }
      })();
      identifier = `${prefix}${String(sectionCounter).padStart(2, '0')}`;
    }

    // Disambiguate duplicate identifiers across sections (sheet has K91 twice).
    let unique = identifier;
    let suffix = 1;
    while (usedIds.has(unique)) {
      suffix += 1;
      unique = `${identifier}-${suffix}`;
    }
    usedIds.add(unique);

    const seats = parseInt0(rawSeats);
    const weightKg = parseInt0(rawKg);

    const noteParts: string[] = [];
    if (rawNote) noteParts.push(rawNote);
    if (currentSection.tag !== 'kanoe') noteParts.unshift(`(${currentSection.tag})`);

    const name = model ? `${currentSection.tag.replace(/^./, (c) => c.toUpperCase())} ${model}` : `${currentSection.tag} ${unique}`;

    out.push({
      identifier: unique,
      type: currentSection.type,
      name,
      model,
      color: rawColor || null,
      seats,
      lengthCm,
      weightKg,
      note: noteParts.length ? noteParts.join(' ') : null,
    });
  }

  return out;
}

async function main(): Promise<void> {
  // eslint-disable-next-line no-console
  console.log('Fetching sheet…');
  const csv = await fetchCsv();
  // eslint-disable-next-line no-console
  console.log(`Got ${csv.length} bytes of CSV.`);

  const inventory = parseInventory(csv);
  // eslint-disable-next-line no-console
  console.log(`Parsed ${inventory.length} inventory rows.`);

  // Reset everything except boathouse spaces; we recreate the spaces too so
  // the result is fully deterministic. Reservations + damages cascade.
  await prisma.$transaction(async (tx) => {
    await tx.damage.deleteMany();
    await tx.reservation.deleteMany();
    await tx.resource.deleteMany();

    await tx.resource.create({
      data: {
        identifier: 'SPACE-NOVA',
        type: ResourceType.BOATHOUSE_SPACE,
        name: 'Nová lodenica',
        note: 'Hlavná lodenica — moderná hala.',
      },
    });
    await tx.resource.create({
      data: {
        identifier: 'SPACE-STARA',
        type: ResourceType.BOATHOUSE_SPACE,
        name: 'Stará lodenica',
        note: 'Pôvodná lodenica.',
      },
    });

    const data: Prisma.ResourceCreateManyInput[] = inventory.map((r) => ({
      identifier: r.identifier,
      type: r.type,
      name: r.name,
      model: r.model,
      color: r.color,
      seats: r.seats,
      lengthCm: r.lengthCm,
      weightKg: r.weightKg,
      note: r.note,
      isActive: true,
    }));

    const result = await tx.resource.createMany({ data });
    // eslint-disable-next-line no-console
    console.log(`Inserted ${result.count} resources from sheet.`);
  });

  await seedSampleReservations();

  const totals = await prisma.resource.groupBy({
    by: ['type'],
    _count: { _all: true },
    orderBy: { type: 'asc' },
  });
  // eslint-disable-next-line no-console
  console.log('Final inventory by type:');
  for (const t of totals) {
    // eslint-disable-next-line no-console
    console.log(`  ${t.type.padEnd(20)} ${t._count._all}`);
  }
}

/**
 * After importing inventory, plant a small set of sample reservations against
 * the imported kayaks/canoes/spaces so the Timeline view has something to
 * display. Picks the first few resources of each kind by identifier.
 */
async function seedSampleReservations(): Promise<void> {
  const today = new Date();
  today.setUTCHours(0, 0, 0, 0);

  const atTime = (dayOffset: number, hours: number): Date => {
    const d = new Date(today);
    d.setUTCDate(d.getUTCDate() + dayOffset);
    d.setUTCHours(hours, 0, 0, 0);
    return d;
  };
  const fullDay = (dayOffset: number): { startsAt: Date; endsAt: Date } => ({
    startsAt: atTime(dayOffset, 0),
    endsAt: atTime(dayOffset + 1, 0),
  });

  const kayaks = await prisma.resource.findMany({
    where: { type: ResourceType.KAYAK, isActive: true },
    orderBy: { identifier: 'asc' },
    take: 5,
  });
  const canoes = await prisma.resource.findMany({
    where: { type: ResourceType.CANOE, isActive: true },
    orderBy: { identifier: 'asc' },
    take: 2,
  });
  const space = await prisma.resource.findFirst({
    where: { type: ResourceType.BOATHOUSE_SPACE, identifier: 'SPACE-NOVA' },
  });

  const samples: Array<{
    resourceId: string;
    customerName: string;
    startsAt: Date;
    endsAt: Date;
    note?: string;
  }> = [];

  if (kayaks[0]) {
    samples.push({
      resourceId: kayaks[0].id,
      customerName: 'Ján Novák',
      startsAt: atTime(0, 9),
      endsAt: atTime(0, 12),
      note: 'Tréning na Dunaji.',
    });
    samples.push({
      resourceId: kayaks[0].id,
      customerName: 'Peter Slovák',
      startsAt: atTime(0, 14),
      endsAt: atTime(0, 17),
      note: 'Popoludňajšia jazda.',
    });
  }
  if (kayaks[1]) {
    samples.push({
      resourceId: kayaks[1].id,
      customerName: 'Eva Kováčová',
      startsAt: atTime(0, 10),
      endsAt: atTime(0, 13),
    });
  }
  if (kayaks[2]) {
    samples.push({
      resourceId: kayaks[2].id,
      customerName: 'Martin R.',
      ...fullDay(1),
    });
  }
  if (kayaks[3]) {
    samples.push({
      resourceId: kayaks[3].id,
      customerName: 'Klubová akcia',
      startsAt: atTime(2, 8),
      endsAt: atTime(2, 18),
      note: 'Celodenné podujatie.',
    });
  }
  if (canoes[0]) {
    const start = atTime(1, 0);
    const end = atTime(4, 0);
    samples.push({
      resourceId: canoes[0].id,
      customerName: 'Skupina víkend',
      startsAt: start,
      endsAt: end,
      note: 'Víkendová tura.',
    });
  }
  if (space) {
    samples.push({
      resourceId: space.id,
      customerName: 'Otvorený deň',
      startsAt: atTime(5, 9),
      endsAt: atTime(5, 14),
    });
  }

  if (samples.length === 0) return;

  await prisma.reservation.createMany({
    data: samples.map((s) => ({
      resourceId: s.resourceId,
      customerName: s.customerName,
      startsAt: s.startsAt,
      endsAt: s.endsAt,
      note: s.note,
      status: ReservationStatus.CONFIRMED,
    })),
  });
  // eslint-disable-next-line no-console
  console.log(`Inserted ${samples.length} sample reservations.`);
}

main()
  .catch((err) => {
    // eslint-disable-next-line no-console
    console.error(err);
    process.exit(1);
  })
  .finally(() => prisma.$disconnect());

import {
  DamageSeverity,
  DamageStatus,
  PrismaClient,
  ReservationStatus,
  ResourceType,
} from '@prisma/client';
import { addDays, startOfDay } from 'date-fns';

const prisma = new PrismaClient();

const today = startOfDay(new Date());

function atTime(day: Date, hours: number): Date {
  const d = new Date(day);
  d.setHours(hours, 0, 0, 0);
  return d;
}

async function main() {
  // Idempotent: only seed when the DB is empty. Once the user has imported
  // real inventory from the sheet (or created their own data), restarts
  // must not wipe it. Re-seed by running `prisma migrate reset`.
  const resourceCount = await prisma.resource.count();
  if (resourceCount > 0) {
    // eslint-disable-next-line no-console
    console.log(`Skipping seed — DB already has ${resourceCount} resources.`);
    return;
  }

  // eslint-disable-next-line no-console
  console.log('Seeding Lodenica demo data…');

  const novaLodenica = await prisma.resource.create({
    data: {
      identifier: 'SPACE-NOVA',
      type: ResourceType.BOATHOUSE_SPACE,
      name: 'Nová lodenica',
      note: 'Hlavná lodenica — moderná hala.',
    },
  });
  const staraLodenica = await prisma.resource.create({
    data: {
      identifier: 'SPACE-STARA',
      type: ResourceType.BOATHOUSE_SPACE,
      name: 'Stará lodenica',
      note: 'Pôvodná lodenica.',
    },
  });

  const kayak1 = await prisma.resource.create({
    data: {
      identifier: 'K-001',
      type: ResourceType.WW_KAYAK,
      name: 'WW kajak Pyranha #1',
      model: 'Pyranha Burn',
      color: 'red',
      seats: 1,
      lengthCm: 270,
      weightKg: 22,
    },
  });
  const kayak2 = await prisma.resource.create({
    data: {
      identifier: 'K-002',
      type: ResourceType.WW_KAYAK,
      name: 'WW kajak Dagger #2',
      model: 'Dagger Mamba',
      color: 'yellow',
      seats: 1,
      lengthCm: 260,
      weightKg: 21,
    },
  });
  await prisma.resource.create({
    data: {
      identifier: 'K-003',
      type: ResourceType.SEA_KAYAK,
      name: 'Morský kajak Prijon #3',
      model: 'Prijon Curve',
      color: 'blue',
      seats: 1,
      lengthCm: 520,
      weightKg: 28,
    },
  });

  const canoe1 = await prisma.resource.create({
    data: {
      identifier: 'C-001',
      type: ResourceType.CANOE,
      name: 'Kanoe Old Town #1',
      model: 'Old Town Discovery',
      color: 'green',
      seats: 2,
      lengthCm: 488,
      weightKg: 38,
    },
  });
  await prisma.resource.create({
    data: {
      identifier: 'C-002',
      type: ResourceType.CANOE,
      name: 'Kanoe Mad River #2',
      model: 'Mad River Explorer',
      color: 'red',
      seats: 3,
      lengthCm: 503,
      weightKg: 42,
    },
  });
  await prisma.resource.create({
    data: {
      identifier: 'P-001',
      type: ResourceType.ROWING_BOAT,
      name: 'Pramica Klasická #1',
      seats: 4,
      lengthCm: 420,
      weightKg: 95,
    },
  });
  await prisma.resource.create({
    data: {
      identifier: 'N-001',
      type: ResourceType.INFLATABLE_BOAT,
      name: 'Nafukovací čln Gumotex #1',
      model: 'Gumotex Pálava',
      color: 'red',
      seats: 2,
      lengthCm: 400,
      weightKg: 18,
    },
  });
  await prisma.resource.create({
    data: {
      identifier: 'T-001',
      type: ResourceType.TRAILER,
      name: 'Príves pre 6 lodí',
      note: 'Maximálna nosnosť 750 kg.',
    },
  });

  // Sample reservations: a mix of all-day and hourly windows.
  await prisma.reservation.create({
    data: {
      resourceId: kayak1.id,
      customerName: 'Ján Novák',
      customerContact: 'jan.novak@example.com',
      startsAt: atTime(today, 9),
      endsAt: atTime(today, 12),
      note: 'Tréning na Dunaji.',
      status: ReservationStatus.CONFIRMED,
    },
  });
  await prisma.reservation.create({
    data: {
      resourceId: kayak2.id,
      customerName: 'Eva Kováčová',
      startsAt: atTime(today, 14),
      endsAt: atTime(today, 17),
      note: 'Popoludňajšia jazda.',
      status: ReservationStatus.CONFIRMED,
    },
  });
  await prisma.reservation.create({
    data: {
      resourceId: canoe1.id,
      customerName: 'Skupina víkend',
      startsAt: addDays(today, 1),
      endsAt: addDays(today, 4),
      note: 'Víkendová tura.',
      status: ReservationStatus.CONFIRMED,
    },
  });
  await prisma.reservation.create({
    data: {
      resourceId: novaLodenica.id,
      customerName: 'Klubová akcia',
      startsAt: atTime(addDays(today, 5), 9),
      endsAt: atTime(addDays(today, 5), 14),
      note: 'Otvorený deň pre verejnosť.',
      status: ReservationStatus.CONFIRMED,
    },
  });
  await prisma.reservation.create({
    data: {
      resourceId: staraLodenica.id,
      customerName: 'Údržba',
      startsAt: addDays(today, 7),
      endsAt: addDays(today, 11),
      note: 'Plánovaná údržba lodenice.',
      status: ReservationStatus.CONFIRMED,
    },
  });

  await prisma.damage.create({
    data: {
      resourceId: kayak2.id,
      description: 'Prasklina na pravom boku.',
      severity: DamageSeverity.MODERATE,
      status: DamageStatus.IN_REPAIR,
      note: 'Lepenie prebieha — návrat do prevádzky o cca 7 dní.',
    },
  });

  // eslint-disable-next-line no-console
  console.log('Seed complete.');
}

main()
  .catch((e) => {
    // eslint-disable-next-line no-console
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });

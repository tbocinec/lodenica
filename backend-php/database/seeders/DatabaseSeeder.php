<?php

namespace Database\Seeders;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder. Only loads demo data when the DB is empty so that
 * `migrate --seed` is safe to re-run; real production data is preserved.
 * Wipe with `php artisan migrate:fresh --seed`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Resource::query()->count() > 0) {
            $this->command?->info('Skipping seed — DB already has resources.');

            return;
        }

        $this->command?->info('Seeding Lodenica demo data…');

        $today = CarbonImmutable::now()->startOfDay();
        $atTime = fn (CarbonImmutable $day, int $hour) => $day->setTime($hour, 0, 0);

        $novaLodenica = Resource::create([
            'identifier' => 'SPACE-NOVA',
            'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Nová lodenica',
            'note' => 'Hlavná lodenica — moderná hala.',
        ]);
        $staraLodenica = Resource::create([
            'identifier' => 'SPACE-STARA',
            'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Stará lodenica',
            'note' => 'Pôvodná lodenica.',
        ]);

        $kayak1 = Resource::create([
            'identifier' => 'K-001',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'WW kajak Pyranha #1',
            'model' => 'Pyranha Burn',
            'color' => 'red',
            'seats' => 1,
            'lengthCm' => 270,
            'weightKg' => 22,
        ]);
        $kayak2 = Resource::create([
            'identifier' => 'K-002',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'WW kajak Dagger #2',
            'model' => 'Dagger Mamba',
            'color' => 'yellow',
            'seats' => 1,
            'lengthCm' => 260,
            'weightKg' => 21,
        ]);
        Resource::create([
            'identifier' => 'K-003',
            'type' => ResourceType::SEA_KAYAK,
            'name' => 'Morský kajak Prijon #3',
            'model' => 'Prijon Curve',
            'color' => 'blue',
            'seats' => 1,
            'lengthCm' => 520,
            'weightKg' => 28,
        ]);

        $canoe1 = Resource::create([
            'identifier' => 'C-001',
            'type' => ResourceType::CANOE,
            'name' => 'Kanoe Old Town #1',
            'model' => 'Old Town Discovery',
            'color' => 'green',
            'seats' => 2,
            'lengthCm' => 488,
            'weightKg' => 38,
        ]);
        Resource::create([
            'identifier' => 'C-002',
            'type' => ResourceType::CANOE,
            'name' => 'Kanoe Mad River #2',
            'model' => 'Mad River Explorer',
            'color' => 'red',
            'seats' => 3,
            'lengthCm' => 503,
            'weightKg' => 42,
        ]);
        Resource::create([
            'identifier' => 'P-001',
            'type' => ResourceType::ROWING_BOAT,
            'name' => 'Pramica Klasická #1',
            'seats' => 4,
            'lengthCm' => 420,
            'weightKg' => 95,
        ]);
        Resource::create([
            'identifier' => 'N-001',
            'type' => ResourceType::INFLATABLE_BOAT,
            'name' => 'Nafukovací čln Gumotex #1',
            'model' => 'Gumotex Pálava',
            'color' => 'red',
            'seats' => 2,
            'lengthCm' => 400,
            'weightKg' => 18,
        ]);
        Resource::create([
            'identifier' => 'T-001',
            'type' => ResourceType::TRAILER,
            'name' => 'Príves pre 6 lodí',
            'note' => 'Maximálna nosnosť 750 kg.',
        ]);

        Reservation::create([
            'resourceId' => $kayak1->id,
            'customerName' => 'Ján Novák',
            'customerContact' => 'jan.novak@example.com',
            'startsAt' => $atTime($today, 9),
            'endsAt' => $atTime($today, 12),
            'note' => 'Tréning na Dunaji.',
            'status' => ReservationStatus::CONFIRMED,
        ]);
        Reservation::create([
            'resourceId' => $kayak2->id,
            'customerName' => 'Eva Kováčová',
            'startsAt' => $atTime($today, 14),
            'endsAt' => $atTime($today, 17),
            'note' => 'Popoludňajšia jazda.',
            'status' => ReservationStatus::CONFIRMED,
        ]);
        Reservation::create([
            'resourceId' => $canoe1->id,
            'customerName' => 'Skupina víkend',
            'startsAt' => $today->addDay(),
            'endsAt' => $today->addDays(4),
            'note' => 'Víkendová tura.',
            'status' => ReservationStatus::CONFIRMED,
        ]);
        Reservation::create([
            'resourceId' => $novaLodenica->id,
            'customerName' => 'Klubová akcia',
            'startsAt' => $atTime($today->addDays(5), 9),
            'endsAt' => $atTime($today->addDays(5), 14),
            'note' => 'Otvorený deň pre verejnosť.',
            'status' => ReservationStatus::CONFIRMED,
        ]);
        Reservation::create([
            'resourceId' => $staraLodenica->id,
            'customerName' => 'Údržba',
            'startsAt' => $today->addDays(7),
            'endsAt' => $today->addDays(11),
            'note' => 'Plánovaná údržba lodenice.',
            'status' => ReservationStatus::CONFIRMED,
        ]);

        Damage::create([
            'resourceId' => $kayak2->id,
            'description' => 'Prasklina na pravom boku.',
            'severity' => DamageSeverity::MODERATE,
            'status' => DamageStatus::IN_REPAIR,
            'note' => 'Lepenie prebieha — návrat do prevádzky o cca 7 dní.',
        ]);

        $this->command?->info('Seed complete.');
    }
}

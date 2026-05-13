<?php

namespace Tests\Feature;

use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the Postgres `EXCLUDE USING gist` constraint that protects against
 * overlapping CONFIRMED reservations at the DB level — the safety net for
 * race conditions where two requests pass application-layer overlap checks
 * concurrently. Skipped on non-Postgres drivers.
 */
class PostgresExcludeConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Attempt to switch the default connection to Postgres if it's
        // configured and reachable. Otherwise skip — sqlite cannot enforce
        // an EXCLUDE constraint.
        try {
            DB::purge('pgsql_test');
            DB::connection('pgsql_test')->getPdo();
            config(['database.default' => 'pgsql_test']);
            DB::setDefaultConnection('pgsql_test');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres not reachable: '.$e->getMessage());

            return;
        }

        // Verify schema is present (run migrations against pgsql at least once).
        if (!DB::getSchemaBuilder()->hasTable('reservations')) {
            $this->markTestSkipped(
                'Postgres reachable but schema not migrated. Run: php artisan migrate'
            );

            return;
        }

        // Clean slate.
        DB::table('damages')->delete();
        DB::table('reservations')->delete();
        DB::table('event_participants')->delete();
        DB::table('events')->delete();
        DB::table('resources')->delete();
    }

    public function test_exclude_constraint_blocks_overlapping_inserts(): void
    {
        $kayak = Resource::create([
            'identifier' => 'TEST-EXCL-1',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Excl test 1',
        ]);

        $start = CarbonImmutable::parse('2099-01-01T09:00:00Z');
        Reservation::create([
            'resourceId' => $kayak->id,
            'customerName' => 'A',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::CONFIRMED,
        ]);

        $caught = null;
        try {
            // Bypass the application-layer overlap check on purpose to
            // exercise the DB-level guard directly.
            DB::table('reservations')->insert([
                'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                'resourceId' => $kayak->id,
                'customerName' => 'B',
                'startsAt' => $start->addHour(),
                'endsAt' => $start->addHours(2),
                'status' => 'CONFIRMED',
            ]);
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'EXCLUDE constraint did not fire.');
        $this->assertSame('23P01', $caught->getCode(), 'Expected exclusion_violation SQLSTATE.');
    }

    public function test_exclude_constraint_allows_back_to_back_inserts(): void
    {
        $kayak = Resource::create([
            'identifier' => 'TEST-EXCL-2',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Excl test 2',
        ]);

        $start = CarbonImmutable::parse('2099-02-01T09:00:00Z');
        Reservation::create([
            'resourceId' => $kayak->id,
            'customerName' => 'A',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::CONFIRMED,
        ]);

        // Back-to-back at the boundary (half-open `[)`) is fine.
        Reservation::create([
            'resourceId' => $kayak->id,
            'customerName' => 'B',
            'startsAt' => $start->addHours(3),
            'endsAt' => $start->addHours(6),
            'status' => ReservationStatus::CONFIRMED,
        ]);

        $this->assertSame(2, DB::table('reservations')->where('resourceId', $kayak->id)->count());
    }

    public function test_exclude_constraint_ignores_cancelled_reservations(): void
    {
        $kayak = Resource::create([
            'identifier' => 'TEST-EXCL-3',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Excl test 3',
        ]);

        $start = CarbonImmutable::parse('2099-03-01T09:00:00Z');
        $first = Reservation::create([
            'resourceId' => $kayak->id,
            'customerName' => 'A',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::CONFIRMED,
        ]);
        // Cancel the first → no longer counts.
        $first->update(['status' => ReservationStatus::CANCELLED]);

        Reservation::create([
            'resourceId' => $kayak->id,
            'customerName' => 'B',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::CONFIRMED,
        ]);

        $confirmed = DB::table('reservations')
            ->where('resourceId', $kayak->id)
            ->where('status', 'CONFIRMED')
            ->count();
        $this->assertSame(1, $confirmed);
    }
}

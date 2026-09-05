<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the two approval-workflow values to the Postgres "ReservationStatus"
 * enum (spec §4). Runs outside a transaction because ALTER TYPE … ADD VALUE
 * refuses to run inside one, and a value added in a transaction cannot be
 * referenced until that transaction commits — which is why the EXCLUDE
 * constraint that uses the new value lives in the next migration file.
 * SQLite stores the status as TEXT; nothing to do there.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS \'PENDING_APPROVAL\'');
        DB::statement('ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS \'REJECTED\'');
    }

    public function down(): void
    {
        // Postgres cannot drop an enum value once rows may reference it.
        // Same stance as 2026_06_17_000000_add_pending_to_userrole_enum.
    }
};

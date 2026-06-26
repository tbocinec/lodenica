<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tie a reservation to the club's internal member ID, not just the account
 * that created it. Stamped from the booker's current memberId at creation,
 * and back-filled when an admin assigns/changes a user's memberId.
 *
 * This makes "my reservations" follow the member identity: if the same
 * internal ID is later bound to a different account (e.g. the member
 * re-registers with a new email), the history stays attached to the ID and
 * surfaces for whoever currently holds it. Nullable — anonymous bookings
 * and members without an assigned ID simply have none. See
 * docs/AUTH-AND-PERMISSIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "reservations" ADD COLUMN IF NOT EXISTS "memberId" TEXT');
            DB::statement('CREATE INDEX IF NOT EXISTS "reservations_memberId_idx" ON "reservations" ("memberId")');

            return;
        }

        Schema::table('reservations', function ($table) {
            $table->string('memberId', 100)->nullable();
            $table->index('memberId');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS "reservations_memberId_idx"');
            DB::statement('ALTER TABLE "reservations" DROP COLUMN IF EXISTS "memberId"');

            return;
        }

        Schema::table('reservations', function ($table) {
            $table->dropIndex(['memberId']);
            $table->dropColumn('memberId');
        });
    }
};

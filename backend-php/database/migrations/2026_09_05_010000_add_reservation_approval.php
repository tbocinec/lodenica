<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation approval workflow (spec §3–4), all additive:
 *  - resources.requiresApproval — admin flag, default FALSE, so nothing
 *    changes for existing inventory
 *  - resource_approvers — who may approve bookings of that resource
 *  - reservations.decidedById / decidedAt / decisionNote — the decision
 *  - users.notificationPrefs — per-user e-mail switches (JSON, missing = on)
 *  - the no-overlap EXCLUDE constraint now also covers PENDING_APPROVAL,
 *    because a request awaiting approval holds its slot (REZ-052)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->boolean('requiresApproval')->default(false);
        });

        Schema::create('resource_approvers', function (Blueprint $table) {
            $table->uuid('resourceId');
            $table->uuid('userId');
            $table->timestamp('createdAt')->useCurrent();
            $table->primary(['resourceId', 'userId']);
            $table->foreign('resourceId')->references('id')->on('resources')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('userId')->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->index('userId');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->uuid('decidedById')->nullable();
            $table->timestamp('decidedAt', 3)->nullable();
            $table->text('decisionNote')->nullable();
            $table->foreign('decidedById')->references('id')->on('users')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('notificationPrefs')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "reservations" DROP CONSTRAINT IF EXISTS "reservations_no_overlap_excl"');
            DB::statement(<<<'SQL'
                ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
                  EXCLUDE USING gist (
                    "resourceId" WITH =,
                    tsrange("startsAt", "endsAt", '[)') WITH &&
                  ) WHERE ("status" IN ('CONFIRMED', 'PENDING_APPROVAL'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "reservations" DROP CONSTRAINT IF EXISTS "reservations_no_overlap_excl"');
            DB::statement(<<<'SQL'
                ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
                  EXCLUDE USING gist (
                    "resourceId" WITH =,
                    tsrange("startsAt", "endsAt", '[)') WITH &&
                  ) WHERE ("status" = 'CONFIRMED')
            SQL);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notificationPrefs'));
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['decidedById']);
            $table->dropColumn(['decidedById', 'decidedAt', 'decisionNote']);
        });
        Schema::dropIfExists('resource_approvers');
        Schema::table('resources', fn (Blueprint $table) => $table->dropColumn('requiresApproval'));
    }
};

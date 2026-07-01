<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds expeditions.route (optional polyline). Separate + idempotent because
 * the expeditions table already existed on the test DB before `route` was
 * added to the create migration — editing an already-run migration doesn't
 * re-run it. On a fresh DB the create migration already includes the column,
 * so this is a no-op there (IF NOT EXISTS / hasColumn guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "expeditions" ADD COLUMN IF NOT EXISTS "route" JSON');

            return;
        }

        if (!Schema::hasColumn('expeditions', 'route')) {
            Schema::table('expeditions', function ($table) {
                $table->json('route')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expeditions', 'route')) {
            Schema::table('expeditions', function ($table) {
                $table->dropColumn('route');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move expeditions from a single `country` string to a `countries` list (a
 * trip can cross several countries). Idempotent: adds `countries` if missing,
 * backfills it from the legacy `country` column (as a one-element array), then
 * drops `country`. On a fresh DB the create migration already has `countries`
 * and no `country`, so the backfill/drop are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "expeditions" ADD COLUMN IF NOT EXISTS "countries" JSON');
            if (Schema::hasColumn('expeditions', 'country')) {
                DB::statement('UPDATE "expeditions" SET "countries" = to_json(ARRAY["country"]) WHERE "country" IS NOT NULL AND "countries" IS NULL');
                DB::statement('ALTER TABLE "expeditions" DROP COLUMN IF EXISTS "country"');
            }

            return;
        }

        // SQLite (tests) and others.
        if (!Schema::hasColumn('expeditions', 'countries')) {
            Schema::table('expeditions', function ($table) {
                $table->json('countries')->nullable();
            });
        }
        if (Schema::hasColumn('expeditions', 'country')) {
            foreach (DB::table('expeditions')->whereNotNull('country')->get(['id', 'country']) as $row) {
                DB::table('expeditions')->where('id', $row->id)->update([
                    'countries' => json_encode([$row->country]),
                ]);
            }
            Schema::table('expeditions', function ($table) {
                $table->dropColumn('country');
            });
        }
    }

    public function down(): void
    {
        // One-way (data reshaped); leave `countries` in place.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional uploaded photo for a resource (boat/trailer/space), mirroring
 * the damage photo. Stores only the relative path inside Laravel's local
 * disk (e.g. "resources/<uuid>.jpg"); ResourcesController::showPhoto streams
 * the bytes so the file stays inside the protected /laravel/ directory on
 * Websupport. Distinct from the existing `imageUrl` (a manually-entered
 * external URL), which stays as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        if ($isPg) {
            DB::statement('ALTER TABLE "resources" ADD COLUMN IF NOT EXISTS "photoPath" TEXT');
        } else {
            Schema::table('resources', function ($table) {
                $table->text('photoPath')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('resources', function ($table) {
            $table->dropColumn('photoPath');
        });
    }
};

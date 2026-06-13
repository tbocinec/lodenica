<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional photo attachment for damage reports. We store only the
 * relative path inside Laravel's local disk (e.g. "damages/<uuid>.jpg")
 * and let `DamagesController::showPhoto` stream the bytes so the file
 * sits inside the protected `/laravel/` directory on Websupport rather
 * than the publicly-served docroot.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';
        if ($isPg) {
            DB::statement('ALTER TABLE "damages" ADD COLUMN IF NOT EXISTS "photoPath" TEXT');
        } else {
            Schema::table('damages', function ($table) {
                $table->text('photoPath')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('damages', function ($table) {
            $table->dropColumn('photoPath');
        });
    }
};

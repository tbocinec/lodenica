<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account lifecycle timestamps surfaced in the admin user detail:
 *  - passwordSetAt — when the user first set their own password (invite link
 *    or self-registration). Null for an invited member who hasn't activated
 *    yet, so the admin can see "invitation still pending".
 *  - gdprConsentAt — when the GDPR consents were recorded (registration, the
 *    invite set-password screen, or first OAuth registration).
 *
 * Both nullable: legacy/admin-created accounts simply have no recorded value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "passwordSetAt" TIMESTAMP(0) WITHOUT TIME ZONE');
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "gdprConsentAt" TIMESTAMP(0) WITHOUT TIME ZONE');

            return;
        }

        Schema::table('users', function ($table) {
            $table->timestamp('passwordSetAt')->nullable();
            $table->timestamp('gdprConsentAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn(['passwordSetAt', 'gdprConsentAt']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR consents captured at self-registration:
 *  - privacyAck   — checkbox 1 (REQUIRED): acknowledged being informed about
 *    the conditions of personal-data processing. Always true for accounts
 *    created via the public form.
 *  - dataConsent  — checkbox 2 (OPTIONAL): grants consent to process personal
 *    data. Default-checked on the form but the user may untick it.
 *
 * Both nullable: accounts that didn't go through the registration form
 * (admin-created, invited, OAuth) simply have no recorded value. The
 * registration timestamp the club needs is the existing users.createdAt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "privacyAck" BOOLEAN');
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "dataConsent" BOOLEAN');

            return;
        }

        Schema::table('users', function ($table) {
            $table->boolean('privacyAck')->nullable();
            $table->boolean('dataConsent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn(['privacyAck', 'dataConsent']);
        });
    }
};

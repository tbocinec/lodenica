<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevádzkový poriadok acknowledgement (separate from the GDPR consents):
 *  - rulesAck   — confirmed reading the club statutes + the boathouse
 *    operating rules and agreeing to follow them. Mandatory at registration.
 *  - rulesAckAt — when that confirmation was recorded.
 *
 * Both nullable for legacy/admin-created accounts that never went through the
 * updated registration form.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "rulesAck" BOOLEAN');
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "rulesAckAt" TIMESTAMP(0) WITHOUT TIME ZONE');

            return;
        }

        Schema::table('users', function ($table) {
            $table->boolean('rulesAck')->nullable();
            $table->timestamp('rulesAckAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn(['rulesAck', 'rulesAckAt']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the Postgres "UserRole" ENUM with a PENDING value to match the
 * three-tier role hierarchy on the application side (see
 * app/Domain/Enums/UserRole.php + docs/AUTH-AND-PERMISSIONS.md).
 *
 * Originally create_users_table only defined ADMIN + MEMBER. When the
 * PENDING role was added on the PHP side (commit b4b3083), the matching
 * DB migration was missed, so inserts/updates with role='PENDING' on a
 * Postgres host fail with `invalid input value for enum "UserRole"`.
 * SQLite uses a plain VARCHAR for the column — no migration needed there.
 */
return new class extends Migration
{
    // ALTER TYPE … ADD VALUE cannot run inside a transaction block on
    // Postgres < 12-ish-but-also-some-newer-paths; disable Laravel's
    // wrapping transaction to be safe across versions.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TYPE "UserRole" ADD VALUE IF NOT EXISTS \'PENDING\'');
    }

    public function down(): void
    {
        // Postgres cannot remove an enum value once data may reference
        // it. Leave the value in place; if a true rollback is needed,
        // dump → re-create the type → reload.
    }
};

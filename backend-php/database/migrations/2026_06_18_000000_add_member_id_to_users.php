<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Internal club member ID — the identifier that represents the member in the
 * club's own databases. Nullable (a fresh registration has none; an admin
 * assigns it on confirmation / via CSV invite), admin-only (hidden from the
 * member), and UNIQUE: at most one user may hold a given ID.
 *
 * The unique index is partial (WHERE memberId IS NOT NULL) so any number of
 * users can have NO id yet while assigned ids stay unique. Postgres already
 * permits multiple NULLs in a plain UNIQUE, but the partial index makes the
 * intent explicit; SQLite (tests) also allows multiple NULLs in a UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "users" ADD COLUMN IF NOT EXISTS "memberId" TEXT');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "users_memberId_unique" ON "users" ("memberId") WHERE "memberId" IS NOT NULL');

            return;
        }

        Schema::table('users', function ($table) {
            $table->string('memberId', 100)->nullable();
            $table->unique('memberId');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS "users_memberId_unique"');
            DB::statement('ALTER TABLE "users" DROP COLUMN IF EXISTS "memberId"');

            return;
        }

        Schema::table('users', function ($table) {
            $table->dropUnique(['memberId']);
            $table->dropColumn('memberId');
        });
    }
};

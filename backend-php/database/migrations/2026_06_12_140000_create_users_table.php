<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users table for Lodenica. UUID primary key (consistent with the rest of
 * the schema), email login, hashed password, and a role column. Originally
 * a 2-value ENUM (ADMIN, MEMBER); PENDING was added later by the followup
 * migration 2026_06_17_000000_add_pending_to_userrole_enum.php — see
 * docs/AUTH-AND-PERMISSIONS.md for the full role matrix.
 *
 * Authorization layers above the role live in routes/api.php +
 * EnsureMember middleware; they're separate from the schema.
 *
 * Portable across Postgres and SQLite for tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        if ($isPg) {
            DB::statement('DROP TYPE IF EXISTS "UserRole" CASCADE');
            DB::statement("CREATE TYPE \"UserRole\" AS ENUM ('ADMIN', 'MEMBER')");

            DB::statement(<<<'SQL'
                CREATE TABLE "users" (
                  "id"                UUID NOT NULL DEFAULT gen_random_uuid(),
                  "name"              TEXT NOT NULL,
                  "email"             TEXT NOT NULL UNIQUE,
                  "email_verified_at" TIMESTAMP(3),
                  "password"          TEXT NOT NULL,
                  "role"              "UserRole" NOT NULL DEFAULT 'MEMBER',
                  "isActive"          BOOLEAN NOT NULL DEFAULT TRUE,
                  "remember_token"    VARCHAR(100),
                  "createdAt"         TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  "updatedAt"         TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY ("id")
                )
            SQL);
            DB::statement('CREATE INDEX "users_role_idx" ON "users" ("role")');
            DB::statement('CREATE INDEX "users_isActive_idx" ON "users" ("isActive")');

            return;
        }

        Schema::create('users', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 16)->default('MEMBER');
            $table->boolean('isActive')->default(true);
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->index('role');
            $table->index('isActive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TYPE IF EXISTS "UserRole"');
        }
    }
};

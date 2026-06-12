<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users table for Lodenica. UUID primary key (consistent with the rest of
 * the schema), email login, hashed password, and a coarse 2-value role:
 *
 *   ADMIN  — full control: can manage other users + the resource inventory
 *   MEMBER — logged-in club member; can view the audit log
 *
 * Authorization layers above the role:
 *   - anonymous users can read & write most things (reservations, events,
 *     damages) — that decision lives in routes/api.php, not here
 *   - the audit log requires MEMBER or ADMIN
 *   - resource & user management requires ADMIN
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

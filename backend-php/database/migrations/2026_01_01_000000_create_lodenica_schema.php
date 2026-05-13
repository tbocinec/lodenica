<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lodenica initial schema. Mirrors the NestJS/Prisma model:
 *  - polymorphic `resources` table covers boats / trailers / spaces
 *  - reservations are half-open `[startsAt, endsAt)` ranges
 *  - a btree_gist EXCLUDE constraint makes overlapping CONFIRMED
 *    reservations physically impossible at the DB level
 *  - damages cascade-delete with their resource
 *  - events optionally group reservations + participants
 *
 * Postgres-specific because we depend on enums + btree_gist exclusion.
 * SQLite (used in tests) skips the exclusion and uses TEXT columns instead
 * of native enums.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        if ($isPg) {
            $this->upPostgres();
        } else {
            $this->upPortable();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('damages');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('events');
        Schema::dropIfExists('resources');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TYPE IF EXISTS "ResourceType"');
            DB::statement('DROP TYPE IF EXISTS "DamageSeverity"');
            DB::statement('DROP TYPE IF EXISTS "DamageStatus"');
            DB::statement('DROP TYPE IF EXISTS "ReservationStatus"');
        }
    }

    private function upPostgres(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS "btree_gist"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"'); // gen_random_uuid

        // Drop types if they exist — `migrate:fresh` drops tables but not
        // user-defined types, so a re-run would otherwise hit
        // "type already exists".
        DB::statement('DROP TYPE IF EXISTS "ResourceType" CASCADE');
        DB::statement('DROP TYPE IF EXISTS "DamageSeverity" CASCADE');
        DB::statement('DROP TYPE IF EXISTS "DamageStatus" CASCADE');
        DB::statement('DROP TYPE IF EXISTS "ReservationStatus" CASCADE');

        DB::statement(<<<'SQL'
            CREATE TYPE "ResourceType" AS ENUM (
              'KAYAK', 'SEA_KAYAK', 'WW_KAYAK', 'CANOE',
              'ROWING_BOAT', 'INFLATABLE_BOAT', 'TRAILER', 'BOATHOUSE_SPACE'
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE TYPE "DamageSeverity" AS ENUM ('MINOR', 'MODERATE', 'CRITICAL')
        SQL);
        DB::statement(<<<'SQL'
            CREATE TYPE "DamageStatus" AS ENUM ('REPORTED', 'IN_REPAIR', 'FIXED')
        SQL);
        DB::statement(<<<'SQL'
            CREATE TYPE "ReservationStatus" AS ENUM ('CONFIRMED', 'CANCELLED')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE "resources" (
              "id"         UUID NOT NULL DEFAULT gen_random_uuid(),
              "identifier" TEXT NOT NULL,
              "type"       "ResourceType" NOT NULL,
              "name"       TEXT NOT NULL,
              "model"      TEXT,
              "color"      TEXT,
              "seats"      INTEGER,
              "lengthCm"   INTEGER,
              "weightKg"   INTEGER,
              "note"       TEXT,
              "imageUrl"   TEXT,
              "isActive"   BOOLEAN NOT NULL DEFAULT TRUE,
              "createdAt"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              "updatedAt"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT "resources_pkey" PRIMARY KEY ("id")
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX "resources_identifier_key" ON "resources"("identifier")');
        DB::statement('CREATE INDEX "resources_type_idx" ON "resources"("type")');
        DB::statement('CREATE INDEX "resources_isActive_idx" ON "resources"("isActive")');

        DB::statement(<<<'SQL'
            CREATE TABLE "events" (
              "id"          UUID NOT NULL DEFAULT gen_random_uuid(),
              "title"       TEXT NOT NULL,
              "description" TEXT,
              "location"    TEXT,
              "startsAt"    TIMESTAMP(3) NOT NULL,
              "endsAt"      TIMESTAMP(3) NOT NULL,
              "createdAt"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              "updatedAt"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT "events_pkey" PRIMARY KEY ("id"),
              CONSTRAINT "events_time_range_chk" CHECK ("endsAt" > "startsAt")
            )
        SQL);
        DB::statement('CREATE INDEX "events_startsAt_endsAt_idx" ON "events"("startsAt", "endsAt")');

        DB::statement(<<<'SQL'
            CREATE TABLE "reservations" (
              "id"              UUID NOT NULL DEFAULT gen_random_uuid(),
              "resourceId"      UUID NOT NULL,
              "eventId"         UUID,
              "customerName"    TEXT NOT NULL,
              "customerContact" TEXT,
              "startsAt"        TIMESTAMP(3) NOT NULL,
              "endsAt"          TIMESTAMP(3) NOT NULL,
              "note"            TEXT,
              "status"          "ReservationStatus" NOT NULL DEFAULT 'CONFIRMED',
              "createdAt"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              "updatedAt"       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT "reservations_pkey" PRIMARY KEY ("id"),
              CONSTRAINT "reservations_time_range_chk" CHECK ("endsAt" > "startsAt"),
              CONSTRAINT "reservations_resourceId_fkey"
                FOREIGN KEY ("resourceId") REFERENCES "resources"("id")
                ON DELETE RESTRICT ON UPDATE CASCADE,
              CONSTRAINT "reservations_eventId_fkey"
                FOREIGN KEY ("eventId") REFERENCES "events"("id")
                ON DELETE SET NULL ON UPDATE CASCADE
            )
        SQL);
        DB::statement('CREATE INDEX "reservations_resourceId_idx" ON "reservations"("resourceId")');
        DB::statement('CREATE INDEX "reservations_eventId_idx" ON "reservations"("eventId")');
        DB::statement('CREATE INDEX "reservations_startsAt_endsAt_idx" ON "reservations"("startsAt", "endsAt")');
        DB::statement('CREATE INDEX "reservations_status_idx" ON "reservations"("status")');

        // Hard guarantee against overlapping CONFIRMED reservations.
        // Half-open tsrange '[)' matches the application-layer rule.
        DB::statement(<<<'SQL'
            ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
              EXCLUDE USING gist (
                "resourceId" WITH =,
                tsrange("startsAt", "endsAt", '[)') WITH &&
              ) WHERE ("status" = 'CONFIRMED')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE "event_participants" (
              "id"        UUID NOT NULL DEFAULT gen_random_uuid(),
              "eventId"   UUID NOT NULL,
              "name"      TEXT NOT NULL,
              "contact"   TEXT,
              "note"      TEXT,
              "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT "event_participants_pkey" PRIMARY KEY ("id"),
              CONSTRAINT "event_participants_eventId_fkey"
                FOREIGN KEY ("eventId") REFERENCES "events"("id")
                ON DELETE CASCADE ON UPDATE CASCADE
            )
        SQL);
        DB::statement('CREATE INDEX "event_participants_eventId_idx" ON "event_participants"("eventId")');

        DB::statement(<<<'SQL'
            CREATE TABLE "damages" (
              "id"          UUID NOT NULL DEFAULT gen_random_uuid(),
              "resourceId"  UUID NOT NULL,
              "description" TEXT NOT NULL,
              "severity"    "DamageSeverity" NOT NULL,
              "status"      "DamageStatus" NOT NULL DEFAULT 'REPORTED',
              "reportedAt"  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              "fixedAt"     TIMESTAMP(3),
              "note"        TEXT,
              "createdAt"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              "updatedAt"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT "damages_pkey" PRIMARY KEY ("id"),
              CONSTRAINT "damages_resourceId_fkey"
                FOREIGN KEY ("resourceId") REFERENCES "resources"("id")
                ON DELETE CASCADE ON UPDATE CASCADE
            )
        SQL);
        DB::statement('CREATE INDEX "damages_resourceId_idx" ON "damages"("resourceId")');
        DB::statement('CREATE INDEX "damages_status_idx" ON "damages"("status")');

        // Eloquent expects updatedAt to bump on UPDATE; we run that from the
        // model. No DB-level trigger needed.
    }

    private function upPortable(): void
    {
        Schema::create('resources', function ($table) {
            $table->uuid('id')->primary();
            $table->string('identifier')->unique();
            $table->string('type', 32);
            $table->string('name');
            $table->string('model')->nullable();
            $table->string('color')->nullable();
            $table->integer('seats')->nullable();
            $table->integer('lengthCm')->nullable();
            $table->integer('weightKg')->nullable();
            $table->text('note')->nullable();
            $table->text('imageUrl')->nullable();
            $table->boolean('isActive')->default(true);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->index('type');
            $table->index('isActive');
        });

        Schema::create('events', function ($table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('startsAt');
            $table->timestamp('endsAt');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->index(['startsAt', 'endsAt']);
        });

        Schema::create('reservations', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('resourceId');
            $table->uuid('eventId')->nullable();
            $table->string('customerName');
            $table->string('customerContact')->nullable();
            $table->timestamp('startsAt');
            $table->timestamp('endsAt');
            $table->text('note')->nullable();
            $table->string('status', 16)->default('CONFIRMED');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('resourceId')->references('id')->on('resources')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('eventId')->references('id')->on('events')->cascadeOnUpdate()->nullOnDelete();
            $table->index('resourceId');
            $table->index('eventId');
            $table->index(['startsAt', 'endsAt']);
            $table->index('status');
        });

        Schema::create('event_participants', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('eventId');
            $table->string('name');
            $table->string('contact')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->foreign('eventId')->references('id')->on('events')->cascadeOnUpdate()->cascadeOnDelete();
            $table->index('eventId');
        });

        Schema::create('damages', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('resourceId');
            $table->text('description');
            $table->string('severity', 16);
            $table->string('status', 16)->default('REPORTED');
            $table->timestamp('reportedAt')->useCurrent();
            $table->timestamp('fixedAt')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('resourceId')->references('id')->on('resources')->cascadeOnUpdate()->cascadeOnDelete();
            $table->index('resourceId');
            $table->index('status');
        });
    }
};

-- Lodenica initial schema.
-- Includes a daterange exclusion constraint to make overlapping reservations
-- physically impossible at the database level. Application-level checks remain
-- the source of user-friendly validation messages; this is the safety net.

CREATE EXTENSION IF NOT EXISTS "btree_gist";

-- Enums
CREATE TYPE "ResourceType" AS ENUM ('KAYAK', 'CANOE', 'ROWING_BOAT', 'INFLATABLE_BOAT', 'TRAILER', 'BOATHOUSE_SPACE');
CREATE TYPE "DamageSeverity" AS ENUM ('MINOR', 'MODERATE', 'CRITICAL');
CREATE TYPE "DamageStatus" AS ENUM ('REPORTED', 'IN_REPAIR', 'FIXED');
CREATE TYPE "ReservationStatus" AS ENUM ('CONFIRMED', 'CANCELLED');

-- resources
CREATE TABLE "resources" (
    "id" UUID NOT NULL DEFAULT gen_random_uuid(),
    "identifier" TEXT NOT NULL,
    "type" "ResourceType" NOT NULL,
    "name" TEXT NOT NULL,
    "model" TEXT,
    "color" TEXT,
    "seats" INTEGER,
    "lengthCm" INTEGER,
    "weightKg" INTEGER,
    "note" TEXT,
    "imageUrl" TEXT,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "resources_pkey" PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX "resources_identifier_key" ON "resources"("identifier");
CREATE INDEX "resources_type_idx" ON "resources"("type");
CREATE INDEX "resources_isActive_idx" ON "resources"("isActive");

-- reservations
CREATE TABLE "reservations" (
    "id" UUID NOT NULL DEFAULT gen_random_uuid(),
    "resourceId" UUID NOT NULL,
    "customerName" TEXT NOT NULL,
    "customerContact" TEXT,
    "startDate" DATE NOT NULL,
    "endDate" DATE NOT NULL,
    "startTime" VARCHAR(5),
    "endTime" VARCHAR(5),
    "note" TEXT,
    "status" "ReservationStatus" NOT NULL DEFAULT 'CONFIRMED',
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "reservations_pkey" PRIMARY KEY ("id")
);

CREATE INDEX "reservations_resourceId_idx" ON "reservations"("resourceId");
CREATE INDEX "reservations_startDate_endDate_idx" ON "reservations"("startDate", "endDate");
CREATE INDEX "reservations_status_idx" ON "reservations"("status");

ALTER TABLE "reservations" ADD CONSTRAINT "reservations_resourceId_fkey"
    FOREIGN KEY ("resourceId") REFERENCES "resources"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE "reservations" ADD CONSTRAINT "reservations_date_range_chk"
    CHECK ("endDate" >= "startDate");

-- Hard guarantee: no two CONFIRMED reservations for the same resource overlap.
-- Uses inclusive daterange: daterange(start, end, '[]').
ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
    EXCLUDE USING gist (
        "resourceId" WITH =,
        daterange("startDate", "endDate", '[]') WITH &&
    ) WHERE ("status" = 'CONFIRMED');

-- damages
CREATE TABLE "damages" (
    "id" UUID NOT NULL DEFAULT gen_random_uuid(),
    "resourceId" UUID NOT NULL,
    "description" TEXT NOT NULL,
    "severity" "DamageSeverity" NOT NULL,
    "status" "DamageStatus" NOT NULL DEFAULT 'REPORTED',
    "reportedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "fixedAt" TIMESTAMP(3),
    "note" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "damages_pkey" PRIMARY KEY ("id")
);

CREATE INDEX "damages_resourceId_idx" ON "damages"("resourceId");
CREATE INDEX "damages_status_idx" ON "damages"("status");

ALTER TABLE "damages" ADD CONSTRAINT "damages_resourceId_fkey"
    FOREIGN KEY ("resourceId") REFERENCES "resources"("id") ON DELETE CASCADE ON UPDATE CASCADE;

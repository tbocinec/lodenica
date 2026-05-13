-- Switch reservations from (DATE, DATE) + nullable (TIME, TIME) to
-- (TIMESTAMP, TIMESTAMP) + allDay flag. Half-open interval [startsAt, endsAt)
-- so back-to-back bookings (handover) don't conflict.

-- 1. Add new columns (nullable for backfill).
ALTER TABLE "reservations" ADD COLUMN "startsAt" TIMESTAMP(3);
ALTER TABLE "reservations" ADD COLUMN "endsAt"   TIMESTAMP(3);
ALTER TABLE "reservations" ADD COLUMN "allDay"   BOOLEAN NOT NULL DEFAULT true;

-- 2. Backfill from existing date+time columns.
--    All-day rows: startsAt = startDate 00:00, endsAt = endDate + 1 day (exclusive).
--    Time-bounded rows: combine date + time directly.
UPDATE "reservations" SET
  "startsAt" = (
    "startDate"::timestamp
    + COALESCE("startTime"::time, '00:00'::time) - '00:00'::time
  ),
  "endsAt" = CASE
    WHEN "endTime" IS NOT NULL
      THEN ("endDate"::timestamp + ("endTime"::time - '00:00'::time))
    ELSE ("endDate"::timestamp + INTERVAL '1 day')
  END,
  "allDay" = ("startTime" IS NULL AND "endTime" IS NULL);

-- 3. Enforce NOT NULL on new columns.
ALTER TABLE "reservations" ALTER COLUMN "startsAt" SET NOT NULL;
ALTER TABLE "reservations" ALTER COLUMN "endsAt"   SET NOT NULL;

-- 4. Drop old constraints, indexes, columns.
ALTER TABLE "reservations" DROP CONSTRAINT "reservations_no_overlap_excl";
ALTER TABLE "reservations" DROP CONSTRAINT "reservations_date_range_chk";
DROP INDEX "reservations_startDate_endDate_idx";

ALTER TABLE "reservations" DROP COLUMN "startDate";
ALTER TABLE "reservations" DROP COLUMN "endDate";
ALTER TABLE "reservations" DROP COLUMN "startTime";
ALTER TABLE "reservations" DROP COLUMN "endTime";

-- 5. New constraints + index.
CREATE INDEX "reservations_startsAt_endsAt_idx"
  ON "reservations"("startsAt", "endsAt");

ALTER TABLE "reservations" ADD CONSTRAINT "reservations_time_range_chk"
  CHECK ("endsAt" > "startsAt");

ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
  EXCLUDE USING gist (
    "resourceId" WITH =,
    tsrange("startsAt", "endsAt", '[)') WITH &&
  ) WHERE ("status" = 'CONFIRMED');

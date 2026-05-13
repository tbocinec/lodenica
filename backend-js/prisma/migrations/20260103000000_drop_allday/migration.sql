-- Unify reservations into a single shape: every booking is `[startsAt, endsAt)`
-- as full timestamps. The `allDay` flag was display metadata only — existing
-- timestamp values already encode the actual range.

ALTER TABLE "reservations" DROP COLUMN "allDay";

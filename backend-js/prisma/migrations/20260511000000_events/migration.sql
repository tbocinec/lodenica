-- Lodenica events: boathouse-organized happenings (trips, regattas, training).
-- An event groups together boat reservations and participant sign-ups for a
-- shared time window. Linking is optional: a reservation can exist without an
-- event, and an event can have zero or more participants and zero or more
-- reservations.

CREATE TABLE "events" (
    "id" UUID NOT NULL DEFAULT gen_random_uuid(),
    "title" TEXT NOT NULL,
    "description" TEXT,
    "location" TEXT,
    "startsAt" TIMESTAMP(3) NOT NULL,
    "endsAt" TIMESTAMP(3) NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "events_pkey" PRIMARY KEY ("id")
);

CREATE INDEX "events_startsAt_endsAt_idx" ON "events"("startsAt", "endsAt");

ALTER TABLE "events" ADD CONSTRAINT "events_time_range_chk"
    CHECK ("endsAt" > "startsAt");

CREATE TABLE "event_participants" (
    "id" UUID NOT NULL DEFAULT gen_random_uuid(),
    "eventId" UUID NOT NULL,
    "name" TEXT NOT NULL,
    "contact" TEXT,
    "note" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "event_participants_pkey" PRIMARY KEY ("id")
);

CREATE INDEX "event_participants_eventId_idx" ON "event_participants"("eventId");

ALTER TABLE "event_participants" ADD CONSTRAINT "event_participants_eventId_fkey"
    FOREIGN KEY ("eventId") REFERENCES "events"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- Optional link from reservations to an event. When the event is deleted,
-- the reservation survives but the link is cleared.
ALTER TABLE "reservations" ADD COLUMN "eventId" UUID;

CREATE INDEX "reservations_eventId_idx" ON "reservations"("eventId");

ALTER TABLE "reservations" ADD CONSTRAINT "reservations_eventId_fkey"
    FOREIGN KEY ("eventId") REFERENCES "events"("id") ON DELETE SET NULL ON UPDATE CASCADE;

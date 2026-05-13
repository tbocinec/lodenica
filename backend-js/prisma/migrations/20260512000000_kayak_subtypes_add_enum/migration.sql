-- Split the KAYAK resource type into two subtypes: SEA_KAYAK and WW_KAYAK.
-- Step 1: introduce the enum values. Postgres rejects using a newly-added
-- enum value in the same transaction, so the data migration moves to a
-- separate file (20260512000001_kayak_subtypes_migrate_data).

ALTER TYPE "ResourceType" ADD VALUE IF NOT EXISTS 'SEA_KAYAK';
ALTER TYPE "ResourceType" ADD VALUE IF NOT EXISTS 'WW_KAYAK';

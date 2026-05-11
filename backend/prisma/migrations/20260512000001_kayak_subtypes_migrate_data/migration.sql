-- Step 2: backfill existing KAYAK rows into the new subtypes based on the
-- inventory note tag set by `import-sheet.ts`:
--   "(morský)"  → SEA_KAYAK    (sea kayak, ocean / flatwater)
--   "(riečny)"  → WW_KAYAK     (whitewater)
-- Any KAYAK row without a matching tag stays as KAYAK so it shows up in the
-- "needs attention" filter and an admin can reclassify it manually.

UPDATE "resources"
SET    "type" = 'SEA_KAYAK'
WHERE  "type" = 'KAYAK'
  AND  "note" ILIKE '%(morský)%';

UPDATE "resources"
SET    "type" = 'WW_KAYAK'
WHERE  "type" = 'KAYAK'
  AND  "note" ILIKE '%(riečny)%';

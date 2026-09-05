<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the two approval-workflow values to the Postgres "ReservationStatus"
 * enum (spec §4) — or, when the application role does not own that type,
 * detaches reservations.status from the enum altogether.
 *
 * Background: on the managed hosting the enum types were created by the
 * superuser while the tables belong to the application role. ALTER TYPE …
 * ADD VALUE needs ownership of the type, so it fails there with "must be
 * owner of type" — the same trap hit once with "UserRole". The table IS
 * ours, so the fallback converts the column to TEXT, keeping every value,
 * the default, a CHECK constraint with the four allowed statuses and the
 * no-overlap EXCLUDE constraint, and leaves the orphaned type in place.
 * Eloquent never relies on the Postgres enum (it casts strings), so both
 * shapes behave identically; the follow-up migration's constraint swap
 * works on either.
 *
 * Runs outside a transaction because ALTER TYPE … ADD VALUE refuses to run
 * inside one and a value added in a transaction cannot be referenced until
 * it commits — which is why the EXCLUDE constraint that uses the new value
 * lives in the next migration file. The fallback wraps its own DDL in an
 * explicit transaction. SQLite stores the status as TEXT; nothing to do.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const STATUSES = ['CONFIRMED', 'PENDING_APPROVAL', 'CANCELLED', 'REJECTED'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (!$this->statusColumnIsEnum()) {
            // Already detached (the fallback ran on an earlier attempt).
            return;
        }

        if ($this->currentRoleMayAlterEnum()) {
            DB::statement('ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS \'PENDING_APPROVAL\'');
            DB::statement('ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS \'REJECTED\'');

            return;
        }

        $this->detachStatusFromEnum();
    }

    public function down(): void
    {
        // Postgres cannot drop an enum value once rows may reference it, and
        // re-attaching a TEXT column to a superuser-owned enum is not ours to
        // do. Same stance as 2026_06_17_000000_add_pending_to_userrole_enum.
    }

    private function statusColumnIsEnum(): bool
    {
        $udt = DB::selectOne(
            "SELECT udt_name FROM information_schema.columns WHERE table_name = 'reservations' AND column_name = 'status'",
        );

        return $udt !== null && $udt->udt_name === 'ReservationStatus';
    }

    /** Owner, a member of the owning role, or a superuser — anyone ALTER TYPE would let through. */
    private function currentRoleMayAlterEnum(): bool
    {
        $row = DB::selectOne(
            "SELECT pg_has_role(current_user, t.typowner, 'USAGE') AS allowed FROM pg_type t WHERE t.typname = 'ReservationStatus'",
        );

        return $row !== null && (bool) $row->allowed;
    }

    /**
     * Same schema semantics without the enum: values preserved via the
     * ::text cast, validity kept by a CHECK constraint, and the EXCLUDE
     * constraint restored with its current predicate (the next migration
     * widens it to the blocking statuses).
     */
    private function detachStatusFromEnum(): void
    {
        $allowed = implode(', ', array_map(fn (string $s) => "'{$s}'", self::STATUSES));

        DB::transaction(function () use ($allowed) {
            DB::statement('ALTER TABLE "reservations" DROP CONSTRAINT IF EXISTS "reservations_no_overlap_excl"');
            DB::statement('ALTER TABLE "reservations" ALTER COLUMN "status" DROP DEFAULT');
            DB::statement('ALTER TABLE "reservations" ALTER COLUMN "status" TYPE text USING "status"::text');
            DB::statement('ALTER TABLE "reservations" ALTER COLUMN "status" SET DEFAULT \'CONFIRMED\'');
            DB::statement("ALTER TABLE \"reservations\" ADD CONSTRAINT \"reservations_status_chk\" CHECK (\"status\" IN ({$allowed}))");
            DB::statement(<<<'SQL'
                ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
                  EXCLUDE USING gist (
                    "resourceId" WITH =,
                    tsrange("startsAt", "endsAt", '[)') WITH &&
                  ) WHERE ("status" = 'CONFIRMED')
            SQL);
        });
    }
};

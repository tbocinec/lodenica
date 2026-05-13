<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log: append-only record of every business change in the system.
 *
 * Polymorphic by (`entityType`, `entityId`) so a single table captures
 * reservations, resources, events, damages and event participants without
 * extra joins. `changes` stores either { before, after } for updates or
 * a snapshot (after-only on create, before-only on delete). `summary` is
 * the pre-rendered Slovak description the UI displays as-is.
 *
 * Deliberately uses TEXT (not Postgres enum) for `entityType` and `action`
 * — those vocabularies are likely to grow and Postgres enum extension
 * (ALTER TYPE … ADD VALUE) is awkward inside transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPg = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('audit_logs', function ($table) use ($isPg) {
            $table->uuid('id')->primary();
            $table->string('entityType', 32);
            $table->uuid('entityId');
            $table->string('action', 32);
            $table->text('summary');

            if ($isPg) {
                $table->jsonb('changes')->nullable();
            } else {
                $table->json('changes')->nullable();
            }

            $table->string('actor')->nullable();
            $table->timestamp('createdAt')->useCurrent();

            $table->index(['entityType', 'entityId']);
            $table->index('action');
            $table->index('createdAt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

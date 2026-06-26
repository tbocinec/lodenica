<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member roster ("číselník") — a curated list of known club members the
 * committee maintains independently of who has logged in. One row per email,
 * carrying the internal member ID (and an optional name, for reference only).
 *
 * It drives self-registration: when someone registers (or first logs in via
 * OAuth) with an email that's on the roster, the account is auto-approved as
 * a MEMBER and inherits the roster's member ID — no manual approval needed.
 * The matched row is then stamped with who/when registered, so the committee
 * can see which roster entries have been claimed.
 *
 * Admin-only. See docs/AUTH-AND-PERMISSIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_roster', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();          // lowercased, the match key
            $table->string('memberId', 100)->nullable(); // internal club ID assigned on registration
            $table->string('name')->nullable();          // reference only — ignored on match
            $table->uuid('registeredUserId')->nullable(); // set when claimed by a registration
            $table->timestamp('registeredAt')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();

            $table->index('registeredUserId');
            $table->foreign('registeredUserId')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_roster');
    }
};

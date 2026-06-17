<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a reservation to the logged-in user who created it, so members
 * can see "my reservations". Nullable: anonymous bookings (the public
 * booking form stays open) have no owner. ON DELETE SET NULL keeps the
 * reservation if the user account is later removed.
 *
 * The customerName/customerContact fields remain the source of truth for
 * WHO the booking is for — a member can book on behalf of someone else,
 * in which case createdById (the booker) differs from the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->uuid('createdById')->nullable();
            $table->index('createdById');
            $table->foreign('createdById')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['createdById']);
            $table->dropIndex(['createdById']);
            $table->dropColumn('createdById');
        });
    }
};

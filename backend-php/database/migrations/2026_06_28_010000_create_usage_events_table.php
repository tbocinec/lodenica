<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight, privacy-light usage tracking for the admin "Štatistiky
 * používania" dashboard. One row per event:
 *   - type 'login'    — a successful login (password or social). userId set.
 *   - type 'visit'    — first SPA load in a browser session (a "visit").
 *   - type 'pageview' — every SPA load.
 *
 * No IP, no PII, no persistent device identifier — visits/pageviews carry no
 * userId. "Unique visitor" is approximated client-side via sessionStorage
 * (cleared when the tab closes), so nothing here tracks a person across
 * sessions. Registrations per day are derived from users.createdAt, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 32);          // 'login' | 'visit' | 'pageview'
            $table->uuid('userId')->nullable();  // only set for 'login'
            $table->timestamp('occurredAt')->useCurrent();

            $table->index(['type', 'occurredAt']);
            $table->index('occurredAt');
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth / social identities linked to a local user account. One user can
 * have several identities (Google + Facebook), and a single provider
 * identity maps to exactly one user — enforced by the unique
 * (provider, providerUserId) index.
 *
 * Kept as a separate table (rather than columns on `users`) so new
 * providers — Apple, etc. — drop in without a schema change. See
 * docs/AUTH-AND-PERMISSIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('userId');
            $table->string('provider', 32);          // 'google' | 'facebook' | …
            $table->string('providerUserId');        // stable id from the provider
            $table->string('email')->nullable();     // email reported by provider (reference)
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();

            $table->unique(['provider', 'providerUserId']);
            $table->index('userId');
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};

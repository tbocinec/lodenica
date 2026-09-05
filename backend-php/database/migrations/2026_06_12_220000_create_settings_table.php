<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic single-row-per-key settings store: rich-text content pages the
 * admin edits (reservation rules, FAQ, privacy policy), JSON blobs for
 * structured settings (mail switches, site config). `key` is the primary
 * key, `value` a TEXT blob. No history here; audit_logs already records
 * who changed what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function ($table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updatedAt')->useCurrent();
        });

        // Content pages (reservation rules, FAQ, privacy policy) are seeded
        // by Database\Seeders\ContentSeeder from templates, so a fresh
        // install of another club never gets KVŠ-specific text.
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

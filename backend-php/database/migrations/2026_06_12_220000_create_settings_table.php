<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic single-row-per-key settings store. First user is the rich-text
 * reservation rules page that admins can edit and members read. Keep it
 * deliberately simple — `key` is the primary key, `value` is a TEXT blob
 * (HTML for the rules, JSON for future structured settings). No history
 * here; audit_logs already records who changed what.
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

        // Seed the reservation_rules row from the canonical HTML kept in
        // git so a fresh install starts with the same content the live
        // admin can later edit (or that `scripts/set-reservation-rules.sh`
        // pushes to an already-deployed instance).
        $rulesHtmlPath = __DIR__.'/../../deploy/reservation-rules.html';
        $defaultRules = is_readable($rulesHtmlPath)
            ? (string) file_get_contents($rulesHtmlPath)
            : '<h2>Pravidlá rezervácie</h2><p>Obsah doplní administrátor cez tlačidlo „Upraviť“.</p>';

        DB::table('settings')->insert([
            'key' => 'reservation_rules',
            'value' => $defaultRules,
            'updatedAt' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

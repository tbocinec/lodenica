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

        // Seed a default "reservation_rules" row so the frontend always
        // has something to render before an admin first edits it.
        DB::table('settings')->insert([
            'key' => 'reservation_rules',
            'value' => '<h2>Pravidlá rezervácie</h2>'
                ."\n".'<p>Tu bude obsah pravidiel rezervácie. Administrátor ho môže upraviť cez tlačidlo „Upraviť“.</p>',
            'updatedAt' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

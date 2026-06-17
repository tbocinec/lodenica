<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `privacy_policy` settings row with the canonical default HTML
 * (deploy/privacy-policy.html), mirroring how reservation_rules was seeded
 * in create_settings_table. Idempotent: only inserts if the row is missing,
 * so an admin's later edits are never clobbered on re-deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', 'privacy_policy')->exists();
        if ($exists) {
            return;
        }

        $htmlPath = __DIR__.'/../../deploy/privacy-policy.html';
        $default = is_readable($htmlPath)
            ? (string) file_get_contents($htmlPath)
            : '<h2>Ochrana osobných údajov</h2><p>Obsah doplní administrátor cez tlačidlo „Upraviť“.</p>';

        DB::table('settings')->insert([
            'key' => 'privacy_policy',
            'value' => $default,
            'updatedAt' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'privacy_policy')->delete();
    }
};

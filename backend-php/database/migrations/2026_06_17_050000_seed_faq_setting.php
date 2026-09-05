<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Historical: used to insert the KVŠ `faq` page. Content pages are now
 * seeded per installation by Database\Seeders\ContentSeeder from
 * templates, so this migration is kept only so the `migrations` table
 * history stays valid on databases that already ran it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty — see ContentSeeder.
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'faq')->delete();
    }
};

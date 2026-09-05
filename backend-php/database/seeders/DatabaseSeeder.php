<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed --force` runs on EVERY deploy (install.php), so
 * everything here is idempotent on a live database:
 *
 *  - AdminSeeder    — guarantees one admin (from ADMIN_* on a first install)
 *  - ContentSeeder  — default rules / FAQ / privacy pages when missing
 *  - DemoDataSeeder — sample boats, only in `local` or with SEED_DEMO_DATA
 *
 * Anything destructive is an importer (an Artisan command behind an
 * explicit flag), never a seeder — see AGENTS.md.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            ContentSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}

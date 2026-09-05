<?php

namespace Database\Seeders;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Guarantees one admin account exists (INST-002). Runs on every deploy.
 *
 *  - an ADMIN already exists → nothing (the configured ADMIN_EMAIL account
 *    is re-enabled if someone deactivated or demoted it)
 *  - none → create from ADMIN_EMAIL / ADMIN_PASSWORD (+ ADMIN_NAME)
 *  - none and no credentials → local/testing fall back to the dev account;
 *    anywhere else the seed THROWS so install.php fails visibly instead of
 *    leaving a production site with a well-known password.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) (config('site.admin.email') ?? '');
        $password = (string) (config('site.admin.password') ?? '');
        $name = (string) (config('site.admin.name') ?: 'Administrátor');

        if (User::query()->where('role', UserRole::ADMIN)->exists()) {
            if ($email !== '') {
                $configured = User::query()->where('email', $email)->first();
                if ($configured && (!$configured->isActive || !$configured->isAdmin())) {
                    $configured->isActive = true;
                    $configured->role = UserRole::ADMIN;
                    $configured->save();
                    $this->command?->info("Re-enabled admin {$email}.");
                }
            }
            $this->command?->info('Admin already present.');

            return;
        }

        if ($email === '' || $password === '') {
            if (app()->environment('local', 'testing')) {
                $email = 'admin@lodenica.sk';
                $password = 'Lodenica2026!';
                $this->command?->warn("No ADMIN_EMAIL/ADMIN_PASSWORD — using the dev account {$email} / {$password}.");
            } else {
                throw new \RuntimeException(
                    'No admin account exists and ADMIN_EMAIL / ADMIN_PASSWORD are not set. '
                    .'A first install needs both in the deploy secrets (see docs/CLIENT-ONBOARDING.md).'
                );
            }
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::ADMIN,
            'isActive' => true,
        ]);

        $this->command?->warn("Created admin {$email} — change the password after the first login.");
    }
}

<?php

namespace App\Services;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bulk-create accounts from a pasted/uploaded CSV of "name,email" rows.
 * The admin is doing the inviting, so each account is created already
 * CONFIRMED (MEMBER) — no separate approval step — and gets an invitation
 * email with a set-your-password link. (Self-registration + first OAuth
 * login still land PENDING; those aren't admin-vetted.)
 *
 * Robust by design: duplicates are skipped (not errors), malformed rows
 * are reported, and an email send that fails for one row doesn't abort the
 * rest. The caller gets a summary it can show the admin.
 */
class BulkUserImportService
{
    public function __construct(
        private readonly UsersService $users,
        private readonly PasswordResetService $passwordReset,
    ) {}

    /**
     * @return array{created: array<int,array{name:string,email:string}>, skipped: array<int,string>, invalid: array<int,string>}
     */
    public function import(string $csv): array
    {
        $created = [];
        $skipped = [];
        $invalid = [];

        foreach ($this->rows($csv) as $row) {
            [$name, $email] = $row;
            $email = strtolower(trim($email));
            $name = trim($name);

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $row[1] !== '' ? $row[1] : '(prázdny riadok)';
                continue;
            }
            if ($name === '') {
                $name = Str::before($email, '@');
            }

            if (User::query()->where('email', $email)->exists()) {
                $skipped[] = $email;
                continue;
            }

            $user = $this->users->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(40), // placeholder; set via invite link
                'role' => UserRole::MEMBER, // admin-invited → auto-confirmed
                'isActive' => true,
            ]);

            try {
                $this->passwordReset->sendInvitation($user);
            } catch (\Throwable $e) {
                // The account exists; only the email failed. Don't roll back —
                // the admin can resend later. Surface in logs.
                Log::warning('Bulk import: invitation email failed for '.$email.': '.$e->getMessage());
            }

            $created[] = ['name' => $user->name, 'email' => $user->email];
        }

        return ['created' => $created, 'skipped' => $skipped, 'invalid' => $invalid];
    }

    /**
     * Parse CSV into [name, email] pairs. Accepts comma or semicolon
     * separators, an optional header row, and "email" or "name,email"
     * column orders (best-effort: the cell that looks like an email is the
     * email, the other is the name).
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function rows(string $csv): array
    {
        $rows = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $sep = str_contains($line, ';') && !str_contains($line, ',') ? ';' : ',';
            $cells = array_map('trim', str_getcsv($line, $sep));

            // Skip an obvious header row.
            if ($i === 0) {
                $lower = strtolower(implode(',', $cells));
                if (str_contains($lower, 'email') || str_contains($lower, 'meno') || str_contains($lower, 'name')) {
                    continue;
                }
            }

            $email = '';
            $name = '';
            foreach ($cells as $cell) {
                if ($email === '' && filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                    $email = $cell;
                } elseif ($name === '') {
                    $name = $cell;
                }
            }
            // Single-column "email only" files.
            if ($email === '' && count($cells) === 1) {
                $email = $cells[0];
            }

            $rows[] = [$name, $email];
        }

        return $rows;
    }
}

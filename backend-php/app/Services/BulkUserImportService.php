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
            [$name, $email, $memberId] = $row;
            $email = strtolower(trim($email));
            $name = trim($name);
            $memberId = trim($memberId);

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $email !== '' ? $email : '(prázdny riadok)';
                continue;
            }
            if ($name === '') {
                $name = Str::before($email, '@');
            }

            if (User::query()->where('email', $email)->exists()) {
                $skipped[] = $email;
                continue;
            }

            // Member ID must be unique across users; report a clash instead
            // of failing the whole batch.
            if ($memberId !== '' && User::query()->where('memberId', $memberId)->exists()) {
                $invalid[] = "{$email} (členské ID „{$memberId}“ je už použité)";
                continue;
            }

            try {
                $user = $this->users->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Str::random(40), // placeholder; set via invite link
                    'role' => UserRole::MEMBER, // admin-invited → auto-confirmed
                    'isActive' => true,
                    'memberId' => $memberId !== '' ? $memberId : null,
                ]);
            } catch (\Throwable $e) {
                // e.g. a race on the unique memberId/email — skip this row,
                // keep the rest of the batch going.
                Log::warning('Bulk import: create failed for '.$email.': '.$e->getMessage());
                $invalid[] = "{$email} (nepodarilo sa vytvoriť)";
                continue;
            }

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
     * Parse CSV into [name, email, memberId] triples. Accepts comma or
     * semicolon separators and an optional header row. The cell that looks
     * like an email is the email; of the remaining cells (in order) the first
     * is the name and the second is the internal member ID — so the natural
     * layout is `meno,email,id`, but order is forgiving.
     *
     * @return array<int,array{0:string,1:string,2:string}>
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
            $others = [];
            foreach ($cells as $cell) {
                if ($email === '' && filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                    $email = $cell;
                } else {
                    $others[] = $cell;
                }
            }
            // Single-column "email only" files.
            if ($email === '' && count($cells) === 1) {
                $email = $cells[0];
                $others = [];
            }

            $name = $others[0] ?? '';
            $memberId = $others[1] ?? '';

            $rows[] = [$name, $email, $memberId];
        }

        return $rows;
    }
}

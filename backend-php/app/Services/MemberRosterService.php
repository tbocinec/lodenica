<?php

namespace App\Services;

use App\Exceptions\ConflictDomainException;
use App\Exceptions\NotFoundDomainException;
use App\Models\MemberRosterEntry;
use App\Models\User;

/**
 * Manages the member roster ("číselník") and applies it to registrations.
 *
 * On registration we look the email up here; a hit pre-approves the account
 * and hands it the roster's member ID. See {@see findMatch} +
 * {@see markRegistered}, called from the auth/OAuth registration flows.
 */
class MemberRosterService
{
    /** @return array{items: \Illuminate\Support\Collection<int,MemberRosterEntry>, total: int} */
    public function list(array $options): array
    {
        $query = MemberRosterEntry::query();

        if (!empty($options['search'])) {
            $q = '%'.strtolower(trim((string) $options['search'])).'%';
            $query->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(email) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(COALESCE("memberId", \'\')) LIKE ?', [$q]);
            });
        }
        if (array_key_exists('registered', $options) && $options['registered'] !== null) {
            $options['registered']
                ? $query->whereNotNull('registeredUserId')
                : $query->whereNull('registeredUserId');
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderBy('email')
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 50)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    public function create(string $email, ?string $memberId, ?string $name): MemberRosterEntry
    {
        $email = strtolower(trim($email));
        if (MemberRosterEntry::query()->where('email', $email)->exists()) {
            throw new ConflictDomainException('Tento e-mail už v číselníku je.');
        }
        $this->assertMemberIdFree($memberId, null);

        return MemberRosterEntry::create([
            'email' => $email,
            'memberId' => $this->norm($memberId),
            'name' => $this->norm($name),
        ]);
    }

    public function update(string $id, array $input): MemberRosterEntry
    {
        $entry = $this->require($id);

        if (array_key_exists('email', $input)) {
            $email = strtolower(trim((string) $input['email']));
            if ($email !== $entry->email
                && MemberRosterEntry::query()->where('email', $email)->exists()) {
                throw new ConflictDomainException('Tento e-mail už v číselníku je.');
            }
            $entry->email = $email;
        }
        if (array_key_exists('memberId', $input)) {
            $this->assertMemberIdFree($input['memberId'], $entry->id);
            $entry->memberId = $this->norm($input['memberId']);
        }
        if (array_key_exists('name', $input)) {
            $entry->name = $this->norm($input['name']);
        }
        $entry->save();

        return $entry;
    }

    public function delete(string $id): void
    {
        $this->require($id)->delete();
    }

    /**
     * Import "id,meno,email" rows. Existing emails are skipped; rows whose
     * member ID collides with another roster row are flagged invalid.
     *
     * @return array{created:int, skipped:array<int,string>, invalid:array<int,string>}
     */
    public function import(string $csv): array
    {
        $created = 0;
        $skipped = [];
        $invalid = [];

        foreach ($this->rows($csv) as [$memberId, $name, $email]) {
            $email = strtolower(trim($email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $email !== '' ? $email : '(prázdny riadok)';
                continue;
            }
            if (MemberRosterEntry::query()->where('email', $email)->exists()) {
                $skipped[] = $email;
                continue;
            }
            $memberId = trim($memberId);
            if ($memberId !== ''
                && MemberRosterEntry::query()->where('memberId', $memberId)->exists()) {
                $invalid[] = "{$email} (ID „{$memberId}“ je už v číselníku)";
                continue;
            }
            MemberRosterEntry::create([
                'email' => $email,
                'memberId' => $memberId !== '' ? $memberId : null,
                'name' => trim($name) !== '' ? trim($name) : null,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'invalid' => $invalid];
    }

    /** Find an unclaimed roster entry for this email (the registration hook). */
    public function findMatch(string $email): ?MemberRosterEntry
    {
        return MemberRosterEntry::query()
            ->where('email', strtolower(trim($email)))
            ->first();
    }

    /** Stamp a roster entry with the user that just registered against it. */
    public function markRegistered(MemberRosterEntry $entry, User $user): void
    {
        $entry->registeredUserId = $user->id;
        $entry->registeredAt = now();
        $entry->save();
    }

    private function assertMemberIdFree(?string $memberId, ?string $ignoreId): void
    {
        $memberId = $this->norm($memberId);
        if ($memberId === null) {
            return;
        }
        $clash = MemberRosterEntry::query()
            ->where('memberId', $memberId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($clash) {
            throw new ConflictDomainException('Toto ID už v číselníku je.');
        }
    }

    private function norm(?string $v): ?string
    {
        $v = $v !== null ? trim($v) : null;

        return $v === '' ? null : $v;
    }

    private function require(string $id): MemberRosterEntry
    {
        $entry = MemberRosterEntry::find($id);
        if ($entry === null) {
            throw new NotFoundDomainException('MemberRosterEntry', $id);
        }

        return $entry;
    }

    /**
     * Parse CSV into [memberId, name, email] triples. Column order is
     * id,meno,email; the email is matched by shape so order is forgiving.
     * 2 non-email cells → [id, name]; 1 → treated as name.
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

            if ($i === 0) {
                $lower = strtolower(implode(',', $cells));
                if (str_contains($lower, 'email') || str_contains($lower, 'meno')
                    || str_contains($lower, 'name') || str_contains($lower, 'id')) {
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
            if ($email === '' && count($cells) === 1) {
                $email = $cells[0];
                $others = [];
            }

            if (count($others) >= 2) {
                $memberId = $others[0];
                $name = $others[1];
            } else {
                $memberId = '';
                $name = $others[0] ?? '';
            }

            $rows[] = [$memberId, $name, $email];
        }

        return $rows;
    }
}

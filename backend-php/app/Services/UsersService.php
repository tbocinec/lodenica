<?php

namespace App\Services;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Domain\Enums\UserRole;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundDomainException;
use App\Models\Reservation;
use App\Models\User;

class UsersService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationMailer $mailer,
    ) {}

    public function create(array $input): User
    {
        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => $input['role'] instanceof UserRole
                ? $input['role']
                : UserRole::from($input['role']),
            'isActive' => $input['isActive'] ?? true,
            'memberId' => $input['memberId'] ?? null,
            'privacyAck' => $input['privacyAck'] ?? null,
            'dataConsent' => $input['dataConsent'] ?? null,
            'rulesAck' => $input['rulesAck'] ?? null,
            'passwordSetAt' => $input['passwordSetAt'] ?? null,
            'gdprConsentAt' => $input['gdprConsentAt'] ?? null,
            'rulesAckAt' => $input['rulesAckAt'] ?? null,
        ]);

        $this->audit->logCreate(
            AuditEntityType::USER,
            $user,
            "Pridaný používateľ „{$user->name}“ ({$user->email})",
            $this->snapshot($user),
        );

        return $user;
    }

    public function update(string $id, array $input, User $actor): User
    {
        $user = $this->requireExisting($id);
        $before = $this->snapshot($user);

        // Guard: an admin must not strip themselves of admin or deactivate
        // themselves — otherwise the system can lock itself out.
        if ($actor->id === $user->id) {
            if (array_key_exists('role', $input) && $input['role'] !== UserRole::ADMIN->value) {
                throw new ForbiddenException('Nemôžete odobrať vlastnú admin rolu.');
            }
            if (array_key_exists('isActive', $input) && $input['isActive'] === false) {
                throw new ForbiddenException('Nemôžete deaktivovať svoj vlastný účet.');
            }
        }

        $updates = array_intersect_key($input, array_flip(['name', 'email', 'role', 'isActive', 'memberId']));
        if (!empty($input['password'])) {
            $updates['password'] = $input['password']; // hashed via cast
        }
        if (isset($updates['role']) && !$updates['role'] instanceof UserRole) {
            $updates['role'] = UserRole::from($updates['role']);
        }

        $user->fill($updates);
        $user->save();
        $user->refresh();

        $after = $this->snapshot($user);
        // Mask password-change in the audit log: don't log the hashed value,
        // just record that the password was changed.
        if (array_key_exists('password', $updates)) {
            $before['passwordChanged'] = false;
            $after['passwordChanged'] = true;
        }

        $this->audit->logUpdate(
            AuditEntityType::USER,
            $user,
            "Upravený používateľ „{$user->name}“ ({$user->email})",
            $before,
            $after,
        );

        if (array_key_exists('memberId', $updates)) {
            $this->linkReservationsToMember($user);
        }

        return $user;
    }

    public function delete(string $id, User $actor): void
    {
        $user = $this->requireExisting($id);
        if ($actor->id === $user->id) {
            throw new ForbiddenException('Nemôžete zmazať svoj vlastný účet.');
        }

        $snapshot = $this->snapshot($user);
        $name = $user->name;
        $email = $user->email;
        $user->tokens()->delete();
        $user->delete();

        $this->audit->logDelete(
            AuditEntityType::USER,
            $user,
            "Zmazaný používateľ „{$name}“ ({$email})",
            $snapshot,
        );
    }

    public function findById(string $id): User
    {
        return $this->requireExisting($id);
    }

    /**
     * Promote a PENDING account to MEMBER. Idempotent for accounts that
     * are already MEMBER (returns them as-is). Rejects ADMIN promotion
     * attempts — admin role transitions go via the full update endpoint
     * with audit context.
     */
    public function confirmPending(string $id, User $actor, ?string $memberId = null): User
    {
        $user = $this->requireExisting($id);
        if ($user->role === UserRole::ADMIN) {
            throw new \App\Exceptions\ConflictDomainException(
                'Administrátor sa nedá potvrdiť ako bežný člen.',
            );
        }
        if ($user->role === UserRole::MEMBER) {
            // Already a member — still allow assigning/updating the member ID.
            if ($memberId !== null && $memberId !== '' && $memberId !== $user->memberId) {
                $this->assignMemberId($user, $memberId);
            }

            return $user;
        }

        $before = $this->snapshot($user);
        $user->role = UserRole::MEMBER;
        if (!$user->isActive) {
            $user->isActive = true;
        }
        // Admin assigns the internal member ID at confirmation time.
        if ($memberId !== null && $memberId !== '') {
            $user->memberId = $memberId;
        }
        $user->save();
        $user->refresh();
        $this->linkReservationsToMember($user);

        $this->audit->logUpdate(
            AuditEntityType::USER,
            $user,
            "Potvrdený nový člen „{$user->name}“ ({$user->email})",
            $before,
            $this->snapshot($user),
        );

        // Let the new member know they've been approved. Email failure must
        // not undo the confirmation — log and move on.
        try {
            $loginUrl = rtrim((string) config('app.url'), '/').'/login';
            $this->mailer->send(
                MailNotification::MEMBERSHIP_APPROVED,
                $user->email,
                new \App\Mail\MembershipApprovedMail($user->name, $loginUrl),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Membership-approved email failed for '.$user->email.': '.$e->getMessage(),
            );
        }

        return $user;
    }

    public function list(array $options): array
    {
        $query = User::query();

        if (!empty($options['role'])) {
            $role = $options['role'] instanceof UserRole
                ? $options['role']
                : UserRole::from($options['role']);
            $query->where('role', $role->value);
        }
        if (array_key_exists('isActive', $options) && $options['isActive'] !== null) {
            $query->where('isActive', (bool) $options['isActive']);
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('role')
            ->orderBy('name')
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 50)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Assign a member ID to an already-saved user, rejecting a value held by
     * someone else with a clear message (rather than a raw DB error).
     */
    private function assignMemberId(User $user, string $memberId): void
    {
        $clash = User::query()
            ->where('memberId', $memberId)
            ->where('id', '!=', $user->id)
            ->exists();
        if ($clash) {
            throw new \App\Exceptions\ConflictDomainException(
                'Toto členské ID už má priradené iný používateľ.',
            );
        }
        $user->memberId = $memberId;
        $user->save();
        $user->refresh();
        $this->linkReservationsToMember($user);
    }

    /**
     * Tag the user's own reservations (the ones they created) with their
     * current member ID, so "my reservations" follows the member identity.
     * No-op when the user has no ID. We never clear it back to null — when
     * an ID later moves to a different account, the old reservations keep
     * the ID so the new holder inherits the history.
     */
    private function linkReservationsToMember(User $user): void
    {
        if ($user->memberId === null || $user->memberId === '') {
            return;
        }
        Reservation::query()
            ->where('createdById', $user->id)
            ->update(['memberId' => $user->memberId]);
    }

    private function snapshot(User $u): array
    {
        return [
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role?->value,
            'isActive' => (bool) $u->isActive,
            'memberId' => $u->memberId,
        ];
    }

    private function requireExisting(string $id): User
    {
        $user = User::find($id);
        if ($user === null) {
            throw new NotFoundDomainException('User', $id);
        }

        return $user;
    }
}

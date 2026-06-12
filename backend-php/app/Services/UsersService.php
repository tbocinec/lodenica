<?php

namespace App\Services;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\UserRole;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundDomainException;
use App\Models\User;

class UsersService
{
    public function __construct(private readonly AuditLogger $audit) {}

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

        $updates = array_intersect_key($input, array_flip(['name', 'email', 'role', 'isActive']));
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

    private function snapshot(User $u): array
    {
        return [
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role?->value,
            'isActive' => (bool) $u->isActive,
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

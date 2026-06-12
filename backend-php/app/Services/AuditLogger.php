<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Append-only audit logger. Services call into this to record every
 * meaningful change. Writes are best-effort: if the audit insert fails
 * (DB hiccup, schema drift in a staging env, …) the original business
 * operation MUST still complete, so failures are swallowed and logged
 * as warnings rather than re-thrown.
 *
 * Field selection for `before` / `after` is the responsibility of the
 * caller — services pass plain arrays so the audit row stays compact
 * (no relations, no internal pivots). For updates {@see diffSnapshots}
 * collapses unchanged keys so the JSONB blob contains only what moved.
 */
class AuditLogger
{
    public function logCreate(
        AuditEntityType $entityType,
        Model $entity,
        string $summary,
        array $snapshot,
    ): ?AuditLog {
        return $this->write(
            $entityType,
            (string) $entity->getKey(),
            AuditAction::CREATE,
            $summary,
            ['after' => $snapshot],
        );
    }

    public function logUpdate(
        AuditEntityType $entityType,
        Model $entity,
        string $summary,
        array $before,
        array $after,
    ): ?AuditLog {
        [$beforeDiff, $afterDiff] = $this->diffSnapshots($before, $after);
        if (empty($beforeDiff) && empty($afterDiff)) {
            return null;
        }

        return $this->write(
            $entityType,
            (string) $entity->getKey(),
            AuditAction::UPDATE,
            $summary,
            ['before' => $beforeDiff, 'after' => $afterDiff],
        );
    }

    public function logDelete(
        AuditEntityType $entityType,
        Model $entity,
        string $summary,
        array $snapshot,
    ): ?AuditLog {
        return $this->write(
            $entityType,
            (string) $entity->getKey(),
            AuditAction::DELETE,
            $summary,
            ['before' => $snapshot],
        );
    }

    public function logAction(
        AuditEntityType $entityType,
        string $entityId,
        AuditAction $action,
        string $summary,
        array $changes = [],
    ): ?AuditLog {
        return $this->write($entityType, $entityId, $action, $summary, $changes ?: null);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diffSnapshots(array $before, array $after): array
    {
        $beforeDiff = [];
        $afterDiff = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if (!$this->looselyEquals($oldValue, $newValue)) {
                $beforeDiff[$key] = $oldValue;
                $afterDiff[$key] = $newValue;
            }
        }

        // Keys that existed in `before` but were dropped in `after`
        foreach ($before as $key => $oldValue) {
            if (!array_key_exists($key, $after)) {
                $beforeDiff[$key] = $oldValue;
                $afterDiff[$key] = null;
            }
        }

        return [$beforeDiff, $afterDiff];
    }

    /**
     * Display name for the actor of the current change. Pulls the auth'd
     * user's email if there is one; falls back to a literal "anonymous"
     * marker so anonymous writes are still visible in the audit log
     * (we intentionally let many endpoints work without login).
     */
    private function currentActor(): string
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user instanceof User && !empty($user->email)) {
            return $user->email;
        }

        return 'anonymous';
    }

    private function looselyEquals(mixed $a, mixed $b): bool
    {
        // DateTime-like objects compare by ISO string so casts don't trip diff.
        if ($a instanceof \DateTimeInterface) {
            $a = $a->format(\DateTimeInterface::ATOM);
        }
        if ($b instanceof \DateTimeInterface) {
            $b = $b->format(\DateTimeInterface::ATOM);
        }
        if (is_object($a) && method_exists($a, 'value')) {
            $a = $a->value;
        }
        if (is_object($b) && method_exists($b, 'value')) {
            $b = $b->value;
        }

        return $a === $b;
    }

    private function write(
        AuditEntityType $entityType,
        string $entityId,
        AuditAction $action,
        string $summary,
        ?array $changes,
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'entityType' => $entityType,
                'entityId' => $entityId,
                'action' => $action,
                'summary' => $summary,
                'changes' => $changes,
                'actor' => $this->currentActor(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit write failed', [
                'entityType' => $entityType->value,
                'entityId' => $entityId,
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

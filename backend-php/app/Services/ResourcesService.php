<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ResourceType;
use App\Exceptions\NotFoundDomainException;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application service for resource lifecycle. Keeps the controller thin and
 * gives a single seam for unit tests + future evolution.
 */
class ResourcesService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $input): Resource
    {
        return DB::transaction(function () use ($input) {
            $approverIds = $this->takeApproverIds($input);
            $resource = Resource::create($input);
            if ($approverIds !== null) {
                $this->syncApprovers($resource, $approverIds);
            }
            $resource->load('approvers');

            $this->audit->logCreate(
                AuditEntityType::RESOURCE,
                $resource,
                "Pridaná {$this->kind($resource)} „{$resource->identifier} – {$resource->name}“",
                AuditSnapshot::resource($resource),
            );

            return $resource;
        });
    }

    public function update(string $id, array $input): Resource
    {
        return DB::transaction(function () use ($id, $input) {
            $resource = $this->requireExisting($id);
            $before = AuditSnapshot::resource($resource);

            $approverIds = $this->takeApproverIds($input);
            $resource->fill($input);
            $resource->save();
            if ($approverIds !== null) {
                $this->syncApprovers($resource, $approverIds);
            }
            $resource->refresh()->load('approvers');

            $this->audit->logUpdate(
                AuditEntityType::RESOURCE,
                $resource,
                "Upravená {$this->kind($resource)} „{$resource->identifier}“",
                $before,
                AuditSnapshot::resource($resource),
            );

            return $resource;
        });
    }

    public function findById(string $id): Resource
    {
        return $this->requireExisting($id)->load('approvers');
    }

    /**
     * @return array{items: Collection<int, Resource>, total: int}
     */
    public function list(array $options): array
    {
        $query = Resource::query();

        if (!empty($options['type'])) {
            $type = $options['type'] instanceof ResourceType
                ? $options['type']
                : ResourceType::from($options['type']);
            $query->where('type', $type->value);
        }

        if (array_key_exists('isActive', $options) && $options['isActive'] !== null) {
            $query->where('isActive', (bool) $options['isActive']);
        }

        if (!empty($options['search'])) {
            $needle = '%'.strtolower($options['search']).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(identifier) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(model) LIKE ?', [$needle]);
            });
        }

        $total = (clone $query)->count();

        $items = $query
            // Eager-loaded so ResourceResource can report the worst open
            // damage without one query per row — the picker pulls the
            // whole inventory in a single page. Approvers too, so
            // ResourceResource can name them for members without a query
            // per row.
            ->with(['openDamages', 'approvers'])
            ->orderBy('type')
            ->orderBy('identifier')
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 25)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    public function delete(string $id): void
    {
        $resource = $this->requireExisting($id);
        $snapshot = AuditSnapshot::resource($resource);
        $kind = $this->kind($resource);
        $resource->delete();

        $this->audit->logDelete(
            AuditEntityType::RESOURCE,
            $resource,
            "Zmazaná {$kind} „{$snapshot['identifier']} – {$snapshot['name']}“",
            $snapshot,
        );
    }

    public function setActive(string $id, bool $isActive): Resource
    {
        $resource = $this->requireExisting($id);
        $wasActive = (bool) $resource->isActive;
        $resource->isActive = $isActive;
        $resource->save();
        $resource->refresh();

        if ($wasActive !== $isActive) {
            $this->audit->logAction(
                AuditEntityType::RESOURCE,
                $resource->id,
                $isActive ? AuditAction::ACTIVATE : AuditAction::DEACTIVATE,
                ($isActive ? 'Aktivovaná ' : 'Deaktivovaná ')
                    .$this->kind($resource)." „{$resource->identifier}“",
                ['before' => ['isActive' => $wasActive], 'after' => ['isActive' => $isActive]],
            );
        }

        return $resource;
    }

    /**
     * Pull `approverIds` out of the input (it is not a column) — null when the
     * caller did not send the key at all, so a PATCH without it leaves the
     * list untouched.
     *
     * @return list<string>|null
     */
    private function takeApproverIds(array &$input): ?array
    {
        if (!array_key_exists('approverIds', $input)) {
            return null;
        }
        $ids = array_values(array_unique(array_filter((array) $input['approverIds'], 'is_string')));
        unset($input['approverIds']);

        return $ids;
    }

    /**
     * REZ-050: only confirmed members (or admins) may approve. `exists` in the
     * request already proved the accounts exist; this proves their role.
     *
     * @param  list<string>  $ids
     */
    private function syncApprovers(Resource $resource, array $ids): void
    {
        if ($ids !== []) {
            $eligible = User::query()->whereIn('id', $ids)->get()->filter(fn (User $u) => $u->isMember());
            if ($eligible->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'approverIds' => 'Schvaľovateľ musí byť potvrdený člen.',
                ]);
            }
        }
        $resource->approvers()->sync($ids);
    }

    /**
     * Slovak label for the resource subtype used in audit summaries.
     * Falls back to a generic "položka" if the enum gains a new case
     * we haven't covered yet.
     */
    private function kind(Resource $r): string
    {
        return match ($r->type) {
            ResourceType::KAYAK, ResourceType::SEA_KAYAK, ResourceType::WW_KAYAK => 'loď (kajak)',
            ResourceType::CANOE => 'loď (kanoe)',
            ResourceType::ROWING_BOAT => 'loď (veslica)',
            ResourceType::INFLATABLE_BOAT => 'loď (nafukovacia)',
            ResourceType::TRAILER => 'vozík',
            ResourceType::BOATHOUSE_SPACE => 'lodenicový priestor',
            default => 'položka',
        };
    }

    private function requireExisting(string $id): Resource
    {
        $resource = Resource::find($id);
        if ($resource === null) {
            throw new NotFoundDomainException('Resource', $id);
        }

        return $resource;
    }
}

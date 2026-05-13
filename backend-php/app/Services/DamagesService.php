<?php

namespace App\Services;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\DamageStatus;
use App\Exceptions\NotFoundDomainException;
use App\Models\Damage;
use App\Models\Resource;

class DamagesService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $cmd): Damage
    {
        $resource = Resource::find($cmd['resourceId']);
        if ($resource === null) {
            throw new NotFoundDomainException('Resource', $cmd['resourceId']);
        }

        $damage = Damage::create([
            'resourceId' => $cmd['resourceId'],
            'description' => $cmd['description'],
            'severity' => $cmd['severity'],
            'note' => $cmd['note'] ?? null,
            'status' => DamageStatus::REPORTED,
        ]);

        $this->audit->logCreate(
            AuditEntityType::DAMAGE,
            $damage,
            "Nahlásené poškodenie pre „{$resource->identifier} – {$resource->name}“",
            AuditSnapshot::damage($damage),
        );

        return $damage;
    }

    public function update(string $id, array $cmd): Damage
    {
        $damage = $this->requireExisting($id);
        $before = AuditSnapshot::damage($damage);

        $updates = array_intersect_key($cmd, array_flip([
            'description', 'severity', 'status', 'note',
        ]));

        $newStatus = $cmd['status'] ?? null;
        if ($newStatus !== null) {
            $statusEnum = $newStatus instanceof DamageStatus
                ? $newStatus
                : DamageStatus::from($newStatus);
            if ($statusEnum === DamageStatus::FIXED) {
                $updates['fixedAt'] = now();
            }
        }

        $damage->fill($updates);
        $damage->save();
        $damage->refresh();

        $resource = Resource::find($damage->resourceId);
        $label = $resource ? "„{$resource->identifier} – {$resource->name}“" : 'neznámy zdroj';

        $this->audit->logUpdate(
            AuditEntityType::DAMAGE,
            $damage,
            "Upravené poškodenie pre {$label}",
            $before,
            AuditSnapshot::damage($damage),
        );

        return $damage;
    }

    public function findById(string $id): Damage
    {
        return $this->requireExisting($id);
    }

    public function list(array $options): array
    {
        $query = Damage::query();

        if (!empty($options['resourceId'])) {
            $query->where('resourceId', $options['resourceId']);
        }
        if (!empty($options['status'])) {
            $status = $options['status'] instanceof DamageStatus
                ? $options['status']
                : DamageStatus::from($options['status']);
            $query->where('status', $status->value);
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('status')
            ->orderByDesc('reportedAt')
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 25)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    public function remove(string $id): void
    {
        $damage = $this->requireExisting($id);
        $snapshot = AuditSnapshot::damage($damage);
        $resource = Resource::find($damage->resourceId);
        $label = $resource ? "„{$resource->identifier} – {$resource->name}“" : 'neznámy zdroj';
        $damage->delete();

        $this->audit->logDelete(
            AuditEntityType::DAMAGE,
            $damage,
            "Zmazané poškodenie pre {$label}",
            $snapshot,
        );
    }

    private function requireExisting(string $id): Damage
    {
        $damage = Damage::find($id);
        if ($damage === null) {
            throw new NotFoundDomainException('Damage', $id);
        }

        return $damage;
    }
}

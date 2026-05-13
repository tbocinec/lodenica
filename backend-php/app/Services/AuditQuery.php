<?php

namespace App\Services;

use App\Models\AuditLog;

/**
 * Read-side companion to {@see AuditLogger}. Lists are most-recent-first
 * which is the only ordering the UI needs.
 */
class AuditQuery
{
    public function list(array $options): array
    {
        $query = AuditLog::query();

        if (!empty($options['entityType'])) {
            $query->where('entityType', is_string($options['entityType'])
                ? $options['entityType']
                : $options['entityType']->value);
        }
        if (!empty($options['entityId'])) {
            $query->where('entityId', $options['entityId']);
        }
        if (!empty($options['action'])) {
            $query->where('action', is_string($options['action'])
                ? $options['action']
                : $options['action']->value);
        }
        if (!empty($options['from'])) {
            $query->where('createdAt', '>=', $options['from']);
        }
        if (!empty($options['to'])) {
            $query->where('createdAt', '<', $options['to']);
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('createdAt')
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 50)
            ->get();

        return ['items' => $items, 'total' => $total];
    }
}

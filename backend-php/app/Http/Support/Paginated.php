<?php

namespace App\Http\Support;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape used by the Lodenica frontend for paginated lists. Mirrors the
 * NestJS `PaginatedResponseDto` contract.
 */
final class Paginated
{
    /**
     * @param iterable<int, mixed> $items
     */
    public static function from(
        iterable $items,
        int $total,
        int $page,
        int $pageSize,
        ?string $resource = null,
    ): array {
        $serialized = [];
        foreach ($items as $item) {
            $serialized[] = $resource
                ? (new $resource($item))->toArray(request())
                : $item;
        }

        return [
            'items' => $serialized,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }
}

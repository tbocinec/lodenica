<?php

namespace App\Http\Resources;

use App\Models\DamageComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DamageComment
 */
class DamageCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'damageId' => $this->damageId,
            // Null once the account is gone; authorName still holds the
            // name the comment was written under.
            'authorId' => $this->authorId,
            'authorName' => $this->authorName,
            'body' => $this->body,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}

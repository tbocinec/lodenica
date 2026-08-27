<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundDomainException;
use App\Models\Damage;
use App\Models\DamageComment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Discussion thread on a damage.
 *
 * Comments are not domain state, so posting one writes no audit entry —
 * the comment carries its own author and timestamp. Deleting one does get
 * audited: it destroys a record, and every destructive action in this app
 * leaves a trace.
 */
class DamageCommentsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, DamageComment> */
    public function listFor(string $damageId): Collection
    {
        $this->requireDamage($damageId);

        return DamageComment::query()
            ->where('damageId', $damageId)
            // Oldest first — a thread reads top to bottom.
            ->orderBy('createdAt')
            ->orderBy('id')
            ->get();
    }

    public function create(string $damageId, User $author, string $body): DamageComment
    {
        $this->requireDamage($damageId);

        return DamageComment::create([
            'damageId' => $damageId,
            'authorId' => $author->id,
            // Snapshot: the thread must stay readable after the account is
            // renamed or deleted.
            'authorName' => $author->name,
            'body' => $body,
        ]);
    }

    /**
     * Only the author or an admin. Anyone else gets a 403 — a member
     * mustn't be able to erase someone else's account of what happened.
     */
    public function delete(string $damageId, string $commentId, User $actor): void
    {
        $this->requireDamage($damageId);

        $comment = DamageComment::query()
            ->where('damageId', $damageId)
            ->where('id', $commentId)
            ->first();

        if ($comment === null) {
            throw new NotFoundDomainException('DamageComment', $commentId);
        }

        $isAuthor = $comment->authorId !== null && $comment->authorId === $actor->id;
        if (!$isAuthor && !$actor->isAdmin()) {
            throw new ForbiddenException('Zmazať komentár môže iba jeho autor alebo správca.');
        }

        $comment->delete();

        $this->audit->logAction(
            AuditEntityType::DAMAGE,
            $damageId,
            AuditAction::DELETE,
            "Zmazaný komentár od „{$comment->authorName}“",
        );
    }

    private function requireDamage(string $damageId): Damage
    {
        $damage = Damage::find($damageId);
        if ($damage === null) {
            throw new NotFoundDomainException('Damage', $damageId);
        }

        return $damage;
    }
}

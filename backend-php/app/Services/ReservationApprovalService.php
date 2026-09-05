<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ReservationStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundDomainException;
use App\Exceptions\ReservationNotPendingException;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may decide a waiting reservation and what a decision does (REZ-054 …
 * REZ-057, REZ-060). Kept apart from ReservationsService so that class stays
 * about lifecycle and overlaps.
 */
class ReservationApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReservationNotifier $notifier,
    ) {}

    public function approve(string $id, User $actor, ?string $note = null): Reservation
    {
        return $this->decide($id, $actor, $note, ReservationStatus::CONFIRMED);
    }

    public function reject(string $id, User $actor, ?string $note = null): Reservation
    {
        return $this->decide($id, $actor, $note, ReservationStatus::REJECTED);
    }

    /** REZ-054: admins always; members only when listed on the resource. */
    public function canDecide(User $user, Reservation $reservation): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if (!$user->isMember()) {
            return false;
        }

        return $reservation->resource->isApprover($user);
    }

    /**
     * Waiting reservations the user may decide, oldest slot first. Past
     * requests stay listed until decided or cancelled.
     *
     * @return array{items: \Illuminate\Support\Collection<int, Reservation>, total: int}
     */
    public function pendingFor(User $user, int $skip = 0, int $take = 50): array
    {
        $query = $this->pendingQuery($user);
        $total = (clone $query)->count();
        $items = $query->orderBy('startsAt')->orderBy('createdAt')->skip($skip)->take($take)->get();

        return ['items' => $items, 'total' => $total];
    }

    public function pendingCountFor(User $user): int
    {
        return $this->pendingQuery($user)->count();
    }

    private function pendingQuery(User $user): Builder
    {
        $query = Reservation::query()->where('status', ReservationStatus::PENDING_APPROVAL->value);
        if (!$user->isAdmin()) {
            $query->whereHas('resource.approvers', fn (Builder $q) => $q->whereKey($user->id));
        }

        return $query;
    }

    private function decide(string $id, User $actor, ?string $note, ReservationStatus $to): Reservation
    {
        $reservation = Reservation::find($id);
        if ($reservation === null) {
            throw new NotFoundDomainException('Reservation', $id);
        }
        // Permission before state, so an outsider learns nothing about it.
        if (!$this->canDecide($actor, $reservation)) {
            throw new ForbiddenException('Túto rezerváciu nemôžeš schvaľovať.');
        }

        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        // REZ-056: conditional write. Two approvers clicking at once both pass
        // the checks above; only the first row-level update finds the row
        // still pending, the other gets 0 rows and a 409.
        $affected = Reservation::query()
            ->whereKey($id)
            ->where('status', ReservationStatus::PENDING_APPROVAL->value)
            ->update([
                'status' => $to->value,
                'decidedById' => $actor->id,
                'decidedAt' => now(),
                'decisionNote' => $note,
            ]);
        if ($affected === 0) {
            throw new ReservationNotPendingException($id);
        }

        $reservation->refresh();

        $approved = $to === ReservationStatus::CONFIRMED;
        $this->audit->logAction(
            AuditEntityType::RESERVATION,
            $reservation->id,
            $approved ? AuditAction::APPROVE : AuditAction::REJECT,
            ($approved ? 'Schválená' : 'Zamietnutá')
                ." rezervácia „{$reservation->customerName}“ pre „{$reservation->resource->label()}“ ({$reservation->rangeLabel()})",
            [
                'before' => ['status' => ReservationStatus::PENDING_APPROVAL->value],
                'after' => ['status' => $to->value, 'decisionNote' => $note],
            ],
        );

        $this->notifier->decided($reservation);

        return $reservation;
    }
}

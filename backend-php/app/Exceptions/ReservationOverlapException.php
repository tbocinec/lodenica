<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class ReservationOverlapException extends DomainException
{
    /** @param string[] $conflictingReservationIds */
    public function __construct(string $resourceId, array $conflictingReservationIds)
    {
        parent::__construct(
            errorCode: 'RESERVATION_OVERLAP',
            message: 'Vybraný zdroj je v zadanom termíne už rezervovaný.',
            details: [
                'resourceId' => $resourceId,
                'conflictingReservationIds' => $conflictingReservationIds,
            ],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}

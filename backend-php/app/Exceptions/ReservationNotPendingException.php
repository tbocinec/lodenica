<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/** REZ-056: a decision is final; there is nothing left to approve or reject. */
class ReservationNotPendingException extends DomainException
{
    public function __construct(string $reservationId)
    {
        parent::__construct(
            errorCode: 'RESERVATION_NOT_PENDING',
            message: 'O tejto rezervácii už bolo rozhodnuté.',
            details: ['reservationId' => $reservationId],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}

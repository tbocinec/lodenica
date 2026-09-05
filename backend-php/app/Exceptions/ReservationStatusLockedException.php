<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/** REZ-058: the status of a waiting/rejected reservation is not editable through PATCH. */
class ReservationStatusLockedException extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct(errorCode: 'RESERVATION_STATUS_LOCKED', message: $message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}

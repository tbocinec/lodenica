<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InvalidDateRangeException extends DomainException
{
    public function __construct(string $message = 'Dátum konca rezervácie nesmie predchádzať dátumu začiatku.')
    {
        parent::__construct(
            errorCode: 'RESERVATION_INVALID_RANGE',
            message: $message,
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}

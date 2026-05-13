<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InactiveResourceException extends DomainException
{
    public function __construct(string $resourceId)
    {
        parent::__construct(
            errorCode: 'RESERVATION_RESOURCE_INACTIVE',
            message: 'Zdroj je neaktívny a nemôže byť rezervovaný.',
            details: ['resourceId' => $resourceId],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}

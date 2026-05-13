<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class ConflictDomainException extends DomainException
{
    public function __construct(string $message, ?array $details = null)
    {
        parent::__construct(
            errorCode: 'RESOURCE_CONFLICT',
            message: $message,
            details: $details,
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}

<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class ForbiddenException extends DomainException
{
    public function __construct(string $message = 'Na túto operáciu nemáte oprávnenie.')
    {
        parent::__construct('FORBIDDEN', $message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}

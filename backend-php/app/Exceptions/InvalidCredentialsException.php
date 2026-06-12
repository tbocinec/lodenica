<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('INVALID_CREDENTIALS', 'Neplatný email alebo heslo.');
    }

    public function httpStatus(): int
    {
        return Response::HTTP_UNAUTHORIZED;
    }
}

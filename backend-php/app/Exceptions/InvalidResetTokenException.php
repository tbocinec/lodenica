<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The password-reset / invitation token is missing, wrong, already used,
 * or expired. Same message for all cases so it doesn't reveal which.
 */
class InvalidResetTokenException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            errorCode: 'INVALID_RESET_TOKEN',
            message: 'Odkaz na obnovu hesla je neplatný alebo expiroval. Požiadajte o nový.',
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}

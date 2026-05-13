<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class NotFoundDomainException extends DomainException
{
    public function __construct(string $entity, string $id)
    {
        parent::__construct(
            errorCode: 'RESOURCE_NOT_FOUND',
            message: sprintf('%s with id "%s" was not found.', $entity, $id),
            details: ['entity' => $entity, 'id' => $id],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}

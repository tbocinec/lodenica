<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * REZ-051. 403 for anonymous callers too (not 401): the SPA treats 401 as
 * a lost session and would log a PENDING account out.
 */
class ApprovalMemberRequiredException extends DomainException
{
    public function __construct(string $resourceId)
    {
        parent::__construct(
            errorCode: 'RESERVATION_APPROVAL_MEMBER_REQUIRED',
            message: 'Tento zdroj vyžaduje schválenie. Rezervovať ho môže iba prihlásený člen klubu.',
            details: ['resourceId' => $resourceId],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}

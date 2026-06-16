<?php

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirmed-member gate: MEMBER or ADMIN passes through; PENDING and
 * anonymous get 403. Must run AFTER `auth:sanctum` so the request
 * user is populated. See docs/AUTH-AND-PERMISSIONS.md for the full
 * permission matrix.
 */
class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || !$user->isMember()) {
            throw new ForbiddenException(
                'Túto operáciu môže vykonať iba potvrdený člen klubu.',
            );
        }

        return $next($request);
    }
}

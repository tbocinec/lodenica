<?php

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pipes a request through only if the authenticated user has the ADMIN role.
 * Must run AFTER auth:sanctum so $request->user() is populated.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || !$user->isAdmin()) {
            throw new ForbiddenException('Túto operáciu môže vykonať iba administrátor.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\SiteConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `feature:<name>` — the route exists only on installations that switched
 * the module on (Administrácia → Nastavenia stránky → Moduly). Off means a
 * plain 404, the same answer as a route that was never registered.
 */
class EnsureFeature
{
    public function __construct(private readonly SiteConfig $site) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (!$this->site->feature($feature)) {
            throw new NotFoundHttpException('Táto funkcia nie je na tejto inštalácii zapnutá.');
        }

        return $next($request);
    }
}

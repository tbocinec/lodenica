<?php

use Illuminate\Support\Facades\Route;

/**
 * Dummy `login` route — Laravel's default AuthenticationException handler
 * tries to `redirect(route('login'))` for non-JSON requests; without this
 * named route, an anonymous hit on a protected API endpoint that didn't
 * send `Accept: application/json` 500s with "Route [login] not defined"
 * instead of returning the proper 401 JSON envelope. We force JSON here
 * so the response shape is consistent with the rest of the API.
 */
Route::get('/login', function () {
    return response()->json([
        'statusCode' => 401,
        'error' => 'Unauthorized',
        'code' => 'UNAUTHENTICATED',
        'message' => 'Pre túto operáciu sa musíte prihlásiť.',
    ], 401);
})->name('login');

/**
 * Serve the Vue SPA from public/index.html for any path that isn't an API
 * route. In production, the built frontend is dropped into public/, so
 * Apache will serve assets (JS/CSS/images) directly via its static-file
 * handler. Anything that falls through to Laravel renders the SPA shell
 * so Vue Router can take over.
 *
 * Local dev (no SPA dropped in yet) gets a tiny landing page instead.
 */
Route::fallback(function () {
    $spaIndex = public_path('index.html');
    if (file_exists($spaIndex)) {
        return response()->file($spaIndex);
    }

    return response()->json([
        'status' => 'ok',
        'service' => 'Lodenica API',
        'note' => 'Frontend not deployed here. API is at /api/v1/*.',
    ]);
});

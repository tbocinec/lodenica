<?php

use Illuminate\Support\Facades\Route;

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

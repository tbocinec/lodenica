<?php

/**
 * Laravel bootstrap for shared hosting (Websupport).
 *
 * Docroot is `$HOME/gart.sk/sub/tomas/` (this directory) and contains the
 * built Vue SPA. The Laravel application itself lives outside the docroot
 * at `$HOME/lodenica-app/` so `.env`, `app/`, `vendor/` and other secrets
 * are never web-accessible.
 *
 * `.htaccess` routes `/api/*`, `/health*` and `/up` here; everything else
 * is either a real static file (SPA assets) or falls back to `index.html`
 * so Vue Router can handle deep links.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$laravelRoot = realpath(__DIR__.'/../../../lodenica-app');
if ($laravelRoot === false || !is_dir($laravelRoot)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'statusCode' => 500,
        'error' => 'Internal Server Error',
        'message' => 'Laravel application directory not found.',
    ]);
    exit;
}

// Maintenance-mode handler (php artisan down)
if (file_exists($maintenance = $laravelRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravelRoot.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';

// Public path lives in the docroot, not under the app dir.
// Route::fallback() reads `public_path('index.html')` to serve the SPA shell.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());

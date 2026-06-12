<?php

/**
 * Laravel bootstrap for an all-in-docroot hosting layout (no path above
 * the SFTP root is reachable). The Laravel app lives inside the docroot
 * at ./laravel/ and is web-denied via the sibling `.htaccess` so vendor/,
 * app/, .env etc. are never served to clients.
 *
 * For deploys where the Laravel app can live outside the docroot (e.g.
 * SSH-enabled Websupport with a sibling $HOME/lodenica-app/), see the
 * stock bootstrap in ../websupport-docroot/index.php instead.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$laravelRoot = __DIR__ . '/laravel';
if (!is_dir($laravelRoot)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'statusCode' => 500,
        'error' => 'Internal Server Error',
        'message' => 'Laravel application directory not found at ./laravel — check the deploy.',
    ]);
    exit;
}

// Maintenance-mode handler (php artisan down)
if (file_exists($maintenance = $laravelRoot . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravelRoot . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot . '/bootstrap/app.php';

// Public path lives in the docroot (this dir), not under laravel/public/.
// Route::fallback() reads `public_path('index.html')` to serve the SPA shell.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());

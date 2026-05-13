<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function check(): array
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1');
            $database = 'up';
        } catch (\Throwable) {
            $database = 'down';
        }

        $start = defined('LARAVEL_START')
            ? \LARAVEL_START
            : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return [
            'status' => $database === 'up' ? 'ok' : 'degraded',
            'database' => $database,
            'uptimeSeconds' => (int) (microtime(true) - $start),
        ];
    }

    public function ready(): array
    {
        return ['ready' => true];
    }

    public function live(): array
    {
        return ['live' => true, 'statusCode' => 200];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side proxy for the Dunajčík "vodácky semafor" (paddling traffic
 * light) API. We proxy rather than call it from the browser to (a) dodge
 * CORS, (b) cache so we don't hammer their endpoint, and (c) degrade
 * gracefully — the SPA widget just hides itself on a 502.
 *
 * Data + traffic-light logic are Dunajčík's; we only relay + cache.
 * Source: https://dunajcik.sk/api/paddling-traffic-light
 */
class PaddlingTrafficLightController extends Controller
{
    private const SOURCE_URL = 'https://dunajcik.sk/api/paddling-traffic-light';
    private const CACHE_KEY = 'paddling_traffic_light';
    private const CACHE_TTL = 300; // 5 minutes

    public function show(): JsonResponse
    {
        $data = Cache::get(self::CACHE_KEY);

        if ($data === null) {
            try {
                $resp = Http::timeout(8)
                    ->acceptJson()
                    ->get(self::SOURCE_URL);

                if ($resp->successful() && $resp->json('status') === 'ok') {
                    $data = $resp->json('data');
                    Cache::put(self::CACHE_KEY, $data, self::CACHE_TTL);
                }
            } catch (\Throwable $e) {
                Log::warning('Paddling traffic light fetch failed: '.$e->getMessage());
            }
        }

        if ($data === null) {
            return new JsonResponse(
                ['error' => 'Vodácky semafor je momentálne nedostupný.'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse([
            'data' => $data,
            'source' => 'dunajcik.sk',
            'sourceUrl' => 'https://www.dunajcik.sk/vodacky-semafor',
        ]);
    }
}

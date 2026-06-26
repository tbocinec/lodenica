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

    // Devín gauge read straight from SHMÚ (dunajcik only covers Bratislava).
    private const DEVIN_URL = 'https://www.shmu.sk/sk/?page=765&station_id=5127';
    private const DEVIN_CACHE_KEY = 'paddling_devin';
    private const DEVIN_CACHE_TTL = 900; // 15 minutes (SHMÚ updates every 15 min)

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
            'devin' => $this->devin(),
            'source' => 'dunajcik.sk',
            'sourceUrl' => 'https://www.dunajcik.sk/vodacky-semafor',
        ]);
    }

    /**
     * Latest Devín-Dunaj reading (water level + temperature) scraped from the
     * SHMÚ station page's data table. Cached; returns null on any failure so
     * the SPA can simply omit the second card.
     *
     * @return array{water_level: array{value:int, unit:string}, water_temperature: array{value:float, unit:string}|null, measured_at: string, source: string}|null
     */
    private function devin(): ?array
    {
        // Cap SHMÚ to one hit per TTL regardless of outcome: a hit caches the
        // reading, a miss caches a 'none' sentinel so we don't re-poll for the
        // next 15 minutes. (Cache::get can't tell "absent" from a cached null,
        // hence the sentinel.)
        $cached = Cache::get(self::DEVIN_CACHE_KEY);
        if ($cached !== null) {
            return $cached === 'none' ? null : $cached;
        }

        $devin = $this->fetchDevin();
        Cache::put(self::DEVIN_CACHE_KEY, $devin ?? 'none', self::DEVIN_CACHE_TTL);

        return $devin;
    }

    /** @return array<string,mixed>|null */
    private function fetchDevin(): ?array
    {
        try {
            $resp = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (lodenica-rezervacie)'])
                ->get(self::DEVIN_URL);
            if (!$resp->successful()) {
                return null;
            }

            $html = $resp->body();
            // The data table is the only place carrying both columns. Anchor on
            // its header, then read the first body row: [datetime, level, temp].
            $anchor = mb_strpos($html, 'Teplota vody');
            if ($anchor === false) {
                return null;
            }
            $chunk = mb_substr($html, $anchor, 4000);
            if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $chunk, $m)) {
                return null;
            }
            $cells = array_map(static fn ($c) => trim(html_entity_decode(strip_tags($c))), $m[1]);
            if (count($cells) < 3) {
                return null;
            }
            [$measuredAt, $level, $temp] = array_slice($cells, 0, 3);
            if (!is_numeric($level)) {
                return null;
            }

            return [
                'water_level' => ['value' => (int) round((float) $level), 'unit' => 'cm'],
                'water_temperature' => is_numeric($temp)
                    ? ['value' => (float) $temp, 'unit' => 'C']
                    : null,
                'measured_at' => $measuredAt,
                'source' => 'SHMÚ',
            ];
        } catch (\Throwable $e) {
            Log::warning('Devín gauge fetch failed: '.$e->getMessage());

            return null;
        }
    }
}

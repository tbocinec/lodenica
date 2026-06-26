<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaddlingTrafficLightApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_proxies_and_relays_the_traffic_light_payload(): void
    {
        Http::fake([
            'dunajcik.sk/*' => Http::response([
                'status' => 'ok',
                'data' => [
                    'location' => ['name' => 'Bratislava', 'river' => 'Dunaj'],
                    'recommendation' => ['level' => 'green', 'label' => 'Vhodné podmienky'],
                    'danube' => ['water_level' => ['value' => 330, 'unit' => 'cm']],
                ],
            ], 200),
        ]);

        $this->getJson('/api/v1/paddling-traffic-light')
            ->assertOk()
            ->assertJsonPath('data.recommendation.level', 'green')
            ->assertJsonPath('data.location.name', 'Bratislava')
            ->assertJsonPath('source', 'dunajcik.sk');
    }

    public function test_returns_502_when_upstream_fails(): void
    {
        Http::fake(['dunajcik.sk/*' => Http::response('', 500)]);

        $this->getJson('/api/v1/paddling-traffic-light')
            ->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_caches_the_upstream_response(): void
    {
        Http::fake([
            'dunajcik.sk/*' => Http::response(['status' => 'ok', 'data' => ['x' => 1]], 200),
            'shmu.sk/*' => Http::response('', 200),
        ]);

        $this->getJson('/api/v1/paddling-traffic-light')->assertOk();
        $this->getJson('/api/v1/paddling-traffic-light')->assertOk();

        // Two upstream calls on the first request (dunajcik + SHMÚ Devín),
        // zero on the second — both are cached. SHMÚ is polled at most once
        // per 15 min thanks to the sentinel cache.
        Http::assertSentCount(2);
    }

    public function test_relays_devin_reading_from_shmu(): void
    {
        $html = '<table><th id="h_teplota_vody">Teplota vody [°C]</th><tbody>'
            .'<tr><td>26.6.2026 14:00</td><td>155</td><td>23.2</td></tr>'
            .'<tr><td>26.6.2026 13:45</td><td>156</td><td>23.1</td></tr></tbody></table>';

        Http::fake([
            'dunajcik.sk/*' => Http::response([
                'status' => 'ok',
                'data' => ['danube' => ['water_level' => ['value' => 295, 'unit' => 'cm']]],
            ], 200),
            'shmu.sk/*' => Http::response($html, 200),
        ]);

        $this->getJson('/api/v1/paddling-traffic-light')
            ->assertOk()
            ->assertJsonPath('devin.water_level.value', 155)
            ->assertJsonPath('devin.water_temperature.value', 23.2)
            ->assertJsonPath('devin.source', 'SHMÚ');
    }
}

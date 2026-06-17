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
        ]);

        $this->getJson('/api/v1/paddling-traffic-light')->assertOk();
        $this->getJson('/api/v1/paddling-traffic-light')->assertOk();

        // Only one upstream call thanks to the 5-minute cache.
        Http::assertSentCount(1);
    }
}

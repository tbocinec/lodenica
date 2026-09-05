<?php

namespace Tests\Feature\Api;

use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature switches in the site config hide whole modules from the API
 * (a 404, as if the route did not exist).
 */
class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_light_is_404_while_the_feature_is_off(): void
    {
        config(['site.features.paddling_traffic_light' => false]);

        $this->getJson('/api/v1/paddling-traffic-light')->assertStatus(404);
    }

    public function test_traffic_light_answers_when_the_feature_is_on(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'data' => ['x' => 1]])]);
        app(SiteConfig::class)->update(['features' => ['paddlingTrafficLight' => true]]);

        $this->getJson('/api/v1/paddling-traffic-light')->assertOk();
    }

    public function test_expeditions_are_404_while_the_feature_is_off(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/expeditions')->assertOk();

        app(SiteConfig::class)->update(['features' => ['expeditions' => false]]);

        $this->getJson('/api/v1/expeditions')->assertStatus(404);
        $this->postJson('/api/v1/expeditions', [])->assertStatus(404);
        $this->get('/api/v1/expeditions/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000001')
            ->assertStatus(404);
    }
}

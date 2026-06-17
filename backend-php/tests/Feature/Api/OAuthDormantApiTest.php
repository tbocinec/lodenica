<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OAuth ships dormant: no client id/secret configured in the test env, so
 * the provider list is empty and the redirect/callback routes 404. This
 * guards against accidentally exposing a half-wired social login.
 */
class OAuthDormantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_providers_list_is_empty_when_unconfigured(): void
    {
        $this->getJson('/api/v1/auth/providers')
            ->assertOk()
            ->assertJsonPath('providers', []);
    }

    public function test_redirect_route_404s_when_provider_unconfigured(): void
    {
        $this->get('/api/v1/auth/oauth/google/redirect')->assertStatus(404);
    }

    public function test_callback_route_404s_when_provider_unconfigured(): void
    {
        $this->get('/api/v1/auth/oauth/facebook/callback')->assertStatus(404);
    }

    public function test_unknown_provider_404s(): void
    {
        $this->get('/api/v1/auth/oauth/twitter/redirect')->assertStatus(404);
    }
}

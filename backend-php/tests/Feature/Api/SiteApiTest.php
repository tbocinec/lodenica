<?php

namespace Tests\Feature\Api;

use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The site identity endpoints: public read for the SPA shell, admin write.
 * SITE-002 (no adminEmail in public), SITE-003 (validation, no partial apply).
 */
class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_payload_has_branding_without_admin_email(): void
    {
        app(SiteConfig::class)->update(['siteName' => 'Klub Test', 'adminEmail' => 'spravca@example.test']);

        $this->getJson('/api/v1/site')
            ->assertOk()
            ->assertJsonPath('siteName', 'Klub Test')
            ->assertJsonPath('shortName', 'Klub Test')
            ->assertJsonPath('features.expeditions', true)
            ->assertJsonPath('logoUrl', null)
            ->assertJsonMissingPath('adminEmail');
    }

    public function test_admin_endpoints_are_admin_only(): void
    {
        $this->getJson('/api/v1/admin/site')->assertStatus(401);
        $this->patchJson('/api/v1/admin/site', ['siteName' => 'x'])->assertStatus(401);

        $this->actingAsMember();
        $this->getJson('/api/v1/admin/site')->assertStatus(403);
        $this->patchJson('/api/v1/admin/site', ['siteName' => 'x'])->assertStatus(403);
        $this->postJson('/api/v1/admin/site/logo')->assertStatus(403);
        $this->deleteJson('/api/v1/admin/site/logo')->assertStatus(403);
    }

    public function test_admin_reads_full_config_and_patches_partially(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/site')->assertOk()->assertJsonPath('adminEmail', null);

        $this->patchJson('/api/v1/admin/site', [
            'siteName' => 'Klub Test',
            'contactEmail' => 'klub@example.test',
            'features' => ['paddlingTrafficLight' => true],
        ])->assertOk()
            ->assertJsonPath('siteName', 'Klub Test')
            ->assertJsonPath('shortName', 'Klub Test')
            ->assertJsonPath('adminEmail', 'klub@example.test')
            ->assertJsonPath('features.paddlingTrafficLight', true)
            ->assertJsonPath('features.expeditions', true);

        // A second partial patch leaves the rest alone; null clears.
        $this->patchJson('/api/v1/admin/site', ['contactEmail' => null, 'theme' => 'forest'])
            ->assertOk()
            ->assertJsonPath('siteName', 'Klub Test')
            ->assertJsonPath('contactEmail', null)
            ->assertJsonPath('features.paddlingTrafficLight', true)
            ->assertJsonPath('theme', 'forest');
    }

    public function test_patch_rejects_invalid_values_and_unknown_keys(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/site', ['contactEmail' => 'not-an-email'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->patchJson('/api/v1/admin/site', ['rulesUrl' => 'javascript:alert(1)'])
            ->assertStatus(400);
        $this->patchJson('/api/v1/admin/site', ['theme' => 'Not Valid'])
            ->assertStatus(400);
        $this->patchJson('/api/v1/admin/site', ['siteName' => 'ok', 'bogus' => 1])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->patchJson('/api/v1/admin/site', ['features' => ['nope' => true]])
            ->assertStatus(400);

        // Nothing from the rejected payloads was applied (SITE-003).
        $this->assertSame('Lodenica', app(SiteConfig::class)->siteName());
    }

    public function test_logo_upload_serve_and_remove(): void
    {
        Storage::fake('local');
        $this->getJson('/api/v1/site/logo')->assertStatus(404);

        $this->actingAsAdmin();
        $this->post('/api/v1/admin/site/logo', ['logo' => UploadedFile::fake()->create('logo.png', 10, 'image/png')])
            ->assertOk()
            ->assertJsonPath('logoUrl', fn ($url) => is_string($url) && str_contains($url, '/api/v1/site/logo?v='));
        Storage::disk('local')->assertExists('site/logo.png');

        $this->get('/api/v1/site/logo')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');

        // Replacing with another format removes the old file.
        $this->post('/api/v1/admin/site/logo', ['logo' => UploadedFile::fake()->create('logo.webp', 10, 'image/webp')])
            ->assertOk();
        Storage::disk('local')->assertMissing('site/logo.png');
        Storage::disk('local')->assertExists('site/logo.webp');

        // SVG can carry scripts — not accepted.
        $this->post('/api/v1/admin/site/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertStatus(400);

        $this->deleteJson('/api/v1/admin/site/logo')->assertNoContent();
        Storage::disk('local')->assertMissing('site/logo.webp');
        $this->getJson('/api/v1/site')->assertJsonPath('logoUrl', null);
    }
}

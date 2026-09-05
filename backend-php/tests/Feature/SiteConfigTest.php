<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SITE-001: stored value → config('site.*') from .env → built-in default.
 * SITE-002: adminEmail never leaves through the public payload.
 */
class SiteConfigTest extends TestCase
{
    use RefreshDatabase;

    private function site(): SiteConfig
    {
        return app(SiteConfig::class);
    }

    public function test_built_in_defaults_apply_when_nothing_is_configured(): void
    {
        config(['site.name' => null, 'site.short_name' => null, 'site.theme' => null]);

        $this->assertSame('Lodenica', $this->site()->siteName());
        $this->assertSame('Lodenica', $this->site()->shortName());
        $this->assertSame('ocean', $this->site()->theme());
        $this->assertNull($this->site()->contactEmail());
        $this->assertFalse($this->site()->feature('paddlingTrafficLight'));
        $this->assertTrue($this->site()->feature('expeditions'));
    }

    public function test_env_config_beats_built_in_default(): void
    {
        config([
            'site.name' => 'Klub Env',
            'site.contact_email' => 'env@example.test',
            'site.features.paddling_traffic_light' => true,
        ]);

        $this->assertSame('Klub Env', $this->site()->siteName());
        $this->assertSame('Klub Env', $this->site()->shortName(), 'shortName falls back to siteName');
        $this->assertSame('env@example.test', $this->site()->contactEmail());
        $this->assertTrue($this->site()->feature('paddlingTrafficLight'));
    }

    public function test_env_feature_given_as_string_is_understood(): void
    {
        config(['site.features.paddling_traffic_light' => 'true', 'site.features.expeditions' => 'false']);

        $this->assertTrue($this->site()->feature('paddlingTrafficLight'));
        $this->assertFalse($this->site()->feature('expeditions'));
    }

    public function test_stored_value_beats_env_and_null_clears_it(): void
    {
        config(['site.name' => 'Klub Env']);
        $this->site()->update(['siteName' => 'Klub DB', 'features' => ['paddlingTrafficLight' => true]]);

        $this->assertSame('Klub DB', $this->site()->siteName());
        $this->assertTrue($this->site()->feature('paddlingTrafficLight'));

        $this->site()->update(['siteName' => null]);

        $this->assertSame('Klub Env', $this->site()->siteName());
        $this->assertTrue($this->site()->feature('paddlingTrafficLight'), 'untouched keys survive');
        $stored = json_decode(Setting::find(SiteConfig::SETTING_KEY)->value, true);
        $this->assertArrayNotHasKey('siteName', $stored);
    }

    public function test_empty_string_is_treated_as_clear(): void
    {
        $this->site()->update(['contactEmail' => 'a@example.test']);
        $this->site()->update(['contactEmail' => '']);

        $this->assertNull($this->site()->contactEmail());
    }

    public function test_unknown_keys_are_ignored_on_update(): void
    {
        $this->site()->update(['bogus' => 'x', 'features' => ['nope' => true], 'siteName' => 'Klub']);

        $stored = json_decode(Setting::find(SiteConfig::SETTING_KEY)->value, true);
        $this->assertSame(['siteName' => 'Klub'], $stored);
    }

    public function test_admin_email_falls_back_to_contact_email(): void
    {
        config(['site.admin_email' => null]);
        $this->site()->update(['contactEmail' => 'klub@example.test']);
        $this->assertSame('klub@example.test', $this->site()->adminEmail());

        $this->site()->update(['adminEmail' => 'spravca@example.test']);
        $this->assertSame('spravca@example.test', $this->site()->adminEmail());
    }

    public function test_public_payload_hides_admin_email(): void
    {
        $this->site()->update(['adminEmail' => 'spravca@example.test']);

        $public = $this->site()->publicPayload();
        $this->assertArrayNotHasKey('adminEmail', $public);
        $this->assertArrayHasKey('adminEmail', $this->site()->all());
        $this->assertSame(['paddlingTrafficLight' => false, 'expeditions' => true], $public['features']);
        $this->assertNull($public['logoUrl']);
    }

    public function test_logo_path_falls_back_to_a_file_on_disk(): void
    {
        Storage::fake('local');
        $this->assertNull($this->site()->logoPath());

        Storage::disk('local')->put('site/logo.png', 'png');
        $this->assertSame('site/logo.png', $this->site()->logoPath());
        $this->assertStringContainsString('/api/v1/site/logo?v=', (string) $this->site()->logoUrl());

        Storage::disk('local')->put('site/logo.webp', 'webp');
        $this->site()->setLogoPath('site/logo.webp');
        $this->assertSame('site/logo.webp', $this->site()->logoPath());
    }

    public function test_unknown_feature_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->site()->feature('nope');
    }
}

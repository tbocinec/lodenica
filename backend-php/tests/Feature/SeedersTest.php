<?php

namespace Tests\Feature;

use App\Domain\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Services\SiteConfig;
use Database\Seeders\AdminSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INST-001: db:seed is idempotent and puts no demo data outside `local`.
 * INST-002: a production install without an admin and without ADMIN_*
 * fails loudly.
 */
class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private function env(string $name): void
    {
        $this->app['env'] = $name;
    }

    /**
     * Run a seeder class directly. `$this->seed()` goes through `db:seed`,
     * which in a "production" environment asks for confirmation.
     *
     * @param  class-string<\Illuminate\Database\Seeder>  $class
     */
    private function runSeeder(string $class): void
    {
        app($class)->run();
    }

    public function test_admin_is_created_from_config_on_an_empty_database(): void
    {
        $this->env('production');
        config(['site.admin.email' => 'boss@example.test', 'site.admin.password' => 'S3cret!pass', 'site.admin.name' => 'Boss']);

        $this->runSeeder(AdminSeeder::class);

        $admin = User::query()->where('email', 'boss@example.test')->firstOrFail();
        $this->assertSame(UserRole::ADMIN, $admin->role);
        $this->assertSame('Boss', $admin->name);
        $this->assertTrue(password_verify('S3cret!pass', $admin->password));
    }

    public function test_production_without_credentials_and_without_admin_fails_loudly(): void
    {
        $this->env('production');
        config(['site.admin.email' => null, 'site.admin.password' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_EMAIL');
        $this->runSeeder(AdminSeeder::class);
    }

    public function test_existing_admin_means_no_op(): void
    {
        $this->env('production');
        $this->actingAsAdmin();
        config(['site.admin.email' => null, 'site.admin.password' => null]);

        $this->runSeeder(AdminSeeder::class);

        $this->assertSame(1, User::query()->where('role', UserRole::ADMIN)->count());
    }

    public function test_configured_admin_is_re_enabled_when_deactivated(): void
    {
        $this->env('production');
        $this->actingAsAdmin();
        $demoted = $this->actingAsMember(['email' => 'boss@example.test', 'isActive' => false]);
        config(['site.admin.email' => 'boss@example.test', 'site.admin.password' => 'x']);

        $this->runSeeder(AdminSeeder::class);

        $demoted->refresh();
        $this->assertTrue($demoted->isActive);
        $this->assertSame(UserRole::ADMIN, $demoted->role);
    }

    public function test_local_environment_falls_back_to_dev_admin(): void
    {
        $this->env('local');
        config(['site.admin.email' => null, 'site.admin.password' => null]);

        $this->runSeeder(AdminSeeder::class);

        $this->assertTrue(User::query()->where('email', 'admin@lodenica.sk')->exists());
    }

    public function test_demo_data_only_in_local_or_when_asked(): void
    {
        $this->env('production');
        config(['site.seed_demo_data' => false]);
        $this->runSeeder(DemoDataSeeder::class);
        $this->assertSame(0, Resource::query()->count());

        config(['site.seed_demo_data' => true]);
        $this->runSeeder(DemoDataSeeder::class);
        $count = Resource::query()->count();
        $this->assertGreaterThan(0, $count);

        $this->runSeeder(DemoDataSeeder::class);
        $this->assertSame($count, Resource::query()->count(), 'idempotent');
    }

    public function test_content_seeder_substitutes_placeholders_and_keeps_admin_edits(): void
    {
        app(SiteConfig::class)->update([
            'siteName' => 'Klub Test',
            'clubName' => 'Vodácky klub Test',
            'contactEmail' => 'klub@example.test',
        ]);

        $this->runSeeder(ContentSeeder::class);

        $faq = (string) DB::table('settings')->where('key', 'faq')->value('value');
        $this->assertStringContainsString('klub@example.test', $faq);
        $this->assertStringNotContainsString('{{', $faq);
        $rules = (string) DB::table('settings')->where('key', 'reservation_rules')->value('value');
        $this->assertStringContainsString('Vodácky klub Test', $rules);
        $this->assertStringNotContainsString('KVŠ', $rules);
        $privacy = (string) DB::table('settings')->where('key', 'privacy_policy')->value('value');
        $this->assertStringContainsString('Vodácky klub Test', $privacy);

        DB::table('settings')->where('key', 'faq')->update(['value' => '<p>upravené</p>']);
        $this->runSeeder(ContentSeeder::class);
        $this->assertSame('<p>upravené</p>', DB::table('settings')->where('key', 'faq')->value('value'));
    }

    public function test_render_unwraps_anchors_without_a_target(): void
    {
        $html = ContentSeeder::render(
            '<p>Píšte na <a href="mailto:{{contactEmail}}">{{contactEmail}}</a> alebo <a href="{{rulesUrl}}" target="_blank">poriadok</a>.</p>',
            ['contactEmail' => '', 'rulesUrl' => ''],
        );

        $this->assertSame('<p>Píšte na  alebo poriadok.</p>', $html);
    }

    public function test_render_escapes_values(): void
    {
        $html = ContentSeeder::render('<p>{{clubName}}</p>', ['clubName' => 'A <b>& B']);

        $this->assertSame('<p>A &lt;b&gt;&amp; B</p>', $html);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\MailNotification;
use App\Mail\MailTestMail;
use App\Services\MailNotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin mail diagnostics: read the effective SMTP config, fire a test
 * send, and tail the mail-related log lines.
 *
 * The central rule under test: a failing mailer must come back as
 * HTTP 200 with `ok:false` and the real error text. If it 500'd, the SPA
 * would show a generic "server error" and swallow exactly the message
 * the page exists to surface.
 */
class MailDiagnosticsApiTest extends TestCase
{
    use RefreshDatabase;

    /* ──────────────  Auth gating  ────────────── */

    public function test_all_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/mail/config')->assertStatus(401);
        $this->postJson('/api/v1/admin/mail/test', ['to' => 'a@b.test'])->assertStatus(401);
        $this->getJson('/api/v1/admin/mail/log')->assertStatus(401);
        $this->getJson('/api/v1/admin/mail/notifications')->assertStatus(401);
        $this->patchJson('/api/v1/admin/mail/notifications', [])->assertStatus(401);
    }

    public function test_member_cannot_use_mail_diagnostics(): void
    {
        $this->actingAsMember();

        $this->getJson('/api/v1/admin/mail/config')->assertStatus(403);
        $this->postJson('/api/v1/admin/mail/test', ['to' => 'a@b.test'])->assertStatus(403);
        $this->getJson('/api/v1/admin/mail/log')->assertStatus(403);
        $this->getJson('/api/v1/admin/mail/notifications')->assertStatus(403);
        $this->patchJson('/api/v1/admin/mail/notifications', [])->assertStatus(403);
    }

    /* ──────────────  Config  ────────────── */

    public function test_config_reports_effective_mail_settings(): void
    {
        $this->actingAsAdmin();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.username' => 'robot@example.test',
            'mail.mailers.smtp.password' => 'super-secret-value',
            'mail.from.address' => 'robot@example.test',
            'mail.from.name' => 'Lodenica',
            'mail.admin_address' => 'admin@example.test',
        ]);

        $r = $this->getJson('/api/v1/admin/mail/config')->assertOk();

        $r->assertJsonPath('mailer', 'smtp');
        $r->assertJsonPath('host', 'smtp.example.test');
        $r->assertJsonPath('port', 465);
        $r->assertJsonPath('scheme', 'smtps');
        $r->assertJsonPath('username', 'robot@example.test');
        $r->assertJsonPath('fromAddress', 'robot@example.test');
        $r->assertJsonPath('adminAddress', 'admin@example.test');
        $r->assertJsonPath('passwordSet', true);
    }

    public function test_config_never_leaks_the_smtp_password(): void
    {
        $this->actingAsAdmin();
        config(['mail.mailers.smtp.password' => 'super-secret-value']);

        $body = $this->getJson('/api/v1/admin/mail/config')->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-value', $body);
    }

    public function test_config_reports_password_missing(): void
    {
        $this->actingAsAdmin();
        config(['mail.mailers.smtp.password' => '']);

        $this->getJson('/api/v1/admin/mail/config')
            ->assertOk()
            ->assertJsonPath('passwordSet', false);
    }

    /**
     * The bug this page found in production: Laravel's default view config
     * wraps the path in realpath(), which returns FALSE when the directory
     * doesn't exist yet. Blade then throws "Please provide a valid cache
     * path." on the first render — and e-mails are the only thing in this
     * app that renders Blade, so nothing else looks broken.
     */
    public function test_compiled_view_path_is_a_usable_string(): void
    {
        $this->assertIsString(config('view.compiled'));
        $this->assertNotSame('', config('view.compiled'));
    }

    public function test_config_reports_the_compiled_view_path(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/mail/config')
            ->assertOk()
            ->assertJsonPath('viewCompiledPath', config('view.compiled'))
            ->assertJsonPath('viewCompiledWritable', true);
    }

    public function test_config_flags_an_unusable_compiled_view_path(): void
    {
        $this->actingAsAdmin();
        // What production actually had: realpath() of a missing directory.
        config(['view.compiled' => false]);

        $this->getJson('/api/v1/admin/mail/config')
            ->assertOk()
            ->assertJsonPath('viewCompiledPath', null)
            ->assertJsonPath('viewCompiledWritable', false);
    }

    /* ──────────────  Test send  ────────────── */

    public function test_test_send_delivers_mail_and_reports_success(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $r = $this->postJson('/api/v1/admin/mail/test', ['to' => 'ciel@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('to', 'ciel@example.test');

        $this->assertIsInt($r->json('durationMs'));
        Mail::assertSent(MailTestMail::class, fn ($m) => $m->hasTo('ciel@example.test'));
    }

    public function test_test_send_requires_a_valid_email(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/mail/test', ['to' => 'nie-je-email'])
            ->assertStatus(400);
        $this->postJson('/api/v1/admin/mail/test', [])
            ->assertStatus(400);
    }

    /**
     * The real production failure mode this page was built for: an empty
     * MAIL_MAILER resolves to an undefined mailer and Laravel throws
     * before any socket is opened.
     */
    public function test_test_send_reports_transport_failure_without_500(): void
    {
        $this->actingAsAdmin();
        config(['mail.default' => 'chybajuci-mailer']);

        $r = $this->postJson('/api/v1/admin/mail/test', ['to' => 'ciel@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->assertNotEmpty($r->json('errorMessage'));
        $this->assertNotEmpty($r->json('errorClass'));
    }

    /**
     * The test send is a diagnostic — it must bypass the notification
     * toggles, or it would go dark exactly when an admin switched
     * everything off to stop a mail outage.
     */
    public function test_test_send_works_when_every_notification_is_disabled(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        app(MailNotificationSettings::class)->update(
            array_fill_keys(array_column(MailNotification::cases(), 'value'), false),
        );

        $this->postJson('/api/v1/admin/mail/test', ['to' => 'ciel@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Mail::assertSent(MailTestMail::class);
    }

    /* ──────────────  Log tail  ────────────── */

    public function test_log_reports_unavailable_when_file_is_missing(): void
    {
        $this->actingAsAdmin();
        config(['logging.channels.single.path' => '/neexistuje/laravel.log']);

        $this->getJson('/api/v1/admin/mail/log')
            ->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_log_returns_only_mail_related_entries(): void
    {
        $this->actingAsAdmin();

        $path = storage_path('logs/test-mail-diag.log');
        file_put_contents($path, implode("\n", [
            '[2026-08-25 06:00:00] production.ERROR: Nesuvisiaci zaznam o rezervacii',
            '[2026-08-25 06:01:00] production.WARNING: Membership-approved email failed for a@b.test: nieco',
            '[2026-08-25 06:02:00] production.ERROR: Symfony\Component\Mailer\Exception\TransportException: Connection refused',
            '[2026-08-25 06:03:00] production.INFO: Dalsi nesuvisiaci riadok o lodiach',
        ])."\n");
        config(['logging.channels.single.path' => $path]);

        try {
            $r = $this->getJson('/api/v1/admin/mail/log')->assertOk()
                ->assertJsonPath('available', true);

            $entries = $r->json('entries');
            $this->assertCount(2, $entries);
            $joined = implode("\n", $entries);
            $this->assertStringContainsString('Membership-approved email failed', $joined);
            $this->assertStringContainsString('TransportException', $joined);
            $this->assertStringNotContainsString('rezervacii', $joined);
        } finally {
            @unlink($path);
        }
    }
}

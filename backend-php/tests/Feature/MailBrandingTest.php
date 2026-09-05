<?php

namespace Tests\Feature;

use App\Domain\Enums\MailNotification;
use App\Mail\MailTestMail;
use App\Mail\MembershipApprovedMail;
use App\Mail\PasswordResetMail;
use App\Mail\PendingMemberNotificationMail;
use App\Services\AdminNotifier;
use App\Services\NotificationMailer;
use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every e-mail carries the installation's name, never a club literal:
 * subject suffix, layout header, from-name, and the admin address the
 * "new member waiting" notice goes to.
 */
class MailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteConfig::class)->update(['siteName' => 'Klub Test']);
        config(['mail.from.address' => 'robot@example.test', 'mail.from.name' => 'ignored']);
    }

    public function test_subject_and_layout_carry_the_site_name(): void
    {
        $mail = new PasswordResetMail('a@example.test', 'https://x.test/reset', 30);

        $this->assertStringEndsWith('— Klub Test', (string) $mail->build()->subject);

        $html = $mail->render();
        $this->assertStringContainsString('Klub Test', $html);
        $this->assertStringNotContainsString('KVŠ', $html);
    }

    public function test_test_mail_sets_subject_and_from_name(): void
    {
        $mail = (new MailTestMail('admin@example.test', 'now', 'https://x.test'))->build();

        $this->assertStringEndsWith('— Klub Test', (string) $mail->subject);
        $this->assertTrue($mail->hasFrom('robot@example.test', 'Klub Test'));
    }

    public function test_notification_mailer_sets_the_from_name(): void
    {
        Mail::fake();

        app(NotificationMailer::class)->send(
            MailNotification::MEMBERSHIP_APPROVED,
            'm@example.test',
            new MembershipApprovedMail('Meno', 'https://x.test/login'),
        );

        Mail::assertSent(MembershipApprovedMail::class, fn ($m) => $m->hasFrom('robot@example.test', 'Klub Test'));
    }

    public function test_admin_notifier_uses_the_configured_admin_email(): void
    {
        Mail::fake();
        config(['site.admin_email' => null]);
        app(SiteConfig::class)->update(['contactEmail' => 'klub@example.test']);
        $user = $this->actingAsPending();

        app(AdminNotifier::class)->pendingMemberAwaitingApproval($user);

        Mail::assertSent(PendingMemberNotificationMail::class, fn ($m) => $m->hasTo('klub@example.test'));
    }

    public function test_admin_notifier_stays_silent_without_any_address(): void
    {
        Mail::fake();
        config(['site.admin_email' => null, 'site.contact_email' => null]);
        $user = $this->actingAsPending();

        app(AdminNotifier::class)->pendingMemberAwaitingApproval($user);

        Mail::assertNothingSent();
    }
}

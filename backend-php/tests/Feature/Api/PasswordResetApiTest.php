<?php

namespace Tests\Feature\Api;

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Domain\Enums\UserRole;
use App\Services\CaptchaService;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    private function solvedCaptcha(): array
    {
        $challenge = app(CaptchaService::class)->issue();
        [$a, $op, $b] = explode(' ', $challenge['question']);
        $answer = $op === '+' ? ((int) $a + (int) $b) : ((int) $a * (int) $b);

        return ['captchaToken' => $challenge['token'], 'captchaAnswer' => (string) $answer];
    }

    public function test_captcha_endpoint_returns_token_and_svg(): void
    {
        $this->getJson('/api/v1/auth/captcha')
            ->assertOk()
            ->assertJsonStructure(['token', 'svg']);
    }

    public function test_forgot_password_with_valid_captcha_sends_email(): void
    {
        Mail::fake();
        User::create([
            'name' => 'Reset Me', 'email' => 'reset@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $this->postJson('/api/v1/auth/forgot-password', array_merge(
            ['email' => 'reset@example.test'],
            $this->solvedCaptcha(),
        ))->assertOk();

        Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo('reset@example.test'));
    }

    public function test_forgot_password_with_wrong_captcha_is_rejected(): void
    {
        Mail::fake();
        $challenge = app(CaptchaService::class)->issue();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'whoever@example.test',
            'captchaToken' => $challenge['token'],
            'captchaAnswer' => '99999', // wrong
        ])->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        Mail::assertNothingSent();
    }

    public function test_forgot_password_unknown_email_still_returns_ok_and_sends_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', array_merge(
            ['email' => 'nobody@example.test'],
            $this->solvedCaptcha(),
        ))->assertOk(); // no enumeration

        Mail::assertNothingSent();
    }

    public function test_reset_password_with_valid_token_sets_new_password_and_logs_in(): void
    {
        Mail::fake();
        $user = User::create([
            'name' => 'Reset', 'email' => 'r2@example.test',
            'password' => 'oldpassword1', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        // Issue a real token via the service (capture the plaintext by
        // intercepting the mail) — simplest is to call requestReset then read
        // the emailed URL.
        app(PasswordResetService::class)->requestReset($user->email);

        $token = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $m) use (&$token) {
            parse_str(parse_url($m->resetUrl, PHP_URL_QUERY), $q);
            $token = $q['token'] ?? null;

            return true;
        });
        $this->assertNotNull($token);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'r2@example.test',
            'token' => $token,
            'password' => 'brandnewpass1',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $user->refresh();
        $this->assertTrue(Hash::check('brandnewpass1', $user->password));
    }

    public function test_reset_password_activates_invited_member_and_records_consents(): void
    {
        // An invited member starts inactive with no password-set timestamp.
        $user = User::create([
            'name' => 'Invited', 'email' => 'inv@example.test',
            'password' => 'placeholder-random', 'role' => UserRole::MEMBER,
            'isActive' => false,
        ]);

        // Invitation tokens go through the same table as resets.
        $reset = app(PasswordResetService::class);
        $ref = new \ReflectionMethod($reset, 'issueToken');
        $ref->setAccessible(true);
        $plain = $ref->invoke($reset, $user->email, 3600);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'inv@example.test',
            'token' => $plain,
            'password' => 'memberpass123',
            'privacyAck' => true,
            'dataConsent' => false,
        ])->assertOk();

        $user->refresh();
        $this->assertTrue($user->isActive);          // activated
        $this->assertNotNull($user->passwordSetAt);  // stamped
        $this->assertTrue($user->privacyAck);
        $this->assertFalse($user->dataConsent);
        $this->assertNotNull($user->gdprConsentAt);
    }

    public function test_reset_password_with_bad_token_fails(): void
    {
        User::create([
            'name' => 'X', 'email' => 'r3@example.test',
            'password' => 'oldpassword1', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'r3@example.test',
            'token' => 'totally-wrong-token',
            'password' => 'brandnewpass1',
        ])->assertStatus(400)
            ->assertJsonPath('code', 'INVALID_RESET_TOKEN');
    }
}

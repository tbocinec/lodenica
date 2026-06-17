<?php

namespace Tests\Feature;

use App\Services\CaptchaService;
use Tests\TestCase;

class CaptchaServiceTest extends TestCase
{
    public function test_issued_captcha_renders_a_full_question_and_verifies(): void
    {
        $svc = app(CaptchaService::class);

        // Issue several so we hit both '+' and '×' operators.
        for ($i = 0; $i < 20; $i++) {
            $c = $svc->issue();

            // The SVG must actually contain the operator glyph — a byte-wise
            // split used to shred the multibyte '×' into mojibake.
            [$a, $op, $b] = explode(' ', $c['question']);
            $this->assertStringContainsString($op, $c['svg'], "SVG missing operator '{$op}'");

            $answer = $op === '+' ? ((int) $a + (int) $b) : ((int) $a * (int) $b);
            $this->assertTrue($svc->verify((string) $answer, $c['token']));
            $this->assertFalse($svc->verify((string) ($answer + 1), $c['token']));
        }
    }

    public function test_verify_rejects_garbage_and_missing(): void
    {
        $svc = app(CaptchaService::class);
        $this->assertFalse($svc->verify(null, null));
        $this->assertFalse($svc->verify('5', 'not-a-token'));
        $this->assertFalse($svc->verify('5', '9999999999.deadbeef'));
    }
}

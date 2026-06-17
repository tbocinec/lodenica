<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Stateless, self-hosted captcha — no external service, no GD extension,
 * no server-side session (the hosting runs SESSION_DRIVER=array).
 *
 * A simple arithmetic challenge is rendered as inline SVG. The correct
 * answer is never sent to the client; instead the issued token carries an
 * expiry plus an HMAC that binds the correct answer:
 *
 *     token = "<exp>.<hmac(answer|exp, APP_KEY)>"
 *
 * Verification recomputes the HMAC from the answer the user typed: it only
 * matches when the answer is right and the token hasn't expired. Because
 * the secret is APP_KEY the token can't be forged client-side.
 */
class CaptchaService
{
    private const TTL_SECONDS = 600; // 10 minutes to solve + submit

    /**
     * @return array{token:string, svg:string, question:string}
     */
    public function issue(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        // Only addition/multiplication to keep answers non-negative and easy.
        $op = random_int(0, 1) === 0 ? '+' : '×';
        $answer = $op === '+' ? $a + $b : $a * $b;

        $question = "{$a} {$op} {$b}";
        $exp = $this->now() + self::TTL_SECONDS;
        $token = $exp.'.'.$this->sign((string) $answer, $exp);

        return [
            'token' => $token,
            'svg' => $this->renderSvg($question),
            'question' => $question,
        ];
    }

    public function verify(?string $answer, ?string $token): bool
    {
        if ($answer === null || $token === null || !str_contains($token, '.')) {
            return false;
        }

        [$expPart, $sig] = explode('.', $token, 2);
        $exp = (int) $expPart;
        if ($exp <= 0 || $exp < $this->now()) {
            return false;
        }

        $answer = trim($answer);
        if ($answer === '' || !preg_match('/^\d{1,3}$/', $answer)) {
            return false;
        }

        $expected = $this->sign($answer, $exp);

        return hash_equals($expected, $sig);
    }

    private function sign(string $answer, int $exp): string
    {
        $key = (string) Config::get('app.key');

        return hash_hmac('sha256', $answer.'|'.$exp, $key);
    }

    private function now(): int
    {
        return time();
    }

    private function renderSvg(string $question): string
    {
        // Lightweight distorted SVG — readable to humans, mildly annoying
        // to trivial scrapers. Deterministic-ish jitter via random offsets.
        $chars = '';
        $x = 14;
        foreach (str_split($question) as $ch) {
            $dy = random_int(-4, 4);
            $rot = random_int(-12, 12);
            $fill = sprintf('#%02x%02x%02x', random_int(20, 90), random_int(20, 90), random_int(60, 130));
            $chars .= sprintf(
                '<text x="%d" y="%d" font-size="26" font-family="monospace" font-weight="700" fill="%s" transform="rotate(%d %d %d)">%s</text>',
                $x,
                32 + $dy,
                $fill,
                $rot,
                $x,
                32 + $dy,
                htmlspecialchars($ch, ENT_QUOTES),
            );
            $x += 18;
        }

        $lines = '';
        for ($i = 0; $i < 4; $i++) {
            $lines .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#cbd5e1" stroke-width="1"/>',
                random_int(0, 40),
                random_int(4, 44),
                random_int(120, 160),
                random_int(4, 44),
            );
        }

        $w = max(160, $x + 10);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="48" viewBox="0 0 '.$w.' 48" role="img" aria-label="captcha">'
            .'<rect width="100%" height="100%" fill="#f8fafc" rx="8"/>'
            .$lines
            .$chars
            .'</svg>';
    }
}

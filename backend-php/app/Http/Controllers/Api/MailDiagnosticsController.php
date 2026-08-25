<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Http\Controllers\Controller;
use App\Mail\MailTestMail;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-only mail diagnostics: what the mailer is configured to do, does a
 * real send work, and what did the log say when it didn't.
 *
 * Exists because a broken mailer is otherwise invisible from the outside —
 * `/auth/forgot-password` answers the same generic message whether the
 * e-mail went out or the transport blew up, and half the senders swallow
 * their exceptions by design so a flaky mail server can't break the
 * user-facing action that triggered them.
 */
class MailDiagnosticsController extends Controller
{
    /** Hard ceiling on log lines returned, whatever the caller asks for. */
    private const MAX_LOG_LINES = 500;

    /** Only tail the end of the log — production files get large. */
    private const LOG_TAIL_BYTES = 512 * 1024;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The mail settings actually in force. Read through config() rather
     * than env() so this reports what the app uses after `config:cache`,
     * which is where a stale deploy shows up.
     */
    public function config(): JsonResponse
    {
        $mailerName = (string) config('mail.default', '');
        $mailers = (array) config('mail.mailers', []);
        $mailerDefined = $mailerName !== '' && array_key_exists($mailerName, $mailers);

        // When the configured mailer doesn't resolve, still show the SMTP
        // block — that's the one the deploy writes and the one the operator
        // needs to see to understand why nothing is being sent.
        $active = (array) ($mailerDefined ? $mailers[$mailerName] : ($mailers['smtp'] ?? []));

        // config('view.compiled') is FALSE when Laravel's default realpath()
        // hit a missing directory — cast through string so both that and a
        // genuine path answer the same shape.
        $compiledViews = (string) config('view.compiled');

        return new JsonResponse([
            'mailer' => $mailerName,
            'mailerDefined' => $mailerDefined,
            'transport' => $active['transport'] ?? null,
            'scheme' => $active['scheme'] ?? null,
            'host' => $active['host'] ?? null,
            'port' => isset($active['port']) ? (int) $active['port'] : null,
            'username' => $active['username'] ?? null,
            // Never the value — only whether one is set at all. An empty
            // password is what makes the deploy write an empty MAIL_MAILER.
            'passwordSet' => (string) ($active['password'] ?? '') !== '',
            'fromAddress' => config('mail.from.address'),
            'fromName' => config('mail.from.name'),
            'adminAddress' => config('mail.admin_address'),
            'appUrl' => config('app.url'),
            'queueConnection' => config('queue.default'),
            // Blade's compile directory. E-mails are the only Blade
            // consumer here, so when this is unusable the mail system is
            // the only thing that breaks — worth surfacing next to the
            // SMTP settings rather than leaving it to guesswork.
            'viewCompiledPath' => $compiledViews !== '' ? $compiledViews : null,
            'viewCompiledWritable' => $compiledViews !== '' && $this->pathIsUsable($compiledViews),
        ]);
    }

    /**
     * Fire a real send and report what happened.
     *
     * Answers 200 even when the send fails: a broken transport is the
     * RESULT of this diagnostic, not an error in the request. A 500 would
     * reach the SPA as a generic "unexpected server error" and hide the
     * one string the operator came here to read.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $actor = $request->user();
        $sentAt = CarbonImmutable::now('UTC')->format('d.m.Y H:i:s').' UTC';

        $startedAt = microtime(true);
        $ok = true;
        $errorClass = null;
        $errorMessage = null;

        try {
            // Deliberately bypasses NotificationMailer — the test send must
            // work even with every notification switched off.
            Mail::to($data['to'])->send(new MailTestMail(
                triggeredBy: $actor?->email ?? 'neznámy správca',
                sentAt: $sentAt,
                appUrl: (string) config('app.url'),
            ));
        } catch (\Throwable $e) {
            $ok = false;
            $errorClass = $e::class;
            $errorMessage = $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->audit->logAction(
            AuditEntityType::SETTING,
            'mail_test',
            AuditAction::UPDATE,
            $ok
                ? "Odoslaný testovací e-mail na {$data['to']}"
                : "Testovací e-mail na {$data['to']} zlyhal: {$errorMessage}",
        );

        return new JsonResponse([
            'ok' => $ok,
            'to' => $data['to'],
            'durationMs' => $durationMs,
            'sentAt' => $sentAt,
            'errorClass' => $errorClass,
            'errorMessage' => $errorMessage,
        ]);
    }

    /**
     * Mail-related lines from the tail of the Laravel log. Covers the
     * senders that catch their own exceptions (AdminNotifier, the
     * membership-approved notice) — those never surface any other way.
     */
    public function log(Request $request): JsonResponse
    {
        $wanted = (int) $request->query('lines', 200);
        $wanted = max(1, min(self::MAX_LOG_LINES, $wanted));

        $path = (string) (config('logging.channels.single.path')
            ?: storage_path('logs/laravel.log'));

        if (!is_file($path) || !is_readable($path)) {
            return new JsonResponse([
                'available' => false,
                'reason' => 'Súbor s logom neexistuje alebo sa nedá čítať.',
                'path' => $path,
                'entries' => [],
            ]);
        }

        return new JsonResponse([
            'available' => true,
            'reason' => null,
            'path' => $path,
            'entries' => $this->mailLinesFromTail($path, $wanted),
        ]);
    }

    /**
     * Usable = the directory is there and writable, or Laravel can still
     * create it because the parent is writable.
     */
    private function pathIsUsable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        return is_dir($parent) && is_writable($parent);
    }

    /**
     * @return list<string>
     */
    private function mailLinesFromTail(string $path, int $wanted): array
    {
        $size = (int) filesize($path);
        $offset = max(0, $size - self::LOG_TAIL_BYTES);

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        if ($offset > 0) {
            fseek($handle, $offset);
            fgets($handle); // discard the partial first line
        }
        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            // Line-based filter: stack-trace frames don't match and get
            // dropped, which is what keeps the output readable.
            if ($line !== '' && preg_match('/(mail|smtp|transport)/i', $line) === 1) {
                $lines[] = $line;
            }
        }
        fclose($handle);

        return array_values(array_slice($lines, -$wanted));
    }
}

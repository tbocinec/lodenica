<?php

namespace App\Services;

use App\Models\UsageEvent;
use Illuminate\Support\Facades\Log;

/**
 * Records usage events for the admin dashboard. Best-effort: a tracking
 * failure must never break the actual request (login, page load), so every
 * write is wrapped and logged rather than thrown.
 */
class UsageTracker
{
    public function recordLogin(string $userId): void
    {
        $this->record('login', $userId);
    }

    /**
     * A SPA load. Always a 'pageview'; additionally a 'visit' when this is
     * the first load of the browser session (the client tells us via
     * sessionStorage — no server-side identity).
     */
    public function recordVisit(bool $firstInSession): void
    {
        $this->record('pageview', null);
        if ($firstInSession) {
            $this->record('visit', null);
        }
    }

    private function record(string $type, ?string $userId): void
    {
        try {
            UsageEvent::create([
                'type' => $type,
                'userId' => $userId,
                'occurredAt' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('UsageTracker failed to record '.$type.': '.$e->getMessage());
        }
    }
}

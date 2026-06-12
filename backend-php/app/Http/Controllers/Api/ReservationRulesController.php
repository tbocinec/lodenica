<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReservationRulesRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/reservation-rules is the only "settings" surface the SPA
 * needs right now. Public read so anonymous visitors can see the rules
 * before booking; PATCH gated behind the `admin` middleware (see api.php).
 *
 * Body shape: `{ "content": "<HTML…>", "updatedAt": "ISO-8601" }`.
 */
class ReservationRulesController extends Controller
{
    public const SETTING_KEY = 'reservation_rules';

    public function __construct(private readonly SettingsService $settings) {}

    public function show(): JsonResponse
    {
        $content = $this->settings->get(self::SETTING_KEY, '') ?? '';
        $row = \App\Models\Setting::find(self::SETTING_KEY);

        return new JsonResponse([
            'content' => $content,
            'updatedAt' => $row?->updatedAt?->toIso8601String(),
        ]);
    }

    public function update(UpdateReservationRulesRequest $request): JsonResponse
    {
        $row = $this->settings->set(self::SETTING_KEY, $request->validated('content'));

        return new JsonResponse([
            'content' => $row->value,
            'updatedAt' => $row->updatedAt?->toIso8601String(),
        ]);
    }
}

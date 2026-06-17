<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFaqRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Q&A / FAQ page — same shape + gating as reservation rules and the privacy
 * policy (public read, admin-only PATCH). Stored as an HTML blob under the
 * `faq` settings key.
 *
 * Body shape: `{ "content": "<HTML…>", "updatedAt": "ISO-8601" }`.
 */
class FaqController extends Controller
{
    public const SETTING_KEY = 'faq';

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

    public function update(UpdateFaqRequest $request): JsonResponse
    {
        $row = $this->settings->set(self::SETTING_KEY, $request->validated('content'));

        return new JsonResponse([
            'content' => $row->value,
            'updatedAt' => $row->updatedAt?->toIso8601String(),
        ]);
    }
}

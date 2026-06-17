<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrivacyPolicyRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Privacy policy page — same shape + gating as the reservation rules
 * (public read, admin-only PATCH). Stored as an HTML blob under the
 * `privacy_policy` settings key. Needed both for transparency and because
 * Facebook Login requires a public privacy-policy URL.
 *
 * Body shape: `{ "content": "<HTML…>", "updatedAt": "ISO-8601" }`.
 */
class PrivacyPolicyController extends Controller
{
    public const SETTING_KEY = 'privacy_policy';

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

    public function update(UpdatePrivacyPolicyRequest $request): JsonResponse
    {
        $row = $this->settings->set(self::SETTING_KEY, $request->validated('content'));

        return new JsonResponse([
            'content' => $row->value,
            'updatedAt' => $row->updatedAt?->toIso8601String(),
        ]);
    }
}

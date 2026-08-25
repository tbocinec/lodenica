<?php

namespace App\Http\Controllers\Api;

use App\Domain\Enums\MailNotification;
use App\Http\Controllers\Controller;
use App\Services\MailNotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Per-notification on/off switches for the club's transactional e-mail.
 *
 * The payload carries each notification's label, description and the
 * consequence of switching it off, so the admin page doesn't have to keep
 * its own copy of that wording in sync with the enum.
 */
class MailNotificationsController extends Controller
{
    public function __construct(private readonly MailNotificationSettings $settings) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['notifications' => $this->present($this->settings->all())]);
    }

    /**
     * Partial update: only the keys present in the body change.
     */
    public function update(Request $request): JsonResponse
    {
        $known = array_column(MailNotification::cases(), 'value');

        $unknown = array_diff(array_keys($request->all()), $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'notifications' => 'Neznámy typ notifikácie: '.implode(', ', $unknown),
            ]);
        }

        $request->validate(array_fill_keys(
            array_map(fn (string $k) => $k, $known),
            ['sometimes', 'boolean'],
        ));

        $state = $this->settings->update($request->all());

        return new JsonResponse(['notifications' => $this->present($state)]);
    }

    /**
     * @param  array<string, bool>  $state
     * @return list<array<string, mixed>>
     */
    private function present(array $state): array
    {
        return array_map(fn (MailNotification $type) => [
            'key' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'critical' => $type->isCritical(),
            'consequence' => $type->consequence(),
            'enabled' => $state[$type->value],
        ], MailNotification::cases());
    }
}

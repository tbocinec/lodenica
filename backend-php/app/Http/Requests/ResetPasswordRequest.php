<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used by both "forgot password" and "set your first password" (invited /
 * bulk-imported accounts) — same screen, same token mechanism.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:200'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            // Optional consents — sent by the invite (set-first-password)
            // screen, which shows the same sections as registration. Stored
            // when present; the client enforces the mandatory ones in invite
            // mode.
            'privacyAck' => ['nullable', 'boolean'],
            'dataConsent' => ['nullable', 'boolean'],
            'rulesAck' => ['nullable', 'boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }
}

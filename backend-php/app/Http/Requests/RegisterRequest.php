<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public self-registration. Always lands the account as PENDING (set in the
 * controller, never trusted from input) so an admin must confirm it before
 * it gains member rights. Duplicate emails are rejected by the unique rule.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            // GDPR checkbox 1 — mandatory: must be ticked to register.
            'privacyAck' => ['accepted'],
            // GDPR checkbox 2 — optional consent; default-checked on the form.
            'dataConsent' => ['nullable', 'boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Účet s týmto e-mailom už existuje.',
            'privacyAck.accepted' => 'Pre registráciu musíte potvrdiť oboznámenie s podmienkami spracúvania osobných údajov.',
        ];
    }
}

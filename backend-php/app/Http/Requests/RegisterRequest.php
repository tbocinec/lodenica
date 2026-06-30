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
            // Informational notice — acknowledged implicitly by submitting the
            // form (privacyAck is forced true server-side), so not required here.
            'privacyAck' => ['nullable', 'boolean'],
            // GDPR photo/marketing consent — an explicit yes/no choice is
            // mandatory (the form offers two radio options).
            'dataConsent' => ['required', 'boolean'],
            // Prevádzkový poriadok + stanovy — must be accepted to register.
            'rulesAck' => ['accepted'],
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
            'dataConsent.required' => 'Vyberte, či udeľujete alebo neudeľujete súhlas so spracúvaním osobných údajov na účely propagácie.',
            'rulesAck.accepted' => 'Pre registráciu musíte potvrdiť oboznámenie so stanovami a prevádzkovým poriadkom.',
        ];
    }
}

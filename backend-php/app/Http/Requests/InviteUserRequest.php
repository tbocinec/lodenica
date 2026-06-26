<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin invites a single member: name + email, no password (the invitee
 * sets their own via the emailed link). The account is created already
 * CONFIRMED (MEMBER) — same as a CSV import row, just one at a time.
 */
class InviteUserRequest extends FormRequest
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
            // Optional internal member ID assigned right at invite time.
            'memberId' => ['nullable', 'string', 'max:100', 'unique:users,memberId'],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
        if ($this->exists('memberId') && trim((string) $this->input('memberId')) === '') {
            $this->merge(['memberId' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Účet s týmto e-mailom už existuje.',
            'memberId.unique' => 'Toto členské ID už má priradené iný používateľ.',
        ];
    }
}

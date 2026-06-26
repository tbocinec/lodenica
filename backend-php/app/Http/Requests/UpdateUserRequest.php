<?php

namespace App\Http\Requests;

use App\Domain\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'email', 'max:200', Rule::unique('users', 'email')->ignore($id)],
            // Password is optional on update — only changed when supplied.
            'password' => ['sometimes', 'string', 'min:8', 'max:200'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'isActive' => ['sometimes', 'boolean'],
            // Internal club member ID (admin-only). Unique across users;
            // nullable so an admin can clear it. Empty string → null.
            'memberId' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('users', 'memberId')->ignore($id)],
        ];
    }

    public function messages(): array
    {
        return [
            'memberId.unique' => 'Toto členské ID už má priradené iný používateľ.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Treat an empty-string memberId as null (clear the assignment).
        if ($this->exists('memberId') && trim((string) $this->input('memberId')) === '') {
            $this->merge(['memberId' => null]);
        }
    }
}

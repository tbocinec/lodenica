<?php

namespace App\Http\Requests;

use App\Domain\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
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
            'role' => ['required', Rule::enum(UserRole::class)],
            'isActive' => ['nullable', 'boolean'],
            // Internal club member ID (admin-only), unique across users.
            'memberId' => ['nullable', 'string', 'max:100', 'unique:users,memberId'],
        ];
    }

    public function messages(): array
    {
        return [
            'memberId.unique' => 'Toto členské ID už má priradené iný používateľ.',
        ];
    }
}

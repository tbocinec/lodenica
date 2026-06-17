<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A user changing their OWN password from the profile screen. Requires the
 * current password. Admins change other people's passwords via the user
 * management endpoint (PATCH /users/{id}) — see docs/AUTH-AND-PERMISSIONS.md.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'max:200', 'different:currentPassword'],
        ];
    }
}

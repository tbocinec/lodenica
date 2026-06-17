<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrivacyPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Rich-text HTML from the WYSIWYG editor; capped at 64 KB.
            'content' => ['required', 'string', 'max:65535'],
        ];
    }
}

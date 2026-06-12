<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReservationRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Rich-text HTML payload from the WYSIWYG editor. Capped at
            // 64 KB so an over-eager copy-paste from Word doesn't fill
            // a TEXT column with junk.
            'content' => ['required', 'string', 'max:65535'],
        ];
    }
}

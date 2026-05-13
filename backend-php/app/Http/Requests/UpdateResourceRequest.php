<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Identifier and type are immutable after creation — changing them would
 * invalidate printed labels and QR codes on the boats.
 */
class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'model' => ['sometimes', 'nullable', 'string', 'max:200'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'lengthCm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2000'],
            'weightKg' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'imageUrl' => ['sometimes', 'nullable', 'string', 'max:2000', 'url'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}

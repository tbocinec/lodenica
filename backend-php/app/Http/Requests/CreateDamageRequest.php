<?php

namespace App\Http\Requests;

use App\Domain\Enums\DamageSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateDamageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resourceId' => ['required', 'uuid'],
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'severity' => ['required', new Enum(DamageSeverity::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

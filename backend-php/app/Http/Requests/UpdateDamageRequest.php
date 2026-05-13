<?php

namespace App\Http\Requests;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDamageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'string', 'min:1', 'max:1000'],
            'severity' => ['sometimes', new Enum(DamageSeverity::class)],
            'status' => ['sometimes', new Enum(DamageStatus::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Enums\DamageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListDamagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'resourceId' => ['nullable', 'uuid'],
            'status' => ['nullable', new Enum(DamageStatus::class)],
        ];
    }
}

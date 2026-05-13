<?php

namespace App\Http\Requests;

use App\Domain\Enums\ResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListResourcesRequest extends FormRequest
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
            'type' => ['nullable', new Enum(ResourceType::class)],
            'isActive' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function prepareForValidation(): void
    {
        // Accept ?isActive=true / false as strings (typical query string usage).
        if ($this->has('isActive')) {
            $raw = $this->input('isActive');
            if ($raw === 'true' || $raw === true) {
                $this->merge(['isActive' => true]);
            } elseif ($raw === 'false' || $raw === false) {
                $this->merge(['isActive' => false]);
            }
        }
    }
}

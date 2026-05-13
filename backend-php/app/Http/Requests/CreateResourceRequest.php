<?php

namespace App\Http\Requests;

use App\Domain\Enums\ResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'min:1', 'max:50', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'type' => ['required', new Enum(ResourceType::class)],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'model' => ['nullable', 'string', 'max:200'],
            'color' => ['nullable', 'string', 'max:50'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:20'],
            'lengthCm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'weightKg' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'imageUrl' => ['nullable', 'string', 'max:2000', 'url'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.regex' => 'identifier musí byť alfanumerický (povolené sú aj -, _, .).',
        ];
    }
}

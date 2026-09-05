<?php

namespace App\Http\Requests;

use App\Domain\Enums\ResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Identifier and type are editable by an admin (if the identifier changes,
 * printed labels / QR codes should be reprinted). Identifier stays unique.
 */
class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'identifier' => [
                'sometimes', 'string', 'min:1', 'max:50', 'regex:/^[A-Za-z0-9\-_.]+$/',
                Rule::unique('resources', 'identifier')->ignore($id),
            ],
            'type' => ['sometimes', new Enum(ResourceType::class)],
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'model' => ['sometimes', 'nullable', 'string', 'max:200'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'lengthCm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2000'],
            'weightKg' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'imageUrl' => ['sometimes', 'nullable', 'string', 'max:2000', 'url'],
            'isActive' => ['sometimes', 'boolean'],
            // Approval workflow (REZ-050). Membership of the approvers is
            // checked in ResourcesService — `exists` only proves the account.
            'requiresApproval' => ['sometimes', 'boolean'],
            'approverIds' => ['sometimes', 'array'],
            'approverIds.*' => ['uuid', Rule::exists('users', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.unique' => 'Tento identifikátor už používa iný zdroj.',
            'identifier.regex' => 'Identifikátor musí byť alfanumerický (povolené sú aj -, _, .).',
        ];
    }
}

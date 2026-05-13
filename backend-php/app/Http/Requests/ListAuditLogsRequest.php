<?php

namespace App\Http\Requests;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entityType' => ['nullable', 'string', Rule::enum(AuditEntityType::class)],
            'entityId' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', Rule::enum(AuditAction::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eventId' => ['sometimes', 'nullable', 'uuid'],
            'customerName' => ['sometimes', 'string', 'min:1', 'max:200'],
            'customerContact' => ['sometimes', 'nullable', 'string', 'max:200'],
            'startsAt' => ['sometimes', 'date'],
            'endsAt' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(ReservationStatus::class)],
        ];
    }
}

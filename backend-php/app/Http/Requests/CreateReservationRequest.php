<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resourceId' => ['required', 'uuid'],
            'eventId' => ['nullable', 'uuid'],
            'customerName' => ['required', 'string', 'min:1', 'max:200'],
            'customerContact' => ['nullable', 'string', 'max:200'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

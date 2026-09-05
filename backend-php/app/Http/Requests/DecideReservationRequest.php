<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /reservations/{id}/approve and …/reject. */
class DecideReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

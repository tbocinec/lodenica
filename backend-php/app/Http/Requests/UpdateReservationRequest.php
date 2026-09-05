<?php

namespace App\Http\Requests;

use App\Domain\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Only the two "ordinary" statuses can be set by hand. The approval
            // statuses are entered through approve/reject (REZ-058).
            'status' => ['sometimes', Rule::in([
                ReservationStatus::CONFIRMED->value,
                ReservationStatus::CANCELLED->value,
            ])],
            // Admin-only: reassign the reservation to a member (internal ID).
            // Non-admins get this stripped in the controller.
            'memberId' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}

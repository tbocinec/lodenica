<?php

namespace App\Http\Requests;

use App\Domain\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListReservationsRequest extends FormRequest
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
            'eventId' => ['nullable', 'uuid'],
            'status' => ['nullable', new Enum(ReservationStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            // "only my bookings" — resolved against the caller's token in
            // the controller (this route is public, so it may be absent).
            'mine' => ['nullable', 'boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        // Accept ?mine=true / false as strings (typical query string usage —
        // it is what axios puts on the wire for a boolean). Same treatment
        // as `isActive` on ListResourcesRequest.
        if ($this->has('mine')) {
            $raw = $this->input('mine');
            if ($raw === 'true' || $raw === true) {
                $this->merge(['mine' => true]);
            } elseif ($raw === 'false' || $raw === false) {
                $this->merge(['mine' => false]);
            }
        }
    }
}

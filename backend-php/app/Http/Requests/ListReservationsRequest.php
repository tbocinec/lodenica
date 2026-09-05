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
            // One value (`?status=CONFIRMED`) or several (`?status[]=…&status[]=…`),
            // normalised to an array below. REZ-061.
            'status' => ['nullable', 'array'],
            'status.*' => [new Enum(ReservationStatus::class)],
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

        // A scalar status becomes a one-element list so the rules above
        // cover both `?status=X` and `?status[]=X&status[]=Y`.
        if ($this->has('status') && !is_array($this->input('status'))) {
            $raw = $this->input('status');
            $this->merge(['status' => ($raw === null || $raw === '') ? null : [$raw]]);
        }
    }
}

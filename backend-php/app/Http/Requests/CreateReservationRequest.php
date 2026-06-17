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

    /**
     * Default a logged-in booker's reservation to themselves: if they
     * didn't supply a customer name/contact, use their own. They can still
     * book for someone else by filling those fields explicitly.
     *
     * POST /reservations is a public route, so the default guard doesn't
     * run — ask the sanctum guard directly to resolve any Bearer token
     * (same pattern as ReservationResource). See docs/AUTH-AND-PERMISSIONS.md.
     */
    public function prepareForValidation(): void
    {
        $user = $this->user('sanctum');
        if ($user === null) {
            return;
        }

        $merge = [];
        if (trim((string) $this->input('customerName')) === '') {
            $merge['customerName'] = $user->name;
        }
        if (trim((string) $this->input('customerContact')) === '') {
            $merge['customerContact'] = $user->email;
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}

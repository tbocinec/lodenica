<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateExpeditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is member-gated; ownership not relevant on create
    }

    public function rules(): array
    {
        $nextYear = (int) date('Y') + 1;

        return [
            'title' => ['required', 'string', 'max:200'],
            'place' => ['required', 'string', 'max:200'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.$nextYear],
            'waterType' => ['nullable', 'in:river,lake,sea,other'],
            'country' => ['nullable', 'string', 'max:120'],
            'participants' => ['nullable', 'string', 'max:500'],
            'distanceKm' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'detail' => ['nullable', 'string', 'max:5000'],
            // Optional route polyline: ordered [lat, lng] pairs.
            'route' => ['nullable', 'array', 'max:5000'],
            'route.*' => ['array', 'size:2'],
            'route.*.0' => ['numeric', 'between:-90,90'],
            'route.*.1' => ['numeric', 'between:-180,180'],
            // Mandatory at creation: the submitter consents to publishing the
            // entry to all club members.
            'publishConsent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Zadajte názov expedície.',
            'place.required' => 'Zadajte miesto.',
            'latitude.required' => 'Vyberte miesto na mape (kliknutím nastavíte značku).',
            'longitude.required' => 'Vyberte miesto na mape (kliknutím nastavíte značku).',
            'publishConsent.accepted' => 'Pre uloženie musíte súhlasiť so zverejnením záznamu členom klubu.',
        ];
    }
}

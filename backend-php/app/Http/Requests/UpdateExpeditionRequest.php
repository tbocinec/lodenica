<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpeditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership/admin check happens in the controller
    }

    public function rules(): array
    {
        $nextYear = (int) date('Y') + 1;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'place' => ['sometimes', 'required', 'string', 'max:200'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.$nextYear],
            'waterType' => ['sometimes', 'nullable', 'in:river,lake,sea,other'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'participants' => ['sometimes', 'nullable', 'string', 'max:500'],
            'distanceKm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'route' => ['sometimes', 'nullable', 'array', 'max:5000'],
            'route.*' => ['array', 'size:2'],
            'route.*.0' => ['numeric', 'between:-90,90'],
            'route.*.1' => ['numeric', 'between:-180,180'],
        ];
    }
}

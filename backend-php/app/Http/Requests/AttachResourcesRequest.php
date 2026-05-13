<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resourceIds' => ['required', 'array', 'min:1'],
            'resourceIds.*' => ['uuid'],
        ];
    }
}

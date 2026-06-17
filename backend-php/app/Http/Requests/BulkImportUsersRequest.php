<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin bulk user import. The SPA reads the chosen CSV file client-side and
 * posts its text as `csv` — simpler and more testable than a multipart
 * upload, and the files are tiny (a member roster).
 */
class BulkImportUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'csv' => ['required', 'string', 'max:100000'],
        ];
    }
}

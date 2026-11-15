<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('patient'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identifier_type' => ['required', 'in:nric,passport'],
            'issuing_country_code' => ['nullable', 'string', 'size:2'],
            'value' => ['required', 'string', 'max:100'],
        ];
    }
}

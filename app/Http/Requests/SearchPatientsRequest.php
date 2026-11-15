<?php

namespace App\Http\Requests;

use App\Domain\Patient\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchPatientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('search', Patient::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['query' => trim((string) $this->input('query'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:3', 'max:100'],
            'search_type' => ['required', Rule::in(['patient_number', 'nric', 'passport', 'name', 'phone'])],
            'issuing_country_code' => ['nullable', 'required_if:search_type,passport', 'string', 'size:2'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'query.required' => 'Enter a Patient name, NRIC, phone number or Patient number.',
            'query.min' => 'Enter at least 3 characters to search Patients.',
            'issuing_country_code.required_if' => 'Please enter the passport issuing country.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Patient\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Patient::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['required', 'in:female,male,indeterminate,unknown'],
            'nationality_code' => ['nullable', 'string', 'size:2'],
            'mobile_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'identifiers' => ['nullable', 'array', 'max:3'],
            'identifiers.*.identifier_type' => ['required', 'in:nric,passport'],
            'identifiers.*.issuing_country_code' => ['nullable', 'string', 'size:2'],
            'identifiers.*.value' => ['required', 'string', 'max:100'],
            'duplicate_override' => ['nullable', 'boolean'],
            'organisation_id' => ['prohibited'],
            'patient_number' => ['prohibited'],
            'search_name' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'lock_version' => ['prohibited'],
        ];
    }
}

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
            'mobile_phone' => ['required', 'string', 'max:64'],
            'phone_country' => ['sometimes', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'identifiers' => ['required', 'array', 'size:1'],
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mobile_phone.required' => 'Nombor telefon diperlukan.',
            'mobile_phone.string' => 'Masukkan nombor telefon yang sah untuk negara yang dipilih.',
            'mobile_phone.max' => 'Masukkan nombor telefon yang sah untuk negara yang dipilih.',
            'identifiers.required' => 'Pilih satu No. IC atau Passport.',
            'identifiers.0.value.required' => $this->input('identifiers.0.identifier_type') === 'passport' ? 'No. Passport tidak lengkap.' : 'No. IC mesti mempunyai 12 digit.',
        ];
    }
}

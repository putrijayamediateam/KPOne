<?php

namespace App\Http\Requests;

use App\Domain\Patient\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;

class CheckPatientDuplicatesRequest extends FormRequest
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
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'mobile_phone' => ['nullable', 'string', 'max:64'],
            'phone_country' => ['sometimes', 'string', 'size:2'],
        ];
    }
}

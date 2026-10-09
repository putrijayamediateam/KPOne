<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RedirectsValidationFailuresToDispensaryCase;
use Illuminate\Foundation\Http\FormRequest;

/** DS-01a-services: a service the CA adds that the doctor did not order. */
class AddDispensaryServiceLineRequest extends FormRequest
{
    use RedirectsValidationFailuresToDispensaryCase;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'case_lock_version' => ['required', 'integer', 'min:1'],
            'service_public_id' => ['required', 'uuid'],
            'quantity_performed' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'clinical_instruction' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RedirectsValidationFailuresToDispensaryCase;
use Illuminate\Foundation\Http\FormRequest;

/** DS-01a: a medicine the CA adds that the doctor did not order. */
class AddDispensaryItemRequest extends FormRequest
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
            'medicine_public_id' => ['required', 'uuid'],
            'quantity_dispensed' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'dosage' => ['required', 'string', 'max:500'],
            'frequency' => ['required', 'string', 'max:500'],
            'duration' => ['nullable', 'string', 'max:500'],
            'route' => ['nullable', 'string', 'max:500'],
            'administration_instruction' => ['nullable', 'string', 'max:2000'],
            'precaution' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1', 'max:20'],
            'allocations.*.location_public_id' => ['required', 'uuid'],
            'allocations.*.sku_public_id' => ['required', 'uuid'],
            'allocations.*.batch_public_id' => ['required', 'uuid'],
            'allocations.*.quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
        ];
    }
}

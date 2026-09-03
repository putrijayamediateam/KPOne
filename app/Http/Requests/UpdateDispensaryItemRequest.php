<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDispensaryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_branch_id' => ['required', 'integer', 'min:1'], 'case_lock_version' => ['required', 'integer', 'min:1'], 'item_lock_version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['pending', 'dispensed', 'partial', 'not_dispensed'])], 'quantity_dispensed' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'], 'reason' => ['nullable', Rule::in(['patient_declined', 'out_of_stock', 'clarification_required', 'other'])], 'allocations' => ['array', 'max:20'], 'allocations.*.location_public_id' => ['required', 'uuid'], 'allocations.*.sku_public_id' => ['required', 'uuid'], 'allocations.*.batch_public_id' => ['required', 'uuid'], 'allocations.*.quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/']];
    }
}

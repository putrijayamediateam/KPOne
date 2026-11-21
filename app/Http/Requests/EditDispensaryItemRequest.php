<?php

namespace App\Http\Requests;

use App\Domain\Organisation\Inventory\Services\InventoryBatchEligibility;
use App\Http\Requests\Concerns\RedirectsValidationFailuresToDispensaryCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** DS-01a: the CA's final version of one dispensed line. */
class EditDispensaryItemRequest extends FormRequest
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
            'item_lock_version' => ['required', 'integer', 'min:1'],
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
            'allocations.*.fefo_exception_reason' => ['nullable', Rule::in(InventoryBatchEligibility::FEFO_REASONS)], 'allocations.*.quantity' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            // DSP-1: public ids of the matched allergy records the CA ticked "I have checked with the patient/doctor" for.
            // DSP-24: whole packs (bottles) through the SKU's checked STK-52 conversion; checked again in the service.
            'packs' => ['nullable', 'integer', 'min:1', 'max:99'],
            'pack_conversion_public_id' => ['nullable', 'required_with:packs', 'uuid'],
            'pack_conversion_version' => ['nullable', 'required_with:packs', 'integer', 'min:1'],
            'allergy_confirmed_record_ids' => ['nullable', 'array', 'max:50'],
            'allergy_confirmed_record_ids.*' => ['uuid'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RedirectsInventoryValidationFailuresToIndex;
use Illuminate\Foundation\Http\FormRequest;

class InventoryReferenceSkuStoreRequest extends FormRequest
{
    use RedirectsInventoryValidationFailuresToIndex;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'inventory_item_public_id' => ['required', 'uuid'],
            'sku_code' => ['required', 'string', 'max:64'],
            'pack_size' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'purchase_unit' => ['required', 'string', 'max:100'],
            'stock_unit' => ['required', 'string', 'max:100'],
            'dispensing_unit' => ['required', 'string', 'max:100'],
            'unit_conversion' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'batch_tracking_required' => ['sometimes', 'boolean'],
            'expiry_tracking_required' => ['sometimes', 'boolean'],
        ];
    }
}

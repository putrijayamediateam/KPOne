<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['expected_branch_id' => ['required', 'integer', 'min:1'], 'source_location_public_id' => ['required', 'uuid', 'different:destination_location_public_id'], 'destination_location_public_id' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid'], 'batch_public_id' => ['required', 'uuid'], 'quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryReferenceBatchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'inventory_sku_public_id' => ['required', 'uuid'],
            'batch_number' => ['required', 'string', 'max:100'],
            'expiry_date' => ['required', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RedirectsInventoryValidationFailuresToIndex;
use Illuminate\Foundation\Http\FormRequest;

class InventoryReferenceMappingStoreRequest extends FormRequest
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
            'medicine_public_id' => ['required', 'uuid'],
            'inventory_sku_public_id' => ['required', 'uuid'],
        ];
    }
}

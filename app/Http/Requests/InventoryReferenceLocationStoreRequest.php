<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReferenceLocationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['medical_stock', 'branch_store', 'dispensary'])],
        ];
    }
}

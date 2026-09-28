<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryReferenceItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'generic_name' => ['required', 'string', 'max:300'],
            'brand_name' => ['nullable', 'string', 'max:300'],
            'strength' => ['nullable', 'string', 'max:100'],
            'dosage_form' => ['nullable', 'string', 'max:100'],
            'route' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'mal_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}

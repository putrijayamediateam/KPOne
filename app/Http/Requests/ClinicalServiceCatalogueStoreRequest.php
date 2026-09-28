<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClinicalServiceCatalogueStoreRequest extends FormRequest
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
            'display_name' => ['required', 'string', 'max:500'],
            'order_unit' => ['required', 'string', 'max:100'],
        ];
    }
}

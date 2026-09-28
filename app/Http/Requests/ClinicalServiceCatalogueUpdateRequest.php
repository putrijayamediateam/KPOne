<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClinicalServiceCatalogueUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:64'],
            'display_name' => ['sometimes', 'string', 'max:500'],
            'order_unit' => ['sometimes', 'string', 'max:100'],
        ];
    }
}

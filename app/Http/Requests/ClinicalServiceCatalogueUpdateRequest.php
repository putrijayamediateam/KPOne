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
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expected_branch_id' => ['nullable', 'integer', 'min:1'],
            'prices' => ['nullable', 'array'],
            'prices.self_pay_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.panel_default_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.panel_overrides' => ['nullable', 'array', 'max:100'],
            'prices.panel_overrides.*.panel_id' => ['required_with:prices.panel_overrides.*.amount_sen', 'integer', 'min:1'],
            'prices.panel_overrides.*.amount_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }
}

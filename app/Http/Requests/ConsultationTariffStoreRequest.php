<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultationTariffStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'display_name' => ['required', 'string', 'max:500'],
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'prices.self_pay_sen' => ['nullable', 'integer', 'min:0'],
            'prices.panel_default_sen' => ['nullable', 'integer', 'min:0'],
            'prices.panel_overrides' => ['nullable', 'array', 'max:100'],
            'prices.panel_overrides.*.panel_id' => ['required', 'integer', 'min:1', 'distinct'],
            'prices.panel_overrides.*.amount_sen' => ['required', 'integer', 'min:0'],
            'expected_versions.self_pay' => ['required', 'integer', 'min:0'],
            'expected_versions.panel_default' => ['required', 'integer', 'min:0'],
            'expected_versions.panel_overrides' => ['nullable', 'array'],
            'expected_versions.panel_overrides.*' => ['required', 'integer', 'min:0'],
        ];
    }
}

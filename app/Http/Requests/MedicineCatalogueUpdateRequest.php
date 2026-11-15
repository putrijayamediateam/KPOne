<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedicineCatalogueUpdateRequest extends FormRequest
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
            'strength_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dosage_form' => ['sometimes', 'nullable', 'string', 'max:100'],
            'order_unit' => ['sometimes', 'string', 'max:100'],
            'generic_name' => ['sometimes', 'nullable', 'string', 'max:300'],
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'group_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'default_dosage_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'default_dosage_unit' => ['sometimes', 'nullable', 'string', 'max:100'],
            'default_instruction' => ['sometimes', 'nullable', 'string', 'max:200'],
            'default_precaution' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_frequency' => ['sometimes', 'nullable', 'string', 'max:200'],
            'default_duration' => ['sometimes', 'nullable', 'string', 'max:100'],
            'default_indication' => ['sometimes', 'nullable', 'string', 'max:300'],
            'route' => ['sometimes', 'nullable', 'string', 'max:100'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'mal_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expected_branch_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'inventory_sku_public_id' => ['sometimes', 'nullable', 'uuid'],
            'sku' => ['sometimes', 'array'],
            'sku.sku_code' => ['sometimes', 'string', 'max:64'],
            'sku.barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sku.pack_size' => ['sometimes', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'sku.purchase_unit' => ['sometimes', 'string', 'max:100'],
            'sku.stock_unit' => ['sometimes', 'string', 'max:100'],
            'sku.dispensing_unit' => ['sometimes', 'string', 'max:100'],
            'sku.unit_conversion' => ['sometimes', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'sku.storage_type' => ['sometimes', 'string', 'max:40'],
            'sku.minimum_temperature' => ['sometimes', 'nullable', 'numeric', 'between:-100,100'],
            'sku.maximum_temperature' => ['sometimes', 'nullable', 'numeric', 'between:-100,100'],
            'sku.cold_chain_required' => ['sometimes', 'boolean'],
            'sku.do_not_freeze' => ['sometimes', 'boolean'],
            'sku.protect_from_light' => ['sometimes', 'boolean'],
            'sku.batch_tracking_required' => ['sometimes', 'boolean'],
            'sku.expiry_tracking_required' => ['sometimes', 'boolean'],
            'prices' => ['sometimes', 'array'],
            'prices.self_pay_sen' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.panel_default_sen' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.panel_overrides' => ['sometimes', 'array', 'max:100'],
            'prices.panel_overrides.*.panel_id' => ['required_with:prices.panel_overrides.*.amount_sen', 'integer', 'min:1'],
            'prices.panel_overrides.*.amount_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'batch' => ['sometimes', 'array'],
            'batch.batch_number' => ['nullable', 'string', 'max:100'],
            'batch.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'supplier_public_id' => ['sometimes', 'nullable', 'uuid'],
            'opening_stock' => ['sometimes', 'array', 'max:10'],
            'opening_stock.*.branch_id' => ['required', 'integer', 'min:1'],
            'opening_stock.*.location_public_id' => ['required', 'uuid', 'distinct'],
            'opening_stock.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'],
            'opening_stock.*.unit_cost_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }
}

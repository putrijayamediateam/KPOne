<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedicineCatalogueStoreRequest extends FormRequest
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
            'strength_text' => ['nullable', 'string', 'max:100'],
            'dosage_form' => ['nullable', 'string', 'max:100'],
            'order_unit' => ['required', 'string', 'max:100'],
            'generic_name' => ['nullable', 'string', 'max:300'],
            'category' => ['nullable', 'string', 'max:120'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'default_dosage_amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'default_dosage_unit' => ['nullable', 'string', 'max:100'],
            'default_instruction' => ['nullable', 'string', 'max:200'],
            'default_precaution' => ['nullable', 'string', 'max:500'],
            'default_frequency' => ['nullable', 'string', 'max:200'],
            'default_duration' => ['nullable', 'string', 'max:100'],
            'default_indication' => ['nullable', 'string', 'max:300'],
            'route' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'mal_number' => ['nullable', 'string', 'max:100'],
            'inventory_sku_public_id' => ['nullable', 'uuid'],
            'expected_branch_id' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'array'],
            'sku.sku_code' => ['nullable', 'string', 'max:64'],
            'sku.barcode' => ['nullable', 'string', 'max:100'],
            'sku.pack_size' => ['nullable', 'numeric', 'gt:0'],
            'sku.purchase_unit' => ['nullable', 'string', 'max:100'],
            'sku.stock_unit' => ['nullable', 'string', 'max:100'],
            'sku.dispensing_unit' => ['nullable', 'string', 'max:100'],
            'sku.unit_conversion' => ['nullable', 'numeric', 'gt:0'],
            'sku.storage_type' => ['nullable', 'string', 'max:40'],
            'sku.minimum_temperature' => ['nullable', 'numeric', 'between:-100,100'],
            'sku.maximum_temperature' => ['nullable', 'numeric', 'between:-100,100'],
            'sku.cold_chain_required' => ['nullable', 'boolean'],
            'sku.do_not_freeze' => ['nullable', 'boolean'],
            'sku.protect_from_light' => ['nullable', 'boolean'],
            'sku.batch_tracking_required' => ['nullable', 'boolean'],
            'sku.expiry_tracking_required' => ['nullable', 'boolean'],
            'prices' => ['nullable', 'array'],
            'prices.self_pay_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.panel_default_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.panel_overrides' => ['nullable', 'array', 'max:100'],
            'prices.panel_overrides.*.panel_id' => ['required_with:prices.panel_overrides.*.amount_sen', 'integer', 'min:1'],
            'prices.panel_overrides.*.amount_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'batch' => ['nullable', 'array'],
            'batch.batch_number' => ['nullable', 'string', 'max:100'],
            'batch.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'supplier_public_id' => ['nullable', 'uuid'],
            'opening_stock' => ['nullable', 'array', 'max:10'],
            'opening_stock.*.branch_id' => ['required', 'integer', 'min:1'],
            'opening_stock.*.location_public_id' => ['required', 'uuid', 'distinct'],
            'opening_stock.*.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'],
            'opening_stock.*.unit_cost_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }
}

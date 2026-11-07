<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** DSP-12: the SKU public id from a scan (STK-9 lookup); finding only, the service re-checks access. */
class FindDispensaryMedicineBySkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['sku' => ['required', 'uuid']];
    }
}

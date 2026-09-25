<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PricingChargeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['consultation', 'medicine', 'service'])],
            'medicine_public_id' => ['required_if:type,medicine', 'uuid'],
            'service_public_id' => ['required_if:type,service', 'uuid'],
            'code' => ['required', 'string', 'max:64'],
            'display_name' => ['required', 'string', 'max:500'],
        ];
    }
}

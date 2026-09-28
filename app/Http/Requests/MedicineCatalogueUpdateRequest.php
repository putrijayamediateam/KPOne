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
        ];
    }
}

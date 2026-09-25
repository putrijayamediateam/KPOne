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
        ];
    }
}

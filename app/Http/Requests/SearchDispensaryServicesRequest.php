<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** DS-01a-services: catalogue search for the CA. */
class SearchDispensaryServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}

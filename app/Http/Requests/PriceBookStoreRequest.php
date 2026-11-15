<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PriceBookStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:150'],
            'currency' => ['required', 'string', 'in:MYR'],
        ];
    }
}

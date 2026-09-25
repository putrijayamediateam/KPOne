<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PricePublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'price_book_public_id' => ['required', 'uuid'],
            'amount_sen' => ['required', 'integer', 'min:0'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'expected_branch_id' => ['required', 'integer', 'min:1'],
        ];
    }
}

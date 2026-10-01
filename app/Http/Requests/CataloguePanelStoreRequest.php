<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CataloguePanelStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeDispensaryPartialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['case_lock_version' => ['required', 'integer', 'min:1'], 'item_lock_version' => ['required', 'integer', 'min:1']];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendTreatmentPlanToDispensaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['expected_branch_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:1']];
    }
}

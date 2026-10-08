<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RedirectsValidationFailuresToDispensaryCase;
use Illuminate\Foundation\Http\FormRequest;

class DispensaryCaseActionRequest extends FormRequest
{
    use RedirectsValidationFailuresToDispensaryCase;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['expected_branch_id' => ['required', 'integer', 'min:1'], 'case_lock_version' => ['required', 'integer', 'min:1']];
    }
}

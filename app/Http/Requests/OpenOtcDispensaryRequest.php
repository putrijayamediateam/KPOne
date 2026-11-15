<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class OpenOtcDispensaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['expected_branch_id' => ['required', 'integer', 'min:1']];
    }

    /** The app sends no referrer, so a refused request returns to the board it was pressed on, not the last full page. */
    protected function failedValidation(Validator $validator): never
    {
        throw (new ValidationException($validator))->redirectTo(route('registration.index'));
    }
}

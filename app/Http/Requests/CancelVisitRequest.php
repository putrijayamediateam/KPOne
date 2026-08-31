<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->route('visit'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['nullable', 'integer', 'min:1'],
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'Please enter a reason for cancelling this Visit.',
            'cancellation_reason.max' => 'The cancellation reason must be 500 characters or fewer.',
            'lock_version.required' => 'This Visit needs to be reloaded before it can be cancelled.',
        ];
    }
}

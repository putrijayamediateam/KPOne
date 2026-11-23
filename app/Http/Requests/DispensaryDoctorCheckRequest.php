<?php

namespace App\Http\Requests;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * DSP-23: Verify or Send back on a case waiting for the doctor's check. The doctor acts from the consultation page and
 * a CA Supervisor from the Dispensary case page, so a failed request returns to the page it came from. The reason
 * lists and the "Other" note rules are checked again in DispensaryService.
 */
class DispensaryDoctorCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'case_lock_version' => ['required', 'integer', 'min:1'],
            'retry_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
            'override_reason' => ['nullable', 'string', 'max:40'],
            'override_note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function returnUrl(): string
    {
        $case = $this->route('dispensaryCase');
        if (! $case instanceof DispensaryCase) {
            return route('dashboard');
        }

        return $this->user()?->hasRole('resident_doctor')
            ? route('encounters.show', $case->visit()->firstOrFail())
            : route('dispensary.show', $case);
    }

    /**
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): never
    {
        throw (new ValidationException($validator))->redirectTo($this->returnUrl());
    }
}

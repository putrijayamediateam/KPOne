<?php

namespace App\Http\Requests;

use App\Domain\Clinical\Triage\TriagePermissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TRI-1b: shape only. TriageService validates every value, checks permission and branch under lock and decides the
 * zone; a refusal returns to the form (never a bare back()).
 */
class RecordTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(TriagePermissions::RECORD_BRANCH) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer'],
            'queue_lock_version' => ['required', 'integer'],
            'expected_latest_triage_id' => ['present', 'nullable', 'integer'],
            'expected_guideline_version_id' => ['present', 'nullable', 'integer'],
            'systolic_bp' => ['nullable'],
            'diastolic_bp' => ['nullable'],
            'pulse_bpm' => ['nullable'],
            'temperature_celsius' => ['nullable'],
            'spo2_percent' => ['nullable'],
            'weight_kg' => ['nullable'],
            'height_cm' => ['nullable'],
            'red_flag_codes' => ['present', 'array'],
            'red_flag_codes.*' => ['string'],
            'yellow_sign_codes' => ['present', 'array'],
            'yellow_sign_codes.*' => ['string'],
            'chosen_zone' => ['nullable', 'string'],
            'override_reason_code' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('visits.triage.show', $this->route('visit'));
    }
}

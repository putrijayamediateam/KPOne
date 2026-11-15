<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AttendanceSource
{
    /** @return array<string, mixed> */
    public function capture(User $actor, Branch $branch, Visit $visit, ClinicalEncounter $encounter): array
    {
        $patient = Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->firstOrFail();
        $credential = ClinicianProfessionalProfile::query()->where('organisation_id', $branch->organisation_id)->where('user_id', $actor->id)->lockForUpdate()->first();
        if (! $credential?->verified) {
            throw ValidationException::withMessages(['credentials' => 'Verified professional name and MMC number are required.']);
        }
        $head = app(DocumentLetterheadCapture::class)->capture($branch);
        $source = ['patient' => ['name' => $patient->full_name, 'number' => $patient->patient_number], 'visit' => $visit->visit_number, 'examDate' => $encounter->started_at->setTimezone($branch->timezone)->format('d/m/Y'), 'doctor' => ['name' => $credential->professional_name, 'mmc' => $credential->mmc_number, 'version' => $credential->lock_version], 'letterhead' => $head];
        // HMAC only: source versions are checked without releasing any clinical text.
        $source['token'] = hash_hmac('sha256', json_encode([$branch->id, $branch->timezone, $source, $patient->lock_version, $visit->lock_version, $encounter->lock_version, $encounter->status, $credential->lock_version], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $source;
    }
}

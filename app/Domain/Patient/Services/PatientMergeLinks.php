<?php

namespace App\Domain\Patient\Services;

use App\Domain\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REG-3 read helpers: a merge is a link only, so readers resolve a patient to its kept record and read the
 * records of every patient merged into it. Nothing here writes.
 */
final class PatientMergeLinks
{
    /** The kept patient for any patient (itself when not merged away). */
    public static function kept(Patient $patient): Patient
    {
        if ($patient->merged_into_patient_id === null) {
            return $patient;
        }

        return Patient::query()->whereKey($patient->merged_into_patient_id)->where('organisation_id', $patient->organisation_id)->firstOrFail();
    }

    /**
     * Ids whose visits, invoices and receipts belong on this patient's screens: the kept patient and every
     * patient merged into it. Old rows keep their own patient id and KP number.
     *
     * @return list<int>
     */
    public static function groupIds(int $patientId): array
    {
        $keptId = (int) (DB::table('patients')->where('id', $patientId)->value('merged_into_patient_id') ?? $patientId);

        return array_values(array_unique(array_map('intval', [
            $keptId,
            ...DB::table('patients')->where('merged_into_patient_id', $keptId)->orderBy('id')->pluck('id')->all(),
        ])));
    }

    /**
     * Replace a set of matched patients by their kept patients (search, duplicate check, QR candidates).
     *
     * @param  Builder<Patient>  $matches
     * @return Builder<Patient>
     */
    public static function keptOf(Builder $matches): Builder
    {
        $ids = (clone $matches)->toBase()->reorder()->select(DB::raw('COALESCE(merged_into_patient_id, id)'));

        return Patient::query()->whereIn('id', $ids)->whereNull('merged_into_patient_id');
    }
}

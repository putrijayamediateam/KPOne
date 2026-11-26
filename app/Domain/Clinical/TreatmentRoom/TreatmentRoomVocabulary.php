<?php

namespace App\Domain\Clinical\TreatmentRoom;

use App\Domain\Queue\Treatment\TreatmentStepService;

final class TreatmentRoomVocabulary
{
    public const SAMPLES = [
        'blood_sample_outside_lab' => 'Blood sample (outside lab)',
        'urine_sample' => 'Urine sample',
        'swab_sample' => 'Swab sample',
    ];

    public const PROCEDURES = [...TreatmentStepService::STEPS, ...self::SAMPLES];

    public const REASONS = [
        'patient_declined' => 'Patient declined',
        'could_not_obtain_sample' => 'Could not obtain sample',
        'stopped_patient_reaction' => 'Stopped: patient reaction',
        'equipment_or_stock_unavailable' => 'Equipment or stock unavailable',
        'doctor_stopped' => 'Doctor stopped',
        'other' => 'Other',
    ];
}

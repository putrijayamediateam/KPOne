<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TRI-1a (TRI-1-DESIGN sections 3 to 8, owner decisions 19 Nov 2026). A CA or CA Supervisor records the six
 * readings and any red flag or yellow sign before the doctor; KPOne suggests a zone from the approved guideline and
 * the CA confirms it or chooses another. Each save is a new append-only row; the latest counts. Triage is allowed
 * while the consultation is Waiting, or called in before the doctor starts the consultation; after that it is read
 * only. Yellow and red put a Waiting visit into the existing Urgent order; nothing here lowers priority.
 *
 * Lock order (as Call In and the visit edit): users (actor and assigned doctor, ascending), the actor's grants and
 * assignments, the visit, the queue entry, then the guideline in force FOR SHARE. The patient's date of birth is
 * read without a lock. Audit holds ids, the zone and codes only, never readings.
 */
class TriageService
{
    public const LOWER_REASONS = [
        'doctor_reviewed_at_triage' => 'Doctor reviewed at triage',
        'device_error_confirmed' => 'Device error confirmed by a second device',
    ];

    public const HIGHER_REASONS = [
        'staff_concern' => 'Staff concern',
        'patient_appears_unwell' => 'Patient appears unwell',
    ];

    public const READING_NAMES = [
        'systolic_bp' => 'blood pressure (systolic)',
        'diastolic_bp' => 'blood pressure (diastolic)',
        'pulse_bpm' => 'pulse',
        'temperature_celsius' => 'temperature',
        'spo2_percent' => 'SpO2',
        'weight_kg' => 'weight',
        'height_cm' => 'height',
    ];

    public function __construct(
        private TransactionalActorAuthority $authority,
        private TriageGuidelineService $guidelines,
        private VisitAdministrationService $visits,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function record(User $actor, Visit $visit, array $input): VisitTriageAssessment
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        if (! $actor || ! $actor->is_active || ! $actor->can(TriagePermissions::RECORD_BRANCH)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $branch = $this->authority->branch($actor, $input['expected_branch_id'] ?? null);
        if ($visit->organisation_id !== $actor->organisation_id || $visit->branch_id !== $branch->id) {
            throw new ModelNotFoundException;
        }
        $data = $this->validate($input);
        $hash = hash_hmac('sha256', json_encode(['triage', $actor->organisation_id, $actor->id, $visit->id, $branch->id, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $visit, $branch, $data, $hash): VisitTriageAssessment {
            // One instant for the age group and recorded_at, so PostgreSQL works out the same age group.
            $now = CarbonImmutable::now('UTC');
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $visit->assigned_doctor_user_id]);
            $actor = $this->authority->lock($actor, $branch, TriagePermissions::RECORD_BRANCH);
            $originalDoctorId = $visit->assigned_doctor_user_id;
            $lockedVisit = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $queue = QueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->first();

            $existing = VisitTriageAssessment::query()->where('organisation_id', $actor->organisation_id)
                ->where('recorded_by_user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->visit_id !== $lockedVisit->id || ! hash_equals($existing->payload_hash, $hash)) {
                    $this->refuse('idempotency_key', 'The retry key has already been used for a different triage.');
                }

                return $existing;
            }

            $latest = VisitTriageAssessment::query()->where('visit_id', $lockedVisit->id)->orderByDesc('sequence')->first();
            if ($lockedVisit->status !== Visit::STATUS_REGISTERED || $lockedVisit->visit_type !== 'consultation' || ! $queue) {
                $this->refuse('triage', 'Triage is only for a registered consultation in the queue.');
            }
            if ($lockedVisit->assigned_doctor_user_id !== $originalDoctorId
                || $lockedVisit->lock_version !== $data['visit_lock_version'] || $queue->lock_version !== $data['queue_lock_version']
                || $latest?->id !== $data['expected_latest_triage_id']) {
                $this->refuse('lock_version', "This patient's triage or queue changed. Reload and review the latest readings.");
            }
            $started = ClinicalEncounter::query()->where('visit_id', $lockedVisit->id)->exists();
            if (! in_array($queue->status, [QueueEntry::STATUS_WAITING, QueueEntry::STATUS_SERVING], true)) {
                $this->refuse('triage', 'Triage is only for a registered consultation in the queue.');
            }
            if ($queue->status === QueueEntry::STATUS_SERVING && $started) {
                $this->refuse('triage', 'Triage is read only once the doctor has started the consultation.');
            }

            $guideline = $this->guidelines->inForce($actor->organisation_id, true);
            if ($guideline?->id !== $data['expected_guideline_version_id']) {
                $this->refuse('expected_guideline_version_id', 'The triage guideline changed. Reload the form.');
            }

            $readings = array_intersect_key($data, array_flip(VisitTriageAssessment::READINGS));
            [$ageBand, $suggested, $chosen] = [null, null, null];
            if ($guideline === null) {
                if ($data['red_flag_codes'] !== [] || $data['yellow_sign_codes'] !== [] || $data['chosen_zone'] !== null || $data['override_reason_code'] !== null) {
                    $this->refuse('chosen_zone', 'No triage guideline is approved yet, so triage records readings only.');
                }
            } else {
                $this->assertCodes($guideline, TriageGuidelineSign::RED_FLAG, $data['red_flag_codes'], 'red_flag_codes');
                $this->assertCodes($guideline, TriageGuidelineSign::YELLOW_SIGN, $data['yellow_sign_codes'], 'yellow_sign_codes');
                if ($data['chosen_zone'] === null) {
                    $this->refuse('chosen_zone', 'Confirm the suggested zone or choose another.');
                }
                $ageBand = $this->ageBand($lockedVisit, $branch->timezone, $guideline->child_age_limit_years, $now);
                $assessment = TriageZoneRules::suggest($readings, $data['red_flag_codes'], $data['yellow_sign_codes'], $ageBand, self::limits($guideline));
                $suggested = $assessment['zone'];
                $chosen = $data['chosen_zone'];
                $this->assertZoneChoice($lockedVisit, $suggested, $chosen, $data, $readings, $assessment['crossed']);
            }

            $row = new VisitTriageAssessment;
            $row->forceFill([
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id,
                'visit_id' => $lockedVisit->id,
                'queue_entry_id' => $queue->id,
                'sequence' => ($latest->sequence ?? 0) + 1,
                ...$readings,
                'red_flag_codes' => $data['red_flag_codes'],
                'yellow_sign_codes' => $data['yellow_sign_codes'],
                'age_band' => $ageBand,
                'guideline_version_id' => $guideline?->id,
                'suggested_zone' => $suggested,
                'chosen_zone' => $chosen,
                'override_reason_code' => $data['override_reason_code'],
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => $now,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
            ])->save();

            $raised = false;
            if (in_array($chosen, ['yellow', 'red'], true) && $lockedVisit->priority !== 'urgent' && $queue->status === QueueEntry::STATUS_WAITING) {
                $this->visits->raiseToUrgentForTriage($lockedVisit, $queue, $actor, $branch, $row->id);
                $raised = true;
            }

            abort_if($this->audit->record('visit.triage.recorded', $lockedVisit, [
                'triage_assessment_id' => $row->id,
                'sequence' => $row->sequence,
                'guideline_version_id' => $guideline?->id,
                'suggested_zone' => $suggested,
                'chosen_zone' => $chosen,
                'override_reason_code' => $data['override_reason_code'],
                'red_flag_codes' => $data['red_flag_codes'],
                'yellow_sign_codes' => $data['yellow_sign_codes'],
                'priority_raised' => $raised,
            ], $actor, $branch, $actor->organisation_id) === null, 503, 'Unable to record the audit. Try again.');

            return $row->refresh();
        }, 3);
    }

    /** The latest triage of a visit (TRI-1b board and TRI-1c pre-fill), or null. */
    public function latest(Visit $visit): ?VisitTriageAssessment
    {
        return VisitTriageAssessment::query()->where('visit_id', $visit->id)->orderByDesc('sequence')->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{visit_lock_version:int, queue_lock_version:int, expected_latest_triage_id:int|null, expected_guideline_version_id:int|null, systolic_bp:int|null, diastolic_bp:int|null, pulse_bpm:int|null, temperature_celsius:string|null, spo2_percent:string|null, weight_kg:string|null, height_cm:string|null, red_flag_codes:list<string>, yellow_sign_codes:list<string>, chosen_zone:string|null, override_reason_code:string|null, idempotency_key:string}
     */
    private function validate(array $input): array
    {
        $code = ['required', 'string', 'regex:'.CodeArrayCast::PATTERN, 'distinct'];
        $data = Validator::make($input, [
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
            'expected_latest_triage_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_guideline_version_id' => ['present', 'nullable', 'integer', 'min:1'],
            'systolic_bp' => ['nullable', 'integer', 'between:1,32767', 'required_with:diastolic_bp'],
            'diastolic_bp' => ['nullable', 'integer', 'between:1,32767', 'required_with:systolic_bp'],
            'pulse_bpm' => ['nullable', 'integer', 'between:1,32767'],
            'temperature_celsius' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999.99'],
            'spo2_percent' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'weight_kg' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999.99'],
            'height_cm' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999.99'],
            'red_flag_codes' => ['present', 'array', 'max:12'],
            'red_flag_codes.*' => $code,
            'yellow_sign_codes' => ['present', 'array', 'max:12'],
            'yellow_sign_codes.*' => $code,
            'chosen_zone' => ['nullable', Rule::in(array_keys(TriageZoneRules::ZONES))],
            'override_reason_code' => ['nullable', Rule::in([...array_keys(self::LOWER_REASONS), ...array_keys(self::HIGHER_REASONS)])],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'systolic_bp.required_with' => 'Enter both systolic and diastolic blood pressure, or leave both blank.',
            'diastolic_bp.required_with' => 'Enter both systolic and diastolic blood pressure, or leave both blank.',
        ])->validate();

        $int = fn (string $key): ?int => isset($data[$key]) ? (int) $data[$key] : null;
        $decimal = fn (string $key): ?string => isset($data[$key]) && $data[$key] !== ''
            ? number_format(TriageZoneRules::hundredths((string) $data[$key]) / 100, 2, '.', '') : null;

        return [
            'visit_lock_version' => (int) $data['visit_lock_version'],
            'queue_lock_version' => (int) $data['queue_lock_version'],
            'expected_latest_triage_id' => $int('expected_latest_triage_id'),
            'expected_guideline_version_id' => $int('expected_guideline_version_id'),
            'systolic_bp' => $int('systolic_bp'),
            'diastolic_bp' => $int('diastolic_bp'),
            'pulse_bpm' => $int('pulse_bpm'),
            'temperature_celsius' => $decimal('temperature_celsius'),
            'spo2_percent' => $decimal('spo2_percent'),
            'weight_kg' => $decimal('weight_kg'),
            'height_cm' => $decimal('height_cm'),
            'red_flag_codes' => array_values($data['red_flag_codes']),
            'yellow_sign_codes' => array_values($data['yellow_sign_codes']),
            'chosen_zone' => $data['chosen_zone'] ?? null,
            'override_reason_code' => $data['override_reason_code'] ?? null,
            'idempotency_key' => $data['idempotency_key'],
        ];
    }

    /** @param list<string> $codes */
    private function assertCodes(TriageGuidelineVersion $guideline, string $kind, array $codes, string $field): void
    {
        $known = $guideline->signs->where('kind', $kind)->pluck('code')->all();
        if (array_diff($codes, $known) !== []) {
            $this->refuse($field, 'A ticked sign is not in the triage guideline in force. Reload the form.');
        }
    }

    /**
     * The limits of a guideline version in the shape TriageZoneRules reads.
     *
     * @return list<array{reading:string, age_band:string, direction:string, limit_value:string, zone:string}>
     */
    public static function limits(TriageGuidelineVersion $guideline): array
    {
        return array_values($guideline->thresholds->map(fn (TriageGuidelineThreshold $t): array => [
            'reading' => $t->reading, 'age_band' => $t->age_band, 'direction' => $t->direction,
            'limit_value' => (string) $t->limit_value, 'zone' => $t->zone,
        ])->all());
    }

    /**
     * The readings that crossed a limit in any earlier triage of a visit, each judged by that row's own guideline
     * version and age band (TRI-1b, owner rule 1). A row with no guideline never crossed anything.
     *
     * @return list<string>
     */
    public function readingsToRetake(Visit $visit): array
    {
        $rows = VisitTriageAssessment::query()->where('visit_id', $visit->id)->whereNotNull('guideline_version_id')->orderBy('sequence')->get();
        $versions = TriageGuidelineVersion::query()->whereIn('id', $rows->pluck('guideline_version_id')->unique()->all())->with('thresholds')->get()->keyBy('id');
        $crossed = [];
        foreach ($rows as $row) {
            $readings = [];
            foreach (VisitTriageAssessment::READINGS as $field) {
                $value = $row->getAttribute($field);
                $readings[$field] = $value === null ? null : (string) $value;
            }
            $result = TriageZoneRules::suggest($readings, [], [], (string) $row->age_band, self::limits($versions[$row->guideline_version_id]));
            $crossed = [...$crossed, ...array_keys($result['crossed'])];
        }

        return array_values(array_filter(VisitTriageAssessment::READINGS, fn (string $field): bool => in_array($field, $crossed, true)));
    }

    /**
     * TRI-1b, owner rule 1 (20 Nov 2026): a zone is lowered only after the reading that crossed its limit has been
     * retaken and is within limits, and never while a red flag is ticked. A save lowers the zone when it chooses a zone
     * below the suggestion, or below the zone of the visit's latest earlier triage.
     *
     * @param  array{red_flag_codes:list<string>, override_reason_code:string|null}  $data
     * @param  array<string, int|string|null>  $readings
     * @param  array<string, array{direction:string, zone:string}>  $crossedNow
     */
    private function assertZoneChoice(Visit $visit, string $suggested, string $chosen, array $data, array $readings, array $crossedNow): void
    {
        $reason = $data['override_reason_code'];
        $difference = TriageZoneRules::rank($chosen) <=> TriageZoneRules::rank($suggested);
        if ($difference === 0 && $reason !== null) {
            $this->refuse('override_reason_code', 'A reason is needed only when you choose another zone.');
        }
        if ($difference > 0 && $reason !== null && ! array_key_exists($reason, self::HIGHER_REASONS)) {
            $this->refuse('override_reason_code', 'Choose a reason for a higher zone.');
        }
        if ($difference < 0 && ($reason === null || ! array_key_exists($reason, self::LOWER_REASONS))) {
            $this->refuse('override_reason_code', 'Choose a reason to give a lower zone than suggested.');
        }
        $previous = VisitTriageAssessment::query()->where('visit_id', $visit->id)->whereNotNull('chosen_zone')->orderByDesc('sequence')->value('chosen_zone');
        $lowersVisit = is_string($previous) && TriageZoneRules::rank($chosen) < TriageZoneRules::rank($previous);
        if ($difference >= 0 && ! $lowersVisit) {
            return;
        }
        $earlier = VisitTriageAssessment::query()->where('visit_id', $visit->id)->exists();
        if (! $earlier || array_filter($readings, fn ($value): bool => $value !== null) === []) {
            $this->refuse('chosen_zone', 'Take the readings again before choosing a lower zone.');
        }
        if ($data['red_flag_codes'] !== []) {
            $this->refuse('chosen_zone', 'A zone cannot be lowered while a red flag is ticked.');
        }
        if ($chosen !== 'red' && $this->hadRedFlag($visit)) {
            $this->refuse('chosen_zone', 'A red flag was ticked at this visit, so the zone stays red.');
        }
        // Past a limit of a zone above the one chosen: any limit for green, a red limit for yellow, none for red.
        $above = array_values(array_filter(VisitTriageAssessment::READINGS, fn (string $field): bool => isset($crossedNow[$field])
            && TriageZoneRules::rank($crossedNow[$field]['zone']) > TriageZoneRules::rank($chosen)));
        $notRetaken = array_values(array_filter(
            $this->readingsToRetake($visit),
            fn (string $field): bool => ($readings[$field] ?? null) === null || in_array($field, $above, true),
        ));
        if ($notRetaken !== []) {
            $this->refuse('chosen_zone', 'Take the readings again: '.self::readingNames($notRetaken).' must be retaken and within limits before the zone is lowered.');
        }
        if ($difference < 0 && $above !== []) {
            $this->refuse('chosen_zone', 'Take the readings again: '.self::readingNames($above).' must be within limits before choosing a lower zone than suggested.');
        }
    }

    /** Whether any triage of this visit ticked a red flag (TRI-1b R1: then the zone stays red). */
    public function hadRedFlag(Visit $visit): bool
    {
        return VisitTriageAssessment::query()->where('visit_id', $visit->id)->get(['red_flag_codes'])
            ->contains(fn (VisitTriageAssessment $row): bool => $row->red_flag_codes !== []);
    }

    /** @param list<string> $fields */
    public static function readingNames(array $fields): string
    {
        $names = array_map(fn (string $field): string => self::READING_NAMES[$field], $fields);
        $last = array_pop($names);

        return ucfirst($names === [] ? $last : implode(', ', $names).' and '.$last);
    }

    /**
     * Adult, child or unknown on the branch-local date of $now; an unknown date of birth with an adult confirmation is
     * adult. Whole years as PostgreSQL's age() counts them (the birthday is reached on the same month and day), so the
     * insert trigger works out the same group.
     */
    public function ageBand(Visit $visit, string $timezone, int $childAgeLimit, ?CarbonImmutable $now = null): string
    {
        $patient = Patient::query()->whereKey($visit->patient_id)->where('organisation_id', $visit->organisation_id)->first(['id', 'date_of_birth']);
        if ($patient?->date_of_birth === null) {
            $confirmed = $patient !== null && DB::table('patient_adult_confirmations')
                ->where('organisation_id', $visit->organisation_id)->where('patient_id', $patient->id)->exists();

            return $confirmed ? 'adult' : 'unknown';
        }
        $today = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($timezone);
        $born = $patient->date_of_birth;
        $years = $today->year - (int) $born->format('Y');
        if ([$today->month, $today->day] < [(int) $born->format('n'), (int) $born->format('j')]) {
            $years--;
        }

        return $years < $childAgeLimit ? 'child' : 'adult';
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

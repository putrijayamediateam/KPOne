<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TRI-1a (TRI-1-DESIGN 4.2, owner decisions 19 Nov 2026): the Director or the HR Manager enters a guideline version
 * as a Draft; the other one checks it against the signing doctor's paper copy and approves it. Whoever entered a
 * version can never approve it (service and PostgreSQL). The earlier approved version stays in force until then.
 * Lock order: actor, then organisation, then the draft, then the version in force. Audit holds ids and counts only.
 */
class TriageGuidelineService
{
    public const READINGS = ['systolic_bp', 'diastolic_bp', 'pulse_bpm', 'temperature_celsius', 'spo2_percent', 'weight_kg', 'height_cm'];

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function enter(User $actor, array $input): TriageGuidelineVersion
    {
        $data = $this->validateEntry($input);

        return DB::transaction(function () use ($actor, $data): TriageGuidelineVersion {
            $actor = TriageActorLock::organisation($actor, TriagePermissions::GUIDELINE_MANAGE_ORGANISATION);
            $this->lockOrganisation($actor);
            $hash = $this->hash('enter', $actor, null, $data);
            $existing = TriageGuidelineVersion::query()->where('organisation_id', $actor->organisation_id)
                ->where('entered_by_user_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    $this->refuse('idempotency_key', 'The retry key has already been used for a different guideline.');
                }

                return $existing;
            }
            if (TriageGuidelineVersion::query()->where('organisation_id', $actor->organisation_id)->where('status', TriageGuidelineVersion::STATUS_DRAFT)->exists()) {
                $this->refuse('guideline', 'A draft guideline is waiting for its check. Approve or discard it first.');
            }
            $now = CarbonImmutable::now('UTC');
            $number = (int) TriageGuidelineVersion::query()->where('organisation_id', $actor->organisation_id)->max('version_number') + 1;
            $version = new TriageGuidelineVersion;
            $version->forceFill([
                'organisation_id' => $actor->organisation_id,
                'version_number' => $number,
                'status' => TriageGuidelineVersion::STATUS_DRAFT,
                'child_age_limit_years' => $data['child_age_limit_years'],
                'approving_doctor_name' => $data['approving_doctor_name'],
                'doctor_signed_on' => $data['doctor_signed_on'],
                'red_flag_count' => count($data['red_flags']),
                'yellow_sign_count' => count($data['yellow_signs']),
                'threshold_count' => count($data['thresholds']),
                'entered_by_user_id' => $actor->id,
                'entered_at' => $now,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
                'lock_version' => 1,
            ])->save();
            foreach ([TriageGuidelineSign::RED_FLAG => [$data['red_flags'], 'rf'], TriageGuidelineSign::YELLOW_SIGN => [$data['yellow_signs'], 'ys']] as $kind => [$labels, $prefix]) {
                foreach ($labels as $index => $label) {
                    $sign = new TriageGuidelineSign;
                    $sign->forceFill([
                        'guideline_version_id' => $version->id, 'kind' => $kind,
                        'code' => sprintf('%s_%02d', $prefix, $index + 1), 'label' => $label,
                        'position' => $index + 1, 'created_at' => $now,
                    ])->save();
                }
            }
            foreach ($data['thresholds'] as $row) {
                $threshold = new TriageGuidelineThreshold;
                $threshold->forceFill([...$row, 'guideline_version_id' => $version->id, 'created_at' => $now])->save();
            }
            $this->event($version, 'entered', $actor->id, $now);
            $this->record('triage.guideline.entered', $version, $actor, [
                'version_number' => $number,
                'red_flag_count' => $version->red_flag_count,
                'yellow_sign_count' => $version->yellow_sign_count,
                'threshold_count' => $version->threshold_count,
            ]);

            return $version->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function approve(User $actor, TriageGuidelineVersion $version, array $input): TriageGuidelineVersion
    {
        $data = Validator::make($input, [
            'lock_version' => ['required', 'integer', 'min:1'],
            'matches_signed_copy' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ], ['matches_signed_copy.accepted' => 'Confirm that this version matches the signed paper copy.'])->validate();

        return $this->decide($actor, $version, 'approve', $data);
    }

    /** @param array<string, mixed> $input */
    public function discard(User $actor, TriageGuidelineVersion $version, array $input): TriageGuidelineVersion
    {
        $data = Validator::make($input, [
            'lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        return $this->decide($actor, $version, 'discard', $data);
    }

    /**
     * The version in force for an organisation with its signs and thresholds, or null when none is approved.
     * Inside a transaction the version row is read FOR SHARE, so an approval cannot commit between this read and a
     * triage insert that cites it.
     */
    public function inForce(int $organisationId, bool $lock = false): ?TriageGuidelineVersion
    {
        $query = TriageGuidelineVersion::query()->where('organisation_id', $organisationId)->where('status', TriageGuidelineVersion::STATUS_APPROVED);
        if ($lock) {
            $query->sharedLock();
        }
        $version = $query->first();
        $version?->load(['signs', 'thresholds']);

        return $version;
    }

    /** @param array<string, mixed> $data */
    private function decide(User $actor, TriageGuidelineVersion $version, string $action, array $data): TriageGuidelineVersion
    {
        return DB::transaction(function () use ($actor, $version, $action, $data): TriageGuidelineVersion {
            $actor = TriageActorLock::organisation($actor, TriagePermissions::GUIDELINE_MANAGE_ORGANISATION);
            $this->lockOrganisation($actor);
            $payload = ['version' => $version->id, 'lock_version' => (int) $data['lock_version']];
            $hash = $this->hash($action, $actor, $version->id, $payload);
            $command = DB::table('triage_guideline_commands')->where('organisation_id', $actor->organisation_id)
                ->where('actor_user_id', $actor->id)->where('retry_key', $data['idempotency_key'])->first();
            if ($command) {
                if (! hash_equals($command->payload_hash, $hash)) {
                    $this->refuse('idempotency_key', 'The retry key has already been used for a different action.');
                }

                return TriageGuidelineVersion::query()->where('organisation_id', $actor->organisation_id)->findOrFail($version->id);
            }
            $locked = TriageGuidelineVersion::query()->whereKey($version->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TriageGuidelineVersion::STATUS_DRAFT || $locked->lock_version !== (int) $data['lock_version']) {
                $this->refuse('lock_version', 'This guideline version has changed. Reload and review it again.');
            }
            $now = CarbonImmutable::now('UTC');
            if ($action === 'discard') {
                $locked->forceFill(['status' => TriageGuidelineVersion::STATUS_DISCARDED, 'discarded_by_user_id' => $actor->id, 'discarded_at' => $now, 'lock_version' => $locked->lock_version + 1])->save();
                $this->event($locked, 'discarded', $actor->id, $now);
                $this->record('triage.guideline.discarded', $locked, $actor, ['version_number' => $locked->version_number]);
            } else {
                if ($locked->entered_by_user_id === $actor->id) {
                    $this->refuse('guideline', 'You entered this version, so someone else must check and approve it.');
                }
                $current = TriageGuidelineVersion::query()->where('organisation_id', $actor->organisation_id)
                    ->where('status', TriageGuidelineVersion::STATUS_APPROVED)->lockForUpdate()->first();
                if ($current) {
                    $current->forceFill(['status' => TriageGuidelineVersion::STATUS_REPLACED, 'replaced_at' => $now, 'lock_version' => $current->lock_version + 1])->save();
                    $this->event($current, 'replaced', null, $now);
                }
                $locked->forceFill(['status' => TriageGuidelineVersion::STATUS_APPROVED, 'approved_by_user_id' => $actor->id, 'approved_at' => $now, 'lock_version' => $locked->lock_version + 1])->save();
                $this->event($locked, 'approved', $actor->id, $now);
                $this->record('triage.guideline.approved', $locked, $actor, [
                    'version_number' => $locked->version_number,
                    'entered_by_user_id' => $locked->entered_by_user_id,
                    'replaced_version_id' => $current?->id,
                ]);
            }
            DB::table('triage_guideline_commands')->insert([
                'organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'retry_key' => $data['idempotency_key'],
                'payload_hash' => $hash, 'result' => json_encode(['id' => $locked->id, 'status' => $locked->status], JSON_THROW_ON_ERROR), 'occurred_at' => $now,
            ]);

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{child_age_limit_years:int, approving_doctor_name:string, doctor_signed_on:string, red_flags:list<string>, yellow_signs:list<string>, thresholds:list<array{reading:string, age_band:string, direction:string, limit_value:string, zone:string}>, idempotency_key:string}
     */
    private function validateEntry(array $input): array
    {
        foreach (['red_flags', 'yellow_signs'] as $list) {
            if (isset($input[$list]) && is_array($input[$list])) {
                $input[$list] = array_map(fn ($label) => is_string($label) ? trim($label) : $label, $input[$list]);
            }
        }
        if (isset($input['approving_doctor_name']) && is_string($input['approving_doctor_name'])) {
            $input['approving_doctor_name'] = trim($input['approving_doctor_name']);
        }
        $data = Validator::make($input, [
            'child_age_limit_years' => ['required', 'integer', 'between:1,18'],
            'approving_doctor_name' => ['required', 'string', 'min:3', 'max:120'],
            'doctor_signed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->latestLocalToday()],
            'red_flags' => ['required', 'array', 'min:1', 'max:12'],
            'red_flags.*' => ['required', 'string', 'min:3', 'max:160', 'distinct:ignore_case'],
            'yellow_signs' => ['present', 'array', 'max:12'],
            'yellow_signs.*' => ['required', 'string', 'min:3', 'max:160', 'distinct:ignore_case'],
            'thresholds' => ['present', 'array', 'max:28'],
            'thresholds.*.reading' => ['required', Rule::in(self::READINGS)],
            'thresholds.*.age_band' => ['required', Rule::in(['adult', 'child'])],
            'thresholds.*.direction' => ['required', Rule::in(['very_low', 'very_high'])],
            'thresholds.*.limit_value' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999.99'],
            'thresholds.*.zone' => ['required', Rule::in(['red', 'yellow'])],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'doctor_signed_on.before_or_equal' => 'The signature date cannot be in the future.',
            'red_flags.required' => 'Enter at least one red flag.',
            'red_flags.min' => 'Enter at least one red flag.',
        ])->validate();
        $thresholds = [];
        $seen = [];
        foreach (array_values($data['thresholds']) as $row) {
            $key = $row['reading'].'|'.$row['age_band'].'|'.$row['direction'];
            if (isset($seen[$key])) {
                $this->refuse('thresholds', 'Enter each reading, age group and direction only once.');
            }
            $seen[$key] = TriageZoneRules::hundredths((string) $row['limit_value']);
            $thresholds[] = [
                'reading' => $row['reading'], 'age_band' => $row['age_band'], 'direction' => $row['direction'],
                'limit_value' => number_format($seen[$key] / 100, 2, '.', ''), 'zone' => $row['zone'],
            ];
        }
        foreach ($seen as $key => $limit) {
            [$reading, $band, $direction] = explode('|', $key);
            $high = $seen["$reading|$band|very_high"] ?? null;
            if ($direction === 'very_low' && $high !== null && $limit >= $high) {
                $this->refuse('thresholds', 'A "very low" limit must be below the "very high" limit for the same reading.');
            }
        }

        return [
            'child_age_limit_years' => (int) $data['child_age_limit_years'],
            'approving_doctor_name' => $data['approving_doctor_name'],
            'doctor_signed_on' => $data['doctor_signed_on'],
            'red_flags' => array_values($data['red_flags']),
            'yellow_signs' => array_values($data['yellow_signs']),
            'thresholds' => $thresholds,
            'idempotency_key' => $data['idempotency_key'],
        ];
    }

    /** The latest "today" among the organisation's branch time zones, so a signature made today anywhere is accepted. */
    private function latestLocalToday(): string
    {
        $zones = Branch::query()->distinct()->pluck('timezone')->filter()->all() ?: ['UTC'];

        return collect($zones)->map(fn (string $zone): string => CarbonImmutable::now($zone)->toDateString())->max();
    }

    private function lockOrganisation(User $actor): void
    {
        Organisation::query()->whereKey($actor->organisation_id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function hash(string $action, User $actor, ?int $versionId, array $payload): string
    {
        return hash_hmac('sha256', json_encode([$action, $actor->organisation_id, $actor->id, $versionId, $payload], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function event(TriageGuidelineVersion $version, string $type, ?int $actorId, CarbonImmutable $at): void
    {
        DB::table('triage_guideline_events')->insert([
            'guideline_version_id' => $version->id, 'event_type' => $type, 'actor_user_id' => $actorId,
            'record_version' => $version->lock_version, 'occurred_at' => $at,
        ]);
    }

    /** @param array<string, int|string|null> $metadata */
    private function record(string $event, TriageGuidelineVersion $version, User $actor, array $metadata): void
    {
        abort_if($this->audit->record($event, $version, $metadata, $actor, null, $actor->organisation_id) === null, 503, 'Unable to record the audit. Try again.');
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

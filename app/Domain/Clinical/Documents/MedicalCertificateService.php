<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MedicalCertificateService
{
    public function __construct(private MedicalCertificateAuthority $authority, private MedicalCertificateSnapshot $snapshots, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function act(User $actor, Visit $visit, array $input): int
    {
        $input = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'], 'action' => ['required', Rule::in(['issue', 'replace', 'void'])],
            'document_id' => ['nullable', 'integer', 'min:1', 'required_unless:action,issue'],
            'language' => ['required_unless:action,void', Rule::in(['en', 'bm'])],
            'start_date' => ['required_unless:action,void', 'date_format:Y-m-d'], 'end_date' => ['required_unless:action,void', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:200', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
            'reason_consent' => ['required_with:reason', 'boolean'],
            'change_reason' => ['required_unless:action,issue', Rule::in(['dates_corrected', 'details_corrected', 'entered_in_error', 'patient_request'])],
        ])->validate();
        if (($input['reason'] ?? '') !== '' && ! filter_var($input['reason_consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['reason_consent' => 'Explicit patient consent is required to print a reason.']);
        }
        // Canonical key ordering makes identical HTTP retries stable; the hash never contains plaintext metadata.
        ksort($input);
        $hash = hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $visit, $input, $hash): int {
            [$actor, $branch, $visit, $encounter] = $this->authority->lock($actor, $visit, $input['expected_branch_id'], write: true);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['mc-command:'.$input['idempotency_key']]);
            $retry = DB::table('mc_actions')->where('idempotency_key', $input['idempotency_key'])->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->visit_id !== $visit->id || ! hash_equals($retry->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for different content.']);
                }

                return $retry->document_id;
            }
            DB::table('mc_controls')->insertOrIgnore(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id, 'lock_version' => 0]);
            $control = DB::table('mc_controls')->where('visit_id', $visit->id)->lockForUpdate()->sole();
            if ($control->lock_version !== (int) $input['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'The MC history changed. Reload before continuing.']);
            }
            $original = null;
            if ($input['action'] === 'issue') {
                $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
                if (DB::table('medical_certificates')->where('visit_id', $visit->id)->exists()
                    || $visit->visit_type !== 'consultation' || $visit->status !== Visit::STATUS_REGISTERED || $visit->assigned_doctor_user_id !== $actor->id
                    || $queue->status !== QueueEntry::STATUS_SERVING || $encounter->status !== 'in_progress'
                    || ConsultationHold::query()->where('clinical_encounter_id', $encounter->id)->whereNull('resumed_at')->exists()
                    || ConsultationCheckout::query()->where('visit_id', $visit->id)->exists()) {
                    throw ValidationException::withMessages(['certificate' => 'First issue requires Serving, not held, before clinical checkout, with no previous MC.']);
                }
            } else {
                $original = DB::table('medical_certificates')->where('id', $input['document_id'])->where('visit_id', $visit->id)->where('issuer_user_id', $actor->id)->lockForUpdate()->firstOrFail();
                if (DB::table('mc_actions')->where('replaces_id', $original->id)->exists()) {
                    throw ValidationException::withMessages(['certificate' => 'This MC has already been replaced or voided. Reload the history.']);
                }
            }
            $documentId = $original?->id;
            if ($input['action'] !== 'void') {
                $credential = ClinicianProfessionalProfile::query()->where('user_id', $actor->id)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->first();
                if (! $credential?->verified) {
                    throw ValidationException::withMessages(['certificate' => 'Verified professional name and MMC registration are required before issuance.']);
                }
                $today = now()->setTimezone($branch->timezone)->toDateString();
                $exam = $encounter->started_at->setTimezone($branch->timezone)->toDateString();
                $days = CarbonImmutable::parse($input['start_date'])->diffInDays(CarbonImmutable::parse($input['end_date'])) + 1;
                if ($input['start_date'] < $exam || $input['start_date'] > $today || $days < 1 || $days > 30) {
                    throw ValidationException::withMessages(['start_date' => 'Start must be between examination and today; duration must be 1–30 inclusive days.']);
                }
                $prefix = ['CHERAS' => 'CHR', 'SUNGAI_BESI' => 'SGB', 'PUCHONG' => 'PCG'][$branch->code] ?? null;
                if ($branch->organisation->code !== 'KLINIK_PUTRIJAYA' || $prefix === null) {
                    throw ValidationException::withMessages(['certificate' => 'MC numbering has not been configured for this organisation and branch.']);
                }
                $year = (int) now()->setTimezone($branch->timezone)->format('Y');
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["mc-number:{$branch->organisation_id}:{$branch->id}:{$year}"]);
                $counter = DB::table('mc_number_counters')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('year', $year)->lockForUpdate()->first();
                $sequence = ($counter ? $counter->last_number : 0) + 1;
                if ($sequence > 999999) {
                    throw ValidationException::withMessages(['certificate' => 'The annual MC number range is exhausted.']);
                }
                $counter ? DB::table('mc_number_counters')->where('id', $counter->id)->update(['last_number' => $sequence])
                    : DB::table('mc_number_counters')->insert(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'year' => $year, 'last_number' => $sequence]);
                $number = sprintf('MC/KP/%s/%d/%06d', $prefix, $year, $sequence);
                $issuedAt = now()->utc()->format('Y-m-d H:i:s.uP');
                $snapshot = $this->snapshots->create($branch, $visit, $encounter, $credential, $input, $number, $issuedAt);
                $encrypted = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
                $documentId = DB::table('medical_certificates')->insertGetId(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                    'visit_id' => $visit->id, 'clinical_encounter_id' => $encounter->id, 'issuer_user_id' => $actor->id, 'template_revision_id' => $snapshot['templateId'],
                    'number' => $number, 'encrypted_snapshot' => $encrypted, 'ciphertext_sha256' => hash('sha256', $encrypted), 'issued_at' => $issuedAt]);
            }
            DB::table('mc_controls')->where('id', $control->id)->update(['lock_version' => $control->lock_version + 1]);
            DB::table('mc_actions')->insert(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id,
                'actor_user_id' => $actor->id, 'document_id' => $documentId, 'replaces_id' => $original?->id, 'action' => $input['action'], 'change_reason' => $input['action'] === 'issue' ? null : $input['change_reason'],
                'idempotency_key' => $input['idempotency_key'], 'payload_hash' => $hash, 'control_version' => $control->lock_version + 1, 'created_at' => now()->utc()->format('Y-m-d H:i:s.uP')]);
            $this->audit->record('medical_certificate.'.$input['action'], $encounter, ['document_id' => $documentId, 'replaces_id' => $original?->id,
                'control_version' => $control->lock_version + 1, 'fields' => $input['action'] === 'void' ? ['status'] : ['certificate']], $actor, $branch);

            return $documentId;
        }, 3);
    }
}

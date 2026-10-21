<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReferralService
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(User $actor, Visit $visit, array $input): array
    {
        $data = $this->validate($input);
        abort_if($data['action'] === 'void', 422);

        return DB::transaction(function () use ($actor, $visit, $data): array {
            [$actor, $branch, $visit, $encounter] = app(ReferralAuthority::class)->lock($actor, $visit, $data['expected_branch_id'], 'referrals.issue.own');
            $source = app(ReferralSource::class)->capture($actor, $branch, $visit, $encounter);
            $this->assertSource($data, $source['token']);
            $snapshot = app(ReferralSnapshot::class)->create($branch, $source, $data, 'PREVIEW — not issued', now()->utc()->toIso8601String());
            $this->audit($actor, $branch, $encounter, 'previewed', ['fields' => ['preview']]);

            return ['snapshot' => $snapshot, 'reviewToken' => $this->reviewToken($data)];
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function act(User $actor, Visit $visit, array $input): int
    {
        $data = $this->validate($input, true);
        $hash = $this->hash($data);

        return DB::transaction(function () use ($actor, $visit, $data, $hash): int {
            [$actor, $branch, $visit, $encounter] = app(ReferralAuthority::class)->lock($actor, $visit, $data['expected_branch_id'], 'referrals.issue.own');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['ref-command:'.$data['idempotency_key']]);
            $retry = DB::table('referral_actions')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->visit_id !== $visit->id || ! hash_equals($retry->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This retry key was used for different content.']);
                }

                return $retry->document_id;
            }
            $original = null;
            if ($data['action'] === 'issue') {
                $visit->queueEntry()->lockForUpdate()->firstOrFail();
                if (! app(ReferralAuthority::class)->canFirstIssue($visit, $encounter, $actor) || $data['lock_version'] !== 0) {
                    throw ValidationException::withMessages(['referral' => 'First issue requires Serving, not held, before clinical checkout.']);
                }
            } else {
                $original = DB::table('referral_documents')->where('id', $data['document_id'])->where('visit_id', $visit->id)->where('issuer_user_id', $actor->id)->lockForUpdate()->firstOrFail();
                $chain = DB::table('referral_chains')->where('id', $original->chain_id)->where('issuer_user_id', $actor->id)->lockForUpdate()->sole();
                if ($chain->lock_version !== $data['lock_version'] || DB::table('referral_actions')->where('replaces_id', $original->id)->exists()) {
                    throw ValidationException::withMessages(['lock_version' => 'This referral changed or its chain is closed. Reload history.']);
                }
            }
            if ($data['action'] === 'void') {
                $this->assertSource($data, $this->voidToken($visit, $encounter, $original));
                $id = $original->id;
            } else {
                $source = app(ReferralSource::class)->capture($actor, $branch, $visit, $encounter);
                $this->assertSource($data, $source['token']);
                if (! hash_equals($this->reviewToken($data), $data['review_token'])) {
                    throw ValidationException::withMessages(['review_token' => 'Preview the exact selected content before issuing.']);
                }
                if (! $original) {
                    $chainId = DB::table('referral_chains')->insertGetId(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id, 'issuer_user_id' => $actor->id, 'lock_version' => 0]);
                    $chain = DB::table('referral_chains')->where('id', $chainId)->lockForUpdate()->sole();
                }
                $clock = now()->utc()->toImmutable();
                $number = app(BranchDocumentNumberAllocator::class)->allocate($branch, 'referral', 'REF', $clock);
                $at = $clock->format('Y-m-d H:i:s.uP');
                $snapshot = app(ReferralSnapshot::class)->create($branch, $source, $data, $number, $at);
                $id = DB::table('referral_documents')->insertGetId(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id, 'issuer_user_id' => $actor->id, 'chain_id' => $chain->id, 'clinical_encounter_id' => $encounter->id, 'template_revision_id' => $snapshot['templateId'], 'number' => $number, 'issued_at' => $at, ...app(DocumentEvidenceCodec::class)->encode($snapshot)]);
            }
            DB::table('referral_chains')->where('id', $chain->id)->update(['lock_version' => $chain->lock_version + 1]);
            DB::table('referral_actions')->insert(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'visit_id' => $visit->id, 'actor_user_id' => $actor->id, 'chain_id' => $chain->id, 'document_id' => $id, 'replaces_id' => $original?->id, 'action' => $data['action'], 'change_reason' => $data['action'] === 'issue' ? null : $data['change_reason'], 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'control_version' => $chain->lock_version + 1, 'created_at' => now()->utc()]);
            $this->audit($actor, $branch, $encounter, $data['action'], ['document_id' => $id, 'chain_id' => $chain->id, 'replaces_id' => $original?->id, 'version' => $chain->lock_version + 1, 'fields' => [$data['action'] === 'void' ? 'status' : 'referral']]);

            return $id;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function history(User $actor, Visit $visit, int $page): array
    {
        return DB::transaction(function () use ($actor, $visit, $page): array {
            [$actor, $branch, $visit, $encounter] = app(ReferralAuthority::class)->lock($actor, $visit, app(BranchAccessService::class)->activeBranch($actor)?->id, 'referrals.view.own');
            $docs = DB::table('referral_documents')->where('visit_id', $visit->id)->where('issuer_user_id', $actor->id)->orderByDesc('id')->paginate(20, page: $page);
            $documents = $docs->map(function (\stdClass $doc) use ($visit, $encounter): array {
                $action = DB::table('referral_actions')->where('replaces_id', $doc->id)->value('action');

                return ['id' => $doc->id, 'number' => $doc->number, 'issuedAt' => CarbonImmutable::parse($doc->issued_at)->utc()->toIso8601String(), 'status' => $action === 'replace' ? 'replaced' : ($action === 'void' ? 'void' : 'current'), 'version' => DB::table('referral_chains')->where('id', $doc->chain_id)->value('lock_version'), 'voidToken' => $this->voidToken($visit, $encounter, $doc)];
            })->all();
            $this->audit($actor, $branch, $encounter, 'history_viewed', ['fields' => ['history']]);

            return ['visitNumber' => $visit->visit_number, 'branchId' => $branch->id, 'timezone' => $branch->timezone, 'canIssue' => $actor->getAllPermissions()->contains('name', 'referrals.issue.own'), 'canFirstIssue' => app(ReferralAuthority::class)->canFirstIssue($visit, $encounter, $actor), 'canPrint' => $actor->getAllPermissions()->contains('name', 'referrals.print.own'), 'documents' => $documents, 'page' => $docs->currentPage(), 'hasMore' => $docs->hasMorePages()];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function source(User $actor, Visit $visit): array
    {
        return DB::transaction(function () use ($actor, $visit): array {
            [$actor, $branch, $visit, $encounter] = app(ReferralAuthority::class)->lock($actor, $visit, app(BranchAccessService::class)->activeBranch($actor)?->id, 'referrals.issue.own');
            $source = app(ReferralSource::class)->capture($actor, $branch, $visit, $encounter);
            $this->audit($actor, $branch, $encounter, 'source_viewed', ['fields' => ['saved_source']]);

            return $source;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function copy(User $actor, Visit $visit, int $id): array
    {
        return DB::transaction(function () use ($actor, $visit, $id): array {
            [$actor, $branch, $visit, $encounter] = app(ReferralAuthority::class)->lock($actor, $visit, app(BranchAccessService::class)->activeBranch($actor)?->id, 'referrals.view.own');
            abort_unless($actor->getAllPermissions()->contains('name', 'referrals.print.own'), 403);
            $doc = DB::table('referral_documents')->where('id', $id)->where('visit_id', $visit->id)->where('issuer_user_id', $actor->id)->firstOrFail();
            $snapshot = app(DocumentEvidenceCodec::class)->decode($doc->encrypted_snapshot, $doc->ciphertext_sha256);
            $action = DB::table('referral_actions')->where('replaces_id', $id)->value('action');
            $this->audit($actor, $branch, $encounter, 'print_view_opened', ['document_id' => $id]);

            return ['snapshot' => $snapshot, 'status' => $action === 'replace' ? 'REPLACED' : ($action === 'void' ? 'VOID' : 'CURRENT')];
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $write = false): array
    {
        $rules = ['expected_branch_id' => ['required', 'integer'], 'action' => ['required', Rule::in(['issue', 'replace', 'void'])], 'document_id' => ['nullable', 'integer', 'required_unless:action,issue'], 'lock_version' => ['required', 'integer', 'min:0'], 'source_token' => ['required', 'string', 'size:64'], 'review_token' => [$write && ($input['action'] ?? '') !== 'void' ? 'required' : 'nullable', 'string', 'size:64'], 'idempotency_key' => [$write ? 'required' : 'nullable', 'uuid'], 'change_reason' => ['required_unless:action,issue', Rule::in(['details_corrected', 'entered_in_error', 'patient_request'])]];
        if (($input['action'] ?? '') !== 'void') {
            $rules += ['language' => ['required', Rule::in(['en', 'bm'])], 'urgency' => ['required', Rule::in(['routine', 'urgent'])], 'consent' => ['required', 'accepted'], 'include_allergies' => ['required', 'boolean']];
            foreach (ReferralSnapshot::TEXT_LIMITS as $field => $limit) {
                $rules[$field] = [in_array($field, ['institution', 'reason'], true) ? 'required' : 'nullable', 'string', 'max:'.$limit, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'];
            }
            foreach (['diagnoses', 'medicines', 'allergies'] as $field) {
                $rules[$field] = ['present', 'array', 'max:20'];
                $rules[$field.'.*'] = ['integer', 'distinct', 'min:1'];
            }
        }
        $data = Validator::make($input, $rules)->validate();
        foreach (ReferralSnapshot::TEXT_LIMITS as $field => $limit) {
            if (isset($data[$field])) {
                $data[$field] = trim(str_replace("\r\n", "\n", $data[$field]));
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function reviewToken(array $data): string
    {
        unset($data['review_token'], $data['idempotency_key']);

        return $this->hash($data);
    }

    /** @param array<string, mixed> $data */
    private function hash(array $data): string
    {
        ksort($data);

        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $data */
    private function assertSource(array $data, string $token): void
    {
        if (! hash_equals($token, $data['source_token'])) {
            throw ValidationException::withMessages(['source_token' => 'Saved source changed. Reload and preview again.']);
        }
    }

    private function voidToken(Visit $visit, ClinicalEncounter $encounter, \stdClass $doc): string
    {
        return $this->hash(['visit' => $visit->getAttributes(), 'encounter' => $encounter->getAttributes(), 'document' => $doc->id, 'hash' => $doc->ciphertext_sha256]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, Branch $branch, ClinicalEncounter $encounter, string $action, array $metadata): void
    {
        abort_if(app(AuditRecorder::class)->record('referral.'.$action, $encounter, $metadata, $actor, $branch) === null, 503, 'Unable to audit document access.');
    }
}

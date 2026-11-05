<?php

namespace Tests\Support;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * READ-PATH TESTS ONLY. Never use it to test merge or unmerge behaviour: it skips PatientMergeService's checks
 * (unfinished visits, open QR check-ins, missing allergies, authority) and writes no audit row.
 *
 * Writes an approved merge or unmerge (request, event, pointer) in the order the PostgreSQL guards require, for
 * read-path tests whose fixtures keep visits open (the service refuses a merge while a visit is unfinished).
 */
final class MergeFixture
{
    public static function merge(Patient $kept, Patient $duplicate, User $proposer, User $approver): void
    {
        self::record(PatientMergeRequest::KIND_MERGE, $kept, $duplicate, $proposer, $approver);
    }

    public static function unmerge(Patient $kept, Patient $duplicate, User $proposer, User $approver): void
    {
        self::record(PatientMergeRequest::KIND_UNMERGE, $kept, $duplicate->refresh(), $proposer, $approver);
    }

    private static function record(string $kind, Patient $kept, Patient $duplicate, User $proposer, User $approver): void
    {
        if ($proposer->is($approver)) {
            throw new \LogicException('A merge needs two different people.');
        }
        $merge = $kind === PatientMergeRequest::KIND_MERGE;
        $requestId = DB::table('patient_merge_requests')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $kept->organisation_id, 'kind' => $kind,
            'kept_patient_id' => $kept->id, 'merged_patient_id' => $duplicate->id, 'reason_code' => $merge ? 'same_person_registered_twice' : 'merged_in_error',
            'status' => PatientMergeRequest::STATUS_APPROVED, 'proposed_by_user_id' => $proposer->id, 'proposed_at' => now(),
            'decided_by_user_id' => $approver->id, 'decided_at' => now(), 'kept_lock_version' => $kept->lock_version,
            'merged_lock_version' => $duplicate->lock_version, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()),
            'lock_version' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('patient_merges')->insert([
            'organisation_id' => $kept->organisation_id, 'patient_merge_request_id' => $requestId, 'event' => $merge ? 'merged' : 'unmerged',
            'kept_patient_id' => $kept->id, 'merged_patient_id' => $duplicate->id, 'proposed_by_user_id' => $proposer->id,
            'approved_by_user_id' => $approver->id, 'occurred_at' => now(),
        ]);
        DB::table('patients')->where('id', $duplicate->id)->update([
            'merged_into_patient_id' => $merge ? $kept->id : null, 'merged_at' => $merge ? now() : null, 'lock_version' => $duplicate->lock_version + 1,
        ]);
    }
}

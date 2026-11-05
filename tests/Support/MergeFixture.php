<?php

namespace Tests\Support;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes an approved merge (request, event, pointer) the same way the database guards require, for read-path tests
 * whose fixtures keep visits open (the service refuses a merge while a visit is unfinished).
 */
final class MergeFixture
{
    public static function merge(Patient $kept, Patient $duplicate, User $proposer, User $approver): void
    {
        $requestId = DB::table('patient_merge_requests')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $kept->organisation_id, 'kind' => PatientMergeRequest::KIND_MERGE,
            'kept_patient_id' => $kept->id, 'merged_patient_id' => $duplicate->id, 'reason_code' => 'same_person_registered_twice',
            'status' => PatientMergeRequest::STATUS_APPROVED, 'proposed_by_user_id' => $proposer->id, 'proposed_at' => now(),
            'decided_by_user_id' => $approver->id, 'decided_at' => now(), 'kept_lock_version' => $kept->lock_version,
            'merged_lock_version' => $duplicate->lock_version, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()),
            'lock_version' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('patient_merges')->insert([
            'organisation_id' => $kept->organisation_id, 'patient_merge_request_id' => $requestId, 'event' => 'merged',
            'kept_patient_id' => $kept->id, 'merged_patient_id' => $duplicate->id, 'proposed_by_user_id' => $proposer->id,
            'approved_by_user_id' => $approver->id, 'occurred_at' => now(),
        ]);
        DB::table('patients')->where('id', $duplicate->id)->update(['merged_into_patient_id' => $kept->id, 'merged_at' => now(), 'lock_version' => $duplicate->lock_version + 1]);
    }
}

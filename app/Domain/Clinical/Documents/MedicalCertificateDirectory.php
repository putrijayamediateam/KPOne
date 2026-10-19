<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class MedicalCertificateDirectory
{
    public function __construct(private MedicalCertificateAuthority $authority, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public function history(User $actor, Visit $visit, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $visit, $page): array {
            [$actor, $branch, $visit, $encounter] = $this->authority->lock($actor, $visit, $this->authority->branchId($actor));
            $documents = DB::table('medical_certificates')->where('visit_id', $visit->id)->orderByDesc('id')->paginate(20, ['id', 'number', 'issued_at', 'issuer_user_id'], page: $page);
            $canIssue = $actor->getAllPermissions()->contains('name', 'medical_certificates.issue.own') && $encounter->attending_clinician_user_id === $actor->id;
            $credential = ClinicianProfessionalProfile::query()->where('user_id', $actor->id)->where('organisation_id', $branch->organisation_id)->first();

            return ['visitNumber' => $visit->visit_number, 'branchId' => $branch->id, 'lockVersion' => DB::table('mc_controls')->where('visit_id', $visit->id)->value('lock_version') ?? 0,
                'patientName' => $visit->patient->full_name, 'canIssue' => $canIssue,
                'canFirstIssue' => $canIssue && ! DB::table('medical_certificates')->where('visit_id', $visit->id)->exists() && $visit->status === 'registered' && $visit->visit_type === 'consultation' && $visit->assigned_doctor_user_id === $actor->id
                    && $visit->queueEntry?->status === 'serving' && ! DB::table('consultation_holds')->where('clinical_encounter_id', $encounter->id)->whereNull('resumed_at')->exists() && ! DB::table('consultation_checkouts')->where('visit_id', $visit->id)->exists(), 'credentialVerified' => (bool) $credential?->verified, 'today' => now()->setTimezone($branch->timezone)->toDateString(),
                'canPrint' => $actor->getAllPermissions()->contains('name', 'medical_certificates.print.branch'),
                'documents' => $documents->map(function (\stdClass $document) use ($canIssue, $actor): array {
                    $status = DB::table('mc_actions')->where('replaces_id', $document->id)->value('action');

                    return ['id' => $document->id, 'number' => $document->number, 'issuedAt' => CarbonImmutable::parse($document->issued_at)->utc()->toIso8601String(), 'status' => $status === 'replace' ? 'replaced' : ($status === 'void' ? 'void' : 'current'),
                        'canCorrect' => $canIssue && $document->issuer_user_id === $actor->id && $status === null];
                })->all(), 'page' => $documents->currentPage(), 'hasMore' => $documents->hasMorePages()];
        }, 3);
    }

    /** @return array{snapshot: array<string, mixed>, status: string} */
    public function snapshot(User $actor, Visit $visit, int $id, bool $print = false): array
    {
        return DB::transaction(function () use ($actor, $visit, $id, $print): array {
            [$actor, $branch, $visit, $encounter] = $this->authority->lock($actor, $visit, $this->authority->branchId($actor), print: $print);
            $doc = DB::table('medical_certificates')->where('id', $id)->where('visit_id', $visit->id)->firstOrFail();
            abort_unless(hash_equals($doc->ciphertext_sha256, hash('sha256', $doc->encrypted_snapshot)), 500);
            $snapshot = json_decode(Crypt::decryptString($doc->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
            $status = DB::table('mc_actions')->where('replaces_id', $id)->value('action');
            $this->audit->record($print ? 'medical_certificate.print_view_opened' : 'medical_certificate.viewed', $encounter, ['document_id' => $id], $actor, $branch);

            return ['snapshot' => $snapshot, 'status' => $status === 'replace' ? 'REPLACED' : ($status === 'void' ? 'VOID' : 'CURRENT')];
        }, 3);
    }
}

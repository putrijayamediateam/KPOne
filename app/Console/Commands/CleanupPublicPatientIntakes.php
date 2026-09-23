<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupPublicPatientIntakes extends Command
{
    protected $signature = 'public-intakes:cleanup';

    protected $description = 'Expire public patient intakes and purge retained encrypted payloads idempotently.';

    public function handle(AuditRecorder $audit): int
    {
        $expired = 0;
        $purged = 0;
        $now = now()->utc();

        PublicPatientIntake::query()
            ->whereIn('status', [
                PublicPatientIntake::STATUS_PENDING,
                PublicPatientIntake::STATUS_UNDER_REVIEW,
                PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
            ])
            ->where('expires_at', '<=', $now)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($audit, &$expired): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $audit, &$expired): void {
                        $intake = PublicPatientIntake::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                        if (! in_array($intake->status, [
                            PublicPatientIntake::STATUS_PENDING,
                            PublicPatientIntake::STATUS_UNDER_REVIEW,
                            PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
                        ], true) || $intake->expires_at->isFuture()) {
                            return;
                        }
                        $from = $intake->status;
                        $intake->forceFill([
                            'status' => PublicPatientIntake::STATUS_EXPIRED,
                            'lock_version' => $intake->lock_version + 1,
                        ])->save();
                        $audit->record('public_intake.expired', $intake, [
                            'public_intake_public_id' => $intake->public_id,
                            'from_state' => $from,
                            'to_state' => PublicPatientIntake::STATUS_EXPIRED,
                        ], branch: $intake->branch, organisationId: $intake->organisation_id);
                        $expired++;
                    }, 3);
                }
            });

        PublicPatientIntake::query()
            ->whereNull('payload_purged_at')
            ->whereNotNull('encrypted_payload')
            ->where('payload_purge_at', '<=', $now)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($audit, $now, &$purged): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $audit, $now, &$purged): void {
                        $intake = PublicPatientIntake::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                        if ($intake->payload_purged_at !== null || $intake->payload_purge_at->isFuture()) {
                            return;
                        }
                        $intake->forceFill([
                            'encrypted_payload' => null,
                            'payload_purged_at' => $now,
                            'lock_version' => $intake->lock_version + 1,
                        ])->save();
                        $audit->record('public_intake.payload_purged', $intake, [
                            'public_intake_public_id' => $intake->public_id,
                            'lifecycle_state' => $intake->status,
                        ], branch: $intake->branch, organisationId: $intake->organisation_id);
                        $purged++;
                    }, 3);
                }
            });

        $prunedSessions = 0;
        PublicIntakeSession::query()
            ->where('expires_at', '<=', $now)
            ->whereDoesntHave('intake')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($audit, &$prunedSessions): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $audit, &$prunedSessions): void {
                        $session = PublicIntakeSession::query()->whereKey($row->id)->lockForUpdate()->first();
                        if (! $session || $session->expires_at->isFuture()) {
                            return;
                        }
                        // Re-check under lock: a submission could have consumed this
                        // session (creating its intake) between the outer scan above
                        // and this row lock being acquired.
                        if (PublicPatientIntake::query()->where('public_intake_session_id', $session->id)->exists()) {
                            return;
                        }
                        $branch = $session->branch;
                        $organisationId = $session->organisation_id;
                        $sessionPublicId = $session->public_id;
                        $session->delete();
                        $audit->record('public_intake.session_pruned', null, [
                            'public_intake_session_public_id' => $sessionPublicId,
                        ], branch: $branch, organisationId: $organisationId);
                        $prunedSessions++;
                    }, 3);
                }
            });

        $this->components->info("Expired {$expired}; purged {$purged}; pruned {$prunedSessions} unused sessions.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Shared;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * DSP-25 (owner decision 4 of 20-21 Nov 2026, answers P4, P5, 15 and 16): a Director's read-only review of a clinical
 * record is recorded before any content is built. Shared with the consultation review (Queue & TV, Amirul), from
 * TRI-1e the doctor's read-only triage view (Patients & Registration, Syazwan) and, since BIL-37, the Resident Doctor's
 * read-only bill of their own patient (Billing, event billing.reviewed).
 *
 * Call it first and let it commit; only then build the page data. If the audit row cannot be written it throws, so the
 * caller shows nothing. Event names and id keys are allowlisted, so no content can slip into the audit row: a new
 * review adds its event and keys here deliberately.
 */
final class ReadOnlyReviewAudit
{
    /** @var list<string> */
    public const EVENTS = ['dispensary.case_reviewed', 'consultation.reviewed', 'triage.viewed', 'billing.reviewed'];

    /** @var list<string> */
    public const ID_KEYS = ['case', 'visit', 'patient', 'encounter', 'invoice'];

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $ids */
    public function record(User $actor, string $event, Model $subject, array $ids, ?Branch $branch = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('This review event is not allowlisted.');
        }

        foreach ($ids as $key => $value) {
            if (! in_array($key, self::ID_KEYS, true) || ! (is_int($value) || is_string($value))) {
                throw new InvalidArgumentException('This review id key is not allowlisted.');
            }
        }

        // Committed on its own before the page data is built (the caller is never inside an outer transaction).
        $log = DB::transaction(fn () => $this->audit->record($event, $subject, ['ids' => $ids], $actor, $branch, $actor->organisation_id));

        if ($log === null || ! $log->exists) {
            throw new RuntimeException('The review could not be recorded, so nothing is shown.');
        }
    }
}

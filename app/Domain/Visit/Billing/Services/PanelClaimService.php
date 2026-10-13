<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimDocument;
use App\Domain\Visit\Billing\Models\PanelClaimEvent;
use App\Domain\Visit\Billing\Models\PanelClaimLine;
use App\Domain\Visit\Billing\Models\PanelClaimPayment;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PanelClaimService
{
    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $a */
    public function prepare(User $actor, CoverageAllocation $allocation, array $a): PanelClaim
    {
        Validator::make($a, ['expected_branch_id' => ['required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $allocation, $a): PanelClaim {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, 'panel.claims.manage.branch');
            $claim = $this->buildLockedSnapshot($actor, $branch, $allocation);

            $this->event($claim, $actor, $branch, 'prepared', amount: $claim->claim_amount_sen);
            $this->audit->record('panel_claim.prepared', $claim, ['claim_amount_sen' => $claim->claim_amount_sen], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function buildLockedSnapshot(User $actor, Branch $branch, CoverageAllocation $allocation, array $attributes = []): PanelClaim
    {
        $allocation = CoverageAllocation::query()->whereKey($allocation->id)
            ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->firstOrFail();
        $invoice = Invoice::query()->whereKey($allocation->invoice_id)->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
        $allocation = CoverageAllocation::query()->whereKey($allocation->id)
            ->where('invoice_id', $invoice->id)->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
        $this->requireClaimable($allocation, $invoice);

        if (PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->exists()) {
            throw ValidationException::withMessages(['claim' => 'A claim pack already exists for this approved Panel allocation.']);
        }

        $visit = Visit::query()->whereKey($invoice->visit_id)->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->firstOrFail();
        $patient = DB::table('patients')->where('id', $invoice->patient_id)
            ->where('organisation_id', $actor->organisation_id)->first(['full_name']);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
        if (! $patient || $lines->isEmpty() || $invoice->invoice_number === null) {
            throw ValidationException::withMessages(['claim' => 'The finalized Invoice is missing claim-pack source data.']);
        }

        $claim = new PanelClaim;
        $claim->forceFill(array_replace([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $actor->organisation_id,
            'branch_id' => $branch->id,
            'coverage_allocation_id' => $allocation->id,
            'invoice_id' => $invoice->id,
            'patient_id' => $invoice->patient_id,
            'panel_id' => $allocation->panel_id,
            'invoice_number' => $invoice->invoice_number,
            'visit_number' => $visit->visit_number,
            'patient_name_snapshot' => $patient->full_name,
            'panel_name_snapshot' => $allocation->panel_name_snapshot,
            'member_reference' => $allocation->member_reference,
            'branch_name_snapshot' => $branch->name,
            'visit_date' => $visit->registered_at->setTimezone($branch->timezone)->toDateString(),
            'invoice_total_sen' => $invoice->total_sen,
            'claim_amount_sen' => $allocation->amount_sen,
            'status' => 'prepared',
            'lock_version' => 1,
            'created_by_user_id' => $actor->id,
        ], $attributes))->save();

        foreach ($lines as $index => $line) {
            $copy = new PanelClaimLine;
            $copy->forceFill([
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id,
                'panel_claim_id' => $claim->id,
                'line_number' => $index + 1,
                'line_type' => $line->line_type,
                'display_name' => $line->display_name,
                'code_snapshot' => $line->code_snapshot,
                'unit_snapshot' => $line->unit_snapshot,
                'quantity' => $line->quantity,
                'unit_price_sen' => $line->unit_price_sen,
                'line_total_sen' => $line->line_total_sen,
            ])->save();
        }

        return $claim;
    }

    /** @param array<string, mixed> $a */
    public function create(User $actor, CoverageAllocation $allocation, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'allocation_lock_version' => ['required', 'integer', 'min:1'],
            'invoice_lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'submitted_date' => ['required', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'photos' => ['sometimes', 'array', 'max:10'],
            'photos.*' => ['required', 'file'],
        ])->validate();
        $photos = $a['photos'] ?? [];
        $hashes = [];
        foreach ($photos as $index => $photo) {
            $mime = $this->validateDocument($photo, "photos.{$index}");
            $hashes[] = [
                'sha256' => hash_file('sha256', $photo->getRealPath()),
                'mime' => $mime, 'size' => $photo->getSize(), 'name' => $photo->getClientOriginalName(),
            ];
        }
        $remarks = trim((string) ($a['remarks'] ?? ''));
        $hash = hash('sha256', json_encode([
            $allocation->id, (int) $a['expected_branch_id'], (int) $a['allocation_lock_version'],
            (int) $a['invoice_lock_version'], $a['submitted_date'], $remarks, $hashes,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $allocation, $a, $remarks, $hash): PanelClaim {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, 'panel.claims.manage.branch');
            // Actor authority locking serialises retries; the scoped unique key also guards the database.
            $existing = PanelClaim::query()->where('organisation_id', $actor->organisation_id)
                ->where('creation_idempotency_key', $a['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->branch_id !== $branch->id || $existing->creation_payload_hash !== $hash) {
                    throw ValidationException::withMessages(['claim' => 'This creation key was already used with different details.']);
                }

                return $existing;
            }
            $claim = $this->buildLockedSnapshot($actor, $branch, $allocation, [
                'status' => 'submitted', 'remarks' => $remarks === '' ? null : $remarks,
                'creation_idempotency_key' => $a['idempotency_key'], 'creation_payload_hash' => $hash,
            ]);
            $lockedAllocation = CoverageAllocation::query()->findOrFail($claim->coverage_allocation_id);
            $invoice = Invoice::query()->findOrFail($claim->invoice_id);
            if ($lockedAllocation->lock_version !== (int) $a['allocation_lock_version'] || $invoice->lock_version !== (int) $a['invoice_lock_version']) {
                throw ValidationException::withMessages(['claim' => 'The invoice or allocation has changed. Reload before creating the claim.']);
            }
            $today = CarbonImmutable::now($branch->timezone);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $a['submitted_date'], $branch->timezone);
            if ($date === null || $date->toDateString() > $today->toDateString() || $date->toDateString() < $claim->visit_date->toDateString()) {
                throw ValidationException::withMessages(['submitted_date' => 'The submitted date must be between the visit date and today.']);
            }
            $occurredAt = ($date->isSameDay($today) ? $today : $date)->utc();
            $this->event($claim, $actor, $branch, 'prepared', amount: $claim->claim_amount_sen, occurredAt: $occurredAt);
            $this->event($claim, $actor, $branch, 'submitted', amount: $claim->claim_amount_sen, occurredAt: $occurredAt);
            $this->audit->record('panel_claim.created', $claim, [
                'claim_amount_sen' => $claim->claim_amount_sen, 'submitted_date' => $a['submitted_date'],
            ], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function adjustAmount(User $actor, PanelClaim $routeClaim, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'amount_sen' => ['required'],
            'reason' => ['required', 'string', 'max:500'],
            'confirm_over_invoice' => ['sometimes', 'boolean'],
        ])->validate();
        $amount = ExactMoney::sen($a['amount_sen']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount_sen' => 'A Panel claim must be positive.']);
        }

        return DB::transaction(function () use ($actor, $routeClaim, $a, $amount): PanelClaim {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $this->requireVersion($claim, $a['lock_version']);
            if (! in_array($claim->status, ['prepared', 'submitted', 'queried', 'rejected'], true) || $claim->payments()->exists()) {
                throw ValidationException::withMessages(['claim' => 'Only an unpaid prepared, submitted, queried or rejected claim can be adjusted.']);
            }
            if ($amount > $claim->invoice_total_sen && ! filter_var($a['confirm_over_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['confirm_over_invoice' => 'Confirm the separate above-invoice adjustment before saving this claim amount.']);
            }
            if ($amount === $claim->claim_amount_sen) {
                throw ValidationException::withMessages(['amount_sen' => 'Enter a different claim amount.']);
            }

            $previous = $claim->claim_amount_sen;
            $claim->forceFill([
                'claim_amount_sen' => $amount,
                'accepted_amount_sen' => $claim->status === 'submitted' ? $claim->accepted_amount_sen : null,
                'status' => $claim->status === 'submitted' ? 'submitted' : 'prepared',
                'lock_version' => $claim->lock_version + 1,
            ])->save();
            $this->event($claim, $actor, $branch, 'amount_adjusted', $previous, $amount, note: $a['reason']);
            $this->audit->record('panel_claim.amount_adjusted', $claim, ['previous_amount_sen' => $previous, 'amount_sen' => $amount], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function submit(User $actor, PanelClaim $routeClaim, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'external_reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeClaim, $a): PanelClaim {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $this->requireVersion($claim, $a['lock_version']);
            if (! in_array($claim->status, ['prepared', 'queried', 'rejected'], true) || $claim->payments()->exists()) {
                throw ValidationException::withMessages(['claim' => 'This claim is not ready for submission.']);
            }
            $this->requireSourceStillClaimable($claim);

            $claim->forceFill([
                'status' => 'submitted',
                'accepted_amount_sen' => null,
                'lock_version' => $claim->lock_version + 1,
            ])->save();
            $this->event($claim, $actor, $branch, 'submitted', amount: $claim->claim_amount_sen, externalReference: $a['external_reference'] ?? null);
            $this->audit->record('panel_claim.submitted', $claim, ['claim_amount_sen' => $claim->claim_amount_sen], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function recordQuery(User $actor, PanelClaim $routeClaim, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:500'],
            'external_reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeClaim, $a): PanelClaim {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $this->requireVersion($claim, $a['lock_version']);
            if ($claim->status !== 'submitted' || $claim->payments()->exists()) {
                throw ValidationException::withMessages(['claim' => 'Only a submitted claim can record a Panel query.']);
            }

            $claim->forceFill(['status' => 'queried', 'lock_version' => $claim->lock_version + 1])->save();
            $this->event($claim, $actor, $branch, 'queried', amount: $claim->claim_amount_sen, externalReference: $a['external_reference'] ?? null, note: $a['note']);
            $this->audit->record('panel_claim.queried', $claim, ['record_version' => $claim->lock_version], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function recordDecision(User $actor, PanelClaim $routeClaim, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:accepted,partially_accepted,rejected'],
            'accepted_amount_sen' => ['required'],
            'note' => ['required', 'string', 'max:500'],
            'external_reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
        ])->validate();
        $accepted = ExactMoney::sen($a['accepted_amount_sen'], 'accepted_amount_sen');

        return DB::transaction(function () use ($actor, $routeClaim, $a, $accepted): PanelClaim {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $this->requireVersion($claim, $a['lock_version']);
            if (! in_array($claim->status, ['submitted', 'queried'], true) || $claim->payments()->exists()) {
                throw ValidationException::withMessages(['claim' => 'A decision can only be recorded for a submitted claim without remittances.']);
            }
            $decision = (string) $a['decision'];
            $expected = match ($decision) {
                'accepted' => $accepted > 0 && $accepted === $claim->claim_amount_sen,
                'partially_accepted' => $accepted > 0 && $accepted < $claim->claim_amount_sen,
                'rejected' => $accepted === 0,
                default => false,
            };
            if (! $expected) {
                throw ValidationException::withMessages(['accepted_amount_sen' => 'The accepted amount must match the recorded Panel decision and cannot exceed the claim.']);
            }

            $claim->forceFill(['status' => $decision, 'accepted_amount_sen' => $accepted, 'lock_version' => $claim->lock_version + 1])->save();
            $eventType = 'decision_'.$decision;
            $this->event($claim, $actor, $branch, $eventType, amount: $accepted, externalReference: $a['external_reference'] ?? null, note: $a['note']);
            $this->audit->record('panel_claim.decision_recorded', $claim, ['decision' => $decision, 'accepted_amount_sen' => $accepted], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function recordPayment(User $actor, PanelClaim $routeClaim, array $a): PanelClaimPayment
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'amount_sen' => ['required'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
        ])->validate();
        $amount = ExactMoney::sen($a['amount_sen']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount_sen' => 'A remittance must be positive.']);
        }

        return DB::transaction(function () use ($actor, $routeClaim, $a, $amount): PanelClaimPayment {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $a['received_date'], $branch->timezone);
            if (! $date || $date->format('Y-m-d') !== $a['received_date']) {
                throw ValidationException::withMessages(['received_date' => 'Enter a valid Panel remittance date.']);
            }
            $receivedAt = $date->setTime(12, 0)->utc();
            $hash = hash('sha256', json_encode([
                $claim->public_id,
                $actor->id,
                $amount,
                $receivedAt->toIso8601String(),
                $a['reference'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $existing = PanelClaimPayment::query()->where('organisation_id', $actor->organisation_id)
                ->where('idempotency_key', $a['idempotency_key'])->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash || $existing->branch_id !== $branch->id) {
                    throw ValidationException::withMessages(['payment' => 'This request key was already used.']);
                }

                return $existing;
            }
            if (! in_array($claim->status, ['accepted', 'partially_accepted', 'partially_paid'], true) || $claim->accepted_amount_sen === null) {
                throw ValidationException::withMessages(['payment' => 'A Panel remittance can only be recorded after an accepted decision.']);
            }
            $received = (int) $claim->payments()->sum('amount_sen');
            if ($amount > $claim->accepted_amount_sen - $received) {
                throw ValidationException::withMessages(['amount_sen' => 'The remittance exceeds the accepted amount still outstanding on this claim.']);
            }

            $payment = new PanelClaimPayment;
            $payment->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id,
                'panel_claim_id' => $claim->id,
                'amount_sen' => $amount,
                'currency' => 'MYR',
                'reference' => $a['reference'] ?? null,
                'idempotency_key' => $a['idempotency_key'],
                'payload_hash' => $hash,
                'received_at' => $receivedAt,
                'recorded_by_user_id' => $actor->id,
            ])->save();
            $claim->forceFill([
                'status' => $received + $amount === $claim->accepted_amount_sen ? 'paid' : 'partially_paid',
                'lock_version' => $claim->lock_version + 1,
            ])->save();
            $this->audit->record('panel_claim.payment_received', $claim, [
                'amount_sen' => $amount,
                'record_version' => $claim->lock_version,
            ], $actor, $branch);

            return $payment;
        }, 3);
    }

    public const DOCUMENT_DISK = 'local';

    public const MAX_DOCUMENTS = 10;

    public const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

    /** @param array<string, mixed> $a */
    public function addNote(User $actor, PanelClaim $routeClaim, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeClaim, $a): PanelClaim {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $this->event($claim, $actor, $branch, 'note', note: trim((string) $a['note']));
            $this->audit->record('panel_claim.note_added', $claim, ['record_version' => $claim->lock_version], $actor, $branch);

            return $claim;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function updateRemarks(User $actor, PanelClaim $routeClaim, array $a): PanelClaim
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeClaim, $a): PanelClaim {
            [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
            $this->requireVersion($claim, $a['lock_version']);
            if (! in_array($claim->status, ['prepared', 'submitted', 'queried', 'rejected'], true)) {
                throw ValidationException::withMessages(['claim' => 'Remarks can no longer be edited once the Panel has accepted the claim.']);
            }
            $remarks = trim((string) ($a['remarks'] ?? ''));
            $claim->forceFill([
                'remarks' => $remarks === '' ? null : $remarks,
                'lock_version' => $claim->lock_version + 1,
            ])->save();
            $this->audit->record('panel_claim.remarks_updated', $claim, ['record_version' => $claim->lock_version], $actor, $branch);

            return $claim;
        }, 3);
    }

    private function validateDocument(UploadedFile $file, string $field = 'file'): string
    {
        $path = $file->getRealPath();
        $mime = $path === false ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! $file->isValid() || ! in_array($mime, ['image/jpeg', 'image/png'], true)
            || $file->getSize() < 1 || $file->getSize() > self::MAX_DOCUMENT_BYTES) {
            throw ValidationException::withMessages([$field => 'Upload a JPG or PNG image of at most 5 MB.']);
        }

        return $mime;
    }

    /** @param array<string, mixed> $a */
    public function addDocument(User $actor, PanelClaim $routeClaim, array $a, UploadedFile $file): PanelClaimDocument
    {
        Validator::make($a, ['expected_branch_id' => ['required', 'integer', 'min:1']])->validate();
        $mime = $this->validateDocument($file);
        $extension = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $paths = [];

        try {
            $document = DB::transaction(function () use ($actor, $routeClaim, $a, $file, $mime, $extension, &$paths): PanelClaimDocument {
                [$actor, $branch, $claim] = $this->lockClaim($actor, $routeClaim, $a);
                if (isset($a['lock_version'])) {
                    $this->requireVersion($claim, $a['lock_version']);
                }
                if ($claim->documents()->count() >= self::MAX_DOCUMENTS) {
                    throw ValidationException::withMessages(['file' => 'A claim can hold at most '.self::MAX_DOCUMENTS.' documents.']);
                }
                $claim->forceFill(['lock_version' => $claim->lock_version + 1])->save();
                $publicId = (string) Str::uuid();
                $path = "panel-claims/{$claim->organisation_id}/{$branch->id}/{$claim->public_id}/{$publicId}.{$extension}";
                $paths[] = $path;
                if (Storage::disk(self::DOCUMENT_DISK)->putFileAs(dirname($path), $file, basename($path)) === false) {
                    throw new \RuntimeException('Claim photo storage failed.');
                }
                $document = new PanelClaimDocument;
                $document->forceFill([
                    'public_id' => $publicId,
                    'organisation_id' => $claim->organisation_id,
                    'branch_id' => $branch->id,
                    'panel_claim_id' => $claim->id,
                    'original_name' => Str::limit(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', (string) $file->getClientOriginalName()) ?: 'photo', 200, ''),
                    'mime_type' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: str_repeat('0', 64),
                    'storage_path' => $path,
                    'uploaded_by_user_id' => $actor->id,
                ])->save();
                $this->audit->record('panel_claim.document_added', $claim, ['mime_type' => $mime, 'size_bytes' => $document->size_bytes], $actor, $branch);

                return $document;
            }, 3);
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk(self::DOCUMENT_DISK)->delete($path);
            }

            throw $e;
        }

        // A retried transaction may have written an earlier file before it rolled back.
        foreach ($paths as $path) {
            if ($path !== $document->storage_path) {
                Storage::disk(self::DOCUMENT_DISK)->delete($path);
            }
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{User, Branch, PanelClaim}
     */
    private function lockClaim(User $actor, PanelClaim $routeClaim, array $a): array
    {
        $branch = $this->authority->branch($actor, $a['expected_branch_id'] ?? null);
        $actor = $this->authority->lock($actor, $branch, 'panel.claims.manage.branch');
        $claim = PanelClaim::query()->whereKey($routeClaim->id)->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();

        return [$actor, $branch, $claim];
    }

    private function requireClaimable(CoverageAllocation $allocation, Invoice $invoice): void
    {
        if ($allocation->status !== 'approved' || (int) $allocation->current_invoice_guard !== $invoice->id
            || $invoice->status !== 'finalized' || $invoice->correction_hold || $invoice->source_stale
            || $allocation->amount_sen < 1 || $allocation->amount_sen > $invoice->total_sen) {
            throw ValidationException::withMessages(['claim' => 'Only a current approved Panel allocation on an eligible finalized Invoice can be claimed.']);
        }
    }

    private function requireSourceStillClaimable(PanelClaim $claim): void
    {
        $invoice = Invoice::query()->whereKey($claim->invoice_id)->where('organisation_id', $claim->organisation_id)
            ->where('branch_id', $claim->branch_id)->lockForUpdate()->firstOrFail();
        $allocation = CoverageAllocation::query()->whereKey($claim->coverage_allocation_id)
            ->where('organisation_id', $claim->organisation_id)->where('branch_id', $claim->branch_id)
            ->lockForUpdate()->firstOrFail();
        $this->requireClaimable($allocation, $invoice);
    }

    private function requireVersion(PanelClaim $claim, mixed $version): void
    {
        if ($claim->lock_version !== filter_var($version, FILTER_VALIDATE_INT)) {
            throw ValidationException::withMessages(['claim' => 'This claim has changed. Reload it before continuing.']);
        }
    }

    private function event(
        PanelClaim $claim,
        User $actor,
        Branch $branch,
        string $type,
        ?int $previousAmount = null,
        ?int $amount = null,
        ?string $externalReference = null,
        ?string $note = null,
        ?CarbonImmutable $occurredAt = null,
    ): void {
        $event = new PanelClaimEvent;
        $event->forceFill([
            'organisation_id' => $claim->organisation_id,
            'branch_id' => $branch->id,
            'panel_claim_id' => $claim->id,
            'actor_user_id' => $actor->id,
            'event_type' => $type,
            'previous_amount_sen' => $previousAmount,
            'amount_sen' => $amount,
            'external_reference' => $externalReference,
            'note' => $note,
            'occurred_at' => $occurredAt ?? now()->utc(),
        ])->save();
    }
}

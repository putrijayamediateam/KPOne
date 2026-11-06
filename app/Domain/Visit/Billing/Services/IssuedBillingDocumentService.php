<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\IssuedBillingDocument;
use App\Domain\Visit\Billing\Models\IssuedBillingDocumentPrint;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BIL-5 (owner decisions 2026-11-05): an invoice or receipt is frozen at first print. Each distinct printed content is
 * one encrypted, immutable version; the first print of version 1 is ORIGINAL, the first print of a later version is
 * REVISED, every other print is COPY. Printing never changes an amount, a number or any financial record.
 */
final class IssuedBillingDocumentService
{
    public const PERMISSION = 'billing.print.branch';

    public function __construct(
        private BillingContextService $context,
        private BillingDirectoryService $directory,
        private InvoiceBillToService $billTo,
        private BranchLetterheadAssembler $letterheads,
        private StaffAccessService $staff,
        private BranchAccessService $branches,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function issue(User $actor, Visit $visit, Invoice $invoice, ?Payment $payment, array $input): IssuedBillingDocumentPrint
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $invoice, $payment, $input, $key): IssuedBillingDocumentPrint {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, self::PERMISSION);
            $invoice = Invoice::query()->whereKey($invoice->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('visit_id', $visit->id)->lockForUpdate()->first();
            abort_if($invoice === null || $invoice->status === 'draft', 404);
            if ($payment !== null) {
                abort_unless(PaymentAllocation::query()->where('payment_id', $payment->id)->where('invoice_id', $invoice->id)->exists(), 404);
                $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            }
            $hash = $this->payloadHash(['issue', $actor->id, $invoice->id, $payment?->id]);
            if ($existing = $this->replay($actor, $key, $hash)) {
                return $existing;
            }

            [$legacy, $content] = $this->contents($actor, $visit, $invoice, $payment, $branch);
            $contentHash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
            $latest = IssuedBillingDocument::query()->where('invoice_id', $invoice->id)
                ->where(fn ($q) => $payment ? $q->where('payment_id', $payment->id) : $q->whereNull('payment_id'))->orderByDesc('version')->first();
            // BIL-9 (owner decision 4): a version issued in the earlier layout is compared on what it printed, so the
            // layout change alone never makes a REVISED version; it reprints as COPY in the layout it was issued in.
            if ($latest !== null && (hash_equals($latest->content_sha256, $contentHash)
                || hash_equals($latest->content_sha256, hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR))))) {
                return $this->print($actor, $latest, 'copy', $key, $hash, $branch);
            }

            $encrypted = Crypt::encryptString(json_encode($content, JSON_THROW_ON_ERROR));
            $document = new IssuedBillingDocument;
            $document->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'invoice_id' => $invoice->id, 'payment_id' => $payment?->id, 'document_type' => $payment ? 'receipt' : 'invoice',
                'version' => ($latest->version ?? 0) + 1, 'content_sha256' => $contentHash, 'encrypted_snapshot' => $encrypted,
                'ciphertext_sha256' => hash('sha256', $encrypted), 'issued_by_user_id' => $actor->id, 'issued_at' => now()->utc()])->save();
            $this->audit->record('billing.document_issued', $document, ['document_type' => $document->document_type, 'version' => $document->version], $actor, $branch);

            return $this->print($actor, $document, $document->version === 1 ? 'original' : 'revised', $key, $hash, $branch);
        }, 3);
    }

    /**
     * Reprint an earlier (or the latest) issued version, always as COPY.
     *
     * @param  array<string, mixed>  $input
     */
    public function reprint(User $actor, Visit $visit, string $documentPublicId, array $input): IssuedBillingDocumentPrint
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $documentPublicId, $input, $key): IssuedBillingDocumentPrint {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, self::PERMISSION);
            $document = $this->visitDocument($actor, $visit, $branch, $documentPublicId);
            Invoice::query()->whereKey($document->invoice_id)->lockForUpdate()->firstOrFail();
            $hash = $this->payloadHash(['reprint', $actor->id, $document->id]);

            return $this->replay($actor, $key, $hash) ?? $this->print($actor, $document, 'copy', $key, $hash, $branch);
        }, 3);
    }

    /**
     * The print view of one recorded print: the frozen content plus its label and what has happened since.
     *
     * @return array<string, mixed>
     */
    public function show(User $actor, Visit $visit, string $printPublicId): array
    {
        $active = $this->branches->activeBranch($actor);
        abort_unless($active !== null, 404);

        return DB::transaction(function () use ($actor, $visit, $printPublicId, $active): array {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $active->id, self::PERMISSION);
            $print = IssuedBillingDocumentPrint::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $printPublicId)->first();
            abort_if($print === null, 404);
            $document = $this->visitDocument($actor, $visit, $branch, IssuedBillingDocument::query()->whereKey($print->issued_billing_document_id)->value('public_id'));
            $failed = ValidationException::withMessages(['document' => 'This issued copy failed its integrity check. Do not print it; report it.']);
            if (! hash_equals($document->ciphertext_sha256, hash('sha256', $document->encrypted_snapshot))) {
                throw $failed;
            }
            try {
                /** @var array<string, mixed> $content */
                $content = json_decode(Crypt::decryptString($document->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                throw $failed;
            }
            $latest = (int) IssuedBillingDocument::query()->where('invoice_id', $document->invoice_id)
                ->where(fn ($q) => $document->payment_id ? $q->where('payment_id', $document->payment_id) : $q->whereNull('payment_id'))->max('version');
            $notes = [];
            if ($latest > $document->version) {
                $notes[] = "A later version of this {$document->document_type} exists (version {$latest}). This paper is not the current one.";
            }
            if ($document->payment_id !== null && ($content['receipt']['status'] ?? null) === 'posted'
                && Payment::query()->whereKey($document->payment_id)->value('status') === 'reversed') {
                $notes[] = 'Current status: This receipt was later reversed (recording error).';
            }

            // Only the person who made the latest print of this document sees its ORIGINAL or REVISED label again;
            // reopening any other print (history, bookmark, another person) shows COPY, so no second ORIGINAL sheet.
            $lastPrint = IssuedBillingDocumentPrint::query()->where('issued_billing_document_id', $document->id)->max('id');
            $mine = $print->printed_by_user_id === $actor->id;
            $label = $mine && (int) $lastPrint === $print->id ? $print->label : 'copy';

            return $content + ['issued' => [
                'label' => $label,
                'recordedLabel' => $print->label,
                'version' => $document->version,
                'issuedAt' => $document->issued_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'printedAt' => $print->printed_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'printedByYou' => $mine,
                'notes' => $notes,
                'backUrl' => route('billing.show', $visit, absolute: false),
            ]];
        }, 3);
    }

    /**
     * Issued versions of this visit's invoices and receipts, newest last, for the Billing page.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function forVisit(User $actor, Visit $visit, string $timezone): ?array
    {
        if (! $actor->can(self::PERMISSION)) {
            return null;
        }
        $documents = IssuedBillingDocument::query()->withCount('prints')
            ->where('organisation_id', $actor->organisation_id)
            ->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))
            ->orderBy('invoice_id')->orderByRaw('COALESCE(payment_id, 0)')->orderBy('version')->get();
        $numbers = Invoice::query()->whereIn('id', $documents->pluck('invoice_id'))->pluck('invoice_number', 'id');
        $receipts = Payment::query()->whereIn('id', $documents->pluck('payment_id')->filter())->pluck('receipt_number', 'id');
        $people = $this->staff->visibleUsers($actor)->whereIn('id', $documents->pluck('issued_by_user_id')->unique())->pluck('name', 'id');
        $latest = $documents->groupBy(fn (IssuedBillingDocument $d): string => $d->invoice_id.':'.($d->payment_id ?? 0))->map->max('version');

        return $documents->map(fn (IssuedBillingDocument $d): array => [
            'publicId' => $d->public_id,
            'type' => $d->document_type,
            'number' => $d->payment_id ? (string) $receipts->get($d->payment_id) : (string) $numbers->get($d->invoice_id),
            'version' => $d->version,
            'firstPrintedAs' => $d->version === 1 ? 'original' : 'revised',
            'issuedAt' => $d->issued_at->setTimezone($timezone)->format('j M Y, g:i A'),
            'issuedBy' => $people->get($d->issued_by_user_id, 'Staff member'),
            'prints' => (int) $d->getAttribute('prints_count'),
            'latest' => $latest->get($d->invoice_id.':'.($d->payment_id ?? 0)) === $d->version,
        ])->values()->all();
    }

    /**
     * Exactly what the print page shows, and nothing that depends on who looks at it (no permissions, links or ids).
     *
     * @return array<string, mixed>
     */
    /**
     * The earlier (BIL-5, D03) content and the BIL-9 content, a superset of it with `layout` = 'bil9'.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function contents(User $actor, Visit $visit, Invoice $invoice, ?Payment $payment, Branch $branch): array
    {
        // BIL-9 (owner decision 1): the paper names the patient by IC/passport and phone, so the printer must be able
        // to see them (CA and CA Supervisor hold this already).
        if (! $actor->can('patients.view.organisation')) {
            throw ValidationException::withMessages(['document' => 'You cannot see every part of this document, so you cannot issue it.']);
        }
        [$legacy, $head, $source] = $this->legacyContent($actor, $visit, $invoice, $payment, $branch);
        $patient = Patient::query()->where('organisation_id', $actor->organisation_id)->whereKey($visit->patient_id)->firstOrFail();
        $identifier = PatientIdentifier::query()->where('patient_id', $patient->id)->whereNull('retired_at')->latest('created_at')->latest('id')->first();
        $categories = DB::table('invoice_lines as l')->leftJoin('charge_definitions as c', 'c.id', '=', 'l.charge_definition_id')
            ->leftJoin('clinical_service_catalogue_items as s', 's.id', '=', 'c.clinical_service_catalogue_item_id')
            ->where('l.invoice_id', $invoice->id)->orderBy('l.id')->get(['l.line_type', 's.category'])
            ->map(fn (object $l): string => self::category((string) $l->line_type, $l->category === null ? null : (string) $l->category))->all();
        /** @var list<array{name: string, quantity: string, unit: string|null, unitPriceSen: int, totalSen: int}> $sourceLines */
        $sourceLines = array_values($source['lines']);
        $lines = [];
        $totals = array_fill_keys(self::CATEGORIES, 0);
        foreach ($sourceLines as $i => $l) {
            $category = $categories[$i] ?? null;
            abort_if($category === null, 409, 'Invoice lines changed while printing. Try again.');
            $lines[] = ['name' => $l['name'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unitPriceSen' => $l['unitPriceSen'], 'totalSen' => $l['totalSen'], 'category' => $category];
            $totals[$category] += $l['totalSen'];
        }
        abort_if(count($categories) !== count($sourceLines), 409, 'Invoice lines changed while printing. Try again.');
        $content = $legacy;
        $content['layout'] = 'bil9';
        $content['billing']['patient'] += [
            'identifier' => $identifier ? app(PatientIdentityService::class)->displayIdentifier($identifier->identifier_type, $identifier->normalized_value) : null,
            'phone' => $patient->mobile_phone === null || trim($patient->mobile_phone) === '' ? null : $patient->mobile_phone,
        ];
        $content['billing']['invoice'] = [...$content['billing']['invoice'], 'lines' => $lines,
            'categories' => array_map(fn (string $name): array => ['name' => $name, 'totalSen' => $totals[$name]], self::CATEGORIES),
            'adjustment' => ['subtotalSen' => $source['adjustment']['subtotalSen'], 'adjustmentSen' => $source['adjustment']['adjustmentSen']],
        ];
        $secondary = $head['details']['phone_secondary'] ?? null;
        $content['letterhead']['details']['phone_secondary'] = is_string($secondary) && trim($secondary) !== '' ? $secondary : null;

        return [$legacy, $content];
    }

    /** BIL-9 (owner decision 2): the seven printed categories, in the clinic's order. */
    public const CATEGORIES = ['Service', 'Procedure', 'Investigation', 'Consultation', 'Medicine', 'Document', 'Package'];

    public static function category(string $lineType, ?string $serviceCategory): string
    {
        if ($lineType === 'consultation') {
            return 'Consultation';
        }
        if ($lineType === 'medicine') {
            return 'Medicine';
        }

        return match (mb_strtolower(trim((string) $serviceCategory))) {
            'procedure', 'procedures' => 'Procedure',
            'investigation', 'investigations' => 'Investigation',
            default => 'Service',
        };
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function legacyContent(User $actor, Visit $visit, Invoice $invoice, ?Payment $payment, Branch $branch): array
    {
        // The frozen content must not depend on who prints: issue only for people who see every printed part.
        if (! $actor->can('billing.bill_to.view.branch') || (! $actor->can('billing.view.branch') && ! $actor->can('billing.lines.view.branch'))) {
            throw ValidationException::withMessages(['document' => 'You cannot see every part of this document, so you cannot issue it.']);
        }
        $data = $this->directory->detail($actor, $visit, $invoice, true);
        $source = $data['invoice'];
        $receipt = null;
        if ($payment !== null) {
            $row = null;
            foreach ($data['payments'] as $candidate) {
                if ($candidate['publicId'] === $payment->public_id) {
                    $row = $candidate;
                }
            }
            abort_if($row === null, 404);
            $receipt = ['number' => $row['number'], 'receivedAt' => $row['receivedAt'], 'status' => $row['status'], 'amountSen' => $row['amountSen'], 'method' => $row['method']];
        }
        $head = $this->letterheads->assemble($branch, function (string $publicId) use ($branch): string {
            $asset = BusinessDetailAsset::query()->where('organisation_id', $branch->organisation_id)->where('public_id', $publicId)->whereNull('retired_at')->sole();
            $bytes = Storage::disk('local')->get($asset->path);
            if ($bytes === null || ! hash_equals($asset->sha256, hash('sha256', $bytes))) {
                throw ValidationException::withMessages(['document' => 'The letterhead logo is unavailable. Restore it in Business details before printing.']);
            }

            return 'data:'.$asset->mime.';base64,'.base64_encode($bytes);
        })->toArray();
        $details = array_intersect_key($head['details'], array_flip(['clinic_name', 'address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'country', 'email', 'phone']));

        return [[
            'billing' => [
                'patient' => ['name' => $data['patient']['name'], 'patientNumber' => $data['patient']['patientNumber']],
                'visit' => ['visitNumber' => $data['visit']['visitNumber']],
                // A receipt prints only its own figures and the invoice number, so later payments never revise it.
                'invoice' => $payment ? ['number' => $source['number']] : [
                    'number' => $source['number'], 'finalizedAt' => $source['finalizedAt'], 'status' => $source['status'],
                    'lines' => array_map(fn (array $l): array => ['name' => $l['name'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unitPriceSen' => $l['unitPriceSen'], 'totalSen' => $l['totalSen']], $source['lines']),
                    'adjustment' => ['subtotalSen' => $source['adjustment']['subtotalSen'], 'adjustmentSen' => $source['adjustment']['adjustmentSen']],
                    'state' => array_intersect_key($source['state'], array_flip(['total', 'self_pay', 'panel', 'deferred', 'due_now'])),
                ],
            ],
            'receipt' => $receipt,
            'billTo' => $payment ? null : $this->billTo->forPrint($actor, $visit, $invoice, $branch->id),
            'letterhead' => ['details' => $details, 'logo' => $head['logo'] ? ['url' => $head['logo']['url']] : null, 'footerText' => $head['footerText']],
        ], $head, $source];
    }

    private function print(User $actor, IssuedBillingDocument $document, string $label, string $key, string $hash, Branch $branch): IssuedBillingDocumentPrint
    {
        $print = new IssuedBillingDocumentPrint;
        $print->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'issued_billing_document_id' => $document->id,
            'label' => $label, 'printed_by_user_id' => $actor->id, 'printed_at' => now()->utc(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
        $this->audit->record('billing.document_printed', $document, ['document_type' => $document->document_type, 'version' => $document->version, 'label' => $label], $actor, $branch);

        return $print;
    }

    private function visitDocument(User $actor, Visit $visit, Branch $branch, ?string $publicId): IssuedBillingDocument
    {
        $document = $publicId === null ? null : IssuedBillingDocument::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('public_id', $publicId)->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))->first();
        abort_if($document === null, 404);

        return $document;
    }

    private function replay(User $actor, string $key, string $hash): ?IssuedBillingDocumentPrint
    {
        $existing = IssuedBillingDocumentPrint::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
        if ($existing !== null && ! hash_equals($existing->payload_hash, $hash)) {
            throw ValidationException::withMessages(['document' => 'This request was already used for a different print. Reload and try again.']);
        }

        return $existing;
    }

    /** @param list<mixed> $parts */
    private function payloadHash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function key(mixed $key): string
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Reload the page and try again.']);
        }

        return $key;
    }
}

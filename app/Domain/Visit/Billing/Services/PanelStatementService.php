<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Domain\Visit\Billing\Models\PanelStatementUpload;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * PNL-7 (owner decisions 2026-11-05): a Panel's remittance statement (CSV) helps fill the PNL-3 split. KPOne reads
 * the file and suggests, for each row, the open accepted claim of that Panel at this branch with the same claim
 * number, invoice number or member reference. The officer ticks suggestions and records through PNL-3; nothing is
 * recorded here. The file is kept encrypted on the private disk and deleted after 30 days; its rows are never
 * stored in the database, and the audit never holds its contents.
 */
class PanelStatementService
{
    public const PERMISSION = PanelRemittanceService::PERMISSION;

    public const DISK = 'local';

    public const MAX_BYTES = 1_048_576;

    public const MAX_ROWS = 500;

    public const MAX_COLUMNS = 30;

    public const RETENTION_DAYS = 30;

    private const PAYABLE = ['accepted', 'partially_accepted', 'partially_paid'];

    /** How many of the oldest open claims are compared for near matches. */
    private const NEAR_CANDIDATES = 500;

    private const UNDECIDED = ['prepared', 'submitted', 'queried'];

    /**
     * Header words used to pre-select each column (lower case, matched as whole words or prefixes), strongest first.
     * The claim column is never pre-selected: a Panel's own claim number is not KPOne's claim number.
     */
    private const HEADER_HINTS = [
        'amount' => ['paid', 'bayaran', 'payment', 'payable', 'amount', 'amt', 'jumlah', 'rm', 'myr', 'total'],
        'claim' => [],
        'invoice' => ['invoice', 'inv', 'bill', 'invois'],
        'member' => ['member', 'membership', 'staff', 'employee', 'policy', 'card', 'ahli'],
    ];

    public function __construct(
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $a */
    public function upload(User $actor, array $a, mixed $file): PanelStatementUpload
    {
        Validator::make(['expected_branch_id' => $a['expected_branch_id'] ?? null, 'panel' => $a['panel'] ?? null, 'statement' => $file], [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'panel' => ['required', 'string', 'max:80'],
            'statement' => ['required', 'file', 'max:'.intdiv(self::MAX_BYTES, 1024), 'mimes:csv,txt'],
        ], [
            'statement.required' => 'Choose a CSV file.',
            'statement.file' => 'Choose a CSV file.',
            'statement.mimes' => 'Choose a CSV file.',
            'statement.max' => 'The file is over 1 MB. Split it and upload each part.',
        ])->validate();
        /** @var UploadedFile $file */
        $content = $this->normalise((string) file_get_contents($file->getRealPath()));
        $table = $this->parse($content);
        $hash = hash('sha256', $content);

        try {
            return $this->store($actor, $a, $content, $hash, count($table['rows']));
        } catch (UniqueConstraintViolationException) {
            // Two uploads of the same file at once: the first one won; return it.
            return $this->store($actor, $a, $content, $hash, count($table['rows']));
        }
    }

    /**
     * The suggestions for one live statement of this Panel at this branch, or null when it is gone.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>|null
     */
    public function suggestions(User $actor, Branch $branch, Panel $panel, string $publicId, array $mapping): ?array
    {
        $upload = PanelStatementUpload::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('panel_id', $panel->id)->whereNull('deleted_at')->where('delete_after', '>', now()->utc())
            ->where('public_id', strtolower($publicId))->first();
        if ($upload === null || ! Storage::disk(self::DISK)->exists($upload->stored_path)) {
            return null;
        }
        $table = $this->parse(Crypt::decryptString((string) Storage::disk(self::DISK)->get($upload->stored_path)));
        $columns = count($table['headers']);
        $chosen = [];
        foreach (array_keys(self::HEADER_HINTS) as $key) {
            $value = $mapping[$key] ?? null;
            $chosen[$key] = match (true) {
                $value === null => $this->guess($table['headers'], $key, $chosen),
                $value === '' => null,
                (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value < $columns => (int) $value,
                default => null,
            };
        }

        $nearCut = false;
        $rows = $chosen['amount'] === null || ($chosen['claim'] === null && $chosen['invoice'] === null && $chosen['member'] === null)
            ? [] : $this->match($actor, $branch, $panel, $table['rows'], $chosen, $nearCut);

        return [
            'publicId' => $upload->public_id,
            'uploadedDate' => $upload->uploaded_at->setTimezone($branch->timezone)->format('j M Y'),
            'deleteDate' => $upload->delete_after->setTimezone($branch->timezone)->format('j M Y'),
            'rowCount' => $upload->row_count,
            'headers' => array_map(static fn (string $label, int $index): array => ['index' => $index, 'label' => $label !== '' ? Str::limit($label, 40) : 'Column '.($index + 1)],
                $table['headers'], array_keys($table['headers'])),
            'mapping' => $chosen,
            'rows' => $rows,
            // PNL-20: true when a "Not found" row was compared for near matches against only the oldest open claims
            // because the Panel has more than that, so staff know a newer claim may still match by hand.
            'nearCut' => $nearCut,
            'nearLimit' => $this->nearCandidateLimit(),
        ];
    }

    /** @param array<string, mixed> $a */
    public function remove(User $actor, string $publicId, array $a): void
    {
        DB::transaction(function () use ($actor, $publicId, $a): void {
            $branch = $this->authority->branch($actor, $a['expected_branch_id'] ?? null);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $upload = PanelStatementUpload::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('public_id', strtolower($publicId))->lockForUpdate()->first();
            if ($upload === null || $upload->deleted_at !== null) {
                return;
            }
            $this->deleteFile($upload, 'removed', $actor, $branch);
        }, 3);
    }

    /** Deletes every statement file past its 30 days. Returns how many were deleted. */
    public function purgeExpired(): int
    {
        $deleted = 0;
        PanelStatementUpload::query()->whereNull('deleted_at')->where('delete_after', '<=', now()->utc())
            ->select('id')->orderBy('id')->chunkById(100, function ($rows) use (&$deleted): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, &$deleted): void {
                        $upload = PanelStatementUpload::query()->whereKey($row->id)->lockForUpdate()->first();
                        if ($upload === null || $upload->deleted_at !== null || $upload->delete_after->isFuture()) {
                            return;
                        }
                        $this->deleteFile($upload, 'expired', null, Branch::query()->find($upload->branch_id));
                        $deleted++;
                    }, 3);
                }
            });

        return $deleted;
    }

    /** @param array<string, mixed> $a */
    private function store(User $actor, array $a, string $content, string $hash, int $rowCount): PanelStatementUpload
    {
        // Every file written by any attempt: a retried transaction writes a new one each time.
        $written = [];
        try {
            $upload = DB::transaction(function () use ($actor, $a, $content, $hash, $rowCount, &$written): PanelStatementUpload {
                $branch = $this->authority->branch($actor, $a['expected_branch_id']);
                $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
                $panel = Panel::query()->where('organisation_id', $actor->organisation_id)->where('code', (string) $a['panel'])->first();
                if ($panel === null) {
                    throw ValidationException::withMessages(['panel' => 'Choose a Panel.']);
                }
                $existing = PanelStatementUpload::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                    ->where('panel_id', $panel->id)->where('content_hash', $hash)->whereNull('deleted_at')->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
                $publicId = (string) Str::uuid();
                $path = 'panel-statements/'.$actor->organisation_id.'/'.$publicId.'.csv.enc';
                $written[] = $path;
                if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($content))) {
                    throw ValidationException::withMessages(['statement' => 'The file could not be saved. Try again.']);
                }
                $now = CarbonImmutable::now()->utc();
                $upload = new PanelStatementUpload;
                $upload->forceFill([
                    'public_id' => $publicId,
                    'organisation_id' => $actor->organisation_id,
                    'branch_id' => $branch->id,
                    'panel_id' => $panel->id,
                    'stored_path' => $path,
                    'size_bytes' => strlen($content),
                    'content_hash' => $hash,
                    'row_count' => $rowCount,
                    'uploaded_by_user_id' => $actor->id,
                    'uploaded_at' => $now,
                    'delete_after' => $now->addDays(self::RETENTION_DAYS),
                ])->save();
                $this->audit->record('panel_statement.uploaded', $upload, [
                    'row_count' => $rowCount,
                    'size_bytes' => strlen($content),
                    'panel_id' => $panel->id,
                ], $actor, $branch);

                return $upload;
            }, 3);
        } catch (Throwable $e) {
            // No record was saved, so no file written for it may stay behind.
            $this->deleteUnrecorded($written);
            throw $e;
        }
        // Files from rolled-back attempts belong to no record.
        $this->deleteUnrecorded(array_values(array_diff($written, [$upload->stored_path])));

        return $upload;
    }

    /** @param list<string> $paths */
    private function deleteUnrecorded(array $paths): void
    {
        foreach ($paths as $path) {
            if (! PanelStatementUpload::query()->where('stored_path', $path)->exists()) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }

    private function deleteFile(PanelStatementUpload $upload, string $reason, ?User $actor, ?Branch $branch): void
    {
        Storage::disk(self::DISK)->delete($upload->stored_path);
        $upload->forceFill(['deleted_at' => now()->utc()])->save();
        $this->audit->record('panel_statement.deleted', $upload, ['reason' => $reason, 'row_count' => $upload->row_count],
            $actor, $branch, $upload->organisation_id);
    }

    /** Plain UTF-8 text without a byte-order mark; anything binary is refused. */
    private function normalise(string $content): string
    {
        if ($content === '' || str_contains($content, "\0")) {
            $this->refuse('Choose a CSV file.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            // Spreadsheet programs often save CSV in the Windows character set.
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
     */
    private function parse(string $content): array
    {
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $d): int => substr_count($firstLine, $d))->first();
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $this->refuse('The file could not be read. Try again.');
        }
        fwrite($handle, $content);
        rewind($handle);
        $headers = null;
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($handle, 0, (string) $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map(static fn (mixed $cell): string => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if ($headers === null) {
                $headers = $cells;
                if (count($headers) < 2 || count($headers) > self::MAX_COLUMNS
                    || collect($headers)->every(fn (string $h): bool => $h === '' || $this->amount($h) !== null)
                    || collect($headers)->contains(fn (string $h): bool => $this->looksPersonal($h))) {
                    fclose($handle);
                    $this->refuse('The first row must hold column names.');
                }

                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                $this->refuse('The file has more than '.self::MAX_ROWS.' rows.');
            }
            $rows[] = ['line' => $line, 'cells' => array_map(static fn (string $cell): string => mb_substr($cell, 0, 200), $cells)];
        }
        fclose($handle);
        if ($headers === null) {
            $this->refuse('The first row must hold column names.');
        }
        if ($rows === []) {
            $this->refuse('The file has no rows under the column names.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, int|null>  $taken
     */
    private function guess(array $headers, string $key, array $taken): ?int
    {
        // Strongest hint first across every column, so "Amount paid" wins over an earlier "Claim amount".
        foreach (self::HEADER_HINTS[$key] as $hint) {
            foreach ($headers as $index => $header) {
                if (in_array($index, $taken, true)) {
                    continue;
                }
                foreach (preg_split('/[^a-z0-9]+/', strtolower($header)) ?: [] as $word) {
                    if ($word !== '' && ($word === $hint || (strlen($hint) > 3 && str_starts_with($word, $hint)))) {
                        return $index;
                    }
                }
            }
        }

        return null;
    }

    /** A header cell that looks like an IC number or a phone number means the first row is data, not column names. */
    private function looksPersonal(string $text): bool
    {
        $compact = preg_replace('/[\s()+.]/', '', $text) ?? '';

        return preg_match('/\b\d{6}-?\d{2}-?\d{4}\b/', $text) === 1
            || preg_match('/\A(?:60|0)1\d-?\d{7,8}\z/', $compact) === 1
            || preg_match('/\A(?:60|0)[3-9]-?\d{7,8}\z/', $compact) === 1;
    }

    /** Ringgit text such as "RM 1,234.50" in sen; null when it is not a positive amount. */
    private function amount(string $text): ?int
    {
        $clean = preg_replace('/^(?:rm|myr)\s*/i', '', str_replace(' ', '', trim($text))) ?? '';
        // "10,00" (a comma before exactly two final digits and no dot) is ringgit and sen; other commas group thousands.
        $clean = preg_match('/\A[0-9]{1,9},[0-9]{2}\z/', $clean) === 1 ? str_replace(',', '.', $clean) : str_replace(',', '', $clean);
        if (preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/', $clean, $m) !== 1) {
            return null;
        }
        $sen = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $sen > 0 ? $sen : null;
    }

    /**
     * @param  list<array{line: int, cells: list<string>}>  $rows
     * @param  array<string, int|null>  $columns
     * @return list<array<string, mixed>>
     */
    private function match(User $actor, Branch $branch, Panel $panel, array $rows, array $columns, bool &$nearCut): array
    {
        $cell = static fn (array $row, ?int $index): string => $index === null ? '' : (string) ($row['cells'][$index] ?? '');
        $claimNumbers = $invoices = $members = [];
        foreach ($rows as $row) {
            $claimNumbers[] = ltrim(preg_replace('/\D+/', '', $cell($row, $columns['claim'])) ?? '', '0');
            $invoices[] = strtolower($cell($row, $columns['invoice']));
            $members[] = strtolower($cell($row, $columns['member']));
        }
        $claims = $this->claims($actor, $branch, $panel, array_values(array_filter($claimNumbers)), array_values(array_filter($invoices)), array_values(array_filter($members)));
        $outstanding = static fn (PanelClaim $claim): int => max(0, (int) $claim->accepted_amount_sen - (int) $claim->getAttribute('received_sen'));
        $used = [];
        $pending = [];
        $out = [];
        foreach ($rows as $i => $row) {
            // Invoice number first, then KPOne's claim number; a member reference is only a fallback, because one
            // member can have many visits, and then only for a row with no invoice or claim number and only among
            // claims that still wait for payment.
            $byInvoice = $invoices[$i] === '' ? collect() : $claims->filter(fn (PanelClaim $c): bool => strtolower($c->invoice_number) === $invoices[$i]);
            $byClaim = $claimNumbers[$i] === '' ? collect() : $claims->filter(fn (PanelClaim $c): bool => (string) $c->id === $claimNumbers[$i]);
            $found = match (true) {
                $byInvoice->isNotEmpty() => $byInvoice->count() === 1 && $byClaim->isNotEmpty() && $byClaim->first()->id !== $byInvoice->first()->id
                    ? $byInvoice->concat($byClaim) : $byInvoice,
                $byClaim->isNotEmpty() => $byClaim,
                $members[$i] !== '' && $invoices[$i] === '' && $claimNumbers[$i] === '' => $claims->filter(fn (PanelClaim $c): bool => strtolower((string) $c->member_reference) === $members[$i]
                    && in_array($c->status, self::PAYABLE, true) && $outstanding($c) > 0),
                default => collect(),
            };
            $amount = $this->amount($cell($row, $columns['amount']));
            $claim = $found->count() === 1 ? $found->first() : null;
            $unpaid = $claim ? $outstanding($claim) : 0;
            $result = match (true) {
                $claim === null => $found->isEmpty() ? 'not_found' : 'ambiguous',
                $amount === null => 'unreadable_amount',
                in_array($claim->status, self::UNDECIDED, true) => 'not_accepted',
                $claim->status === 'rejected' => 'rejected',
                ! in_array($claim->status, self::PAYABLE, true) || $unpaid === 0 => 'already_paid',
                isset($used[$claim->id]) => 'repeated',
                $amount > $unpaid => 'over_unpaid',
                default => 'matched',
            };
            if ($result === 'matched') {
                $used[$claim->id] = true;
            }
            if ($result === 'not_found' && $invoices[$i] !== '' && $amount !== null) {
                $pending[$i] = $amount;
            }
            $reference = (string) (array_values(array_filter([$cell($row, $columns['claim']), $cell($row, $columns['invoice']), $cell($row, $columns['member'])]))[0] ?? '');
            $out[] = [
                'line' => $row['line'],
                'reference' => Str::limit($reference, 40),
                'amountSen' => $amount,
                'result' => $result,
                'claim' => $claim === null ? null : [
                    'publicId' => $claim->public_id,
                    'claimNo' => (string) $claim->id,
                    'invoiceNumber' => $claim->invoice_number,
                    'outstandingSen' => $unpaid,
                ],
            ];
        }
        // Second pass, after every exact match is known: a "Not found" row whose invoice number is close to exactly
        // one open claim (see nearInvoice) is offered as a near match. It is only a suggestion; the officer must tick it.
        if ($pending !== []) {
            $open = $this->openClaims($actor, $branch, $panel, $nearCut);
            foreach ($pending as $i => $pendingSen) {
                $statementInvoice = $cell($rows[$i], $columns['invoice']);
                // Exactly one candidate first; only then the already-used and unpaid checks, so a second claim on a
                // near number never lets the first one through.
                $near = $open->filter(fn (PanelClaim $c): bool => self::nearInvoice($statementInvoice, (string) $c->invoice_number) !== null);
                $claim = $near->count() === 1 ? $near->first() : null;
                if ($claim === null || isset($used[$claim->id]) || $pendingSen > $outstanding($claim)) {
                    continue;
                }
                $used[$claim->id] = true;
                $out[$i]['result'] = 'near_match';
                $out[$i]['statementInvoice'] = Str::limit($statementInvoice, 40);
                $out[$i]['nearReason'] = self::nearInvoice($statementInvoice, (string) $claim->invoice_number);
                $out[$i]['claim'] = ['publicId' => $claim->public_id, 'claimNo' => (string) $claim->id, 'invoiceNumber' => $claim->invoice_number, 'outstandingSen' => $outstanding($claim)];
            }
        }

        return $out;
    }

    /**
     * Why two different invoice numbers count as a near match, or null when they do not. KPOne numbers run in
     * sequence, so a digit typed wrongly may be another real invoice: only differences that cannot change the
     * number count. "format": the same apart from spaces, punctuation, capitals or leading zeros; "lookalike":
     * also reading letters that look like digits (O, I, L, S, B, Z) as those digits.
     */
    public static function nearInvoice(string $statement, string $claim): ?string
    {
        $format = self::invoiceTokens($statement, false);
        if ($format === null || ($claimFormat = self::invoiceTokens($claim, false)) === null) {
            return null;
        }

        return match (true) {
            $format === $claimFormat => 'format',
            self::invoiceTokens($statement, true) === self::invoiceTokens($claim, true) => 'lookalike',
            default => null,
        };
    }

    /**
     * An invoice number as its letter and number groups, in capitals. Groups are compared one by one and never glued
     * together, so "KPI-00000012-1" is not "KPI-00000121". Format mode drops each number's leading zeros; look-alike
     * mode reads O, I, L, S, B and Z as digits but keeps every number at its exact length, so a letter suffix such as
     * "KPI-00000012B" can never become another invoice ("KPI-00000128").
     */
    private static function invoiceTokens(string $value, bool $lookalike): ?string
    {
        $value = strtoupper($value);
        if ($lookalike) {
            // A look-alike letter is read as a digit only when a real digit follows it in the same group, so a trailing
            // letter ("KPI-0000012B") is never an extra digit.
            $value = preg_replace_callback('/[A-Z0-9]+/', static function (array $m): string {
                if (preg_match('/[0-9][^0-9]*\z/', $m[0], $tail, PREG_OFFSET_CAPTURE) !== 1) {
                    return $m[0];
                }
                $lastDigit = (int) $tail[0][1];

                return strtr(substr($m[0], 0, $lastDigit), ['O' => '0', 'I' => '1', 'L' => '1', 'S' => '5', 'B' => '8', 'Z' => '2']).substr($m[0], $lastDigit);
            }, $value) ?? '';
        }
        preg_match_all('/[A-Z]+|[0-9]+/', $value, $groups);
        if ($groups[0] === []) {
            return null;
        }

        return implode('|', array_map(static fn (string $g): string => ctype_digit($g) && ! $lookalike ? (ltrim($g, '0') === '' ? '0' : ltrim($g, '0')) : $g, $groups[0]));
    }

    /** How many of the oldest open claims are compared for near matches (a method so a test can use a small number). */
    protected function nearCandidateLimit(): int
    {
        return self::NEAR_CANDIDATES;
    }

    /**
     * Open claims of this Panel at this branch that still wait for payment, oldest first, as near-match candidates.
     *
     * @return Collection<int, PanelClaim>
     */
    private function openClaims(User $actor, Branch $branch, Panel $panel, bool &$cut): Collection
    {
        $received = '(select coalesce(sum(p.amount_sen), 0) from panel_claim_payments p where p.panel_claim_id = panel_claims.id and p.organisation_id = panel_claims.organisation_id and p.branch_id = panel_claims.branch_id and '.PanelClaimPaymentReversal::NOT_REVERSED_P.')';

        $claims = PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('panel_id', $panel->id)
            ->whereIn('status', self::PAYABLE)->orderBy('id')
            ->select('panel_claims.*')->selectRaw("{$received} as received_sen")->limit($this->nearCandidateLimit() + 1)->get();
        // One extra row tells us the Panel has more open claims than are compared (PNL-20).
        $cut = $claims->count() > $this->nearCandidateLimit();

        return $claims->take($this->nearCandidateLimit())
            ->filter(fn (PanelClaim $c): bool => max(0, (int) $c->accepted_amount_sen - (int) $c->getAttribute('received_sen')) > 0)->values();
    }

    /**
     * Claims of this Panel at this branch that carry any of the given numbers, with what each has still received.
     *
     * @param  list<string>  $claimNumbers
     * @param  list<string>  $invoices
     * @param  list<string>  $members
     * @return Collection<int, PanelClaim>
     */
    private function claims(User $actor, Branch $branch, Panel $panel, array $claimNumbers, array $invoices, array $members): Collection
    {
        if ($claimNumbers === [] && $invoices === [] && $members === []) {
            return collect();
        }
        $received = '(select coalesce(sum(p.amount_sen), 0) from panel_claim_payments p where p.panel_claim_id = panel_claims.id and p.organisation_id = panel_claims.organisation_id and p.branch_id = panel_claims.branch_id and '.PanelClaimPaymentReversal::NOT_REVERSED_P.')';

        return PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('panel_id', $panel->id)
            ->where(function ($q) use ($claimNumbers, $invoices, $members): void {
                $q->whereIn('id', array_map('intval', array_filter($claimNumbers, static fn (string $n): bool => strlen($n) <= 18)))
                    ->orWhereIn(DB::raw('lower(invoice_number)'), $invoices)
                    ->orWhereIn(DB::raw('lower(member_reference)'), $members);
            })
            ->select('panel_claims.*')->selectRaw("{$received} as received_sen")->limit(2000)->get();
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['statement' => $message]);
    }
}

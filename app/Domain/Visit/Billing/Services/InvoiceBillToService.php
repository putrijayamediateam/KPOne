<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceBillToRevision;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InvoiceBillToService
{
    public const FIELDS = ['party_type', 'display_name', 'company_name', 'address_line_1', 'address_line_2', 'city', 'state', 'postcode', 'country_code'];

    public function __construct(private BillingContextService $context, private StaffAccessService $staff, private AuditRecorder $audit) {}

    private function allows(User $actor, string $permission): bool
    {
        return $actor->getAllPermissions()->contains('name', $permission);
    }

    /** Content is fetched only through the authorised endpoint, never embedded in page links.
     * @return array{url:string,branchId:int,invoiceNumber:?string,canManage:bool}|null
     */
    public function link(User $actor, Visit $visit, ?Invoice $invoice): ?array
    {
        if (! $invoice || $invoice->status === 'draft' || ! $this->allows($actor, 'billing.bill_to.view.branch') || ! ($this->allows($actor, 'billing.view.branch') || $this->allows($actor, 'billing.summary.branch'))) {
            return null;
        }

        return ['url' => route('billing.bill-to', [$visit, $invoice], false), 'branchId' => $visit->branch_id, 'invoiceNumber' => $invoice->invoice_number, 'canManage' => $invoice->status === 'finalized' && $this->allows($actor, 'billing.bill_to.manage.branch')];
    }

    /** @return array{User,Visit,Invoice,Branch} */
    private function source(User $actor, Visit $visit, Invoice $invoice, int $expectedBranch, string $permission = 'billing.bill_to.view.branch'): array
    {
        [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $expectedBranch, $permission);
        // Use the freshly loaded grant relations, including revocations within an already-cached request.
        abort_unless($this->allows($actor, $permission), 403);
        abort_unless($permission === 'billing.print.branch' || ($this->allows($actor, 'billing.bill_to.view.branch') && ($this->allows($actor, 'billing.view.branch') || $this->allows($actor, 'billing.summary.branch'))), 403);
        $invoice = Invoice::query()->whereKey($invoice->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
        abort_if($invoice->status === 'draft', 404);

        return [$actor, $visit, $invoice, $branch];
    }

    private function latest(Invoice $invoice): ?InvoiceBillToRevision
    {
        return InvoiceBillToRevision::query()->where('invoice_id', $invoice->id)->where('organisation_id', $invoice->organisation_id)->where('branch_id', $invoice->branch_id)->latest('revision_version')->first();
    }

    /** Invoice print only. A print grant does not imply Bill to view, or vice versa.
     * @return array<string,string|null>|null
     */
    public function forPrint(User $actor, Visit $visit, Invoice $invoice, int $expectedBranch): ?array
    {
        return DB::transaction(function () use ($actor, $visit, $invoice, $expectedBranch): ?array {
            [$actor, , $invoice] = $this->source($actor, $visit, $invoice, $expectedBranch, 'billing.print.branch');

            return $this->allows($actor, 'billing.bill_to.view.branch') ? $this->latest($invoice)?->recipient : null;
        });
    }

    /** @return array<string,mixed> */
    public function page(User $actor, Visit $visit, Invoice $invoice, int $expectedBranch, bool $summary = false, ?string $cursor = null): array
    {
        return DB::transaction(function () use ($actor, $visit, $invoice, $expectedBranch, $summary, $cursor): array {
            [$actor, , $invoice, $branch] = $this->source($actor, $visit, $invoice, $expectedBranch);
            $canManage = $invoice->status === 'finalized' && $this->allows($actor, 'billing.bill_to.manage.branch');
            $scope = $actor->id.':'.$invoice->id.':'.$branch->id.':'.(int) $canManage;
            $query = InvoiceBillToRevision::query()->where('invoice_id', $invoice->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id);
            if ($cursor !== null) {
                try {
                    $after = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                    if (($after['scope'] ?? null) !== $scope || ! is_int($after['id'] ?? null)) {
                        throw new \RuntimeException;
                    }
                } catch (Throwable) {
                    throw ValidationException::withMessages(['cursor' => 'Refresh Bill to history before continuing.']);
                }
                $query->where('id', '<', $after['id']);
            }
            $rows = $summary ? collect() : $query->orderByDesc('id')->limit(21)->get();
            $hasMore = $rows->count() > 20;
            $rows = $rows->take(20);
            $names = $this->staff->visibleUsers($actor)->whereIn('id', $rows->pluck('actor_user_id')->unique())->pluck('name', 'id');

            return ['current' => $this->latest($invoice)?->recipient, 'version' => (int) (DB::table('invoice_bill_to_controls')->where('invoice_id', $invoice->id)->value('lock_version') ?? 0),
                'canManage' => $canManage,
                'data' => $rows->map(fn (InvoiceBillToRevision $r): array => ['id' => $r->public_id, 'kind' => $r->kind, 'recipient' => $r->recipient, 'actor' => $names[$r->actor_user_id] ?? 'Staff member', 'occurredAt' => $r->created_at->setTimezone($branch->timezone)->format('j M Y, g:i A')])->all(),
                'nextCursor' => $hasMore ? Crypt::encryptString(json_encode(['scope' => $scope, 'id' => (int) $rows->last()->id], JSON_THROW_ON_ERROR)) : null];
        });
    }

    /** @param array<string,mixed> $attributes
     * @return array{version:int}
     */
    public function write(User $actor, Visit $visit, Invoice $invoice, array $attributes): array
    {
        return DB::transaction(function () use ($actor, $visit, $invoice, $attributes): array {
            [$actor, , $invoice, $branch] = $this->source($actor, $visit, $invoice, (int) ($attributes['expected_branch_id'] ?? 0), 'billing.bill_to.manage.branch');
            $recipient = $attributes['recipient'] ?? null;
            if (is_array($recipient)) {
                foreach ($recipient as $key => $value) {
                    if (is_string($value)) {
                        $recipient[$key] = trim($value) === '' ? null : trim($value);
                    }
                }
                if (is_string($recipient['country_code'] ?? null)) {
                    $recipient['country_code'] = strtoupper($recipient['country_code']);
                }
                $attributes['recipient'] = $recipient;
            }
            $plain = static function ($attribute, $value, $fail): void {
                if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value))) {
                    $fail('Use a single line of plain text without HTML or control characters.');
                }
            };
            $rules = ['expected_branch_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'operation' => ['required', 'in:set,clear'],
                'recipient' => ['nullable', 'required_if:operation,set', 'prohibited_if:operation,clear', 'array:'.implode(',', self::FIELDS)],
                'recipient.party_type' => ['required_if:operation,set', 'in:person,company,panel'], 'recipient.display_name' => ['required_if:operation,set', 'string', 'max:200', $plain]];
            foreach (['company_name' => 200, 'address_line_1' => 200, 'address_line_2' => 200, 'city' => 100, 'state' => 100, 'postcode' => 20, 'country_code' => 2] as $key => $max) {
                $rules['recipient.'.$key] = ['nullable', 'string', 'max:'.$max, $plain];
            }
            $rules['recipient.country_code'][] = 'regex:/\A[A-Z]{2}\z/';
            $data = Validator::make($attributes, $rules)->validate();
            $payload = $data['operation'] === 'set' ? array_replace(array_fill_keys(self::FIELDS, null), $data['recipient']) : null;
            $hash = hash_hmac('sha256', json_encode([$actor->id, $invoice->id, $data['lock_version'], $data['operation'], $payload], JSON_THROW_ON_ERROR), (string) config('app.key'));
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$actor->organisation_id.':bill-to:'.$data['idempotency_key']]);
            }
            $command = DB::table('invoice_bill_to_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($command) {
                if ((int) $command->invoice_id !== $invoice->id || (int) $command->actor_user_id !== $actor->id || ! hash_equals($command->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different Bill to details. Cancel and refresh before continuing.']);
                }

                return ['version' => (int) $command->revision_version];
            }
            if ($invoice->status !== 'finalized') {
                throw ValidationException::withMessages(['invoice' => 'Bill to cannot be changed on a voided invoice.']);
            }
            $control = DB::table('invoice_bill_to_controls')->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            $version = (int) ($control->lock_version ?? 0);
            if ($version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Bill to changed. Refresh and review before saving.']);
            }
            $before = $this->latest($invoice)?->recipient;
            if ($before === $payload) {
                throw ValidationException::withMessages(['recipient' => 'There is no Bill to change to save.']);
            }
            $kind = $payload === null ? 'cleared' : ($before === null ? 'set' : 'changed');
            if ($control === null) {
                DB::table('invoice_bill_to_controls')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'lock_version' => 1]);
            } else {
                DB::table('invoice_bill_to_controls')->where('id', $control->id)->update(['lock_version' => $version + 1]);
            }
            $revision = new InvoiceBillToRevision;
            $revision->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'actor_user_id' => $actor->id, 'kind' => $kind, 'recipient' => $payload, 'revision_version' => $version + 1, 'created_at' => now()->utc()])->save();
            DB::table('invoice_bill_to_commands')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'revision_id' => $revision->id, 'revision_version' => $version + 1, 'created_at' => now()->utc()->format('Y-m-d H:i:s.uP')]);
            $fields = array_values(array_filter(self::FIELDS, fn ($key): bool => ($before[$key] ?? null) !== ($payload[$key] ?? null)));
            $this->audit->record('billing.bill_to_'.$kind, $invoice, ['revision_id' => $revision->id, 'fields' => $fields], $actor, $branch);

            return ['version' => $version + 1];
        }, 3);
    }
}

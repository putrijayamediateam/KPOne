<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceNoteEvent;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InvoiceNoteService
{
    public function __construct(private BillingContextService $context, private StaffAccessService $staff, private AuditRecorder $audit) {}

    /**
     * No note content or existence count is included in page props.
     *
     * @return array{url:string,branchId:int,invoiceNumber:?string,canAdd:bool}|null
     */
    public function link(User $actor, Visit $visit, ?Invoice $invoice): ?array
    {
        if (! $invoice || $invoice->status === 'draft' || ! $actor->can('billing.notes.view.branch') || ! $actor->hasAnyPermission(['billing.view.branch', 'billing.summary.branch'])) {
            return null;
        }

        return ['url' => route('billing.notes', [$visit, $invoice], false), 'branchId' => $visit->branch_id, 'invoiceNumber' => $invoice->invoice_number, 'canAdd' => $invoice->status === 'finalized' && $actor->can('billing.notes.add.branch')];
    }

    /** @return array{User,Visit,Invoice,Branch} */
    private function source(User $actor, Visit $visit, Invoice $invoice, int $expectedBranch): array
    {
        [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $expectedBranch, 'billing.notes.view.branch');
        abort_unless($actor->hasAnyPermission(['billing.view.branch', 'billing.summary.branch']), 403);
        $invoice = Invoice::query()->whereKey($invoice->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
        abort_if($invoice->status === 'draft', 404);

        return [$actor, $visit, $invoice, $branch];
    }

    /** @return array<string,mixed> */
    public function page(User $actor, Visit $visit, Invoice $invoice, int $expectedBranch, ?string $cursor = null): array
    {
        return DB::transaction(function () use ($actor, $visit, $invoice, $expectedBranch, $cursor): array {
            [$actor, , $invoice, $branch] = $this->source($actor, $visit, $invoice, $expectedBranch);
            $scope = $actor->id.':'.$invoice->id.':'.$branch->id.':'.(int) $actor->can('billing.notes.add.branch').':'.(int) $actor->can('billing.notes.retract.branch');
            $query = InvoiceNoteEvent::query()->where('invoice_id', $invoice->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id);
            if ($cursor !== null) {
                try {
                    $after = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                    if (($after['scope'] ?? null) !== $scope || ! is_int($after['id'] ?? null)) {
                        throw new \RuntimeException;
                    }
                } catch (Throwable) {
                    throw ValidationException::withMessages(['cursor' => 'Refresh note history before continuing.']);
                }
                $query->where('id', '<', $after['id']);
            }
            $rows = $query->orderByDesc('id')->limit(21)->get();
            $hasMore = $rows->count() > 20;
            $rows = $rows->take(20);
            $resolved = InvoiceNoteEvent::query()->where('invoice_id', $invoice->id)->whereIn('target_id', $rows->pluck('id'))->get()->keyBy('target_id');
            $names = $this->staff->visibleUsers($actor)->whereIn('id', $rows->pluck('actor_user_id')->unique())->pluck('name', 'id');
            $writable = $invoice->status === 'finalized';
            $data = $rows->map(function (InvoiceNoteEvent $event) use ($actor, $branch, $resolved, $names, $writable): array {
                $resolution = $resolved->get($event->id);
                $canResolve = $writable && $event->kind !== 'retracted' && ! $resolution && ($actor->can('billing.notes.retract.branch') || ((int) $event->actor_user_id === $actor->id && $actor->can('billing.notes.add.branch')));

                return ['id' => $event->public_id, 'kind' => $event->kind, 'body' => $event->body, 'reasonCode' => $event->reason_code,
                    'actor' => $names[$event->actor_user_id] ?? 'Staff member', 'occurredAt' => $event->created_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'resolved' => $resolution !== null, 'resolution' => $resolution?->kind, 'canRetract' => $canResolve,
                    'canCorrect' => $canResolve && $actor->can('billing.notes.add.branch')];
            })->all();

            return ['data' => $data, 'version' => (int) (DB::table('invoice_note_streams')->where('invoice_id', $invoice->id)->value('lock_version') ?? 0),
                'canAdd' => $writable && $actor->can('billing.notes.add.branch'),
                'nextCursor' => $hasMore ? Crypt::encryptString(json_encode(['scope' => $scope, 'id' => (int) $rows->last()->id], JSON_THROW_ON_ERROR)) : null];
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array{version:int}
     */
    public function write(User $actor, Visit $visit, Invoice $invoice, array $attributes): array
    {
        // Fail permission checks before validating any potentially private content.
        abort_unless($actor->can('billing.notes.view.branch') && $actor->hasAnyPermission(['billing.notes.add.branch', 'billing.notes.retract.branch']), 403);
        $attributes['body'] = is_string($attributes['body'] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", $attributes['body'])) : ($attributes['body'] ?? null);
        $data = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'],
            'operation' => ['required', 'in:added,retracted,corrected'],
            'target' => ['nullable', 'required_unless:operation,added', 'uuid', 'prohibited_if:operation,added'],
            'reason_code' => ['nullable', 'required_unless:operation,added', 'in:duplicate,entered_in_error,replaced', 'prohibited_if:operation,added'],
            'body' => ['nullable', 'required_unless:operation,retracted', 'prohibited_if:operation,retracted', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (is_string($value) && (strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))) {
                    $fail('Use plain text without HTML or control characters.');
                }
            }],
        ])->validate();
        $hash = hash_hmac('sha256', json_encode([$invoice->id, $actor->id, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $visit, $invoice, $data, $hash): array {
            [$actor, , $invoice, $branch] = $this->source($actor, $visit, $invoice, (int) $data['expected_branch_id']);
            if (in_array($data['operation'], ['added', 'corrected'], true)) {
                abort_unless($actor->can('billing.notes.add.branch'), 403);
            }
            abort_unless($actor->hasAnyPermission(['billing.notes.add.branch', 'billing.notes.retract.branch']), 403);
            $command = DB::table('invoice_note_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($command) {
                if ((int) $command->invoice_id !== $invoice->id || (int) $command->actor_user_id !== $actor->id || ! hash_equals($command->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different note details. Refresh before continuing.']);
                }

                return ['version' => (int) $command->stream_version];
            }
            if ($invoice->status !== 'finalized') {
                throw ValidationException::withMessages(['invoice' => 'Notes cannot be changed on a voided invoice.']);
            }
            $stream = DB::table('invoice_note_streams')->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            $version = (int) ($stream->lock_version ?? 0);
            if ($version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Invoice notes changed. Refresh and review before saving.']);
            }
            $target = null;
            if ($data['operation'] !== 'added') {
                $target = InvoiceNoteEvent::query()->where('invoice_id', $invoice->id)->where('public_id', $data['target'])->lockForUpdate()->firstOrFail();
                abort_unless($actor->can('billing.notes.retract.branch') || ((int) $target->actor_user_id === $actor->id && $actor->can('billing.notes.add.branch')), 403);
                if ($target->kind === 'retracted' || InvoiceNoteEvent::query()->where('target_id', $target->id)->exists()) {
                    throw ValidationException::withMessages(['target' => 'This note has already been retracted or corrected. Refresh note history.']);
                }
            }
            if ($stream === null) {
                DB::table('invoice_note_streams')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'lock_version' => 1]);
            } else {
                DB::table('invoice_note_streams')->where('id', $stream->id)->update(['lock_version' => $version + 1]);
            }
            $event = new InvoiceNoteEvent;
            $event->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'actor_user_id' => $actor->id, 'kind' => $data['operation'], 'target_id' => $target?->id, 'reason_code' => $data['reason_code'] ?? null,
                'body' => $data['body'] ?? null, 'stream_version' => $version + 1, 'created_at' => now()->utc()])->save();
            DB::table('invoice_note_commands')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'event_id' => $event->id, 'stream_version' => $version + 1, 'created_at' => now()->utc()->format('Y-m-d H:i:s.uP')]);
            $this->audit->record('billing.note_'.$data['operation'], $invoice, ['record_version' => $version + 1], $actor, $branch);

            return ['version' => $version + 1];
        }, 3);
    }
}

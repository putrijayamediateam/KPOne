<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemBatchAllocation;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturnCheck;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * DSP-7 medicine returns (owner decisions 2026-11-05, TEAM.md decision 20). Step 1: a CA or CA Supervisor at the
 * case's branch records what a patient brought back on one line of a completed case. Nothing moves in stock; the
 * return waits for a check by a different person (step 2). Money stays outside KPOne in v1.
 *
 * Step 2 (owner decision 21): in v1 only a CA Supervisor who did not record the return checks it, on the case page,
 * once: Dispose (no stock change), Quarantine (credits a patient returns location through STK-11) or Refuse (a fixed
 * reason). A controlled medicine is Disposed or, when recorded by mistake, Refused; never quarantined. A refused
 * return stops counting towards the returned quantity.
 */
final class DispensaryReturnService
{
    /** Owner decision 20: recording reuses the existing Dispensary edit permission (CA, CA Supervisor). */
    public const RECORD = 'dispensary.update.branch';

    /** The existing variance approval permission (CA Supervisor, Finance Officer, Director); v1 limits it to CA Supervisors. */
    public const CHECK = 'inventory.variances.approve.branch';

    public function __construct(private DispensaryAuthorityService $authority, private AuditRecorder $audit, private StaffAccessService $staff) {}

    /**
     * DSP-7: the read-only returns section of a completed case, sent as its own page prop so the completed-case
     * payload keys stay as they are. The caller has already authorised the page (dispensary.view.branch, CA or CA
     * Supervisor, the case's branch). Staff names follow the staff-directory visibility rule, otherwise "Staff member".
     *
     * @return array<string, mixed>
     */
    public function panel(User $actor, DispensaryCase $case, Branch $branch): array
    {
        $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->where('status', DispensaryHandoff::STATUS_COMPLETED)->first();
        $items = $handoff === null ? collect() : DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)
            ->whereIn('status', [DispensaryItem::STATUS_DISPENSED, DispensaryItem::STATUS_PARTIAL])->where('quantity_dispensed', '>', 0)->orderBy('id')->get();
        $returnRows = DispensaryReturn::query()->where('dispensary_case_id', $case->id)->orderBy('id')->get();
        $returns = $returnRows->groupBy('dispensary_item_id');
        $checks = DispensaryReturnCheck::query()->whereIn('dispensary_return_id', $returnRows->pluck('id'))->get()->keyBy('dispensary_return_id');
        $controlled = MedicineCatalogueItem::query()->where('organisation_id', $case->organisation_id)->whereIn('id', $items->pluck('medicine_catalogue_item_id')->unique())->pluck('is_controlled', 'id');
        $names = $this->staff->visibleUsers($actor)->whereIn('id', $returnRows->pluck('recorded_by_user_id')->merge($checks->pluck('checked_by_user_id'))->unique())->pluck('name', 'id');
        $locations = InventoryLocation::query()->where('organisation_id', $case->organisation_id)->where('branch_id', $branch->id)->where('type', InventoryLocation::TYPE_DISPENSARY_RETURNS)->orderBy('name')->get(['id', 'public_id', 'name', 'is_active']);
        $mayCheck = $this->mayCheck($actor);
        $batches = DispensaryItemBatchAllocation::query()->whereIn('dispensary_item_id', $items->pluck('id'))->with('batch:id,public_id,batch_number,expiry_date')->orderBy('id')->get()->groupBy('dispensary_item_id');

        return [
            'casePublicId' => $case->public_id,
            'branchId' => $branch->id,
            'canRecord' => $actor->can(self::RECORD),
            'canCheck' => $mayCheck,
            'reasons' => DispensaryReturn::REASONS,
            'conditions' => DispensaryReturn::CONDITIONS,
            'refuseReasons' => DispensaryReturnCheck::REFUSE_REASONS,
            'noteMax' => DispensaryReturn::NOTE_MAX,
            'returnsLocations' => $mayCheck ? $locations->where('is_active', true)->map(fn (InventoryLocation $l): array => ['publicId' => $l->public_id, 'name' => $l->name])->values()->all() : [],
            'lines' => $items->map(function (DispensaryItem $item) use ($returns, $checks, $controlled, $names, $locations, $actor, $branch, $mayCheck, $batches): array {
                $lineReturns = $returns->get($item->id, collect());
                $dispensed = StockUnitQuantity::scaled((string) $item->quantity_dispensed);
                $returned = $this->countedQuantity($lineReturns, $checks);
                $isControlled = (bool) ($controlled[$item->medicine_catalogue_item_id] ?? false);

                return [
                    'publicId' => $item->public_id, 'name' => $item->medicine_name_snapshot, 'strength' => $item->strength_snapshot,
                    'dosageForm' => $item->dosage_form_snapshot, 'code' => $item->medicine_code_snapshot, 'unit' => $item->unit_snapshot,
                    'controlled' => $isControlled,
                    'dispensed' => $this->trimmed(StockUnitQuantity::format($dispensed)),
                    'returned' => $this->trimmed(StockUnitQuantity::format($returned)),
                    'returnable' => $this->trimmed(StockUnitQuantity::format(max(0, $dispensed - $returned))),
                    'returnCount' => $lineReturns->count(),
                    'batches' => $mayCheck ? $batches->get($item->id, collect())->map(fn (DispensaryItemBatchAllocation $a): array => ['publicId' => (string) $a->batch->public_id, 'label' => trim($a->batch->batch_number.' · exp '.($a->batch->expiry_date ? CarbonImmutable::parse($a->batch->expiry_date)->format('d/m/Y') : '-'))])->unique('publicId')->values()->all() : [],
                    'returns' => $lineReturns->map(function (DispensaryReturn $r) use ($checks, $names, $locations, $actor, $branch, $mayCheck, $isControlled): array {
                        $check = $checks->get($r->id);

                        return [
                            'publicId' => $r->public_id, 'quantity' => $this->trimmed((string) $r->quantity), 'reason' => $r->reason,
                            'note' => $r->note_encrypted, 'condition' => $r->condition, 'status' => $this->status($check),
                            'recordedBy' => $names[$r->recorded_by_user_id] ?? 'Staff member',
                            'recordedByMe' => $r->recorded_by_user_id === $actor->id,
                            'recordedAt' => CarbonImmutable::parse($r->recorded_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                            'canCheck' => $check === null && $mayCheck && $r->recorded_by_user_id !== $actor->id,
                            'allowedOutcomes' => $isControlled ? [DispensaryReturnCheck::OUTCOME_DISPOSE, DispensaryReturnCheck::OUTCOME_REFUSE] : DispensaryReturnCheck::OUTCOMES,
                            'check' => $check === null ? null : [
                                'outcome' => $check->outcome,
                                'checkedBy' => $names[$check->checked_by_user_id] ?? 'Staff member',
                                'checkedAt' => CarbonImmutable::parse($check->checked_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                                'locationName' => $check->inventory_location_id === null ? null : $locations->firstWhere('id', $check->inventory_location_id)?->name,
                                'refuseReason' => $check->refuse_reason,
                                'refuseNote' => $check->refuse_note_encrypted,
                            ],
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * DSP-7b (approved by B.E.R.N.I.S.S for the owner, 2026-11-06): the active branch's returns still waiting for a
     * check, oldest first, for the people who may check them (the v1 rule: a CA Supervisor). Read only: each row links
     * to its case page, where the check happens. Staff names follow the staff-directory rule; the Other note is never
     * listed.
     *
     * @return array<string, mixed>
     */
    public function waitingList(User $actor, int $page): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('dispensary.view.branch') && $this->mayCheck($actor), 403);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id && $branches->hasEffectiveAssignment($actor, $branch), 403);

        $query = fn () => DB::table('dispensary_returns as r')
            ->join('dispensary_items as i', 'i.id', '=', 'r.dispensary_item_id')
            ->join('dispensary_cases as c', 'c.id', '=', 'r.dispensary_case_id')
            ->join('visits as v', 'v.id', '=', 'c.visit_id')
            ->join('patients as p', 'p.id', '=', 'v.patient_id')
            ->leftJoin('medicine_catalogue_items as m', 'm.id', '=', 'i.medicine_catalogue_item_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dispensary_return_checks as k')->whereColumn('k.dispensary_return_id', 'r.id'))
            ->orderBy('r.recorded_at')->orderBy('r.id')
            ->select(['r.id', 'r.public_id', 'r.quantity', 'r.reason', 'r.condition', 'r.recorded_by_user_id', 'r.recorded_at',
                'c.public_id as case_public_id', 'v.visit_number', 'p.full_name as patient_name',
                'i.medicine_name_snapshot', 'i.strength_snapshot', 'i.unit_snapshot', 'm.is_controlled']);
        $paginator = $query()->paginate(25, ['*'], 'page', max(1, $page));
        // A page past the end (for example after the last return on it was checked) shows the last page instead.
        if ($paginator->total() > 0 && $paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $query()->paginate(25, ['*'], 'page', $paginator->lastPage());
        }
        $rows = collect($paginator->items());
        $names = $this->staff->visibleUsers($actor)->whereIn('id', $rows->pluck('recorded_by_user_id')->unique())->pluck('name', 'id');

        return [
            'branchName' => $branch->name,
            'rows' => $rows->map(fn (\stdClass $r): array => [
                'publicId' => (string) $r->public_id,
                'recordedAt' => CarbonImmutable::parse($r->recorded_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'patient' => (string) $r->patient_name,
                'visitNumber' => (string) $r->visit_number,
                'medicine' => trim($r->medicine_name_snapshot.' '.($r->strength_snapshot ?? '')),
                'quantity' => $this->trimmed((string) $r->quantity),
                'unit' => (string) $r->unit_snapshot,
                'reason' => (string) $r->reason,
                'condition' => (string) $r->condition,
                'controlled' => (bool) $r->is_controlled,
                'recordedBy' => $names[$r->recorded_by_user_id] ?? 'Staff member',
                'recordedByMe' => (int) $r->recorded_by_user_id === $actor->id,
                'caseUrl' => route('dispensary.show', ['dispensaryCase' => $r->case_public_id], absolute: false),
            ])->values()->all(),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /** DSP-7b: the v1 checker rule, shared with the Clinic management link. */
    public function canCheckReturns(User $actor): bool
    {
        return $this->mayCheck($actor);
    }

    /**
     * DSP-7 step 2: a CA Supervisor who did not record the return checks it, once.
     *
     * @param  array<string, mixed>  $input
     */
    public function check(User $actor, DispensaryCase $case, string $returnPublicId, array $input): DispensaryReturnCheck
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'retry_key' => ['required', 'uuid'],
            'outcome' => ['required', 'string', Rule::in(DispensaryReturnCheck::OUTCOMES)],
            'refuse_reason' => ['nullable', 'string', Rule::requiredIf(($input['outcome'] ?? null) === DispensaryReturnCheck::OUTCOME_REFUSE), Rule::prohibitedIf(($input['outcome'] ?? null) !== DispensaryReturnCheck::OUTCOME_REFUSE), Rule::in(DispensaryReturnCheck::REFUSE_REASONS)],
            'refuse_note' => ['nullable', 'string', 'max:'.DispensaryReturn::NOTE_MAX, Rule::requiredIf(($input['refuse_reason'] ?? null) === 'other'), Rule::prohibitedIf(($input['refuse_reason'] ?? null) !== 'other')],
            'location_public_id' => ['nullable', 'uuid', Rule::requiredIf(($input['outcome'] ?? null) === DispensaryReturnCheck::OUTCOME_QUARANTINE), Rule::prohibitedIf(($input['outcome'] ?? null) !== DispensaryReturnCheck::OUTCOME_QUARANTINE)],
            'batch_public_id' => ['nullable', 'uuid', Rule::requiredIf(($input['outcome'] ?? null) === DispensaryReturnCheck::OUTCOME_QUARANTINE), Rule::prohibitedIf(($input['outcome'] ?? null) !== DispensaryReturnCheck::OUTCOME_QUARANTINE)],
        ], [
            'refuse_reason.required' => 'Choose a reason for refusing.',
            'refuse_note.required' => 'Write a short note for Other.',
            'refuse_note.max' => 'Keep the note to '.DispensaryReturn::NOTE_MAX.' characters.',
            'location_public_id.required' => 'Choose a returns location.',
            'batch_public_id.required' => 'Choose the batch the units came from.',
        ])->validate();
        $note = isset($data['refuse_note']) ? trim((string) $data['refuse_note']) : null;
        if (($data['refuse_reason'] ?? null) === 'other' && ($note === null || $note === '')) {
            $this->refuse('refuse_note', 'Write a short note for Other.');
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['check', $case->id, $returnPublicId, $data['outcome'], $data['refuse_reason'] ?? null, $note, $data['location_public_id'] ?? null, $data['batch_public_id'] ?? null]);

        return DB::transaction(function () use ($actor, $branch, $case, $returnPublicId, $data, $note, $hash): DispensaryReturnCheck {
            $actor = $this->authority->lock($actor, $branch, self::CHECK);
            if (! $this->mayCheck($actor)) {
                $this->refuse('outcome', 'Only a CA Supervisor can check returns at this branch.');
            }
            $lockedCase = DispensaryCase::query()->whereKey($case->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_unless($lockedCase !== null, 404);
            $retry = DispensaryReturnCheck::query()->where('organisation_id', $actor->organisation_id)->where('checked_by_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry !== null) {
                if (! hash_equals($retry->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different check.');
                }

                return $retry;
            }
            $return = DispensaryReturn::query()->where('public_id', $returnPublicId)->where('dispensary_case_id', $lockedCase->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            abort_unless($return !== null, 404);
            if (DispensaryReturnCheck::query()->where('dispensary_return_id', $return->id)->exists()) {
                $this->refuse('state', 'This return has already been checked. Reload the page to see the result.');
            }
            if ($return->recorded_by_user_id === $actor->id) {
                $this->refuse('outcome', 'You recorded this return, so someone else must check it.');
            }
            $item = DispensaryItem::query()->whereKey($return->dispensary_item_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $controlled = (bool) MedicineCatalogueItem::query()->whereKey($item->medicine_catalogue_item_id)->where('organisation_id', $actor->organisation_id)->value('is_controlled');
            if ($controlled && $data['outcome'] === DispensaryReturnCheck::OUTCOME_QUARANTINE) {
                $this->refuse('outcome', 'A returned controlled medicine is always disposed. Choose Dispose, or Refuse if it was recorded by mistake.');
            }
            $publicId = (string) Str::uuid();
            $location = null;
            $batch = null;
            $movementId = null;
            if ($data['outcome'] === DispensaryReturnCheck::OUTCOME_QUARANTINE) {
                $location = InventoryLocation::query()->where('public_id', $data['location_public_id'])->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('type', InventoryLocation::TYPE_DISPENSARY_RETURNS)->lockForUpdate()->first();
                if ($location === null || ! $location->is_active) {
                    $this->refuse('location_public_id', 'This returns location is no longer available. Choose another one.');
                }
                $allocations = DispensaryItemBatchAllocation::query()->where('dispensary_item_id', $item->id)->with('batch:id,public_id')->orderBy('id')->get();
                $chosen = $allocations->filter(fn (DispensaryItemBatchAllocation $a): bool => $a->batch?->public_id === $data['batch_public_id']);
                if ($chosen->isEmpty()) {
                    $this->refuse('batch_public_id', 'Choose a batch this line was dispensed from.');
                }
                $allocated = $chosen->sum(fn (DispensaryItemBatchAllocation $a): int => StockUnitQuantity::scaled((string) $a->quantity));
                // Earlier quarantines of this line into the same batch use up part of what was dispensed from it.
                $alreadyQuarantined = DispensaryReturnCheck::query()->join('dispensary_returns', 'dispensary_returns.id', '=', 'dispensary_return_checks.dispensary_return_id')
                    ->where('dispensary_returns.dispensary_item_id', $item->id)->where('dispensary_return_checks.outcome', DispensaryReturnCheck::OUTCOME_QUARANTINE)
                    ->where('dispensary_return_checks.inventory_batch_id', $chosen->first()->inventory_batch_id)->pluck('dispensary_returns.quantity')
                    ->sum(fn ($q): int => StockUnitQuantity::scaled((string) $q));
                if (StockUnitQuantity::scaled((string) $return->quantity) + $alreadyQuarantined > $allocated) {
                    $this->refuse('batch_public_id', 'More was returned than was dispensed from this batch. Dispose the return, or refuse it and record one return per batch.');
                }
                $first = $chosen->first();
                // SKU before batch, the shared inventory lock order.
                $sku = InventorySku::query()->whereKey($first->inventory_sku_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
                $batch = InventoryBatch::query()->whereKey($first->inventory_batch_id)->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
                $movementId = app(InventoryMovementService::class)->recordDispensaryReturn($actor, $location, $sku, $batch, (string) $return->quantity, $publicId)->id;
            }
            $check = new DispensaryReturnCheck;
            $check->forceFill([
                'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'dispensary_return_id' => $return->id, 'outcome' => $data['outcome'],
                'refuse_reason' => $data['refuse_reason'] ?? null, 'refuse_note_encrypted' => $note,
                'inventory_location_id' => $location?->id, 'inventory_batch_id' => $batch?->id, 'stock_movement_id' => $movementId,
                'checked_by_user_id' => $actor->id, 'checked_at' => now()->utc(),
                'retry_key' => $data['retry_key'], 'payload_hash' => $hash,
            ])->save();
            // Codes and ids only: never the medicine name or a note.
            $this->audited('dispensary.return_checked', array_filter([
                'return_id' => $return->id, 'check_id' => $check->id, 'dispensary_case_id' => $lockedCase->id,
                'outcome' => (string) $data['outcome'], 'refuse_reason' => $data['refuse_reason'] ?? null,
                'stock_movement_id' => $movementId,
            ], fn ($v): bool => $v !== null), $actor, $branch, $return);

            return $check;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function record(User $actor, DispensaryCase $case, string $itemPublicId, array $input): DispensaryReturn
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'retry_key' => ['required', 'uuid'],
            'expected_return_count' => ['required', 'integer', 'min:0'],
            'quantity' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', Rule::in(DispensaryReturn::REASONS)],
            'note' => ['nullable', 'string', 'max:'.DispensaryReturn::NOTE_MAX, Rule::requiredIf(($input['reason'] ?? null) === 'other'), Rule::prohibitedIf(($input['reason'] ?? null) !== 'other')],
            'condition' => ['required', 'string', Rule::in(DispensaryReturn::CONDITIONS)],
        ], [
            'note.required' => 'Say briefly what happened when the reason is Other.',
            'note.prohibited' => 'A note is only kept when the reason is Other.',
            'note.max' => 'Keep the note to '.DispensaryReturn::NOTE_MAX.' characters.',
        ])->validate();
        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        if ($data['reason'] === 'other' && ($note === null || $note === '')) {
            $this->refuse('note', 'Say briefly what happened when the reason is Other.');
        }
        $scaled = StockUnitQuantity::scaled((string) $data['quantity']);
        $quantity = StockUnitQuantity::format($scaled);
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['record', $case->id, $itemPublicId, $quantity, $data['reason'], $note, $data['condition']]);

        return DB::transaction(function () use ($actor, $branch, $case, $itemPublicId, $data, $note, $scaled, $quantity, $hash): DispensaryReturn {
            $actor = $this->authority->lock($actor, $branch, self::RECORD);
            $lockedCase = DispensaryCase::query()->whereKey($case->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_unless($lockedCase !== null, 404);
            // Checked under the actor and case locks, so a concurrent replay of the same key waits and then replays.
            $retry = DispensaryReturn::query()->where('organisation_id', $actor->organisation_id)->where('recorded_by_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry !== null) {
                if (! hash_equals($retry->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different return.');
                }

                return $retry;
            }
            if ($lockedCase->status !== DispensaryCase::STATUS_COMPLETED) {
                $this->refuse('state', 'Returns can only be recorded after the case is completed.');
            }
            $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $lockedCase->id)->where('status', DispensaryHandoff::STATUS_COMPLETED)->lockForUpdate()->first();
            abort_unless($handoff !== null, 404);
            $item = DispensaryItem::query()->where('dispensary_handoff_id', $handoff->id)->where('public_id', $itemPublicId)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            abort_unless($item !== null, 404);
            $dispensed = $item->quantity_dispensed === null ? 0 : StockUnitQuantity::scaled((string) $item->quantity_dispensed, allowZero: true);
            if (! in_array($item->status, [DispensaryItem::STATUS_DISPENSED, DispensaryItem::STATUS_PARTIAL], true) || $dispensed === 0) {
                $this->refuse('quantity', 'Nothing was dispensed on this line, so there is nothing to return.');
            }
            $previous = DispensaryReturn::query()->where('dispensary_item_id', $item->id)->orderBy('id')->get(['id', 'quantity']);
            $previousChecks = DispensaryReturnCheck::query()->whereIn('dispensary_return_id', $previous->pluck('id'))->get()->keyBy('dispensary_return_id');
            if ($previous->count() !== (int) $data['expected_return_count']) {
                $this->refuse('expected_return_count', 'Another return was recorded on this line. Reload and review it before recording again.');
            }
            $returned = $this->countedQuantity($previous, $previousChecks);
            $open = $dispensed - $returned;
            if ($scaled > $open) {
                $this->refuse('quantity', 'You can return at most '.$this->trimmed(StockUnitQuantity::format(max(0, $open))).' '.$item->unit_snapshot.' on this line.');
            }
            $return = new DispensaryReturn;
            $return->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'dispensary_case_id' => $lockedCase->id, 'dispensary_item_id' => $item->id, 'quantity' => $quantity,
                'reason' => $data['reason'], 'note_encrypted' => $note, 'condition' => $data['condition'],
                'recorded_by_user_id' => $actor->id, 'recorded_at' => now()->utc(),
                'retry_key' => $data['retry_key'], 'payload_hash' => $hash,
            ])->save();
            // Codes and ids only: never the medicine name or the note.
            $this->audited('dispensary.return_recorded', [
                'return_id' => $return->id, 'dispensary_case_id' => $lockedCase->id, 'dispensary_item_id' => $item->id,
                'reason' => (string) $data['reason'], 'condition' => (string) $data['condition'], 'quantity' => $quantity,
            ], $actor, $branch, $return);

            return $return;
        }, 3);
    }

    /** v1 checkers: holders of the variance approval permission who are CA Supervisors (owner decision 21). */
    private function mayCheck(User $actor): bool
    {
        return $actor->can(self::CHECK) && $actor->hasRole('ca_supervisor');
    }

    private function status(?DispensaryReturnCheck $check): string
    {
        return match ($check?->outcome) {
            null => 'waiting_check',
            DispensaryReturnCheck::OUTCOME_DISPOSE => 'disposed',
            DispensaryReturnCheck::OUTCOME_QUARANTINE => 'quarantined',
            default => 'refused',
        };
    }

    /**
     * Returned so far, in thousandths: waiting, disposed and quarantined returns count; refused ones do not.
     *
     * @param  Collection<int, DispensaryReturn>  $returns
     * @param  Collection<int, DispensaryReturnCheck>  $checks  keyed by return id
     */
    private function countedQuantity(Collection $returns, Collection $checks): int
    {
        return $returns->reject(fn (DispensaryReturn $r): bool => $checks->get($r->id)?->outcome === DispensaryReturnCheck::OUTCOME_REFUSE)
            ->sum(fn (DispensaryReturn $r): int => StockUnitQuantity::scaled((string) $r->quantity));
    }

    private function trimmed(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** @param array<int, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        // Keyed, so a short note cannot be guessed back from the stored hash.
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, int|string> $metadata */
    private function audited(string $event, array $metadata, User $actor, Branch $branch, DispensaryReturn $subject): void
    {
        abort_if($this->audit->record($event, $subject, $metadata, $actor, $branch) === null, 503, 'The return could not be audited. Nothing was saved.');
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

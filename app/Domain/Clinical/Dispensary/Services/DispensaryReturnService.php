<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * DSP-7 medicine returns (owner decisions 2026-11-05, TEAM.md decision 20). Step 1: a CA or CA Supervisor at the
 * case's branch records what a patient brought back on one line of a completed case. Nothing moves in stock; the
 * return waits for a check by a different person (step 2). Money stays outside KPOne in v1.
 */
final class DispensaryReturnService
{
    /** Owner decision 20: recording reuses the existing Dispensary edit permission (CA, CA Supervisor). */
    public const RECORD = 'dispensary.update.branch';

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
        $returns = DispensaryReturn::query()->where('dispensary_case_id', $case->id)->orderBy('id')->get()->groupBy('dispensary_item_id');
        $controlled = MedicineCatalogueItem::query()->where('organisation_id', $case->organisation_id)->whereIn('id', $items->pluck('medicine_catalogue_item_id')->unique())->pluck('is_controlled', 'id');
        $names = $this->staff->visibleUsers($actor)->whereIn('id', $returns->flatten()->pluck('recorded_by_user_id')->unique())->pluck('name', 'id');

        return [
            'casePublicId' => $case->public_id,
            'branchId' => $branch->id,
            'canRecord' => $actor->can(self::RECORD),
            'reasons' => DispensaryReturn::REASONS,
            'conditions' => DispensaryReturn::CONDITIONS,
            'noteMax' => DispensaryReturn::NOTE_MAX,
            'lines' => $items->map(function (DispensaryItem $item) use ($returns, $controlled, $names, $actor, $branch): array {
                $lineReturns = $returns->get($item->id, collect());
                $dispensed = StockUnitQuantity::scaled((string) $item->quantity_dispensed);
                $returned = $lineReturns->sum(fn (DispensaryReturn $r): int => StockUnitQuantity::scaled((string) $r->quantity));

                return [
                    'publicId' => $item->public_id, 'name' => $item->medicine_name_snapshot, 'strength' => $item->strength_snapshot,
                    'dosageForm' => $item->dosage_form_snapshot, 'code' => $item->medicine_code_snapshot, 'unit' => $item->unit_snapshot,
                    'controlled' => (bool) ($controlled[$item->medicine_catalogue_item_id] ?? false),
                    'dispensed' => $this->trimmed(StockUnitQuantity::format($dispensed)),
                    'returned' => $this->trimmed(StockUnitQuantity::format($returned)),
                    'returnable' => $this->trimmed(StockUnitQuantity::format(max(0, $dispensed - $returned))),
                    'returnCount' => $lineReturns->count(),
                    'returns' => $lineReturns->map(fn (DispensaryReturn $r): array => [
                        'publicId' => $r->public_id, 'quantity' => $this->trimmed((string) $r->quantity), 'reason' => $r->reason,
                        'note' => $r->note_encrypted, 'condition' => $r->condition, 'status' => 'waiting_check',
                        'recordedBy' => $names[$r->recorded_by_user_id] ?? 'Staff member',
                        'recordedByMe' => $r->recorded_by_user_id === $actor->id,
                        'recordedAt' => CarbonImmutable::parse($r->recorded_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
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
            if ($previous->count() !== (int) $data['expected_return_count']) {
                $this->refuse('expected_return_count', 'Another return was recorded on this line. Reload and review it before recording again.');
            }
            $returned = $previous->sum(fn (DispensaryReturn $r): int => StockUnitQuantity::scaled((string) $r->quantity));
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

    private function trimmed(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** @param array<int, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
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

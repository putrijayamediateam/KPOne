<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\HR\Records\Models\ChecklistItem;
use App\Domain\HR\Records\Models\EmploymentPeriod;
use App\Domain\HR\Records\RecordPermissions;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-4b onboarding and offboarding checklists (HR-PLAN 11.3 rule 11). KPOne ships no items; HR enters them. A period
 * offers the onboarding list from its start and the offboarding list once it has an end date. A tick records who and
 * when. An item about the account ("Create the KPOne account", "Deactivate the KPOne account") records only that HR
 * asked the account administrator; HR never changes an account.
 */
final class ChecklistService
{
    public const LISTS = ['onboarding' => 'Onboarding', 'offboarding' => 'Offboarding'];

    public const MAX_ITEMS = 40;

    public function __construct(private RecordSupport $support) {}

    /** @param array<string, mixed> $input */
    public function addItem(User $actor, array $input): ChecklistItem
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $list = $input['list'] ?? null;
        if (! is_string($list) || ! array_key_exists($list, self::LISTS)) {
            throw ValidationException::withMessages(['list' => 'Choose onboarding or offboarding.']);
        }
        $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        if (mb_strlen($label) < 2 || mb_strlen($label) > 160) {
            throw ValidationException::withMessages(['label' => 'Enter the item (2 to 160 characters).']);
        }
        $aboutAccount = filter_var($input['about_account'] ?? false, FILTER_VALIDATE_BOOL);

        return DB::transaction(function () use ($actor, $key, $list, $label, $aboutAccount): ChecklistItem {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'checklist_item', $list, $label, $aboutAccount]);
            if (($replay = $this->support->replay($actor, $key, 'records_checklist_item', $hash)) !== null) {
                return ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
            }
            // Serialise item numbering per organisation and list.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_checklist:'.$actor->organisation_id.':'.$list]);
            $active = ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('list', $list)->where('is_active', true);
            if ($active->count() >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['label' => 'A list holds at most '.self::MAX_ITEMS.' items.']);
            }
            if ((clone $active)->whereRaw('lower(label) = lower(?)', [$label])->exists()) {
                throw ValidationException::withMessages(['label' => 'This item is already on the list.']);
            }
            $item = new ChecklistItem;
            $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'list' => $list, 'label' => $label,
                'about_account' => $aboutAccount, 'position' => (int) ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('list', $list)->max('position') + 1,
                'is_active' => true, 'created_by_user_id' => $actor->id, 'lock_version' => 1])->save();
            $this->support->remember($actor, $key, 'records_checklist_item', $hash, $item->public_id);
            $this->support->audit->record('hr.records.checklist_item_added', $item, ['list' => $list, 'about_account' => $aboutAccount], $actor, null, $actor->organisation_id);

            return $item;
        }, 3);
    }

    /**
     * Archives an item: it leaves the list for new periods; earlier ticks keep it.
     *
     * @param  array<string, mixed>  $input
     */
    public function archiveItem(User $actor, string $itemId, array $input): ChecklistItem
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $itemId, $input, $key): ChecklistItem {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'checklist_archive', $itemId]);
            if ($this->support->replay($actor, $key, 'records_checklist_archive', $hash) !== null) {
                return ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $itemId)->firstOrFail();
            }
            if (! Str::isUuid($itemId)) {
                abort(404);
            }
            $item = ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $itemId)->lockForUpdate()->firstOrFail();
            $this->support->assertLock($item, $input);
            if (! $item->is_active) {
                throw ValidationException::withMessages(['checklist' => 'This item was already archived. Reload the page.']);
            }
            $item->forceFill(['is_active' => false, 'lock_version' => $item->lock_version + 1])->save();
            $this->support->remember($actor, $key, 'records_checklist_archive', $hash, $item->public_id);
            $this->support->audit->record('hr.records.checklist_item_archived', $item, ['list' => $item->list], $actor, null, $actor->organisation_id);

            return $item;
        }, 3);
    }

    /**
     * Ticks one item for one employment period. A tick is final.
     *
     * @param  array<string, mixed>  $input
     */
    public function tick(User $actor, string $workerId, string $periodId, string $itemId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        try {
            DB::transaction(function () use ($actor, $workerId, $periodId, $itemId, $key): void {
                $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
                $hash = $this->support->hash([$actor->id, 'checklist_tick', $periodId, $itemId]);
                if ($this->support->replay($actor, $key, 'records_checklist_tick', $hash) !== null) {
                    return;
                }
                $worker = $this->support->lockWorker($actor, $workerId);
                $this->support->assertManages($actor, $worker);
                if (! Str::isUuid($periodId) || ! Str::isUuid($itemId)) {
                    abort(404);
                }
                $period = EmploymentPeriod::query()->where('worker_id', $worker->id)->where('public_id', $periodId)->lockForUpdate()->firstOrFail();
                $item = ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $itemId)->firstOrFail();
                if (! $item->is_active && ! DB::table('hr_checklist_ticks')->where('period_id', $period->id)->where('item_id', $item->id)->exists()) {
                    throw ValidationException::withMessages(['checklist' => 'This item was archived. Reload the page.']);
                }
                if ($item->list === 'offboarding' && $period->ends_on === null) {
                    throw ValidationException::withMessages(['checklist' => 'Offboarding starts once the period has an end date.']);
                }
                if (DB::table('hr_checklist_ticks')->where('period_id', $period->id)->where('item_id', $item->id)->exists()) {
                    throw ValidationException::withMessages(['checklist' => 'This item was already ticked. Reload the page.']);
                }
                DB::table('hr_checklist_ticks')->insert(['organisation_id' => $worker->organisation_id, 'period_id' => $period->id, 'item_id' => $item->id,
                    'ticked_by_user_id' => $actor->id, 'ticked_at' => now()->utc()]);
                $this->support->change($actor, $worker, 'checklist', [$item->list, $item->about_account ? 'asked_account_administrator' : 'ticked']);
                $this->support->remember($actor, $key, 'records_checklist_tick', $hash, $item->public_id);
                $this->support->audit->record('hr.records.checklist_ticked', $worker, ['period_id' => $period->id, 'item_id' => $item->id, 'list' => $item->list,
                    'about_account' => $item->about_account], $actor, null, $actor->organisation_id);
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['checklist' => 'This item was already ticked. Reload the page.']);
            }
            throw $e;
        }
    }
}

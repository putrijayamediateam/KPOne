<?php

namespace App\Domain\MyDay;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Agents\Services\PersonalText;
use App\Domain\Audit\AuditRecorder;
use App\Domain\MyDay\Models\MyRoutineItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DAY-1b: a person's own daily routine. Every method works on the actor's own list only (there is no user id in any input).
 * Each write: DB::transaction, the person's account row lock, the list's lock_version (add, rename, reorder, hide, restore),
 * an idempotency key on add and restore, and the unique (item, date) key for ticks. Audit holds the action and the item count
 * only, never a title or a tick. Items are never read for anyone else, and are not in ai_read or any agent source.
 */
final class MyRoutineService
{
    public const MAX_ITEMS = 20;

    public const MAX_TITLE = 120;

    public const RESTORE_DAYS = 7;

    public function __construct(private BranchAccessService $branches, private AuditRecorder $audit) {}

    /**
     * @return array{date: string, lockVersion: int, items: list<array{id: string, title: string, done: bool}>, done: int, total: int, removed: list<array{id: string, title: string}>, max: int}
     */
    public function page(User $actor): array
    {
        $this->assertHuman($actor);
        $date = $this->today($actor);
        $items = MyRoutineItem::query()->where('user_id', $actor->id)->whereNull('hidden_at')->orderBy('position')->orderBy('id')->get();
        $ticked = DB::table('my_routine_ticks')->where('user_id', $actor->id)->where('done_on', $date)->pluck('item_id')->all();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['id' => $item->public_id, 'title' => $item->title, 'done' => in_array($item->id, $ticked, true)];
        }
        $removed = [];
        foreach (MyRoutineItem::query()->where('user_id', $actor->id)->whereNotNull('hidden_at')->where('hidden_at', '>=', now()->subDays(self::RESTORE_DAYS))->orderByDesc('hidden_at')->get() as $item) {
            $removed[] = ['id' => $item->public_id, 'title' => $item->title];
        }

        return [
            'date' => $date,
            'lockVersion' => (int) (DB::table('my_routine_lists')->where('user_id', $actor->id)->value('lock_version') ?? 1),
            'items' => $rows,
            'done' => count(array_filter($rows, fn (array $r): bool => $r['done'])),
            'total' => count($rows),
            'removed' => $removed,
            'max' => self::MAX_ITEMS,
        ];
    }

    /** @param array{title: string, lock_version: int, idempotency_key: string} $input */
    public function add(User $actor, array $input): void
    {
        $title = $this->title($input['title']);
        if ($this->replay($actor, $input['idempotency_key'], 'add', hash('sha256', 'add|'.$title))) {
            return; // A retry of a request that already succeeded changes nothing, whatever the list's version is now.
        }
        $this->write($actor, 'added', (int) $input['lock_version'], function (User $locked) use ($title, $input): void {
            $hash = hash('sha256', 'add|'.$title);
            if ($this->replay($locked, $input['idempotency_key'], 'add', $hash)) {
                return;
            }
            $visible = MyRoutineItem::query()->where('user_id', $locked->id)->whereNull('hidden_at')->count();
            if ($visible >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['title' => 'Your routine can hold up to '.self::MAX_ITEMS.' items. Remove one first.']);
            }
            $item = MyRoutineItem::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $locked->id, 'title' => $title, 'position' => $this->nextPosition($locked), 'created_at' => now()]);
            $this->remember($locked, $input['idempotency_key'], 'add', $hash, $item->id);
        });
    }

    /** @param array{title: string, lock_version: int} $input */
    public function rename(User $actor, string $itemPublicId, array $input): void
    {
        $title = $this->title($input['title']);
        $this->write($actor, 'renamed', (int) $input['lock_version'], function (User $locked) use ($itemPublicId, $title): void {
            $this->own($locked, $itemPublicId, visible: true)->forceFill(['title' => $title])->save();
        });
    }

    /**
     * @param  array{order: list<string>, lock_version: int}  $input
     */
    public function reorder(User $actor, array $input): void
    {
        $this->write($actor, 'reordered', (int) $input['lock_version'], function (User $locked) use ($input): void {
            $items = MyRoutineItem::query()->where('user_id', $locked->id)->whereNull('hidden_at')->get()->keyBy('public_id');
            $order = array_values(array_unique($input['order']));
            if (count($order) !== $items->count() || array_diff($order, $items->keys()->all()) !== []) {
                throw ValidationException::withMessages(['order' => 'The list changed. Reload and try again.']);
            }
            foreach ($order as $index => $publicId) {
                $items[$publicId]->forceFill(['position' => $index + 1])->save();
            }
        });
    }

    /** @param array{lock_version: int} $input */
    public function hide(User $actor, string $itemPublicId, array $input): void
    {
        $this->write($actor, 'hidden', (int) $input['lock_version'], function (User $locked) use ($itemPublicId): void {
            $this->own($locked, $itemPublicId, visible: true)->forceFill(['hidden_at' => now()])->save();
            $this->compact($locked);
        });
    }

    /** @param array{lock_version: int, idempotency_key: string} $input */
    public function restore(User $actor, string $itemPublicId, array $input): void
    {
        if ($this->replay($actor, $input['idempotency_key'], 'restore', hash('sha256', 'restore|'.$itemPublicId))) {
            return;
        }
        $this->write($actor, 'restored', (int) $input['lock_version'], function (User $locked) use ($itemPublicId, $input): void {
            $hash = hash('sha256', 'restore|'.$itemPublicId);
            if ($this->replay($locked, $input['idempotency_key'], 'restore', $hash)) {
                return;
            }
            $item = $this->own($locked, $itemPublicId, visible: false);
            if ($item->hidden_at === null || $item->hidden_at->lt(now()->subDays(self::RESTORE_DAYS))) {
                throw ValidationException::withMessages(['item' => 'This item can no longer be restored.']);
            }
            if (MyRoutineItem::query()->where('user_id', $locked->id)->whereNull('hidden_at')->count() >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['item' => 'Your routine can hold up to '.self::MAX_ITEMS.' items. Remove one first.']);
            }
            $item->forceFill(['hidden_at' => null, 'position' => $this->nextPosition($locked)])->save();
            $this->remember($locked, $input['idempotency_key'], 'restore', $hash, $item->id);
        });
    }

    /** Ticks (or un-ticks) one item for today's branch-local date. The date is never taken from the request. */
    public function tick(User $actor, string $itemPublicId, bool $done): void
    {
        $this->assertHuman($actor);
        DB::transaction(function () use ($actor, $itemPublicId, $done): void {
            $locked = $this->lockAccount($actor);
            $item = $this->own($locked, $itemPublicId, visible: true);
            $date = $this->today($locked);
            if ($done) {
                DB::table('my_routine_ticks')->insertOrIgnore(['item_id' => $item->id, 'user_id' => $locked->id, 'done_on' => $date, 'created_at' => now()]);
            } else {
                DB::table('my_routine_ticks')->where('item_id', $item->id)->where('done_on', $date)->delete();
            }
        }, 3);
    }

    /** Removes what the retention rules no longer keep. Returns the number of rows removed. */
    public function purge(): int
    {
        $ticks = DB::table('my_routine_ticks')->where('done_on', '<', now()->subDays(31)->toDateString())->delete();
        $items = DB::table('my_routine_items')->whereNotNull('hidden_at')->where('hidden_at', '<', now()->subDays(self::RESTORE_DAYS))->delete();
        $inactive = DB::table('my_routine_items')->whereIn('user_id', User::query()->where('is_active', false)->where('updated_at', '<', now()->subDays(30))->select('id'))->delete();

        return $ticks + $items + $inactive;
    }

    /** @param callable(User): void $change */
    private function write(User $actor, string $action, int $expectedVersion, callable $change): void
    {
        $this->assertHuman($actor);
        DB::transaction(function () use ($actor, $action, $expectedVersion, $change): void {
            $locked = $this->lockAccount($actor);
            DB::table('my_routine_lists')->insertOrIgnore(['user_id' => $locked->id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $current = (int) DB::table('my_routine_lists')->where('user_id', $locked->id)->lockForUpdate()->value('lock_version');
            if ($current !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'Your list changed on another screen. Reload and try again.']);
            }
            $change($locked);
            DB::table('my_routine_lists')->where('user_id', $locked->id)->update(['lock_version' => $current + 1, 'updated_at' => now()]);
            $this->audit->record('my_routine.changed', null, [
                'action' => $action,
                'item_count' => MyRoutineItem::query()->where('user_id', $locked->id)->whereNull('hidden_at')->count(),
            ], $locked);
        }, 3);
    }

    private function assertHuman(User $actor): void
    {
        abort_if($actor->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE), 403);
    }

    private function lockAccount(User $actor): User
    {
        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->is_active, 403);

        return $locked;
    }

    private function own(User $locked, string $publicId, bool $visible): MyRoutineItem
    {
        $query = MyRoutineItem::query()->where('user_id', $locked->id)->where('public_id', $publicId);
        $visible ? $query->whereNull('hidden_at') : $query->whereNotNull('hidden_at');

        return $query->lockForUpdate()->firstOr(fn () => abort(404));
    }

    private function nextPosition(User $locked): int
    {
        return min(1000, 1 + (int) MyRoutineItem::query()->where('user_id', $locked->id)->whereNull('hidden_at')->max('position'));
    }

    /** Keeps the visible positions 1..n after a removal. */
    private function compact(User $locked): void
    {
        $position = 0;
        foreach (MyRoutineItem::query()->where('user_id', $locked->id)->whereNull('hidden_at')->orderBy('position')->orderBy('id')->get() as $item) {
            $item->forceFill(['position' => ++$position])->save();
        }
    }

    private function title(string $raw): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            throw ValidationException::withMessages(['title' => 'Write between 1 and '.self::MAX_TITLE.' characters.']);
        }
        if (PersonalText::looksPersonal($title)) {
            throw ValidationException::withMessages(['title' => 'Do not enter patient or personal details.']);
        }

        return $title;
    }

    private function replay(User $locked, string $key, string $action, string $hash): bool
    {
        $row = DB::table('my_routine_requests')->where('user_id', $locked->id)->where('idempotency_key', $key)->first();
        if ($row === null) {
            return false;
        }
        if ($row->action !== $action || $row->payload_hash !== $hash) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for something else. Reload and try again.']);
        }

        return true;
    }

    private function remember(User $locked, string $key, string $action, string $hash, int $itemId): void
    {
        DB::table('my_routine_requests')->insert(['user_id' => $locked->id, 'idempotency_key' => $key, 'action' => $action, 'payload_hash' => $hash, 'item_id' => $itemId, 'created_at' => now()]);
    }

    /** The branch-local date of the active branch; without a branch, the organisation default (Asia/Kuala_Lumpur). */
    private function today(User $actor): string
    {
        $branch = $this->branches->activeBranch($actor);

        return CarbonImmutable::now($branch === null ? 'Asia/Kuala_Lumpur' : $branch->timezone)->toDateString();
    }
}

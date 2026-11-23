<?php

namespace App\Domain\HR\Memos\Services;

use App\Domain\HR\Memos\MemoPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one "memos to read" rule (APPR-1d step 0): the memos addressed to the person (by branch and role, through
 * {@see MemoAudience}), each with its status, and which open ones the person has not acknowledged. Used by the memos page
 * ("My memos", My HR details) and by the Approvals inbox task source, so the two cannot drift. Counts and dates only.
 */
final class MemosToRead
{
    public const READER_LIMIT = 1000;

    public function __construct(private MemoAudience $audience) {}

    /**
     * Memos a reader may see (not withdrawn, not closed for more than 90 days), newest first, kept only when the person is
     * in the audience.
     *
     * @return Collection<int, \stdClass>
     */
    public function expected(User $actor): Collection
    {
        $memos = DB::table('hr_memos')->where('organisation_id', $actor->organisation_id)->orderByDesc('published_at')->orderByDesc('id')
            ->whereNull('withdrawn_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->utc()->subDays(90)))->limit(self::READER_LIMIT)
            ->get(['id', 'public_id', 'title', 'pdf_path', 'published_by_user_id', 'published_at', 'expires_at', 'replaces_memo_id', 'withdrawn_at',
                'withdrawn_by_user_id', 'withdrawn_reason', 'lock_version']);
        if ($memos->isEmpty()) {
            return $memos;
        }
        $actor->loadMissing('roles');
        $effective = $this->audience->effective($actor->organisation_id, [(int) $actor->id]);
        $ids = $memos->pluck('id')->all();
        $branchSets = DB::table('hr_memo_branches')->whereIn('memo_id', $ids)->get(['memo_id', 'branch_id'])->groupBy('memo_id');
        $roleSets = DB::table('hr_memo_roles')->whereIn('memo_id', $ids)->get(['memo_id', 'role'])->groupBy('memo_id');

        return $memos->filter(function (\stdClass $m) use ($actor, $effective, $branchSets, $roleSets): bool {
            $branchIds = array_values(array_map('intval', ($branchSets->get($m->id) ?? collect())->pluck('branch_id')->all()));
            $roles = array_map('strval', ($roleSets->get($m->id) ?? collect())->pluck('role')->all());

            return $this->audience->expects($actor, $branchIds, $roles, $effective);
        })->values();
    }

    /**
     * Open memos addressed to the person that they have not acknowledged.
     *
     * @return Collection<int, \stdClass>
     */
    public function waiting(User $actor): Collection
    {
        if (! $actor->can(MemoPermissions::READ_OWN)) {
            return collect();
        }
        $memos = $this->expected($actor);
        if ($memos->isEmpty()) {
            return $memos;
        }
        $ids = $memos->pluck('id')->all();
        $replacedBy = $this->replacements($ids);
        $acknowledged = DB::table('hr_memo_acknowledgements')->where('user_id', $actor->id)->whereIn('memo_id', $ids)->pluck('memo_id')
            ->map(fn ($id): int => (int) $id)->all();

        return $memos->filter(fn (\stdClass $m): bool => self::status($m, $replacedBy) === 'open' && ! in_array((int) $m->id, $acknowledged, true))->values();
    }

    public function count(User $actor): int
    {
        return $this->waiting($actor)->count();
    }

    /** When the oldest memo still to read was published (UTC), or null. */
    public function oldest(User $actor): ?CarbonImmutable
    {
        $at = $this->waiting($actor)->min('published_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, string> replaced memo id => public id of the memo that replaces it
     */
    public function replacements(array $ids): array
    {
        return DB::table('hr_memos as n')->join('hr_memos as o', 'o.id', '=', 'n.replaces_memo_id')->whereIn('o.id', $ids)->whereNull('n.withdrawn_at')->pluck('n.public_id', 'o.id')
            ->map(fn ($v): string => (string) $v)->all();
    }

    /** @param array<int, string> $replacedBy */
    public static function status(\stdClass $memo, array $replacedBy): string
    {
        if ($memo->withdrawn_at !== null) {
            return 'withdrawn';
        }
        if (isset($replacedBy[$memo->id])) {
            return 'replaced';
        }

        return $memo->expires_at !== null && CarbonImmutable::parse((string) $memo->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC')) ? 'closed' : 'open';
    }
}

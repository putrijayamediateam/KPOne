<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-7a page props for /hr/payroll: Pay profiles, Tables and Settings, for payroll preparers and approvers only. Read
 * only. Salaries reach this page and no other; internal ids stay on the server. Every decision button carries the
 * service's own refusal, so the page and the service never disagree.
 */
final class PayrollPageService
{
    public function __construct(private PayrollSetupService $setup, private RecordSupport $support) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $tabs = ['profiles', 'tables', 'settings'];
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : 'profiles';
        $org = $actor->organisation_id;
        $today = CarbonImmutable::now($this->zone($org))->toDateString();
        $groups = DB::table('hr_pay_groups')->where('organisation_id', $org)->orderBy('name')->get();
        $types = $this->itemTypes($org);
        $rates = DB::table('hr_rate_settings')->where('organisation_id', $org)->orderByDesc('version')->get();
        $names = [];

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'today' => $today,
            'canApprove' => $actor->can(PayrollPermissions::APPROVE),
            'options' => [
                'schemes' => self::options(PayrollSetupService::SCHEMES),
                'rounding' => self::options(PayrollSetupService::ROUNDING),
                'kinds' => self::options(PayrollSetupService::KINDS),
                'tableRejectReasons' => self::options(PayrollSetupService::TABLE_REJECT_REASONS),
                'profileRejectReasons' => self::options(PayrollSetupService::PROFILE_REJECT_REASONS),
                'groups' => $groups->where('is_active', true)->values()->map(fn (\stdClass $g): array => ['value' => (string) $g->public_id, 'label' => (string) $g->name])->all(),
                'allowanceTypes' => $types->where('isActive', true)->where('kind', 'earnings')->values()
                    ->map(fn (array $t): array => ['value' => $t['id'], 'label' => $t['name']])->all(),
                'categories' => $this->categories($org),
                'suggestedItemTypes' => PayrollSetupService::SUGGESTED_ITEM_TYPES,
                'maxRows' => PayrollSetupService::MAX_ROWS,
                'maxItems' => PayrollSetupService::MAX_ITEMS,
            ],
            'setUp' => ['groups' => $groups->where('is_active', true)->isNotEmpty(), 'rates' => $rates->isNotEmpty(),
                'checkedTables' => DB::table('hr_statutory_tables')->where('organisation_id', $org)->where('status', 'checked')->exists()],
            'profiles' => $tab === 'profiles' ? $this->profiles($actor, $today, $names) : null,
            'tables' => $tab === 'tables' ? $this->tables($actor, $names) : null,
            'settings' => $tab === 'settings' ? [
                'groups' => $groups->map(fn (\stdClass $g): array => ['id' => (string) $g->public_id, 'name' => (string) $g->name,
                    'payDay' => $g->pay_day === null ? null : (int) $g->pay_day, 'isActive' => (bool) $g->is_active, 'lockVersion' => (int) $g->lock_version])->values()->all(),
                'itemTypes' => $types->map(fn (array $t): array => array_diff_key($t, ['dbId' => true]))->values()->all(),
                'rates' => ['version' => $rates->isEmpty() ? 0 : (int) $rates->first()->version,
                    'daily' => $rates->isEmpty() ? null : PayrollMoney::divisor((int) $rates->first()->daily_divisor_hundredths),
                    'hourly' => $rates->isEmpty() ? null : PayrollMoney::divisor((int) $rates->first()->hourly_divisor_hundredths),
                    'history' => $rates->take(10)->map(fn (\stdClass $r): array => ['version' => (int) $r->version,
                        'daily' => PayrollMoney::divisor((int) $r->daily_divisor_hundredths), 'hourly' => PayrollMoney::divisor((int) $r->hourly_divisor_hundredths),
                        'by' => $this->name((int) $r->set_by_user_id, $names), 'at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601String()])->values()->all()],
            ] : null,
        ];
    }

    /**
     * Each staff member with the profile in force today, the one waiting and up to five earlier ones.
     *
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function profiles(User $actor, string $today, array &$names): array
    {
        $org = $actor->organisation_id;
        $workers = Worker::query()->with('user.roles')->where('organisation_id', $org)->where('kind', 'staff')->get();
        $profiles = DB::table('hr_pay_profiles')->where('organisation_id', $org)->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('worker_id');
        $items = DB::table('hr_pay_profile_items')->where('organisation_id', $org)->orderBy('position')->get()->groupBy('profile_id');
        $typeNames = DB::table('hr_pay_item_type_versions')->where('organisation_id', $org)->get()
            ->mapWithKeys(fn (\stdClass $v): array => [$v->item_type_id.':'.$v->version => (string) $v->name]);
        $groups = DB::table('hr_pay_groups')->where('organisation_id', $org)->pluck('name', 'id');
        $actorIsDirector = $actor->hasRole('director');
        $rows = $workers->map(function (Worker $w) use ($actor, $today, $profiles, $items, $typeNames, $groups, &$names): array {
            $own = $profiles->get($w->id, collect());
            $current = $own->first(fn (\stdClass $p): bool => $p->status === 'confirmed' && (string) $p->effective_from <= $today);
            $upcoming = $own->filter(fn (\stdClass $p): bool => $p->status === 'confirmed' && (string) $p->effective_from > $today)->sortBy('effective_from')->first();
            $waiting = $own->firstWhere('status', 'waiting');
            $earlier = $own->reject(fn (\stdClass $p): bool => in_array($p->id, [$current?->id, $upcoming?->id, $waiting?->id], true))->take(5);
            $refusal = PayrollSetupService::isManager($w) ? PayrollSetupService::MANAGERS_OUTSIDE
                : ($w->user !== null && $w->user_id !== $actor->id ? $this->support->refusalFor($actor, $w->user) : ($w->user_id === $actor->id ? 'Nobody enters their own pay profile.' : null));
            $map = fn (?\stdClass $p): ?array => $p === null ? null : $this->profile($actor, $w, $p, $items->get($p->id, collect()), $typeNames, $groups, $names);

            return ['id' => $w->public_id, 'name' => $w->name(), 'active' => $w->user === null || (bool) $w->user->is_active, 'outside' => PayrollSetupService::isManager($w),
                'enterRefusal' => $refusal ?? ($waiting !== null ? 'A change is already waiting.' : null),
                'current' => $map($current), 'upcoming' => $map($upcoming), 'waiting' => $map($waiting),
                'earlier' => $earlier->map(fn (\stdClass $p): ?array => $map($p))->values()->all()];
        })->sortBy(fn (array $r): string => mb_strtolower($r['name']))->values();

        return ['people' => $rows->all(), 'waitingCount' => $rows->filter(fn (array $r): bool => $r['waiting'] !== null)->count(),
            'missingCount' => $rows->filter(fn (array $r): bool => $r['active'] && $r['current'] === null && ! $r['outside'])->count(), 'actorIsDirector' => $actorIsDirector];
    }

    /**
     * @param  Collection<int, \stdClass>  $items
     * @param  Collection<string, string>  $typeNames
     * @param  Collection<int|string, mixed>  $groups
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function profile(User $actor, Worker $w, \stdClass $p, Collection $items, Collection $typeNames, Collection $groups, array &$names): array
    {
        $waiting = $p->status === 'waiting';
        $refusal = $waiting ? ($this->setup->deciderRefusal($actor, (int) $p->entered_by_user_id, $w->user_id, 'confirms')
            ?? ($w->user !== null ? $this->support->refusalFor($actor, $w->user) : null)) : null;
        $schemes = [];
        foreach (['epf' => 'EPF', 'socso' => 'SOCSO', 'eis' => 'EIS', 'mtd' => 'MTD', 'hrd' => 'HRD levy'] as $key => $label) {
            if ($p->{$key}) {
                $category = in_array($key, ['epf', 'socso'], true) ? $p->{"{$key}_category"} : null;
                $schemes[] = $category === null ? $label : "{$label} ({$category})";
            }
        }

        return ['id' => (string) $p->public_id, 'status' => (string) $p->status, 'payGroup' => (string) ($groups->get($p->pay_group_id) ?? ''),
            'payType' => (string) $p->pay_type, 'rateSen' => $this->sen((string) $p->rate_encrypted), 'schemes' => $schemes,
            'effectiveFrom' => (string) $p->effective_from,
            'items' => $items->map(fn (\stdClass $i): array => ['name' => (string) ($typeNames->get($i->item_type_id.':'.$i->item_type_version) ?? ''),
                'amountSen' => $this->sen((string) $i->amount_encrypted)])->values()->all(),
            'enteredBy' => $this->name((int) $p->entered_by_user_id, $names), 'enteredAt' => CarbonImmutable::parse((string) $p->created_at)->toIso8601String(),
            'decidedBy' => $p->decided_by_user_id === null ? null : $this->name((int) $p->decided_by_user_id, $names),
            'decidedAt' => $p->decided_at === null ? null : CarbonImmutable::parse((string) $p->decided_at)->toIso8601String(),
            'reason' => $p->decision_reason === null ? null : (PayrollSetupService::PROFILE_REJECT_REASONS[$p->decision_reason] ?? null),
            'lockVersion' => (int) $p->lock_version, 'canDecide' => $waiting && $refusal === null, 'decideRefusal' => $refusal,
            'canWithdraw' => $waiting && (int) $p->entered_by_user_id === $actor->id];
    }

    /**
     * Tables waiting to be checked first, then checked ones, then earlier ones.
     *
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function tables(User $actor, array &$names): array
    {
        $org = $actor->organisation_id;
        $tables = DB::table('hr_statutory_tables')->where('organisation_id', $org)->orderByDesc('effective_from')->orderByDesc('id')->get();
        $rows = DB::table('hr_statutory_table_rows')->where('organisation_id', $org)->orderBy('position')->get()->groupBy('table_id');
        $rank = ['entered' => 0, 'checked' => 1, 'rejected' => 2, 'withdrawn' => 2, 'replaced' => 2];
        $out = $tables->map(function (\stdClass $t) use ($actor, $rows, &$names): array {
            $entered = $t->status === 'entered';
            $refusal = $entered ? $this->setup->deciderRefusal($actor, (int) $t->entered_by_user_id, null) : null;

            return ['id' => (string) $t->public_id, 'scheme' => (string) $t->scheme, 'schemeLabel' => PayrollSetupService::SCHEMES[$t->scheme] ?? (string) $t->scheme,
                'category' => (string) $t->category, 'effectiveFrom' => (string) $t->effective_from, 'rounding' => (string) $t->rounding,
                'roundingLabel' => PayrollSetupService::ROUNDING[$t->rounding] ?? (string) $t->rounding, 'sourceNote' => (string) $t->source_note,
                'status' => (string) $t->status,
                'rows' => $rows->get($t->id, collect())->map(fn (\stdClass $r): array => ['from' => PayrollMoney::rm((int) $r->wage_from_sen),
                    'to' => $r->wage_to_sen === null ? null : PayrollMoney::rm((int) $r->wage_to_sen),
                    'employee' => self::part((string) $r->employee_kind, (int) $r->employee_value), 'employer' => self::part((string) $r->employer_kind, (int) $r->employer_value)])
                    ->values()->all(),
                'enteredBy' => $this->name((int) $t->entered_by_user_id, $names), 'enteredAt' => CarbonImmutable::parse((string) $t->created_at)->toIso8601String(),
                'decidedBy' => $t->decided_by_user_id === null ? null : $this->name((int) $t->decided_by_user_id, $names),
                'decidedAt' => $t->decided_at === null ? null : CarbonImmutable::parse((string) $t->decided_at)->toIso8601String(),
                'reason' => $t->decision_reason === null ? null : (PayrollSetupService::TABLE_REJECT_REASONS[$t->decision_reason] ?? null),
                'lockVersion' => (int) $t->lock_version, 'canDecide' => $entered && $refusal === null, 'decideRefusal' => $refusal,
                'canWithdraw' => $entered && (int) $t->entered_by_user_id === $actor->id];
        })->sortBy(fn (array $t): int => $rank[$t['status']] ?? 3)->values();

        return ['tables' => $out->all(), 'waitingCount' => $out->where('status', 'entered')->count()];
    }

    /** @return Collection<int, array{dbId:int, id:string, name:string, kind:string, kindLabel:string, version:int, countsEpf:bool, countsSocso:bool, countsEis:bool, countsMtd:bool, countsHrd:bool, isActive:bool, lockVersion:int}> current version of each pay item type */
    private function itemTypes(int $org): Collection
    {
        return DB::table('hr_pay_item_types as t')->join('hr_pay_item_type_versions as v', function ($join): void {
            $join->on('v.item_type_id', '=', 't.id')->on('v.version', '=', 't.current_version');
        })->where('t.organisation_id', $org)->orderBy('v.name')->get(['t.id', 't.public_id', 't.lock_version', 'v.*'])
            ->map(fn (\stdClass $v): array => ['dbId' => (int) $v->id, 'id' => (string) $v->public_id, 'name' => (string) $v->name, 'kind' => (string) $v->kind,
                'kindLabel' => PayrollSetupService::KINDS[$v->kind] ?? (string) $v->kind, 'version' => (int) $v->version,
                'countsEpf' => (bool) $v->counts_epf, 'countsSocso' => (bool) $v->counts_socso, 'countsEis' => (bool) $v->counts_eis,
                'countsMtd' => (bool) $v->counts_mtd, 'countsHrd' => (bool) $v->counts_hrd, 'isActive' => (bool) $v->is_active, 'lockVersion' => (int) $v->lock_version]);
    }

    /** @return array<string, list<string>> category names already used on tables, per scheme, for the profile form */
    private function categories(int $org): array
    {
        $out = ['epf' => [], 'socso' => []];
        foreach (DB::table('hr_statutory_tables')->where('organisation_id', $org)->whereIn('scheme', ['epf', 'socso'])->whereIn('status', ['entered', 'checked'])
            ->distinct()->orderBy('category')->get(['scheme', 'category']) as $row) {
            $out[$row->scheme][] = (string) $row->category;
        }

        return ['epf' => array_values(array_unique($out['epf'])), 'socso' => array_values(array_unique($out['socso']))];
    }

    private static function part(string $kind, int $value): string
    {
        return $kind === 'rate' ? PayrollMoney::percent($value) : PayrollMoney::rm($value);
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value:string, label:string}>
     */
    private static function options(array $map): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($map), array_values($map));
    }

    /** @param array<int, string> $names */
    private function name(int $userId, array &$names): string
    {
        return $names[$userId] ??= (string) (User::query()->whereKey($userId)->value('name') ?? 'Staff member');
    }

    private function sen(string $encrypted): ?int
    {
        try {
            $value = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }

        return preg_match('/\A[0-9]{1,9}\z/', $value) === 1 ? (int) $value : null;
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}

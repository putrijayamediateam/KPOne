<?php

namespace App\Domain\HR\Claims\Services;

use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-6 page props for /hr/claims: My claims (everyone), Approvals and Settings (Director, HR Manager), Pay (Finance
 * Officer, Director) and Monthly (both). Read only. Finance sees only the person, branch, category, amount, receipts
 * and payment of approved claims; the purpose is shown to the claimant and to approvers. Payment references reach the
 * page masked to their last four.
 */
final class ClaimsPageService
{
    public function __construct(private ClaimService $claims) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, ?Branch $branch, array $query): array
    {
        $can = [
            'my' => $actor->can(ClaimPermissions::SUBMIT_OWN),
            'approvals' => $actor->can(ClaimPermissions::APPROVE),
            'pay' => $actor->can(ClaimPermissions::PAY),
            'monthly' => $actor->can(ClaimPermissions::PAY) || $actor->can(ClaimPermissions::MANAGE),
            'settings' => $actor->can(ClaimPermissions::MANAGE),
        ];
        $tabs = array_keys(array_filter($can));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');
        $org = $actor->organisation_id;
        $settings = $this->claims->settings($org);
        $categories = $this->categories($org);
        $zone = $branch !== null ? $branch->timezone : $this->zone($org);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'branch' => $branch?->name,
            'today' => CarbonImmutable::now($zone)->toDateString(),
            'setUp' => $categories->where('isActive', true)->isNotEmpty(),
            'options' => [
                'categories' => $categories->where('isActive', true)->values()->map(fn (array $c): array => ['value' => $c['id'], 'label' => $c['name'],
                    'needsReceipt' => $c['needsReceipt'], 'perClaimLimitSen' => $c['perClaimLimitSen'], 'monthlyLimitSen' => $c['monthlyLimitSen']])->all(),
                'rejectReasons' => self::options(ClaimService::REJECT_REASONS),
                'reversalReasons' => self::options(ClaimService::REVERSAL_REASONS),
                'methods' => self::options(ClaimService::METHODS),
                'suggestedCategories' => ClaimService::SUGGESTED_CATEGORIES,
                'suggestedDaysBack' => ClaimService::SUGGESTED_DAYS_BACK,
                'daysBack' => $settings?->days_back,
                'maxReceipts' => ClaimService::MAX_RECEIPTS,
            ],
            'my' => $can['my'] && $tab === 'my' ? self::strip($this->my($actor, $zone)) : null,
            'approvals' => $can['approvals'] && $tab === 'approvals' ? ['claims' => self::strip($this->approvals($actor, $categories, $zone)['claims'])] : null,
            'pay' => $can['pay'] && $tab === 'pay' ? ['claims' => self::strip($this->pay($actor, $zone)['claims'])] : null,
            'monthly' => $can['monthly'] && $tab === 'monthly' ? $this->monthly($actor, $query, $zone) : null,
            'settings' => $can['settings'] && $tab === 'settings' ? ['version' => $settings === null ? 0 : (int) $settings->version,
                'directorLevelSen' => $settings?->director_level_sen === null ? null : (int) $settings->director_level_sen,
                'daysBack' => $settings?->days_back === null ? null : (int) $settings->days_back,
                'categories' => self::strip($categories->values()->all())] : null,
        ];
    }

    /**
     * Internal ids stay on the server.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function strip(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            unset($row['dbId']);

            return $row;
        }, $rows));
    }

    public static function myr(int $sen): string
    {
        return 'RM '.number_format(intdiv($sen, 100)).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string, mixed>> */
    private function my(User $actor, string $zone): array
    {
        $claims = DB::table('hr_staff_claims')->where('organisation_id', $actor->organisation_id)->where('claimant_user_id', $actor->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return array_values($this->rows($actor, $claims, $zone, true, true)->all());
    }

    /**
     * @param  Collection<array-key, array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    private function approvals(User $actor, Collection $categories, string $zone): array
    {
        $claims = DB::table('hr_staff_claims')->where('organisation_id', $actor->organisation_id)->where('status', 'waiting')
            ->orderBy('created_at')->orderBy('id')->get();
        $rows = $this->rows($actor, $claims, $zone, true, false);
        $hashes = DB::table('hr_staff_claim_receipts')->whereIn('claim_id', $claims->pluck('id')->all())->get(['claim_id', 'sha256']);
        $others = $hashes->isEmpty() ? collect() : DB::table('hr_staff_claim_receipts as r')->join('hr_staff_claims as c', 'c.id', '=', 'r.claim_id')
            ->where('r.organisation_id', $actor->organisation_id)->whereIn('r.sha256', $hashes->pluck('sha256')->unique()->all())
            ->whereIn('c.status', ['waiting', 'approved', 'paid'])->get(['r.sha256', 'c.id', 'c.number']);

        return ['claims' => $rows->map(function (array $row) use ($actor, $claims, $categories, $hashes, $others): array {
            $claim = $claims->firstWhere('id', $row['dbId']);
            $category = $categories->firstWhere('dbId', (int) $claim->category_id);
            $warnings = [];
            $amount = (int) $claim->amount_sen;
            if ($category !== null && $category['perClaimLimitSen'] !== null && $amount > $category['perClaimLimitSen']) {
                $warnings[] = 'Over the '.self::myr($category['perClaimLimitSen']).' limit for '.$category['name'];
            }
            $month = substr((string) $claim->spent_on, 0, 7);
            $monthTotal = (int) DB::table('hr_staff_claims')->where('claimant_user_id', $claim->claimant_user_id)->where('category_id', $claim->category_id)
                ->whereIn('status', ['waiting', 'approved', 'paid'])->whereBetween('spent_on', [$month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString()])
                ->sum('amount_sen');
            if ($category !== null && $category['monthlyLimitSen'] !== null && $monthTotal > $category['monthlyLimitSen']) {
                $warnings[] = self::myr($monthTotal).' in '.$category['name'].' this month, over the '.self::myr($category['monthlyLimitSen']).' monthly limit';
            }
            foreach ($hashes->where('claim_id', $claim->id)->pluck('sha256')->unique() as $sha) {
                foreach ($others->where('sha256', $sha)->where('id', '<>', $claim->id)->pluck('number')->unique() as $number) {
                    $warnings[] = "This receipt was also attached to claim {$number}";
                }
            }
            $level = $this->claims->directorLevel($claim);
            if ($level !== null && $amount > $level) {
                $warnings[] = 'Needs the Director: over '.self::myr($level);
            }

            return [...$row, 'warnings' => array_values(array_unique($warnings)), 'monthTotalSen' => $monthTotal,
                'perClaimLimitSen' => $category['perClaimLimitSen'] ?? null, 'monthlyLimitSen' => $category['monthlyLimitSen'] ?? null,
                'refusal' => $this->claims->deciderRefusal($actor, $claim)];
        })->values()->all()];
    }

    /** @return array<string, mixed> */
    private function pay(User $actor, string $zone): array
    {
        $since = CarbonImmutable::now('UTC')->subDays(60);
        $claims = DB::table('hr_staff_claims')->where('organisation_id', $actor->organisation_id)
            ->where(fn ($q) => $q->where('status', 'approved')->orWhere(fn ($p) => $p->where('status', 'paid')->where('updated_at', '>=', $since)))
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 ELSE 1 END")->orderBy('decided_at')->orderBy('id')->get();
        $rows = $this->rows($actor, $claims, $zone, false, false)->map(function (array $row) use ($actor, $claims): array {
            $claim = $claims->firstWhere('id', $row['dbId']);
            $refusal = match (true) {
                (int) $claim->claimant_user_id === $actor->id => 'Someone else records the payment of your own claim.',
                (int) $claim->decided_by_user_id === $actor->id => 'You approved this claim, so someone else records its payment.',
                default => null,
            };

            return [...$row, 'refusal' => $refusal];
        });

        return ['claims' => $rows->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function monthly(User $actor, array $query, string $zone): array
    {
        $month = is_string($query['month'] ?? null) && preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $query['month']) === 1
            ? $query['month'] : CarbonImmutable::now($zone)->format('Y-m');
        $start = CarbonImmutable::parse($month.'-01');
        $rows = DB::table('hr_staff_claims as c')->join('branches as b', 'b.id', '=', 'c.branch_id')
            ->join('hr_claim_category_versions as v', function ($join): void {
                $join->on('v.category_id', '=', 'c.category_id')->on('v.version', '=', 'c.category_version');
            })
            ->where('c.organisation_id', $actor->organisation_id)->whereIn('c.status', ['approved', 'paid'])
            ->whereBetween('c.spent_on', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->groupBy('b.name', 'v.name')->orderBy('b.name')->orderBy('v.name')
            ->selectRaw("b.name as branch, v.name as category, count(*) as claims, sum(c.amount_sen) as total_sen, sum(CASE WHEN c.status = 'paid' THEN c.amount_sen ELSE 0 END) as paid_sen")
            ->get()->map(fn (object $r): array => ['branch' => (string) $r->branch, 'category' => (string) $r->category, 'claims' => (int) $r->claims,
                'totalSen' => (int) $r->total_sen, 'paidSen' => (int) $r->paid_sen, 'unpaidSen' => (int) $r->total_sen - (int) $r->paid_sen])->all();

        return ['month' => $month, 'monthLabel' => $start->format('F Y'), 'rows' => $rows,
            'total' => ['claims' => array_sum(array_column($rows, 'claims')), 'totalSen' => array_sum(array_column($rows, 'totalSen')),
                'paidSen' => array_sum(array_column($rows, 'paidSen')), 'unpaidSen' => array_sum(array_column($rows, 'unpaidSen'))]];
    }

    /**
     * @param  Collection<int, \stdClass>  $claims
     * @return Collection<array-key, array<string, mixed>>
     */
    private function rows(User $actor, Collection $claims, string $zone, bool $withPurpose, bool $own): Collection
    {
        if ($claims->isEmpty()) {
            return collect();
        }
        $ids = $claims->pluck('id')->all();
        $people = User::query()->whereIn('id', [...$claims->pluck('claimant_user_id')->all(), ...$claims->pluck('decided_by_user_id')->filter()->all()])->pluck('name', 'id');
        $branches = DB::table('branches')->whereIn('id', $claims->pluck('branch_id')->unique()->all())->pluck('name', 'id');
        $categoryNames = DB::table('hr_claim_category_versions')->whereIn('category_id', $claims->pluck('category_id')->unique()->all())
            ->get(['category_id', 'version', 'name'])->keyBy(fn (object $v): string => $v->category_id.':'.$v->version);
        $receipts = DB::table('hr_staff_claim_receipts')->whereIn('claim_id', $ids)->orderBy('position')->get()->groupBy('claim_id');
        $payments = DB::table('hr_staff_claim_payments')->whereIn('claim_id', $ids)->orderBy('id')->get()->groupBy('claim_id');
        $notes = DB::table('hr_staff_claim_actions')->whereIn('claim_id', $ids)->where('action', 'rejected')->get(['claim_id', 'note_encrypted'])->keyBy('claim_id');
        $hrManagers = User::query()->whereIn('id', $claims->pluck('claimant_user_id')->unique()->all())
            ->whereHas('roles', fn ($q) => $q->where('name', 'hr_manager'))->whereDoesntHave('roles', fn ($q) => $q->where('name', 'director'))->pluck('id')->all();

        /** @var Collection<array-key, array<string, mixed>> $out */
        $out = $claims->map(function (\stdClass $c) use ($people, $branches, $categoryNames, $receipts, $payments, $notes, $withPurpose, $own, $hrManagers, $zone): array {
            $files = $receipts->get($c->id, collect())->values();
            $count = $files->count();
            $paid = null;
            $reversed = null;
            foreach ($payments->get($c->id, collect()) as $p) {
                if ($p->kind === 'payment') {
                    $paid = ['paidOn' => CarbonImmutable::parse((string) $p->paid_on)->format('j M Y'), 'method' => ClaimService::METHODS[$p->method] ?? $p->method,
                        'last4' => $p->reference_last4, 'paymentId' => $p->id];
                } else {
                    $reversed = ['reason' => ClaimService::REVERSAL_REASONS[$p->reason_code] ?? $p->reason_code, 'on' => CarbonImmutable::parse((string) $p->created_at, 'UTC')->setTimezone($zone)->format('j M Y')];
                    $paid = null;
                }
            }
            $status = (string) $c->status;
            $note = $notes->get($c->id)?->note_encrypted;
            $waitingFor = null;
            if ($status === 'waiting') {
                $level = $this->claims->directorLevel($c);
                $waitingFor = ($level !== null && (int) $c->amount_sen > $level) || in_array((int) $c->claimant_user_id, $hrManagers, true)
                    ? 'Waiting for the Director' : 'Someone else approves this';
            }

            return [
                'dbId' => (int) $c->id,
                'id' => (string) $c->public_id,
                'number' => (string) $c->number,
                'person' => $people[$c->claimant_user_id] ?? 'Staff member',
                'branch' => $branches[$c->branch_id] ?? '',
                'category' => $categoryNames->get($c->category_id.':'.$c->category_version)->name ?? '',
                'spentOn' => CarbonImmutable::parse((string) $c->spent_on)->format('j M Y'),
                'amountSen' => (int) $c->amount_sen,
                'amount' => self::myr((int) $c->amount_sen),
                'purpose' => $withPurpose ? $this->decrypt((string) $c->purpose_encrypted) : null,
                'status' => $status,
                'statusWords' => match ($status) {
                    'waiting' => 'Waiting for approval',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'withdrawn' => 'Withdrawn',
                    'paid' => 'Paid '.($paid['paidOn'] ?? ''),
                    default => $status,
                },
                'rejectReason' => $c->reject_reason === null ? null : (ClaimService::REJECT_REASONS[$c->reject_reason] ?? $c->reject_reason),
                'rejectCode' => $c->reject_reason,
                'rejectNote' => $note === null || ! $withPurpose ? null : $this->decrypt((string) $note),
                'decidedBy' => $c->decided_by_user_id === null ? null : ($people[$c->decided_by_user_id] ?? 'Staff member'),
                'paid' => $paid === null ? null : ['paidOn' => $paid['paidOn'], 'method' => $paid['method'], 'last4' => $paid['last4']],
                'reversed' => $status === 'approved' ? $reversed : null,
                'waitingFor' => $own ? $waitingFor : null,
                'canWithdraw' => $own && $status === 'waiting',
                'canResend' => $own && $status === 'rejected' && $c->reject_reason === 'wrong_amount',
                'lockVersion' => (int) $c->lock_version,
                'receipts' => $files->map(fn (object $r, int $i): array => ['id' => (string) $r->public_id, 'kind' => (string) $r->kind,
                    'url' => '/hr/claims/receipts/'.$r->public_id,
                    'label' => 'Receipt '.($i + 1).' of '.$count.', '.($r->kind === 'jpeg' ? 'JPG' : strtoupper((string) $r->kind))])->all(),
            ];
        });

        return $out;
    }

    /** @return Collection<array-key, array<string, mixed>> */
    private function categories(int $organisationId): Collection
    {
        $categories = DB::table('hr_claim_categories')->where('organisation_id', $organisationId)->orderBy('id')->get();
        if ($categories->isEmpty()) {
            return collect();
        }
        $versions = DB::table('hr_claim_category_versions')->whereIn('category_id', $categories->pluck('id')->all())->orderByDesc('version')->get()->groupBy('category_id');
        $names = User::query()->whereIn('id', DB::table('hr_claim_category_versions')->whereIn('category_id', $categories->pluck('id')->all())->pluck('set_by_user_id')->unique()->all())->pluck('name', 'id');

        /** @var Collection<array-key, array<string, mixed>> $out */
        $out = $categories->map(function (object $c) use ($versions, $names): array {
            $all = $versions->get($c->id, collect());
            $current = $all->firstWhere('version', $c->current_version);

            return ['dbId' => (int) $c->id, 'id' => (string) $c->public_id, 'name' => (string) $current->name, 'needsReceipt' => (bool) $current->needs_receipt,
                'perClaimLimitSen' => $current->per_claim_limit_sen === null ? null : (int) $current->per_claim_limit_sen,
                'monthlyLimitSen' => $current->monthly_limit_sen === null ? null : (int) $current->monthly_limit_sen,
                'isActive' => (bool) $current->is_active, 'version' => (int) $c->current_version, 'lockVersion' => (int) $c->lock_version,
                'versions' => $all->map(fn (object $v): array => ['version' => (int) $v->version, 'name' => (string) $v->name, 'needsReceipt' => (bool) $v->needs_receipt,
                    'perClaimLimitSen' => $v->per_claim_limit_sen === null ? null : (int) $v->per_claim_limit_sen,
                    'monthlyLimitSen' => $v->monthly_limit_sen === null ? null : (int) $v->monthly_limit_sen, 'isActive' => (bool) $v->is_active,
                    'by' => $names[$v->set_by_user_id] ?? 'Staff member', 'on' => CarbonImmutable::parse((string) $v->created_at, 'UTC')->format('j M Y')])->values()->all()];
        })->sortBy(fn (array $c): string => ($c['isActive'] ? '0' : '1').mb_strtolower($c['name']))->values();

        return $out;
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value:string, label:string}>
     */
    private static function options(array $map): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($map), array_values($map));
    }

    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}

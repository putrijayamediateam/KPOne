<?php

namespace App\Domain\HR\LocumPay\Services;

use App\Domain\HR\LocumPay\LocumPayPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR-15g page props for /hr/locum-pay. Statements and Rates for the Director, the HR Manager and the Finance Officer;
 * My pay for a locum with a linked sign-in, which shows only their own statement.
 */
final class LocumPayPageService
{
    public const REJECT_REASONS = [
        'wrong_amount' => 'Wrong amount',
        'wrong_kind' => 'Per hour or per session is wrong',
        'wrong_date' => 'Wrong start date',
        'not_agreed' => 'Not what was agreed',
        'other' => 'Other',
    ];

    public const KINDS = ['hour' => 'Per hour', 'session' => 'Per session'];

    /**
     * @param  array<string, mixed>  $query  tab, month (YYYY-MM), locum (worker public id)
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $org = (int) $actor->organisation_id;
        $canManage = $actor->can(LocumPayPermissions::MANAGE);
        $canFinance = $actor->can(LocumPayPermissions::FINANCE);
        $canOwn = $actor->can(LocumPayPermissions::VIEW_OWN);
        $tabs = array_values(array_filter([$canOwn ? 'mine' : null, ($canManage || $canFinance) ? 'statements' : null, ($canManage || $canFinance) ? 'rates' : null]));
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], $tabs, true) ? $query['tab'] : ($tabs[0] ?? 'mine');

        $zone = $this->zone($org);
        $today = CarbonImmutable::now($zone);
        $current = $today->format('Y-m');
        $month = is_string($query['month'] ?? null) && preg_match('/\A(20[0-9]{2})-(0[1-9]|1[0-2])\z/', $query['month']) === 1 && $query['month'] <= $current
            ? $query['month'] : $current;
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', 'UTC') ?: CarbonImmutable::now('UTC')->startOfMonth();

        $names = [];
        $props = ['tab' => $tab, 'tabs' => $tabs, 'canManage' => $canManage, 'canFinance' => $canFinance,
            'month' => $month, 'monthLabel' => $first->format('F Y'), 'previousMonth' => $first->subMonth()->format('Y-m'),
            'nextMonth' => $month < $current ? $first->addMonth()->format('Y-m') : null, 'monthEnded' => $month < $current,
            'today' => $today->format('Y-m-d'),
            'kinds' => self::options(self::KINDS), 'reasons' => self::options(LocumPayStatement::REASONS),
            'correctionReasons' => self::options(LocumPayStatement::ADJUSTMENT_REASONS), 'rejectReasons' => self::options(self::REJECT_REASONS),
            'mine' => null, 'locums' => [], 'statement' => null, 'rates' => []];

        if ($tab === 'mine') {
            $worker = LocumPayStatement::workerForUser($org, $actor->id);
            if ($worker !== null) {
                $statement = LocumPayStatement::build($org, (int) $worker->id, $month.'-01');
                $rate = LocumPayStatement::rateOn(LocumPayStatement::confirmedRates((int) $worker->id), $today->format('Y-m-d'));
                $props['mine'] = ['name' => (string) $worker->display_name, 'statement' => $this->present($statement, $names, false),
                    'rate' => $rate === null || $rate['sen'] === null ? null : $this->rateLabel($rate['kind'], $rate['sen'])];
            }

            return $props;
        }

        $locums = DB::table('hr_workers')->where('organisation_id', $org)->where('kind', 'locum')->orderBy('display_name')->orderBy('id')
            ->get(['id', 'public_id', 'display_name']);

        if ($tab === 'statements') {
            $selected = null;
            foreach ($locums as $locum) {
                $linked = LocumPayStatement::linkedUserId((int) $locum->id) !== null;
                $paid = DB::table('hr_locum_pay_months')->where('worker_id', $locum->id)->where('month', $month.'-01')->exists();
                $hasRate = DB::table('hr_locum_pay_rates')->where('worker_id', $locum->id)->exists();
                if (! $linked && ! $paid && ! $hasRate) {
                    continue; // a locum with no sign-in and no rate has no shifts and nothing to show
                }
                $statement = LocumPayStatement::build($org, (int) $locum->id, $month.'-01');
                [$status, $label] = match (true) {
                    $statement['paid'] => ['paid', 'Paid'],
                    $statement['lines'] === [] && $statement['corrections'] === [] => ['nothing', 'Nothing this month'],
                    self::rateMissing($statement) => ['needs_rate', 'Rate missing'],
                    $statement['total'] < 0 => ['below_zero', 'Below RM 0.00'],
                    $statement['problems'] !== [] => ['needs_check', 'Needs checking'],
                    $month < $current => ['ready', 'Ready to mark paid'],
                    default => ['open', 'Month not ended'],
                };
                $props['locums'][] = ['id' => (string) $locum->public_id, 'name' => (string) $locum->display_name, 'linked' => $linked,
                    'status' => $status, 'statusLabel' => $label, 'totalLabel' => self::totalLabel($statement),
                    'shifts' => count($statement['lines'])];
                if (($query['locum'] ?? null) === (string) $locum->public_id) {
                    $selected = ['locum' => $locum, 'statement' => $statement];
                }
            }
            if ($selected !== null) {
                $statement = $selected['statement'];
                $props['statement'] = ['workerId' => (string) $selected['locum']->public_id, 'name' => (string) $selected['locum']->display_name,
                    ...$this->present($statement, $names, true),
                    'canMarkPaid' => $canFinance && ! $statement['paid'] && $month < $current && $statement['problems'] === [],
                    'canEdit' => $canManage && ! $statement['paid']];
            }

            return $props;
        }

        // Rates.
        foreach ($locums as $locum) {
            $rows = DB::table('hr_locum_pay_rates')->where('worker_id', $locum->id)->orderByDesc('entered_at')->orderByDesc('id')->get();
            $inForce = LocumPayStatement::rateOn(LocumPayStatement::confirmedRates((int) $locum->id), $today->format('Y-m-d'));
            $waiting = $rows->firstWhere('status', 'waiting');
            $props['rates'][] = ['workerId' => (string) $locum->public_id, 'name' => (string) $locum->display_name,
                'current' => $inForce === null || $inForce['sen'] === null ? null : [...$this->rateLabel($inForce['kind'], $inForce['sen']), 'fromLabel' => $this->day($inForce['from'])],
                'waiting' => ! $waiting instanceof \stdClass ? null : $this->rateRow($waiting, $actor, $canManage, $canFinance, $names),
                'history' => array_values($rows->where('status', '!=', 'waiting')->take(10)->map(fn (\stdClass $r): array => $this->rateRow($r, $actor, false, false, $names))->all())];
        }

        return $props;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function present(array $statement, array &$names, bool $staff): array
    {
        $payment = $statement['payment'];
        if (is_array($payment)) {
            $payment = ['paidOnLabel' => $this->day((string) $payment['paidOn']), 'reference' => $payment['reference'],
                'paidBy' => $staff ? $this->name((int) $payment['paidBy'], $names) : null];
        }
        $lines = array_map(function (array $line) use ($staff): array {
            if (! $staff && is_array($line['notWorked'])) {
                unset($line['notWorked']['id'], $line['notWorked']['lockVersion']);
            }

            return $line;
        }, $statement['lines']);

        return ['paid' => $statement['paid'], 'month' => substr((string) $statement['month'], 0, 7), 'total' => $statement['total'],
            'totalLabel' => self::totalLabel($statement), 'rateMissing' => self::rateMissing($statement),
            'lines' => $lines,
            'corrections' => $staff ? $statement['corrections'] : array_map(fn (array $c): array => array_diff_key($c, ['id' => 1, 'lockVersion' => 1]), $statement['corrections']),
            // A locum sees the lines; the reasons a month cannot be paid yet are for HR and Finance.
            'problems' => $staff ? $statement['problems'] : [], 'staleMarks' => $staff ? $statement['staleMarks'] : [], 'payment' => $payment, 'fingerprint' => $staff ? $statement['fingerprint'] : null];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function rateRow(\stdClass $r, User $actor, bool $canManage, bool $canFinance, array &$names): array
    {
        $sen = LocumPayStatement::decryptSen((string) $r->amount_encrypted);
        $own = (int) $r->entered_by_user_id === $actor->id;

        return ['id' => (string) $r->public_id, ...($sen === null ? ['kindLabel' => self::KINDS[$r->kind] ?? 'Rate', 'amountLabel' => 'Cannot be read'] : $this->rateLabel((string) $r->kind, $sen)),
            'fromLabel' => $this->day(substr((string) $r->effective_from, 0, 10)), 'status' => (string) $r->status,
            'statusLabel' => match ((string) $r->status) {
                'waiting' => 'Waiting for confirmation', 'confirmed' => 'Confirmed', 'rejected' => 'Not confirmed', default => 'Withdrawn'
            },
            'rejectReason' => $r->reject_reason !== null ? (self::REJECT_REASONS[$r->reject_reason] ?? 'Other') : null,
            'enteredBy' => $this->name((int) $r->entered_by_user_id, $names),
            'decidedBy' => $r->decided_by_user_id !== null ? $this->name((int) $r->decided_by_user_id, $names) : null,
            'lockVersion' => (int) $r->lock_version,
            'canDecide' => $canFinance && ! $own, 'ownEntry' => $own, 'canWithdraw' => $canManage];
    }

    /**
     * The total, or "RM —" while a shift has no confirmed rate: a missing rate is unknown, never RM 0.00 (Daniel, 1).
     *
     * @param  array<string, mixed>  $statement
     */
    private static function totalLabel(array $statement): string
    {
        return self::rateMissing($statement) ? 'RM —' : LocumPayStatement::rm((int) $statement['total']);
    }

    /** @param array<string, mixed> $statement */
    private static function rateMissing(array $statement): bool
    {
        if ($statement['paid']) {
            return false;
        }
        foreach ((array) $statement['lines'] as $line) {
            if (is_array($line) && ($line['noRate'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @return array{kind: string, kindLabel: string, amountLabel: string} */
    private function rateLabel(string $kind, int $sen): array
    {
        return ['kind' => $kind, 'kindLabel' => self::KINDS[$kind] ?? 'Rate',
            'amountLabel' => LocumPayStatement::rm($sen).($kind === 'hour' ? ' an hour' : ' a session')];
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $map): array
    {
        return array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys($map), $map);
    }

    private function day(string $date): string
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), 'UTC');

        return $d === null ? $date : $d->format('j M Y');
    }

    /** @param array<int, string> $names */
    private function name(int $userId, array &$names): string
    {
        return $names[$userId] ??= (string) (User::query()->whereKey($userId)->value('name') ?? 'Staff member');
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }
}

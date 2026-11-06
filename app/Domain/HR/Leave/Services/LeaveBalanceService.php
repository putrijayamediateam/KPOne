<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-2a balances, all in half days. Left = the ledger sum for the year (grants, carry-forward, confirmed openings and
 * adjustments, taken, reversals and expiry) minus pending requests. Taken = approved leave already started; booked =
 * pending leave plus approved leave not started yet; so entitled − taken − booked = left.
 */
final class LeaveBalanceService
{
    public function ledgerSum(int $userId, int $typeId, int $year): int
    {
        return (int) DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $typeId)->where('year', $year)->sum('halves');
    }

    public function pendingHalves(int $userId, int $typeId, int $year, ?int $exceptRequestId = null): int
    {
        return (int) DB::table('hr_leave_requests')->where('user_id', $userId)->where('leave_type_id', $typeId)->where('year', $year)
            ->where('status', 'pending')->when($exceptRequestId !== null, fn ($q) => $q->where('id', '<>', $exceptRequestId))->sum('halves');
    }

    public function left(int $userId, int $typeId, int $year, ?int $exceptRequestId = null): int
    {
        return $this->ledgerSum($userId, $typeId, $year) - $this->pendingHalves($userId, $typeId, $year, $exceptRequestId);
    }

    /**
     * Refuses a spend that would take a balance type below zero, unless the type allows it. The caller holds the
     * person's account row lock, so two spends of the same last days are serialised.
     */
    public function assertAffordable(LeaveType $type, int $userId, int $year, int $halves, ?int $exceptRequestId = null, string $field = 'start_date'): void
    {
        if (! $type->uses_balance || $type->allow_negative) {
            return;
        }
        $left = $this->left($userId, $type->id, $year, $exceptRequestId);
        if ($left - $halves < 0) {
            $needs = LeaveSupport::days($halves);
            throw ValidationException::withMessages([$field => "Not enough leave left: this request needs {$needs} ".($needs === '1' ? 'day' : 'days').' and you have '.LeaveSupport::days(max($left, 0)).'.']);
        }
    }

    /**
     * Once a year's unused days were carried forward for a person and type, that year is closed for new spending, so
     * the same days can never be used in both years (Suresh, HR-2a review).
     */
    public function assertYearOpen(LeaveType $type, int $userId, int $year, string $field = 'start_date'): void
    {
        if ($type->uses_balance && DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $type->id)->where('year', $year + 1)->where('kind', 'carry_forward')->exists()) {
            throw ValidationException::withMessages([$field => "{$type->name} for {$year} is closed because its unused days were carried forward. Ask HR."]);
        }
    }

    /**
     * @param  iterable<LeaveType>  $types
     * @return list<array{typeId:int, code:string, name:string, counted:bool, entitled:string, taken:string, booked:string, left:string, leftHalves:int}>
     */
    public function summary(User $person, iterable $types, int $year, CarbonImmutable $today): array
    {
        $ledger = DB::table('hr_leave_ledger')->where('user_id', $person->id)->where('year', $year)
            ->selectRaw("leave_type_id, sum(halves) as total, sum(CASE WHEN kind IN ('taken', 'reversal') THEN halves ELSE 0 END) as spent")
            ->groupBy('leave_type_id')->get()->keyBy('leave_type_id');
        $requests = DB::table('hr_leave_requests')->where('user_id', $person->id)->where('year', $year)->whereIn('status', ['pending', 'approved'])
            ->get(['leave_type_id', 'status', 'start_date', 'halves'])->groupBy('leave_type_id');
        $out = [];
        foreach ($types as $type) {
            $rows = $requests->get($type->id, collect());
            $taken = $booked = $pending = 0;
            foreach ($rows as $r) {
                $started = substr((string) $r->start_date, 0, 10) <= $today->toDateString();
                if ($r->status === 'pending') {
                    $pending += (int) $r->halves;
                    $booked += (int) $r->halves;
                } elseif ($started) {
                    $taken += (int) $r->halves;
                } else {
                    $booked += (int) $r->halves;
                }
            }
            $total = (int) ($ledger->get($type->id)->total ?? 0);
            $spent = (int) ($ledger->get($type->id)->spent ?? 0);
            $left = $total - $pending;
            $out[] = ['typeId' => $type->id, 'code' => $type->code, 'name' => $type->name, 'counted' => $type->uses_balance,
                'entitled' => LeaveSupport::days($total - $spent), 'taken' => LeaveSupport::days($taken), 'booked' => LeaveSupport::days($booked),
                'left' => LeaveSupport::days($left), 'leftHalves' => $left];
        }

        return $out;
    }
}

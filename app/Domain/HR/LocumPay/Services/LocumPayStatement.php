<?php

namespace App\Domain\HR\LocumPay\Services;

use App\Domain\HR\Payroll\Services\PayrollMoney;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-15g: one locum's statement for one month, read only. A paid month returns the statement stored when it was paid;
 * an unpaid month is worked out now from the locum's approved roster shifts in that branch-local month:
 *
 * - per hour: the shift's hours after its break × the hourly rate, rounded half up to the sen (owner's (a));
 * - per session: the session rate, once per approved shift;
 * - the rate is the confirmed rate in force on the shift's own local date (the latest effective date on or before it);
 * - a shift marked "Not worked" pays nothing; a shift with no confirmed rate pays nothing and blocks marking the month paid;
 * - corrections are added as signed lines.
 *
 * All arithmetic is in whole sen with integers only.
 */
final class LocumPayStatement
{
    public const REASONS = [
        'did_not_come' => 'Did not come',
        'cancelled_by_clinic' => 'Cancelled by the clinic',
        'covered_by_other' => 'Covered by someone else',
        'other' => 'Other',
    ];

    public const ADJUSTMENT_REASONS = [
        'earlier_month' => 'Correction for an earlier month',
        'extra_payment' => 'Extra payment',
        'deduction' => 'Deduction',
        'other' => 'Other',
    ];

    /** The account linked to a locum file through an HR-15a sign-in, if any. */
    public static function linkedUserId(int $workerId): ?int
    {
        $id = DB::table('hr_locum_sign_ins')->where('worker_id', $workerId)->where('status', 'done')->whereNotNull('linked_user_id')
            ->orderByDesc('id')->value('linked_user_id');

        return $id === null ? null : (int) $id;
    }

    /** The locum file whose sign-in is this account, if any. */
    public static function workerForUser(int $organisationId, int $userId): ?\stdClass
    {
        $row = DB::table('hr_workers as w')->join('hr_locum_sign_ins as l', 'l.worker_id', '=', 'w.id')
            ->where('w.organisation_id', $organisationId)->where('w.kind', 'locum')
            ->where('l.status', 'done')->where('l.linked_user_id', $userId)
            ->orderByDesc('l.id')->first(['w.id', 'w.public_id', 'w.display_name']);

        return $row instanceof \stdClass ? $row : null;
    }

    public static function decryptSen(?string $encrypted, bool $signed = false): ?int
    {
        if ($encrypted === null) {
            return null;
        }
        try {
            $value = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }

        return preg_match($signed ? '/\A-?[0-9]{1,9}\z/' : '/\A[0-9]{1,9}\z/', $value) === 1 ? (int) $value : null;
    }

    /** "RM 1,234.56", with a minus sign before RM for a negative amount. */
    public static function rm(int $sen): string
    {
        return ($sen < 0 ? '−' : '').PayrollMoney::rm(abs($sen));
    }

    /**
     * The confirmed rates of a locum, newest effective date first.
     *
     * @return list<array{id: int, kind: string, sen: int|null, from: string}>
     */
    public static function confirmedRates(int $workerId): array
    {
        return array_values(DB::table('hr_locum_pay_rates')->where('worker_id', $workerId)->where('status', 'confirmed')
            ->orderByDesc('effective_from')->orderByDesc('decided_at')->orderByDesc('id')->get()
            ->map(fn (object $r): array => ['id' => (int) $r->id, 'kind' => (string) $r->kind, 'sen' => self::decryptSen((string) $r->amount_encrypted),
                'from' => substr((string) $r->effective_from, 0, 10)])->all());
    }

    /**
     * @param  list<array{id: int, kind: string, sen: int|null, from: string}>  $rates
     * @return array{id: int, kind: string, sen: int|null, from: string}|null
     */
    public static function rateOn(array $rates, string $date): ?array
    {
        foreach ($rates as $rate) {
            if ($rate['from'] <= $date) {
                return $rate;
            }
        }

        return null;
    }

    /** Hours after the break × the hourly rate, rounded half up to the sen. */
    public static function hourly(int $minutes, int $ratePerHour): int
    {
        return intdiv($minutes * $ratePerHour + 30, 60);
    }

    /**
     * @return array{
     *   paid: bool, month: string, total: int, lines: list<array<string, mixed>>, corrections: list<array<string, mixed>>,
     *   problems: list<string>, staleMarks: list<array<string, mixed>>, payment: array<string, mixed>|null, fingerprint: string
     * }
     */
    public static function build(int $organisationId, int $workerId, string $month): array
    {
        $paid = DB::table('hr_locum_pay_months')->where('organisation_id', $organisationId)->where('worker_id', $workerId)->where('month', $month)->first();
        if ($paid !== null) {
            $stored = null;
            try {
                $stored = json_decode(Crypt::decryptString((string) $paid->statement_encrypted), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $stored = null;
            }
            $statement = is_array($stored) ? $stored : ['lines' => [], 'corrections' => [], 'total' => self::decryptSen((string) $paid->total_encrypted, true) ?? 0];

            return ['paid' => true, 'month' => $month, 'total' => (int) ($statement['total'] ?? 0), 'lines' => $statement['lines'] ?? [],
                'corrections' => $statement['corrections'] ?? [], 'problems' => [], 'staleMarks' => [],
                'payment' => ['paidOn' => substr((string) $paid->paid_on, 0, 10), 'reference' => $paid->reference_last4 !== null ? '•••• '.$paid->reference_last4 : null,
                    'paidBy' => (int) $paid->paid_by_user_id, 'recordedAt' => (string) $paid->recorded_at],
                'fingerprint' => 'paid'];
        }

        $lines = [];
        $problems = [];
        $staleMarks = [];
        $total = 0;
        $userId = self::linkedUserId($workerId);
        $rates = self::confirmedRates($workerId);
        $exclusions = DB::table('hr_locum_pay_exclusions')->where('worker_id', $workerId)->where('month', $month)->whereNull('removed_at')
            ->orderBy('id')->get()->keyBy('roster_shift_id');
        if ($userId !== null) {
            $start = CarbonImmutable::parse($month.' 00:00:00', 'UTC');
            // A wide UTC window, then the shift's own local month decides (a shift belongs to the month it starts in).
            $shifts = DB::table('hr_roster_shifts as s')->join('hr_roster_versions as v', 'v.id', '=', 's.roster_version_id')
                ->join('branches as b', 'b.id', '=', 's.branch_id')
                ->where('s.organisation_id', $organisationId)->where('s.staff_user_id', $userId)->where('v.status', 'approved')
                ->where('s.starts_at', '>=', $start->subDays(2))->where('s.starts_at', '<', $start->addMonth()->addDays(2))
                ->orderBy('s.starts_at')->orderBy('s.id')
                ->get(['s.id', 's.public_id', 's.starts_at', 's.ends_at', 's.timezone', 's.break_minutes', 's.session_type', 'b.name as branch']);
            foreach ($shifts as $shift) {
                $starts = CarbonImmutable::parse((string) $shift->starts_at)->setTimezone((string) $shift->timezone);
                if ($starts->format('Y-m-01') !== $month) {
                    continue;
                }
                $ends = CarbonImmutable::parse((string) $shift->ends_at)->setTimezone((string) $shift->timezone);
                $minutes = max(0, intdiv($ends->getTimestamp() - $starts->getTimestamp(), 60) - (int) $shift->break_minutes);
                $date = $starts->format('Y-m-d');
                $rate = self::rateOn($rates, $date);
                $exclusion = $exclusions->pull($shift->id);
                $line = ['shiftId' => (string) $shift->public_id, 'date' => $date, 'dateLabel' => $starts->format('D j M'),
                    'time' => $starts->format('g:i A').'–'.$ends->format('g:i A').($ends->format('Y-m-d') !== $date ? ' (next day)' : ''),
                    'branch' => (string) $shift->branch, 'minutes' => $minutes, 'hours' => self::hoursLabel($minutes),
                    'basis' => null, 'amount' => 0, 'notWorked' => null, 'noRate' => false];
                if ($exclusion !== null) {
                    $line['notWorked'] = ['id' => (string) $exclusion->public_id, 'reason' => self::REASONS[(string) $exclusion->reason] ?? 'Other',
                        'lockVersion' => (int) $exclusion->lock_version];
                } elseif ($rate === null || $rate['sen'] === null) {
                    $line['noRate'] = true;
                    $problems[] = "No confirmed rate on {$starts->format('j M')}: confirm a rate that starts on or before this date, or mark the shift not worked.";
                } else {
                    $line['amount'] = $rate['kind'] === 'hour' ? self::hourly($minutes, $rate['sen']) : $rate['sen'];
                    $line['basis'] = $rate['kind'] === 'hour' ? self::hoursLabel($minutes).' × '.self::rm($rate['sen']).' an hour' : 'One session at '.self::rm($rate['sen']);
                    $total += $line['amount'];
                }
                // A missing rate is unknown, never RM 0.00 (Daniel, 1).
                $line['amountLabel'] = $line['noRate'] ? 'RM —' : self::rm($line['amount']);
                $lines[] = $line;
            }
        }

        // A mark whose shift is no longer in an approved week (the week was changed and approved again, which copies
        // every shift to a new row) must not quietly fall away: the copied shift would be paid (Suresh, M1).
        foreach ($exclusions as $stale) {
            $when = DB::table('hr_roster_shifts')->where('id', $stale->roster_shift_id)->first(['starts_at', 'timezone']);
            $label = $when === null ? 'an earlier date'
                : CarbonImmutable::parse((string) $when->starts_at)->setTimezone((string) $when->timezone)->format('j M');
            $problems[] = "A shift marked not worked on {$label} was changed in the roster. Remove the old mark, then mark the shift again if it was still not worked.";
            $staleMarks[] = ['id' => (string) $stale->public_id, 'dateLabel' => $label, 'reason' => self::REASONS[(string) $stale->reason] ?? 'Other',
                'lockVersion' => (int) $stale->lock_version];
        }

        $corrections = [];
        foreach (DB::table('hr_locum_pay_adjustments')->where('worker_id', $workerId)->where('month', $month)->whereNull('removed_at')->orderBy('id')->get() as $row) {
            $sen = self::decryptSen((string) $row->amount_encrypted, true);
            if ($sen === null) {
                $problems[] = 'A saved correction could not be read. Ask the technical team.';

                continue;
            }
            $total += $sen;
            $corrections[] = ['id' => (string) $row->public_id, 'reason' => self::ADJUSTMENT_REASONS[(string) $row->reason] ?? 'Other',
                'forMonth' => $row->for_month !== null ? CarbonImmutable::parse((string) $row->for_month)->format('F Y') : null,
                'amount' => $sen, 'amountLabel' => self::rm($sen), 'lockVersion' => (int) $row->lock_version];
        }
        if ($total < 0) {
            $problems[] = 'The total is below RM 0.00. Move part of the deduction to a later month.';
        }
        if ($lines === [] && $corrections === []) {
            $problems[] = 'Nothing to pay this month.';
        }

        $fingerprint = hash('sha256', json_encode([$total, $lines, $corrections, $staleMarks], JSON_THROW_ON_ERROR));

        return ['paid' => false, 'month' => $month, 'total' => $total, 'lines' => $lines, 'corrections' => $corrections,
            'problems' => array_values(array_unique($problems)), 'staleMarks' => $staleMarks, 'payment' => null, 'fingerprint' => $fingerprint];
    }

    public static function hoursLabel(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h} h" : "{$h} h {$m} min";
    }
}

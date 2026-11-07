<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Attendance\Services\AttendanceTotals;
use App\Domain\HR\Records\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-7b run calculation (HR-PLAN 16.2 to 16.4). Read only: it gathers the month's approved records and works out each
 * person's lines, errors and warnings in whole sen, rounding each line once (PayrollMath). It never writes; the run
 * service stores what it returns. Managers' own pay and locums stay outside KPOne in v1.
 */
final class PayrollRunCalculator
{
    public const ERRORS = [
        'mtd_missing' => 'MTD not entered',
        'no_mtd_schedule' => 'No checked MTD schedule for this year',
        'no_mtd_band' => 'No MTD band for this chargeable income',
        'mtd_one_off' => 'One-off pay on this run counts for MTD: type the MTD for this person',
        'mtd_earlier_open' => 'An earlier month this year is not locked yet: lock it and prepare again, or type the MTD',
        'no_rates' => 'No rate settings for overtime or unpaid leave',
        'no_type_overtime' => 'No pay item type chosen for overtime',
        'no_type_unpaid_leave' => 'No pay item type chosen for unpaid leave',
        'no_type_claim' => 'No pay item type chosen for staff claim refunds',
        'net_negative' => 'Net pay below zero',
        'unreadable_amount' => 'A saved amount could not be read. Check the pay profile',
    ];

    public const WARNINGS = [
        'bank_missing' => 'No confirmed bank details',
        'bank_waiting' => 'Bank details waiting for confirmation',
        'open_clock' => 'A clock-in has no clock-out',
        'old_claim' => 'A staff claim is older than 90 days',
        'leave_crosses_month' => 'Unpaid leave crosses into another month; counted in its start month',
        'no_period' => 'No employment period recorded; counted for the whole month',
        'profile_waiting' => 'A pay profile change is waiting for confirmation',
        'net_negative_adjustment' => 'This correction takes money back: net below zero',
        'mtd_typed' => 'MTD typed for this run instead of the worked-out figure',
        'mtd_tp3' => 'Joined this year: pay from a previous employer (TP3) is not counted in MTD',
        'mtd_not_worked' => 'MTD could not be worked out for this run; the typed figure is used',
    ];

    public const SCHEME_LABELS = ['epf' => 'EPF', 'socso' => 'SOCSO', 'eis' => 'EIS', 'hrd' => 'HRD levy'];

    /** Set when a saved amount for the person being worked out could not be read, so it is never silently RM 0. */
    private bool $unreadable = false;

    private string $kind = 'regular';

    public function __construct(private AttendanceTotals $attendance) {}

    /**
     * @param  array<int, int>  $mtd  sen per worker id
     * @return array{people: array<int, array{workerId:int, profileId:?int, lines:list<array<string, mixed>>, errors:list<string>, warnings:list<string>, totals:array<string,int>}>, versions: array<string, mixed>, fingerprint: string}
     */
    public function calculate(int $org, \stdClass $run, array $mtd): array
    {
        $this->kind = (string) $run->kind;
        $this->unreadable = false;
        $start = CarbonImmutable::parse((string) $run->month)->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $from = $start->toDateString();
        $to = $end->toDateString();
        $days = (int) $end->format('j');
        $types = $this->itemTypes($org);
        $lineTypes = DB::table('hr_payroll_line_types')->where('organisation_id', $org)->orderByDesc('version')->first();
        $rates = DB::table('hr_rate_settings')->where('organisation_id', $org)->orderByDesc('version')->first();
        $versions = ['rates' => $rates?->version === null ? null : (int) $rates->version, 'lineTypes' => $lineTypes?->version === null ? null : (int) $lineTypes->version,
            'group' => ['name' => (string) $run->group_name, 'payDay' => $run->group_pay_day === null ? null : (int) $run->group_pay_day],
            'profiles' => [], 'tables' => [], 'itemTypes' => [], 'mtdSchedule' => null];
        $people = [];

        if ($run->kind === 'adjustment') {
            foreach (DB::table('hr_payroll_manual_lines')->where('run_id', $run->id)->orderBy('id')->get()->groupBy('worker_id') as $workerId => $manual) {
                $lines = [];
                foreach ($manual as $m) {
                    $lines[] = $this->manual($m, $types, $versions);
                }
                $people[(int) $workerId] = $this->finish((int) $workerId, null, $lines, [], [], []);
            }

            return $this->result($people, $versions);
        }

        $workers = Worker::query()->with('user.roles')->where('organisation_id', $org)->where('kind', 'staff')->get()
            ->reject(fn (Worker $w): bool => PayrollSetupService::isManager($w))->keyBy('id');
        $profiles = DB::table('hr_pay_profiles')->where('organisation_id', $org)->whereIn('worker_id', $workers->keys()->all())->where('status', 'confirmed')
            ->where('effective_from', '<=', $to)->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('worker_id')
            ->map(fn (Collection $c): \stdClass => $c->first())->filter(fn (\stdClass $p): bool => (int) $p->pay_group_id === (int) $run->pay_group_id);
        $waiting = DB::table('hr_pay_profiles')->where('organisation_id', $org)->where('status', 'waiting')->pluck('worker_id')->map(fn ($v) => (int) $v)->all();
        $tables = $this->tables($org, $to);
        $year = (int) $start->format('Y');
        $schedule = $this->mtdSchedule($org, $year);
        $yearToDate = $this->mtdYearToDate($org, $run, $types, array_values($profiles->keys()->map(fn ($v): int => (int) $v)->all()));
        $openEarlier = $this->openEarlierThisYear($org, $run);
        $periods = DB::table('hr_employment_periods')->whereIn('worker_id', $profiles->keys()->all())->get()->groupBy('worker_id');

        foreach ($profiles as $workerId => $profile) {
            $worker = $workers->get($workerId);
            if (! $worker instanceof Worker || $worker->user_id === null) {
                continue;
            }
            $errors = [];
            $warnings = in_array((int) $workerId, $waiting, true) ? ['profile_waiting'] : [];
            $employed = $this->daysEmployed($periods->get($workerId, collect()), $from, $to, $warnings);
            if ($employed === 0) {
                continue;
            }
            $versions['profiles'][] = (int) $profile->id;
            $rate = (int) $this->decrypt((string) $profile->rate_encrypted);
            $hourly = $profile->pay_type === 'hourly';
            $lines = [];
            if (! $hourly) {
                $basic = $employed === $days ? $rate : PayrollMath::fraction([$rate, $employed], $days, PayrollMath::DOWN);
                $lines[] = $this->line('basic', 'earnings', $basic, $employed === $days ? 'Basic salary' : "Basic salary · {$employed} of {$days} days employed",
                    'profile', (int) $profile->id);
            } else {
                $worked = $this->attendance->worked($org, [(int) $worker->user_id], $start, $end)[(int) $worker->user_id]['minutes'] ?? 0;
                $lines[] = $this->line('hourly', 'earnings', PayrollMath::fraction([$rate, $worked], 60), 'Worked hours · '.self::duration($worked).' × '.PayrollMoney::rm($rate).' an hour',
                    'profile', (int) $profile->id);
            }
            foreach (DB::table('hr_pay_profile_items')->where('profile_id', $profile->id)->orderBy('position')->get() as $item) {
                $amount = (int) $this->decrypt((string) $item->amount_encrypted);
                $amount = $employed === $days ? $amount : PayrollMath::fraction([$amount, $employed], $days, PayrollMath::DOWN);
                $type = $types->get($item->item_type_id.':'.$item->item_type_version);
                $versions['itemTypes'][] = $item->item_type_id.':'.$item->item_type_version;
                $lines[] = $this->line('allowance', 'earnings', $amount, (string) ($type->name ?? 'Allowance'), 'profile', (int) $profile->id,
                    (int) $item->item_type_id, (int) $item->item_type_version);
            }

            // Overtime approved in the month, at the multiplier stored on its approval.
            $overtime = DB::table('hr_overtime_requests')->where('organisation_id', $org)->where('user_id', $worker->user_id)->where('status', 'approved')
                ->whereBetween('local_date', [$from, $to])->orderBy('local_date')->orderBy('id')->get();
            if ($overtime->isNotEmpty()) {
                $type = $this->lineType($lineTypes, 'overtime_type_id', $types, $errors, 'no_type_overtime', $versions);
                if (! $hourly && $rates === null) {
                    $errors[] = 'no_rates';
                }
                foreach ($overtime as $o) {
                    $minutes = (int) $o->minutes_approved;
                    $multiplier = PayrollMath::hundredths((string) $o->multiplier);
                    $amount = $hourly ? PayrollMath::fraction([$rate, $minutes, $multiplier], 6000)
                        : ($rates === null ? 0 : PayrollMath::fraction([$rate, 10000, $minutes, $multiplier],
                            (int) $rates->daily_divisor_hundredths * (int) $rates->hourly_divisor_hundredths * 60 * 100));
                    $lines[] = $this->line('overtime', 'earnings', $amount, 'Overtime · '.self::duration($minutes).' × '.self::multiplier($multiplier).' ('
                        .str_replace('_', ' ', (string) $o->day_type).', '.CarbonImmutable::parse((string) $o->local_date)->format('j M').')', 'overtime', (int) $o->id,
                        $type['id'], $type['version']);
                }
            }

            // Approved leave on unpaid types, starting in the month (monthly staff only; hourly staff are paid on hours).
            if (! $hourly) {
                $leave = DB::table('hr_leave_requests as r')->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
                    ->where('r.organisation_id', $org)->where('r.user_id', $worker->user_id)->where('r.status', 'approved')->where('t.is_paid', false)
                    ->whereBetween('r.start_date', [$from, $to])->orderBy('r.start_date')->get(['r.id', 'r.start_date', 'r.end_date', 'r.halves']);
                if ($leave->isNotEmpty()) {
                    $type = $this->lineType($lineTypes, 'unpaid_leave_type_id', $types, $errors, 'no_type_unpaid_leave', $versions);
                    if ($rates === null) {
                        $errors[] = 'no_rates';
                    }
                    foreach ($leave as $l) {
                        if ((string) $l->end_date > $to) {
                            $warnings[] = 'leave_crosses_month';
                        }
                        $halves = (int) $l->halves;
                        $amount = $rates === null ? 0 : PayrollMath::fraction([$rate, 100, $halves], (int) $rates->daily_divisor_hundredths * 2);
                        $lines[] = $this->line('unpaid_leave', 'deduction', $amount, 'Unpaid leave · '.self::halves($halves).' from '
                            .CarbonImmutable::parse((string) $l->start_date)->format('j M'), 'leave', (int) $l->id, $type['id'], $type['version']);
                    }
                }
            }

            // Staff claims approved and not yet paid.
            $claims = DB::table('hr_staff_claims')->where('organisation_id', $org)->where('claimant_user_id', $worker->user_id)->where('status', 'approved')
                ->orderBy('id')->get(['id', 'number', 'amount_sen', 'spent_on']);
            if ($claims->isNotEmpty()) {
                $type = $this->lineType($lineTypes, 'claim_type_id', $types, $errors, 'no_type_claim', $versions);
                foreach ($claims as $c) {
                    if ((string) $c->spent_on < $end->subDays(90)->toDateString()) {
                        $warnings[] = 'old_claim';
                    }
                    $lines[] = $this->line('claim', 'refund', (int) $c->amount_sen, "Staff claim {$c->number} refund", 'claim', (int) $c->id, $type['id'], $type['version']);
                }
            }

            foreach (DB::table('hr_payroll_manual_lines')->where('run_id', $run->id)->where('worker_id', $workerId)->orderBy('id')->get() as $m) {
                $lines[] = $this->manual($m, $types, $versions);
            }

            // Statutory parts on the scheme wage (16.3 items 3 to 5). Basic always counts.
            foreach (['epf', 'socso', 'eis', 'hrd'] as $scheme) {
                if (! $profile->{$scheme}) {
                    continue;
                }
                $wage = max(0, self::schemeWage($lines, $types, $scheme));
                $category = in_array($scheme, ['epf', 'socso'], true) ? mb_strtolower((string) $profile->{"{$scheme}_category"}) : null;
                $candidates = ($tables[$scheme] ?? collect())->filter(fn (array $t): bool => $category === null || mb_strtolower($t['category']) === $category);
                if ($candidates->isEmpty()) {
                    $errors[] = "no_table_{$scheme}";

                    continue;
                }
                if ($category === null && $candidates->count() > 1) {
                    $errors[] = "two_tables_{$scheme}";

                    continue;
                }
                $table = $candidates->first();
                $versions['tables'][] = $table['id'];
                $band = collect($table['rows'])->first(fn (\stdClass $r): bool => $wage >= (int) $r->wage_from_sen && ($r->wage_to_sen === null || $wage <= (int) $r->wage_to_sen));
                if (! $band instanceof \stdClass) {
                    $errors[] = "no_band_{$scheme}";

                    continue;
                }
                $label = self::SCHEME_LABELS[$scheme];
                foreach (['employee', 'employer'] as $part) {
                    $kind = (string) $band->{"{$part}_kind"};
                    $value = (int) $band->{"{$part}_value"};
                    $amount = $kind === 'rate' ? PayrollMath::fraction([$wage, $value], 10000, PayrollMath::forTable($table['rounding'])) : $value;
                    $how = $kind === 'rate' ? PayrollMoney::percent($value).' of '.PayrollMoney::rm($wage).' ('.strtolower(PayrollSetupService::ROUNDING[$table['rounding']] ?? '').')'
                        : 'fixed for wages '.PayrollMoney::rm((int) $band->wage_from_sen).($band->wage_to_sen === null ? ' and above' : ' to '.PayrollMoney::rm((int) $band->wage_to_sen));
                    $lines[] = $this->line($part, $part, $amount, "{$label} ".($part === 'employee' ? 'employee' : 'employer').' · '.$how, 'table', $table['id'], null, null, $scheme);
                }
            }

            if ($profile->mtd) {
                // HR-7d: worked out by KPOne when the profile has MTD details (HR-PLAN 18.4). Anything the method cannot see
                // stops the worked figure and asks for a typed one; a typed MTD always counts (Suresh, S1, S2, M1).
                $worked = null;
                $typed = array_key_exists((int) $workerId, $mtd);
                $stop = null;
                if (($profile->mtd_category ?? null) !== null) {
                    $oneOff = self::schemeWage(array_values(array_filter($lines, fn (array $l): bool => $l['kind'] === 'manual')), $types, 'mtd');
                    if ($schedule === null) {
                        $stop = 'no_mtd_schedule';
                    } elseif ($oneOff !== 0) {
                        $stop = 'mtd_one_off'; // a manual line is one-off pay (additional remuneration), never projected over the year
                    } elseif (in_array((int) $workerId, $openEarlier, true)) {
                        $stop = 'mtd_earlier_open';
                    } else {
                        $versions['mtdSchedule'] = $schedule['id'];
                        $epf = 0;
                        foreach ($lines as $line) {
                            $epf += $line['kind'] === 'employee' && $line['scheme'] === 'epf' ? (int) $line['amount'] : 0;
                        }
                        $ytd = $yearToDate[(int) $workerId] ?? ['y' => 0, 'k' => 0, 'x' => 0];
                        $worked = MtdCalculator::month($schedule['values'], $schedule['bands'],
                            ['category' => (int) $profile->mtd_category, 'children' => (int) $profile->mtd_children,
                                'disabled_self' => (bool) $profile->mtd_disabled_self, 'disabled_spouse' => (bool) $profile->mtd_disabled_spouse],
                            ['y' => $ytd['y'], 'k' => $ytd['k'], 'x' => $ytd['x'], 'y1' => self::schemeWage($lines, $types, 'mtd'), 'k1' => $epf], (int) $start->format('n'));
                        if ($worked === null) {
                            $stop = 'no_mtd_band';
                        }
                    }
                    if ($this->joinedThisYear($periods->get($workerId, collect()), $year)) {
                        $warnings[] = 'mtd_tp3';
                    }
                    if ($stop !== null) {
                        if ($typed) {
                            $warnings[] = 'mtd_not_worked';
                        } else {
                            $errors[] = $stop;
                        }
                    }
                }
                $how = $worked === null ? null : PayrollMoney::rm($worked['chargeable']).' chargeable for the year, category '.(int) $profile->mtd_category
                    .', '.$worked['monthsLeft'].' '.($worked['monthsLeft'] === 1 ? 'month' : 'months').' left';
                if ($typed) {
                    if ($worked !== null) {
                        $warnings[] = 'mtd_typed';
                    }
                    $lines[] = $this->line('mtd', 'mtd', $mtd[(int) $workerId], 'MTD (PCB) · entered for this run'
                        .($worked === null ? '' : ' (worked out: '.PayrollMoney::rm($worked['amount']).')'), 'profile', (int) $profile->id);
                } elseif ($worked !== null) {
                    $lines[] = $this->line('mtd', 'mtd', $worked['amount'], 'MTD (PCB) · worked out: '.$how, 'mtd_schedule', $schedule['id'] ?? null);
                } elseif (($profile->mtd_category ?? null) === null) {
                    $errors[] = 'mtd_missing';
                }
            }
            $bank = DB::table('hr_bank_details')->where('worker_id', $workerId)->whereIn('status', ['confirmed', 'waiting'])->pluck('status')->all();
            if (! in_array('confirmed', $bank, true)) {
                $warnings[] = 'bank_missing';
            }
            if (in_array('waiting', $bank, true)) {
                $warnings[] = 'bank_waiting';
            }
            if ($hourly && DB::table('hr_clock_sessions')->where('user_id', $worker->user_id)->whereBetween('local_date', [$from, $to])->whereIn('status', ['open', 'missing_out'])->exists()) {
                $warnings[] = 'open_clock';
            }
            $people[(int) $workerId] = $this->finish((int) $workerId, (int) $profile->id, $lines, $errors, $warnings, $mtd);
        }

        return $this->result($people, $versions);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array<int, int>  $mtd
     * @return array{workerId:int, profileId:?int, lines:list<array<string, mixed>>, errors:list<string>, warnings:list<string>, totals:array<string,int>}
     */
    private function finish(int $workerId, ?int $profileId, array $lines, array $errors, array $warnings, array $mtd): array
    {
        $totals = ['earnings' => 0, 'deduction' => 0, 'refund' => 0, 'employee' => 0, 'employer' => 0, 'mtd' => 0];
        foreach ($lines as $line) {
            $totals[$line['section']] += $line['amount'];
        }
        $totals['net'] = $totals['earnings'] - $totals['deduction'] - $totals['employee'] - $totals['mtd'] + $totals['refund'];
        if ($totals['net'] < 0) {
            // An adjustment holds only differences, so a recovery is negative by nature (Suresh, S2).
            if ($this->kind === 'adjustment') {
                $warnings[] = 'net_negative_adjustment';
            } else {
                $errors[] = 'net_negative';
            }
        }
        if ($this->unreadable) {
            $errors[] = 'unreadable_amount';
            $this->unreadable = false;
        }

        return ['workerId' => $workerId, 'profileId' => $profileId, 'lines' => $lines, 'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)), 'totals' => $totals];
    }

    /**
     * @param  array<int, array{workerId:int, profileId:?int, lines:list<array<string, mixed>>, errors:list<string>, warnings:list<string>, totals:array<string,int>}>  $people
     * @param  array<string, mixed>  $versions
     * @return array{people: array<int, array{workerId:int, profileId:?int, lines:list<array<string, mixed>>, errors:list<string>, warnings:list<string>, totals:array<string,int>}>, versions: array<string, mixed>, fingerprint: string}
     */
    private function result(array $people, array $versions): array
    {
        foreach (['profiles', 'tables', 'itemTypes'] as $key) {
            $list = array_values(array_unique($versions[$key]));
            sort($list);
            $versions[$key] = $list;
        }
        ksort($people);

        return ['people' => $people, 'versions' => $versions, 'fingerprint' => hash('sha256', json_encode([$people, $versions], JSON_THROW_ON_ERROR))];
    }

    /**
     * @param  Collection<string, \stdClass>  $types
     * @param  array<string, mixed>  $versions
     * @return array<string, mixed>
     */
    private function manual(\stdClass $m, Collection $types, array &$versions): array
    {
        $type = $types->get($m->item_type_id.':'.$m->item_type_version);
        $section = match ($type->kind ?? 'earnings') {
            'deduction' => 'deduction', 'refund' => 'refund', default => 'earnings'
        };
        $versions['itemTypes'][] = $m->item_type_id.':'.$m->item_type_version;

        return $this->line('manual', $section, (int) $this->decrypt((string) $m->amount_encrypted), (string) ($type->name ?? 'Manual line').' · '
            .(PayrollRunService::MANUAL_REASONS[$m->reason_code] ?? (string) $m->reason_code), 'manual', (int) $m->id, (int) $m->item_type_id, (int) $m->item_type_version);
    }

    /** @return array<string, mixed> */
    private function line(string $kind, string $section, int $amount, string $explanation, ?string $sourceKind, ?int $sourceId, ?int $typeId = null,
        ?int $typeVersion = null, ?string $scheme = null): array
    {
        return ['kind' => $kind, 'section' => $section, 'amount' => $amount, 'explanation' => $explanation, 'sourceKind' => $sourceKind, 'sourceId' => $sourceId,
            'itemTypeId' => $typeId, 'itemTypeVersion' => $typeVersion, 'scheme' => $scheme];
    }

    /**
     * The pay item type a run uses for overtime, unpaid leave or claim refunds, at its current version.
     *
     * @param  Collection<string, \stdClass>  $types
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $versions
     * @return array{id: ?int, version: ?int}
     */
    private function lineType(?\stdClass $lineTypes, string $column, Collection $types, array &$errors, string $error, array &$versions): array
    {
        $id = $lineTypes?->{$column};
        if ($id === null) {
            $errors[] = $error;

            return ['id' => null, 'version' => null];
        }
        $current = DB::table('hr_pay_item_types')->where('id', $id)->value('current_version');
        $versions['itemTypes'][] = $id.':'.$current;

        return ['id' => (int) $id, 'version' => (int) $current];
    }

    /** @return Collection<string, \stdClass> every pay item type version, keyed "id:version" */
    private function itemTypes(int $org): Collection
    {
        return DB::table('hr_pay_item_type_versions')->where('organisation_id', $org)->get()->keyBy(fn (\stdClass $v): string => $v->item_type_id.':'.$v->version);
    }

    /**
     * Checked tables in force on the month's last day: for each scheme and category, the latest effective one.
     *
     * @return array<string, Collection<int, array{id:int, category:string, rounding:string, rows:list<\stdClass>}>>
     */
    private function tables(int $org, string $to): array
    {
        $out = [];
        $rows = DB::table('hr_statutory_table_rows')->where('organisation_id', $org)->orderBy('position')->get()->groupBy('table_id');
        foreach (DB::table('hr_statutory_tables')->where('organisation_id', $org)->where('status', 'checked')->where('effective_from', '<=', $to)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy(fn (\stdClass $t): string => $t->scheme.'|'.mb_strtolower((string) $t->category)) as $group) {
            $t = $group->first();
            $out[$t->scheme] ??= collect();
            $out[$t->scheme]->push(['id' => (int) $t->id, 'category' => (string) $t->category, 'rounding' => (string) $t->rounding,
                'rows' => $rows->get($t->id, collect())->values()->all()]);
        }

        return $out;
    }

    /**
     * The scheme wage of a person's lines (16.3): basic or worked hours always count, and a line of a pay item type
     * counts where that type is ticked for the scheme; a deduction counts against it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<string, \stdClass>  $types
     */
    private static function schemeWage(array $lines, Collection $types, string $scheme): int
    {
        $wage = 0;
        foreach ($lines as $line) {
            $counts = in_array($line['kind'], ['basic', 'hourly'], true)
                || ($line['itemTypeId'] !== null && (bool) ($types->get($line['itemTypeId'].':'.$line['itemTypeVersion'])->{"counts_{$scheme}"} ?? false));
            if ($counts) {
                $wage += $line['section'] === 'deduction' ? -(int) $line['amount'] : ($line['section'] === 'earnings' ? (int) $line['amount'] : 0);
            }
        }

        return (int) $wage;
    }

    /**
     * The checked MTD schedule for the year, with its bands (HR-PLAN 18.2), or null.
     *
     * @return array{id:int, values:array{rounding:string, minimum_sen:int, individual_relief_sen:int, spouse_relief_sen:int, disabled_self_relief_sen:int, disabled_spouse_relief_sen:int, child_relief_sen:int, epf_relief_cap_sen:int}, bands:list<array{from:int, to:?int, rate_bps:int, base_cat13:int, base_cat2:int}>}|null
     */
    private function mtdSchedule(int $org, int $year): ?array
    {
        $s = DB::table('hr_mtd_schedules')->where('organisation_id', $org)->where('year', $year)->where('status', 'checked')->first();
        if (! $s instanceof \stdClass) {
            return null;
        }
        $bands = DB::table('hr_mtd_schedule_rows')->where('schedule_id', $s->id)->orderBy('position')->get()
            ->map(fn (\stdClass $r): array => ['from' => (int) $r->chargeable_from_sen, 'to' => $r->chargeable_to_sen === null ? null : (int) $r->chargeable_to_sen,
                'rate_bps' => (int) $r->rate_bps, 'base_cat13' => (int) $r->base_cat13_sen, 'base_cat2' => (int) $r->base_cat2_sen])->values()->all();
        $bands = array_values($bands);
        $values = ['rounding' => (string) $s->rounding];
        foreach (['minimum_sen', 'individual_relief_sen', 'spouse_relief_sen', 'disabled_self_relief_sen', 'disabled_spouse_relief_sen', 'child_relief_sen', 'epf_relief_cap_sen'] as $k) {
            $values[$k] = (int) $s->{$k};
        }

        /** @var array{rounding:string, minimum_sen:int, individual_relief_sen:int, spouse_relief_sen:int, disabled_self_relief_sen:int, disabled_spouse_relief_sen:int, child_relief_sen:int, epf_relief_cap_sen:int} $values */
        return ['id' => (int) $s->id, 'values' => $values, 'bands' => $bands];
    }

    /**
     * Year to date from each person's lines in this year's locked runs before this run's month (HR-PLAN 18.4): the pay
     * that counts for MTD (Y), the EPF employee part (K) and the MTD already deducted (X). KPOne's own runs only.
     *
     * @param  Collection<string, \stdClass>  $types
     * @param  list<int>  $workerIds
     * @return array<int, array{y:int, k:int, x:int}>
     */
    private function mtdYearToDate(int $org, \stdClass $run, Collection $types, array $workerIds): array
    {
        $start = CarbonImmutable::parse((string) $run->month)->startOfMonth();
        $runs = DB::table('hr_payroll_runs')->where('organisation_id', $org)->where('status', 'locked')->where('id', '<>', $run->id)
            ->where('month', '>=', $start->startOfYear()->toDateString())->where('month', '<', $start->toDateString())->pluck('id')->all();
        if ($runs === [] || $workerIds === []) {
            return [];
        }
        $people = DB::table('hr_payroll_run_people')->whereIn('run_id', $runs)->whereIn('worker_id', $workerIds)->pluck('worker_id', 'id');
        $out = [];
        $byPerson = DB::table('hr_payroll_lines')->whereIn('run_person_id', $people->keys()->all())->orderBy('id')->get()->groupBy('run_person_id');
        foreach ($byPerson as $personId => $rows) {
            $workerId = (int) $people[$personId];
            $out[$workerId] ??= ['y' => 0, 'k' => 0, 'x' => 0];
            $lines = $rows->map(fn (\stdClass $l): array => ['kind' => (string) $l->kind, 'section' => (string) $l->section, 'amount' => (int) $this->decrypt((string) $l->amount_encrypted),
                'itemTypeId' => $l->item_type_id === null ? null : (int) $l->item_type_id, 'itemTypeVersion' => $l->item_type_version === null ? null : (int) $l->item_type_version,
                'scheme' => $l->scheme])->values()->all();
            $lines = array_values($lines);
            $out[$workerId]['y'] += self::schemeWage($lines, $types, 'mtd');
            foreach ($lines as $line) {
                $out[$workerId]['k'] += $line['kind'] === 'employee' && $line['scheme'] === 'epf' ? $line['amount'] : 0;
                $out[$workerId]['x'] += $line['kind'] === 'mtd' ? $line['amount'] : 0;
            }
        }

        return $out;
    }

    /**
     * Workers in a regular run of an earlier month this year that is not locked yet: their year to date would be
     * missing that month, so KPOne does not work their MTD out (Suresh, S2).
     *
     * @return list<int>
     */
    private function openEarlierThisYear(int $org, \stdClass $run): array
    {
        $start = CarbonImmutable::parse((string) $run->month)->startOfMonth();

        return array_values(DB::table('hr_payroll_run_people as p')->join('hr_payroll_runs as r', 'r.id', '=', 'p.run_id')->where('r.organisation_id', $org)
            ->where('r.kind', 'regular')->where('r.status', '<>', 'locked')->where('r.id', '<>', $run->id)
            ->where('r.month', '>=', $start->startOfYear()->toDateString())->where('r.month', '<', $start->toDateString())
            ->distinct()->pluck('p.worker_id')->map(fn ($v): int => (int) $v)->all());
    }

    /**
     * Whether the person's employment began this year after 1 January, so pay from a previous employer may be missing.
     *
     * @param  Collection<int, \stdClass>  $periods
     */
    private function joinedThisYear(Collection $periods, int $year): bool
    {
        $first = $periods->min('starts_on');

        return is_string($first) && $first > sprintf('%04d-01-01', $year) && $first <= sprintf('%04d-12-31', $year);
    }

    /**
     * Calendar days employed in the month, from HR-4 employment periods; the whole month, with a warning, when none is
     * recorded (16.3 item 2).
     *
     * @param  Collection<int, \stdClass>  $periods
     * @param  list<string>  $warnings
     */
    private function daysEmployed(Collection $periods, string $from, string $to, array &$warnings): int
    {
        $days = (int) CarbonImmutable::parse($to)->format('j');
        if ($periods->isEmpty()) {
            $warnings[] = 'no_period';

            return $days;
        }
        $covered = [];
        foreach ($periods as $p) {
            $s = max((string) $p->starts_on, $from);
            $e = min($p->ends_on === null ? $to : (string) $p->ends_on, $to);
            for ($d = CarbonImmutable::parse($s); $d->toDateString() <= $e; $d = $d->addDay()) {
                $covered[$d->toDateString()] = true;
            }
        }

        return count($covered);
    }

    private function decrypt(string $value): string
    {
        try {
            $plain = Crypt::decryptString($value);
        } catch (DecryptException) {
            $this->unreadable = true;

            return '0';
        }
        if (preg_match('/\A[0-9]{1,9}\z/', $plain) !== 1) {
            $this->unreadable = true;

            return '0';
        }

        return $plain;
    }

    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? "{$h} h " : '').($m > 0 || $h === 0 ? "{$m} min" : ''));
    }

    private static function multiplier(int $hundredths): string
    {
        return $hundredths % 100 === 0 ? (string) intdiv($hundredths, 100) : rtrim(intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT), '0');
    }

    private static function halves(int $halves): string
    {
        $days = intdiv($halves, 2);
        $half = $halves % 2 === 1;

        return ($days > 0 ? $days.($half ? '½' : '') : ($half ? '½' : '0')).($halves === 2 ? ' day' : ' days');
    }
}

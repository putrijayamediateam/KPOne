<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-2a leave settings (B.E.R.N.I.S.S for the owner, 2026-11-05). Everything is clinic-entered: KPOne ships no leave
 * types, entitlement figures, carry-forward rule, working days or holidays. The yearly grant, carry-forward and expiry
 * are manager actions, never a schedule; each writes ledger lines under the affected people's account locks.
 */
final class LeaveSettingsService
{
    private const TYPE_FIELDS = ['code', 'name', 'is_paid', 'uses_balance', 'allow_half_days', 'evidence_needed', 'allow_negative', 'carry_max_halves', 'carry_expires_on', 'is_active',
        'accepts_evidence_files', 'evidence_retention_days'];

    public function __construct(private LeaveSupport $support, private LeaveCalendar $calendar, private LeaveBalanceService $balances) {}

    /** @param array<string, mixed> $input */
    public function saveType(User $actor, array $input): LeaveType
    {
        return DB::transaction(function () use ($actor, $input): LeaveType {
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE);
            $id = filter_var($input['leave_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $type = $id === false ? new LeaveType : LeaveType::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($type->exists) {
                $this->support->assertLock($type, $input);
            }
            $values = $this->typeValues($actor, $input, $type);
            if ($type->exists && $this->typeInUse($type) && ($values['uses_balance'] !== $type->uses_balance || $values['allow_half_days'] !== $type->allow_half_days)) {
                throw ValidationException::withMessages(['uses_balance' => 'This type is already in use, so "Counts against a balance" and "Half days allowed" cannot change. Add a new type instead.']);
            }
            $changed = $type->exists ? array_values(array_filter(self::TYPE_FIELDS, fn (string $f): bool => $type->getAttribute($f) !== $values[$f])) : self::TYPE_FIELDS;
            if ($type->exists && $changed === []) {
                return $type;
            }
            $type->forceFill([...$values, 'organisation_id' => $actor->organisation_id, 'updated_by_user_id' => $actor->id,
                'lock_version' => $type->exists ? $type->lock_version + 1 : 1])->save();
            $this->support->audit->record($id === false ? 'hr.leave.type_created' : 'hr.leave.type_updated', $type, ['leave_type_code' => $type->code, 'changed_fields' => $changed], $actor);

            return $type;
        }, 3);
    }

    /**
     * Appends entitlement rows for a year: by role, or for one person. A later row for the same type, year and role or
     * person replaces the earlier one; nothing earlier is rewritten and no ledger line is written until the grant.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveEntitlements(User $actor, array $input): int
    {
        $rows = $input['rows'] ?? null;
        if (! is_array($rows) || $rows === [] || count($rows) > 200) {
            throw ValidationException::withMessages(['entitlements' => 'Enter at least one entitlement.']);
        }

        return DB::transaction(function () use ($actor, $input, $rows): int {
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE);
            $year = $this->year($input['year'] ?? null);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_leave_entitlements:'.$actor->organisation_id.':'.$year]);
            $types = LeaveType::query()->where('organisation_id', $actor->organisation_id)->where('uses_balance', true)->get()->keyBy('id');
            $current = $this->entitlementRows($actor->organisation_id, $year);
            $written = 0;
            foreach (array_values($rows) as $i => $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages(["rows.{$i}" => 'Check this row.']);
                }
                $type = $types->get((int) filter_var($row['leave_type_id'] ?? null, FILTER_VALIDATE_INT));
                if ($type === null) {
                    throw ValidationException::withMessages(["rows.{$i}.leave_type_id" => 'Choose a leave type that counts against a balance.']);
                }
                $role = $row['role'] ?? null;
                $userId = $row['user_id'] ?? null;
                if ($role !== null && $role !== '') {
                    if (! in_array($role, RosterPermissions::HUMAN_ROLES, true)) {
                        throw ValidationException::withMessages(["rows.{$i}.role" => 'Choose a role.']);
                    }
                    $userId = null;
                } else {
                    $role = null;
                    $person = User::query()->whereKey(filter_var($userId, FILTER_VALIDATE_INT))->where('organisation_id', $actor->organisation_id)->first();
                    if ($person === null) {
                        throw ValidationException::withMessages(["rows.{$i}.user_id" => 'Choose a person.']);
                    }
                    $userId = $person->id;
                }
                $halves = $this->support->halves($row['days'] ?? null, "rows.{$i}.days");
                $key = $type->id.':'.($role ?? 'u'.$userId);
                if (($current[$key] ?? null) === $halves) {
                    continue;
                }
                DB::table('hr_leave_entitlements')->insert(['organisation_id' => $actor->organisation_id, 'leave_type_id' => $type->id, 'year' => $year,
                    'role' => $role, 'user_id' => $userId, 'halves' => $halves, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
                $current[$key] = $halves;
                $written++;
            }
            if ($written > 0) {
                $this->support->audit->record('hr.leave.entitlements_saved', null, ['year' => $year, 'row_count' => $written], $actor, organisationId: $actor->organisation_id);
            }

            return $written;
        }, 3);
    }

    /**
     * What "Give this year's entitlement" would write: one grant line per person and type for the difference between
     * the current entitlement and what was already granted for the year.
     *
     * @return array{people:int, lines:int}
     */
    public function grantPreview(User $actor, mixed $year): array
    {
        $plan = $this->grantPlan($actor->organisation_id, $this->year($year));

        return ['people' => count(array_unique(array_column($plan, 'user_id'))), 'lines' => count($plan)];
    }

    /** @param array<string, mixed> $input */
    public function grant(User $actor, array $input): int
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $year = $this->year($input['year'] ?? null);

        return DB::transaction(function () use ($actor, $key, $year): int {
            $people = $this->humanIds($actor->organisation_id);
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE, $people);
            $hash = $this->support->hash([$actor->id, 'grant', $year]);
            if (($replay = $this->support->replay($actor, $key, 'leave_grant', $hash)) !== null) {
                return (int) $replay;
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_leave_entitlements:'.$actor->organisation_id.':'.$year]);
            // Only the people locked above; someone activated meanwhile is granted on the next press.
            $plan = $this->grantPlan($actor->organisation_id, $year, $people);
            $short = 0;
            $types = LeaveType::query()->whereIn('id', array_unique(array_column($plan, 'leave_type_id')))->get()->keyBy('id');
            foreach ($plan as $line) {
                $type = $types[$line['leave_type_id']];
                if ($line['halves'] < 0 && ! $type->allow_negative && $this->balances->left($line['user_id'], $type->id, $year) + $line['halves'] < 0) {
                    $short++;
                }
            }
            if ($short > 0) {
                throw ValidationException::withMessages(['year' => 'Lowering the entitlement would take '.($short === 1 ? '1 person' : "{$short} people").' below zero. Use an adjustment for them instead.']);
            }
            foreach ($plan as $line) {
                $this->ledger($actor, $line['user_id'], $line['leave_type_id'], $year, 'grant', $line['halves']);
            }
            $this->support->remember($actor, $key, 'leave_grant', $hash, (string) count($plan));
            $this->support->audit->record('hr.leave.entitlement_granted', null, ['year' => $year, 'line_count' => count($plan),
                'people_count' => count(array_unique(array_column($plan, 'user_id')))], $actor, organisationId: $actor->organisation_id);

            return count($plan);
        }, 3);
    }

    /**
     * "Carry forward from {year}": for each type with a carry-forward limit, each person's unused days (after pending
     * requests) up to the limit become one carry-forward line in the next year. Run once per year and type.
     *
     * @param  array<string, mixed>  $input
     */
    public function carryForward(User $actor, array $input): int
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $from = $this->year($input['year'] ?? null);

        return DB::transaction(function () use ($actor, $key, $from): int {
            $people = $this->humanIds($actor->organisation_id);
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE, $people);
            $hash = $this->support->hash([$actor->id, 'carry', $from]);
            if (($replay = $this->support->replay($actor, $key, 'leave_carry', $hash)) !== null) {
                return (int) $replay;
            }
            if ($this->today($actor)->year <= $from) {
                throw ValidationException::withMessages(['year' => "Carry forward from {$from} once {$from} has ended."]);
            }
            $written = 0;
            $types = LeaveType::query()->where('organisation_id', $actor->organisation_id)->where('uses_balance', true)->whereNotNull('carry_max_halves')->orderBy('id')->get();
            foreach ($types as $type) {
                foreach ($people as $userId) {
                    if (DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $type->id)->where('year', $from + 1)->where('kind', 'carry_forward')->exists()) {
                        continue;
                    }
                    $carry = min(max($this->balances->left($userId, $type->id, $from), 0), (int) $type->carry_max_halves);
                    if ($carry > 0) {
                        $this->ledger($actor, $userId, $type->id, $from + 1, 'carry_forward', $carry);
                        $written++;
                    }
                }
            }
            $this->support->remember($actor, $key, 'leave_carry', $hash, (string) $written);
            $this->support->audit->record('hr.leave.carried_forward', null, ['from_year' => $from, 'line_count' => $written], $actor, organisationId: $actor->organisation_id);

            return $written;
        }, 3);
    }

    /**
     * "Expire carried days for {year}": once a type's expiry date has passed, carried days not used by approved leave
     * starting before that date are removed with one expiry line per person and type. Run once per year and type.
     *
     * @param  array<string, mixed>  $input
     */
    public function expire(User $actor, array $input): int
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $year = $this->year($input['year'] ?? null);

        return DB::transaction(function () use ($actor, $key, $year): int {
            $people = $this->humanIds($actor->organisation_id);
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE, $people);
            $hash = $this->support->hash([$actor->id, 'expire', $year]);
            if (($replay = $this->support->replay($actor, $key, 'leave_expire', $hash)) !== null) {
                return (int) $replay;
            }
            $today = $this->today($actor);
            $written = 0;
            $types = LeaveType::query()->where('organisation_id', $actor->organisation_id)->where('uses_balance', true)->whereNotNull('carry_expires_on')->orderBy('id')->get();
            $due = 0;
            foreach ($types as $type) {
                $expiry = "{$year}-{$type->carry_expires_on}";
                if ($today->toDateString() < $expiry) {
                    continue;
                }
                $due++;
                foreach ($people as $userId) {
                    $carried = (int) DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $type->id)->where('year', $year)->where('kind', 'carry_forward')->sum('halves');
                    if ($carried <= 0 || DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $type->id)->where('year', $year)->where('kind', 'expiry')->exists()) {
                        continue;
                    }
                    $used = (int) DB::table('hr_leave_requests')->where('user_id', $userId)->where('leave_type_id', $type->id)->where('year', $year)
                        ->where('status', 'approved')->whereDate('start_date', '<', $expiry)->sum('halves');
                    $expired = min(max($carried - $used, 0), max($this->balances->left($userId, $type->id, $year), 0));
                    if ($expired > 0) {
                        $this->ledger($actor, $userId, $type->id, $year, 'expiry', -$expired);
                        $written++;
                    }
                }
            }
            if ($due === 0) {
                throw ValidationException::withMessages(['year' => 'No carried days are due to expire yet.']);
            }
            $this->support->remember($actor, $key, 'leave_expire', $hash, (string) $written);
            $this->support->audit->record('hr.leave.carry_expired', null, ['year' => $year, 'line_count' => $written], $actor, organisationId: $actor->organisation_id);

            return $written;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function saveWorkingDays(User $actor, array $input): void
    {
        $days = $input['weekdays'] ?? null;
        $clean = is_array($days) ? array_values(array_unique(array_map(fn ($d) => filter_var($d, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]), $days))) : [];
        if ($clean === [] || in_array(false, $clean, true)) {
            throw ValidationException::withMessages(['weekdays' => 'Choose at least one working day.']);
        }
        sort($clean);

        DB::transaction(function () use ($actor, $input, $clean): void {
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE);
            $branch = $this->support->authority->branch($actor, $input['branch_id'] ?? null);
            $this->calendar->lockBranch($branch);
            $row = DB::table('hr_branch_working_days')->where('branch_id', $branch->id)->lockForUpdate()->first();
            if ($row !== null) {
                $this->support->assertLock($row, $input);
                DB::table('hr_branch_working_days')->where('id', $row->id)->update(['weekdays' => json_encode($clean), 'lock_version' => $row->lock_version + 1,
                    'updated_by_user_id' => $actor->id, 'updated_at' => now()->utc()]);
            } else {
                DB::table('hr_branch_working_days')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'weekdays' => json_encode($clean),
                    'updated_by_user_id' => $actor->id, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            }
            $this->support->audit->record('hr.leave.working_days_saved', $branch, ['weekday_count' => count($clean)], $actor, $branch);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function addHoliday(User $actor, array $input): void
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Enter a name of at most 80 characters.']);
        }
        $date = is_string($input['holiday_date'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $input['holiday_date']) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input['holiday_date']) : false;
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $input['holiday_date'] || $date->year < 2000 || $date->year > 2100) {
            throw ValidationException::withMessages(['holiday_date' => 'Choose a date.']);
        }

        DB::transaction(function () use ($actor, $input, $name, $date): void {
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE);
            $branch = $this->support->authority->branch($actor, $input['branch_id'] ?? null);
            $this->calendar->lockBranch($branch);
            if (DB::table('hr_public_holidays')->where('branch_id', $branch->id)->whereDate('holiday_date', $date->toDateString())->exists()) {
                throw ValidationException::withMessages(['holiday_date' => 'This date is already a public holiday.']);
            }
            $this->assertDayUnused($branch, $date, 'holiday_date', 'Already counted by :requests. Cancel them first, or add holidays before staff apply.');
            $id = DB::table('hr_public_holidays')->insertGetId(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'holiday_date' => $date->toDateString(),
                'name' => $name, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            $this->support->audit->record('hr.leave.holiday_added', $branch, ['holiday_id' => $id, 'holiday_date' => $date->toDateString()], $actor, $branch);
        }, 3);
    }

    public function removeHoliday(User $actor, mixed $holidayId): void
    {
        DB::transaction(function () use ($actor, $holidayId): void {
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE);
            $id = filter_var($holidayId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $row = $id === false ? null : DB::table('hr_public_holidays')->where('id', $id)->where('organisation_id', $actor->organisation_id)->first();
            abort_if($row === null, 404);
            $branch = Branch::query()->whereKey($row->branch_id)->firstOrFail();
            $this->calendar->lockBranch($branch);
            $date = CarbonImmutable::parse(substr((string) $row->holiday_date, 0, 10));
            $this->assertDayUnused($branch, $date, 'holiday', 'Used by :requests. It cannot be removed.');
            DB::table('hr_public_holidays')->where('id', $row->id)->delete();
            $this->support->audit->record('hr.leave.holiday_removed', $branch, ['holiday_id' => $row->id, 'holiday_date' => $date->toDateString()], $actor, $branch);
        }, 3);
    }

    /** @return list<int> Active staff of the organisation who hold a human role, in id order. */
    public function humanIds(int $organisationId): array
    {
        return array_values(User::query()->where('organisation_id', $organisationId)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', RosterPermissions::HUMAN_ROLES))->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @return array<string, int> "typeId:role" or "typeId:u{userId}" => halves, latest row wins. */
    public function entitlementRows(int $organisationId, int $year): array
    {
        $out = [];
        foreach (DB::table('hr_leave_entitlements')->where('organisation_id', $organisationId)->where('year', $year)->orderBy('id')->get() as $row) {
            $out[$row->leave_type_id.':'.($row->role ?? 'u'.$row->user_id)] = (int) $row->halves;
        }

        return $out;
    }

    public function today(User $actor): CarbonImmutable
    {
        $zone = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->startOfDay();
    }

    /**
     * @param  list<int>|null  $peopleIds
     * @return list<array{user_id:int, leave_type_id:int, halves:int}>
     */
    private function grantPlan(int $organisationId, int $year, ?array $peopleIds = null): array
    {
        $rows = $this->entitlementRows($organisationId, $year);
        $types = LeaveType::query()->where('organisation_id', $organisationId)->where('uses_balance', true)->where('is_active', true)->orderBy('id')->get();
        $people = User::query()->with('roles')->whereIn('id', $peopleIds ?? $this->humanIds($organisationId))->orderBy('id')->get();
        $granted = DB::table('hr_leave_ledger')->where('organisation_id', $organisationId)->where('year', $year)->where('kind', 'grant')
            ->selectRaw('user_id, leave_type_id, sum(halves) as total')->groupBy('user_id', 'leave_type_id')->get()
            ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->leave_type_id => (int) $r->total]);
        $plan = [];
        foreach ($people as $person) {
            foreach ($types as $type) {
                $target = $this->entitlementFor($rows, $type->id, $person);
                if ($target === null) {
                    continue;
                }
                $delta = $target - ($granted[$person->id.':'.$type->id] ?? 0);
                if ($delta !== 0) {
                    $plan[] = ['user_id' => $person->id, 'leave_type_id' => $type->id, 'halves' => $delta];
                }
            }
        }

        return $plan;
    }

    /** @param array<string, int> $rows A person row wins; otherwise the largest entitlement among the person's roles. */
    private function entitlementFor(array $rows, int $typeId, User $person): ?int
    {
        if (array_key_exists($typeId.':u'.$person->id, $rows)) {
            return $rows[$typeId.':u'.$person->id];
        }
        $values = [];
        foreach ($person->roles->pluck('name') as $role) {
            if (array_key_exists($typeId.':'.$role, $rows)) {
                $values[] = $rows[$typeId.':'.$role];
            }
        }

        return $values === [] ? null : max($values);
    }

    /** The template's ":requests" becomes "1 request" or "3 requests". */
    private function assertDayUnused(Branch $branch, CarbonImmutable $date, string $field, string $template): void
    {
        $count = DB::table('hr_leave_requests')->where('branch_id', $branch->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())->count();
        if ($count > 0) {
            $noun = $count === 1 ? '1 request' : "{$count} requests";
            throw ValidationException::withMessages([$field => str_replace(':requests', $noun, $template)]);
        }
    }

    private function typeInUse(LeaveType $type): bool
    {
        return DB::table('hr_leave_requests')->where('leave_type_id', $type->id)->exists()
            || DB::table('hr_leave_ledger')->where('leave_type_id', $type->id)->exists()
            || DB::table('hr_leave_openings')->where('leave_type_id', $type->id)->exists()
            || DB::table('hr_leave_adjustments')->where('leave_type_id', $type->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function typeValues(User $actor, array $input, LeaveType $type): array
    {
        $code = $type->exists ? $type->code : strtoupper(trim(is_string($input['code'] ?? null) ? $input['code'] : ''));
        if (! $type->exists) {
            if (preg_match('/\A[A-Z][A-Z0-9_]{0,19}\z/', $code) !== 1) {
                throw ValidationException::withMessages(['code' => 'Use 1 to 20 capital letters, digits or _, starting with a letter.']);
            }
            if (LeaveType::query()->where('organisation_id', $actor->organisation_id)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'This code is already used.']);
            }
        }
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => 'Enter a name of at most 60 characters.']);
        }
        $flag = fn (string $f): bool => filter_var($input[$f] ?? false, FILTER_VALIDATE_BOOLEAN);
        $usesBalance = $flag('uses_balance');
        $carry = $input['carry_max_days'] ?? null;
        $carryHalves = $usesBalance && $carry !== null && $carry !== '' ? $this->support->halves($carry, 'carry_max_days') : null;
        if ($carryHalves === 0) {
            $carryHalves = null;
        }
        $expires = $input['carry_expires_on'] ?? null;
        $expires = $carryHalves !== null && is_string($expires) && $expires !== '' ? $expires : null;
        if ($expires !== null && (preg_match('/\A(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\z/', $expires) !== 1 || ! checkdate((int) substr($expires, 0, 2), (int) substr($expires, 3, 2), 2025))) {
            throw ValidationException::withMessages(['carry_expires_on' => 'Choose a month and day, for example 03-31.']);
        }

        // HR-2b: files are off until HR switches them on and sets how many days to keep them after the leave ends.
        $acceptsFiles = $flag('accepts_evidence_files');
        $retention = $input['evidence_retention_days'] ?? null;
        $retention = $retention === null || $retention === '' ? null : filter_var($retention, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($retention === false || ($acceptsFiles && $retention === null)) {
            throw ValidationException::withMessages(['evidence_retention_days' => 'Enter how many days to keep files after the leave ends (1 to 3650).']);
        }
        // Files already kept must still be deleted on time, so the days cannot be cleared while any remain (Suresh).
        if ($retention === null && $type->exists && DB::table('hr_leave_evidence_files')->where('status', 'active')
            ->whereIn('leave_request_id', DB::table('hr_leave_requests')->where('leave_type_id', $type->id)->select('id'))->exists()) {
            throw ValidationException::withMessages(['evidence_retention_days' => 'This type still has evidence files. Keep a number of days so they are deleted on time.']);
        }

        return ['code' => $code, 'name' => $name, 'is_paid' => $flag('is_paid'), 'uses_balance' => $usesBalance, 'allow_half_days' => $flag('allow_half_days'),
            'evidence_needed' => $flag('evidence_needed'), 'allow_negative' => $usesBalance && $flag('allow_negative'), 'carry_max_halves' => $carryHalves,
            'carry_expires_on' => $expires, 'is_active' => $type->exists ? $flag('is_active') : true,
            'accepts_evidence_files' => $acceptsFiles, 'evidence_retention_days' => $retention];
    }

    private function year(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if ($year === false) {
            throw ValidationException::withMessages(['year' => 'Choose a year.']);
        }

        return $year;
    }

    private function ledger(User $actor, int $userId, int $typeId, int $year, string $kind, int $halves): void
    {
        DB::table('hr_leave_ledger')->insert(['organisation_id' => $actor->organisation_id, 'user_id' => $userId, 'leave_type_id' => $typeId, 'year' => $year,
            'kind' => $kind, 'halves' => $halves, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
    }

    /** @return Collection<int, LeaveType> */
    public function types(int $organisationId): Collection
    {
        return LeaveType::query()->where('organisation_id', $organisationId)->orderByDesc('is_active')->orderBy('name')->get();
    }
}

<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-DEFAULTS (owner's draft defaults, 2026-11-15, approved by B.E.R.N.I.S.S for the owner): with no clinic leave
 * policy yet, "Use KPOne defaults" sets the Employment Act 1955 minimums as a starting point. It only adds: a type
 * whose code already exists is left exactly as it is, and an entitlement row HR already entered for the year is never
 * replaced. Everything it writes carries a mark, so the screen says it was set by KPOne defaults and is not advice.
 *
 * Annual, sick and hospitalisation leave count against a yearly balance by length of service at 1 January of the
 * year. Hospitalisation is 60 days a year including sick leave, so its balance is 60 less the sick days. Maternity
 * (98 days a confinement) and paternity (7 days a birth) are not yearly figures, so they are paid types without a
 * balance and the approver checks the days. Nothing is granted here; "Give this year's entitlement" still does that.
 */
final class LeaveDefaultsService
{
    public const LABEL = "Set by KPOne defaults at the owner's instruction; not professional advice. Check against current law before real use.";

    /** Days by service tier: under 2 years, 2 to under 5 years, 5 years or more. */
    public const TIERS = [
        'ANNUAL' => [8, 12, 16],
        'SICK' => [14, 18, 22],
        'HOSPITAL' => [46, 42, 38],
    ];

    /** @var array<string, array{name:string, is_paid:bool, uses_balance:bool, allow_half_days:bool, evidence_needed:bool}> */
    public const TYPES = [
        'ANNUAL' => ['name' => 'Annual leave', 'is_paid' => true, 'uses_balance' => true, 'allow_half_days' => true, 'evidence_needed' => false],
        'SICK' => ['name' => 'Sick leave', 'is_paid' => true, 'uses_balance' => true, 'allow_half_days' => false, 'evidence_needed' => true],
        'HOSPITAL' => ['name' => 'Hospitalisation leave', 'is_paid' => true, 'uses_balance' => true, 'allow_half_days' => false, 'evidence_needed' => true],
        'MATERNITY' => ['name' => 'Maternity leave', 'is_paid' => true, 'uses_balance' => false, 'allow_half_days' => false, 'evidence_needed' => true],
        'PATERNITY' => ['name' => 'Paternity leave', 'is_paid' => true, 'uses_balance' => false, 'allow_half_days' => false, 'evidence_needed' => true],
    ];

    public function __construct(private LeaveSupport $support, private LeaveSettingsService $settings) {}

    /**
     * What "Use KPOne defaults" would add for a year, and who to check by hand.
     *
     * @return array{year:int, typesToAdd:list<string>, typesKept:list<string>, rows:int, base:int, middle:int, long:int, noRecord:int, crossing:int}
     */
    public function preview(User $actor, mixed $year): array
    {
        $year = $this->year($year);
        $plan = $this->plan($actor->organisation_id, $year, $this->settings->humanIds($actor->organisation_id));

        return ['year' => $year, 'typesToAdd' => $plan['typesToAdd'], 'typesKept' => $plan['typesKept'], 'rows' => count($plan['rows']), ...$plan['counts']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{types:int, rows:int}
     */
    public function apply(User $actor, array $input): array
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $year = $this->year($input['year'] ?? null);

        return DB::transaction(function () use ($actor, $key, $year): array {
            $people = $this->settings->humanIds($actor->organisation_id);
            $actor = $this->support->lock($actor, LeavePermissions::MANAGE, $people);
            $hash = $this->support->hash([$actor->id, 'leave_defaults', $year]);
            if (($replay = $this->support->replay($actor, $key, 'leave_defaults', $hash)) !== null) {
                [$types, $rows] = array_map('intval', explode(':', $replay));

                return ['types' => $types, 'rows' => $rows];
            }
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_leave_types:'.$actor->organisation_id]);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_leave_entitlements:'.$actor->organisation_id.':'.$year]);
            $existing = LeaveType::query()->where('organisation_id', $actor->organisation_id)->whereIn('code', array_keys(self::TYPES))->lockForUpdate()->get()->keyBy('code');
            $created = 0;
            foreach (self::TYPES as $code => $values) {
                if ($existing->has($code)) {
                    continue;
                }
                $type = new LeaveType;
                $type->forceFill([...$values, 'code' => $code, 'organisation_id' => $actor->organisation_id, 'allow_negative' => false, 'carry_max_halves' => null,
                    'carry_expires_on' => null, 'is_active' => true, 'accepts_evidence_files' => false, 'evidence_retention_days' => null,
                    'set_by_defaults' => true, 'updated_by_user_id' => $actor->id, 'lock_version' => 1])->save();
                $existing->put($code, $type);
                $created++;
            }
            $plan = $this->plan($actor->organisation_id, $year, $people, $existing->map(fn (LeaveType $t): int => $t->id)->all());
            foreach ($plan['rows'] as $row) {
                DB::table('hr_leave_entitlements')->insert([...$row, 'organisation_id' => $actor->organisation_id, 'year' => $year,
                    'set_by_defaults' => true, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            }
            $this->support->remember($actor, $key, 'leave_defaults', $hash, $created.':'.count($plan['rows']));
            $this->support->audit->record('hr.leave.defaults_applied', null, ['year' => $year, 'types_created' => $created, 'entitlement_rows' => count($plan['rows'])],
                $actor, organisationId: $actor->organisation_id);

            return ['types' => $created, 'rows' => count($plan['rows'])];
        }, 3);
    }

    /**
     * The entitlement rows to add: one row per human role at the first tier, and one row per person whose service puts
     * them in a higher tier. A row HR already entered for the same type and role or person is kept, never replaced.
     *
     * @param  list<int>  $people
     * @param  array<string, int>|null  $typeIds  code => id; null reads the existing types
     * @return array{typesToAdd:list<string>, typesKept:list<string>, rows:list<array{leave_type_id:int, role:?string, user_id:?int, halves:int}>, counts:array{base:int, middle:int, long:int, noRecord:int, crossing:int}}
     */
    private function plan(int $organisationId, int $year, array $people, ?array $typeIds = null): array
    {
        $existing = LeaveType::query()->where('organisation_id', $organisationId)->whereIn('code', array_keys(self::TYPES))->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
        $typeIds ??= $existing;
        $current = $this->settings->entitlementRows($organisationId, $year);
        $defaultKeys = $this->settings->defaultEntitlementKeys($organisationId, $year);
        $service = $this->service($organisationId, $year, $people);
        $roles = User::query()->with('roles')->whereIn('id', $people === [] ? [0] : $people)->get()
            ->mapWithKeys(fn (User $u): array => [$u->id => array_values(array_intersect($u->roles->pluck('name')->all(), RosterPermissions::HUMAN_ROLES))])->all();
        $rows = [];
        $counts = ['base' => 0, 'middle' => 0, 'long' => 0, 'noRecord' => 0, 'crossing' => 0];
        foreach ($people as $id) {
            $s = $service[$id] ?? null;
            $tier = $s === null ? 0 : ($s['years'] >= 5 ? 2 : ($s['years'] >= 2 ? 1 : 0));
            $counts[['base', 'middle', 'long'][$tier]]++;
            $counts['noRecord'] += $s === null ? 1 : 0;
            $counts['crossing'] += $s !== null && $s['crossing'] ? 1 : 0;
            if ($tier === 0) {
                continue;
            }
            foreach (self::TIERS as $code => $days) {
                $typeId = $typeIds[$code] ?? null;
                $target = $days[$tier] * 2;
                // A person row already there (HR's, or an earlier press of the defaults) stays as it is.
                if ($typeId !== null && array_key_exists("{$typeId}:u{$id}", $current)) {
                    continue;
                }
                // A role figure HR entered itself is the clinic's policy: keep it, and add a person row only to raise
                // annual or sick leave to the tier. Hospitalisation is 60 days including sick leave, so it is never
                // adjusted around an HR figure. The defaults' own first-tier rows do not count as HR figures.
                $fromHr = null;
                foreach ($roles[$id] ?? [] as $role) {
                    $roleKey = "{$typeId}:{$role}";
                    if ($typeId !== null && array_key_exists($roleKey, $current) && ! in_array($roleKey, $defaultKeys, true)) {
                        $fromHr = max($fromHr ?? 0, $current[$roleKey]);
                    }
                }
                if ($fromHr === null || ($code !== 'HOSPITAL' && $fromHr < $target)) {
                    // A type that is not there yet gets its id on apply; the preview counts it with id 0.
                    $rows[] = ['leave_type_id' => $typeId ?? 0, 'role' => null, 'user_id' => $id, 'halves' => $target];
                }
            }
        }
        foreach (RosterPermissions::HUMAN_ROLES as $role) {
            foreach (self::TIERS as $code => $days) {
                $typeId = $typeIds[$code] ?? null;
                if ($typeId === null || ! array_key_exists("{$typeId}:{$role}", $current)) {
                    $rows[] = ['leave_type_id' => $typeId ?? 0, 'role' => $role, 'user_id' => null, 'halves' => $days[0] * 2];
                }
            }
        }

        return ['typesToAdd' => array_values(array_diff(array_keys(self::TYPES), array_keys($existing))),
            'typesKept' => array_values(array_intersect(array_keys(self::TYPES), array_keys($existing))), 'rows' => $rows, 'counts' => $counts];
    }

    /**
     * Whole years of service at 1 January, from the person's employment period that covers that day (or, for someone
     * who starts later in the year, zero). "crossing" is true when a 2 or 5 year anniversary falls inside the year, so
     * HR can add a per-person entitlement for the rest of that year by hand.
     *
     * @param  list<int>  $people
     * @return array<int, array{years:int, crossing:bool}>
     */
    private function service(int $organisationId, int $year, array $people): array
    {
        $jan = CarbonImmutable::create($year, 1, 1);
        $dec = CarbonImmutable::create($year, 12, 31);
        $periods = DB::table('hr_employment_periods as p')->join('hr_workers as w', 'w.id', '=', 'p.worker_id')
            ->where('p.organisation_id', $organisationId)->whereIn('w.user_id', $people === [] ? [0] : $people)
            ->where(fn ($q) => $q->whereNull('p.ends_on')->orWhere('p.ends_on', '>=', $jan->toDateString()))
            ->where('p.starts_on', '<=', $dec->toDateString())
            ->orderBy('p.starts_on')->get(['w.user_id', 'p.starts_on']);
        $out = [];
        foreach ($periods as $p) {
            $start = CarbonImmutable::parse(substr((string) $p->starts_on, 0, 10));
            $years = $start->greaterThan($jan) ? 0 : (int) $start->diffInYears($jan);
            $crossing = false;
            foreach ([2, 5] as $mark) {
                $anniversary = $start->addYears($mark);
                $crossing = $crossing || ($anniversary->greaterThan($jan) && $anniversary->lessThanOrEqualTo($dec));
            }
            // The earliest period still covering the year wins (the longest unbroken service on record).
            $out[(int) $p->user_id] ??= ['years' => $years, 'crossing' => $crossing];
        }

        return $out;
    }

    private function year(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if ($year === false) {
            throw ValidationException::withMessages(['year' => 'Choose a year.']);
        }

        return $year;
    }
}

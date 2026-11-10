<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-16a location check at clock-in (HR-PLAN 22). Off by default; a Director turns it on for one branch at a time with
 * a geofence (the branch's point and a radius) and a consent text. At a press at that branch, the browser sends the
 * person's position once (`clock_position`); this service compares it with the geofence and keeps only "inside",
 * "outside" or "not available" and an accuracy band. The position itself is never stored, logged, audited or flashed,
 * and a refusal, a GPS failure or a missing consent never blocks the press. No face, no tracking, no outside service.
 */
final class LocationCheckService
{
    /** A suggestion only; the Director enters the radius (HR-PLAN 22.1). */
    public const SUGGESTED_RADIUS = 150;

    /** A reading less precise than this is "not available", never "outside". */
    public const MAX_ACCURACY_METRES = 1000;

    /** Draft wording for the owner's PDPA review with the Data Protection Officer; the Director may change it. */
    public const DRAFT_CONSENT_EN = 'When you press Clock in or Clock out at this branch, KPOne asks your phone or computer for your location once. '
        .'It checks whether you are inside the branch area and keeps only the result: inside, outside or not available, with how accurate the reading was. '
        .'Your location itself is not kept, and nothing is tracked before or after the press. '
        .'If you say no, or your device cannot find you, your clock-in is still recorded. '
        .'HR uses the result only to ask you about a time, never to decide on its own.';

    public const DRAFT_CONSENT_BM = 'Apabila anda menekan Clock in atau Clock out di cawangan ini, KPOne meminta lokasi telefon atau komputer anda sekali sahaja. '
        .'Ia menyemak sama ada anda berada di dalam kawasan cawangan dan hanya menyimpan keputusannya: di dalam, di luar atau tidak tersedia, bersama tahap ketepatan bacaan. '
        .'Lokasi anda sendiri tidak disimpan, dan tiada penjejakan sebelum atau selepas tekanan itu. '
        .'Jika anda menolak, atau peranti anda tidak dapat mengesan lokasi, clock-in anda tetap direkodkan. '
        .'HR hanya menggunakan keputusan ini untuk bertanya tentang sesuatu masa, bukan untuk membuat keputusan dengan sendirinya.';

    public const RESULTS = ['inside' => 'Inside the branch area', 'outside' => 'Outside the branch area', 'unavailable' => 'Location not checked'];

    public const BANDS = ['good' => 'accurate to about 50 m', 'fair' => 'accurate to about 200 m', 'poor' => 'not very accurate'];

    public const REASONS = ['declined' => 'chose to clock in without it', 'denied' => 'location was not allowed on the device',
        'failed' => 'the device could not find a location', 'imprecise' => 'the reading was too rough to use'];

    public function __construct(private AttendanceSupport $support) {}

    /** The version in force at a branch, or null when the check has never been set there. */
    public function setting(int $branchId): ?\stdClass
    {
        $row = DB::table('hr_location_check_settings')->where('branch_id', $branchId)->orderByDesc('id')->first();

        return $row instanceof \stdClass ? $row : null;
    }

    /** The version in force at a branch if the check is on there. */
    public function enabled(int $branchId): ?\stdClass
    {
        $row = $this->setting($branchId);

        return $row !== null && (bool) $row->enabled ? $row : null;
    }

    public function consented(User $person, \stdClass $setting): bool
    {
        return DB::table('hr_location_consents')->where('user_id', $person->id)->where('setting_id', $setting->id)->exists();
    }

    /**
     * Inside the clock transaction, after the press is recorded: the result for that press, if the check is on at the
     * session's branch. The position in $input is read here and nowhere else.
     *
     * @param  array<string, mixed>  $input  clock_position {latitude, longitude, accuracy}, location_unavailable, location_consent
     */
    public function recordPress(User $actor, ClockSession $session, string $kind, array $input): void
    {
        // A press takes turns with a Director's save, so it is checked against the version really in force (Suresh, m2).
        DB::select('select pg_advisory_xact_lock_shared(hashtextextended(?, 0))', ['hr_location_check:'.$actor->organisation_id]);
        $setting = $this->enabled((int) $session->branch_id);
        if ($setting === null) {
            return; // Off: nothing is read or kept, whatever the browser sent.
        }
        $consented = $this->consented($actor, $setting);
        if (! $consented && ($input['location_consent'] ?? null) === true) {
            DB::table('hr_location_consents')->insert(['organisation_id' => $actor->organisation_id, 'user_id' => $actor->id,
                'setting_id' => $setting->id, 'acknowledged_at' => now()->utc()]);
            $this->support->audit->record('hr.attendance.location_consent_given', null, ['setting_id' => (int) $setting->id], $actor,
                Branch::query()->find($session->branch_id), $actor->organisation_id);
            $consented = true;
        }
        [$result, $band, $reason] = $consented ? $this->evaluate($setting, $input) : ['unavailable', null, 'declined'];
        DB::table('hr_clock_location_checks')->insert(['organisation_id' => $actor->organisation_id, 'clock_session_id' => $session->id, 'kind' => $kind,
            'setting_id' => $setting->id, 'result' => $result, 'accuracy_band' => $band, 'unavailable_reason' => $reason, 'created_at' => now()->utc()]);
        // The result itself stays behind Review; the audit log holds only that a press was checked (Suresh, M1).
        $this->support->audit->record('hr.attendance.location_checked', $session, ['kind' => $kind, 'setting_id' => (int) $setting->id], $actor,
            Branch::query()->find($session->branch_id));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: ?string, 2: ?string} result, accuracy band, reason when not available
     */
    private function evaluate(\stdClass $setting, array $input): array
    {
        $given = $input['location_unavailable'] ?? null;
        if (in_array($given, ['declined', 'denied', 'failed'], true)) {
            return ['unavailable', null, $given];
        }
        $position = $input['clock_position'] ?? null;
        $lat = is_array($position) ? filter_var($position['latitude'] ?? null, FILTER_VALIDATE_FLOAT) : false;
        $lng = is_array($position) ? filter_var($position['longitude'] ?? null, FILTER_VALIDATE_FLOAT) : false;
        $accuracy = is_array($position) ? filter_var($position['accuracy'] ?? null, FILTER_VALIDATE_FLOAT) : false;
        if ($lat === false || $lng === false || $accuracy === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || $accuracy < 0) {
            return ['unavailable', null, 'failed']; // A bad or missing reading never blocks and is never kept.
        }
        if ($accuracy > self::MAX_ACCURACY_METRES) {
            return ['unavailable', null, 'imprecise'];
        }
        $distance = self::metres((float) $setting->geofence_centre_lat, (float) $setting->geofence_centre_lng, $lat, $lng);
        $band = $accuracy <= 50 ? 'good' : ($accuracy <= 200 ? 'fair' : 'poor');

        return [$distance <= (int) $setting->radius_metres ? 'inside' : 'outside', $band, null];
    }

    /** Great-circle distance in metres (haversine). */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * The Director turns the check on or off for a branch. Each save is a new version; consent is asked again after a
     * change, because staff acknowledge a version.
     *
     * @param  array<string, mixed>  $input  branch_id, enabled, centre_lat, centre_lng, radius_metres, consent_en, consent_bm, idempotency_key
     */
    public function save(User $actor, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $enabled = filter_var($input['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw ValidationException::withMessages(['enabled' => 'Choose on or off.']);
        }
        $values = $enabled ? $this->values($input) : null;
        DB::transaction(function () use ($actor, $input, $enabled, $values, $key): void {
            $actor = $this->support->lock($actor, ClockPermissions::LOCATION_MANAGE);
            $hash = $this->support->hash([$actor->id, 'location_check', $input['branch_id'] ?? null, $enabled, $values]);
            if ($this->support->replay($actor, $key, 'location_check', $hash) !== null) {
                return;
            }
            $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
                ->whereKey(filter_var($input['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0)->first();
            if ($branch === null) {
                throw ValidationException::withMessages(['branch_id' => 'Choose a branch.']);
            }
            // One branch at a time: saves take turns per organisation, then the other branches are read.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_location_check:'.$actor->organisation_id]);
            $current = $this->setting($branch->id);
            if (! $enabled && ($current === null || ! (bool) $current->enabled)) {
                throw ValidationException::withMessages(['enabled' => 'The location check is already off at '.$branch->name.'.']);
            }
            if ($enabled) {
                $other = Branch::query()->where('organisation_id', $actor->organisation_id)->where('id', '<>', $branch->id)->get()
                    ->first(fn (Branch $b): bool => $this->enabled($b->id) !== null);
                if ($other !== null) {
                    throw ValidationException::withMessages(['branch_id' => 'The location check is on at '.$other->name.'. Turn it off there first: it runs at one branch at a time.']);
                }
            }
            $id = DB::table('hr_location_check_settings')->insertGetId(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'enabled' => $enabled, 'geofence_centre_lat' => $values['lat'] ?? null, 'geofence_centre_lng' => $values['lng'] ?? null,
                'radius_metres' => $values['radius'] ?? null, 'consent_en' => $values['en'] ?? null, 'consent_bm' => $values['bm'] ?? null,
                'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'location_check', $hash, (string) $id);
            $this->support->audit->record($enabled ? 'hr.attendance.location_check_on' : 'hr.attendance.location_check_off', null,
                ['setting_id' => $id, 'radius_metres' => $values['radius'] ?? null], $actor, $branch);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{lat: float, lng: float, radius: int, en: string, bm: string}
     */
    private function values(array $input): array
    {
        $errors = [];
        $lat = filter_var($input['centre_lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($input['centre_lng'] ?? null, FILTER_VALIDATE_FLOAT);
        $radius = filter_var($input['radius_metres'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 25, 'max_range' => 2000]]);
        if ($lat === false || $lat < -90 || $lat > 90) {
            $errors['centre_lat'] = 'Enter the branch latitude, for example 3.081234.';
        }
        if ($lng === false || $lng < -180 || $lng > 180) {
            $errors['centre_lng'] = 'Enter the branch longitude, for example 101.712345.';
        }
        if ($radius === false) {
            $errors['radius_metres'] = 'Enter a radius from 25 to 2,000 metres.';
        }
        $en = trim((string) ($input['consent_en'] ?? ''));
        $bm = trim((string) ($input['consent_bm'] ?? ''));
        foreach (['consent_en' => $en, 'consent_bm' => $bm] as $field => $text) {
            if ($text === '' || mb_strlen($text) > 2000) {
                $errors[$field] = 'Enter the consent text staff will read (up to 2,000 characters).';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6), 'radius' => (int) $radius, 'en' => $en, 'bm' => $bm];
    }

    /**
     * For My attendance: the check at the branch the next press goes to, the consent text if not yet acknowledged.
     *
     * @return array<string, mixed>|null
     */
    public function forPerson(User $person, ?int $branchId): ?array
    {
        $setting = $branchId === null ? null : $this->enabled($branchId);
        if ($setting === null) {
            return null;
        }
        $consented = $this->consented($person, $setting);
        // Your own latest result, so you see what was kept about you (Suresh, m3); nobody else's.
        $last = DB::table('hr_clock_location_checks as c')->join('hr_clock_sessions as s', 's.id', '=', 'c.clock_session_id')
            ->where('s.user_id', $person->id)->orderByDesc('c.id')->first(['c.result', 'c.accuracy_band', 'c.unavailable_reason', 'c.kind', 'c.created_at', 's.timezone']);

        return ['consentNeeded' => ! $consented, 'consentEn' => $consented ? null : (string) $setting->consent_en,
            'consentBm' => $consented ? null : (string) $setting->consent_bm,
            'lastResult' => $last === null ? null : ($last->kind === 'out' ? 'Your last clock-out ' : 'Your last clock-in ')
                .'('.AttendanceSupport::day(CarbonImmutable::parse((string) $last->created_at)->setTimezone((string) $last->timezone)).'): '
                .self::line($last)];
    }

    /**
     * For Review: a plain-words line per press, keyed by session id. Never a distance, never a position.
     *
     * @param  list<int>  $sessionIds
     * @return array<int, array{in: ?string, out: ?string, outside: bool}>
     */
    public function labels(array $sessionIds): array
    {
        $out = [];
        foreach (DB::table('hr_clock_location_checks')->whereIn('clock_session_id', $sessionIds)->get() as $row) {
            $text = self::line($row);
            $entry = $out[(int) $row->clock_session_id] ?? ['in' => null, 'out' => null, 'outside' => false];
            $entry[$row->kind === 'out' ? 'out' : 'in'] = $text;
            $entry['outside'] = $entry['outside'] || $row->result === 'outside';
            $out[(int) $row->clock_session_id] = $entry;
        }

        return $out;
    }

    private static function line(\stdClass $row): string
    {
        $text = self::RESULTS[$row->result] ?? 'Location not checked';

        return $text.($row->result === 'unavailable' ? ' ('.(self::REASONS[$row->unavailable_reason] ?? '').')' : ', '.(self::BANDS[$row->accuracy_band] ?? ''));
    }

    /**
     * For Settings (Director): every active branch with its current check.
     *
     * @return array<string, mixed>
     */
    public function settingsCard(User $actor): array
    {
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get();

        return [
            'suggestedRadius' => self::SUGGESTED_RADIUS,
            'draftConsentEn' => self::DRAFT_CONSENT_EN,
            'draftConsentBm' => self::DRAFT_CONSENT_BM,
            'branches' => $branches->map(function (Branch $b): array {
                $s = $this->setting($b->id);
                $on = $s !== null && (bool) $s->enabled;

                return ['id' => $b->id, 'name' => (string) $b->name, 'enabled' => $on,
                    'centreLat' => $on ? (string) $s->geofence_centre_lat : ($b->public_latitude !== null ? (string) round((float) $b->public_latitude, 6) : ''),
                    'centreLng' => $on ? (string) $s->geofence_centre_lng : ($b->public_longitude !== null ? (string) round((float) $b->public_longitude, 6) : ''),
                    'radiusMetres' => $on ? (int) $s->radius_metres : null,
                    'consentEn' => $on ? (string) $s->consent_en : null,
                    'consentBm' => $on ? (string) $s->consent_bm : null,
                    'since' => $on ? AttendanceSupport::day(CarbonImmutable::parse((string) $s->created_at)) : null];
            })->values()->all(),
        ];
    }
}

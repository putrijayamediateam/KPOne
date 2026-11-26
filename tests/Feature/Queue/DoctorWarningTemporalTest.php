<?php

namespace Tests\Feature\Queue;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Assignment\DoctorWarningProjection;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AUTO-1c (owner answers Q1 to Q6): a neutral warning on a WAITING consultation row whose assigned doctor is off the
 * roster, without a room, or busy while another doctor is free. Read only, batched, shown only to a viewer who may edit the
 * visit, and never naming another doctor.
 */
class DoctorWarningTemporalTest extends QueueTestCase
{
    private CarbonImmutable $week;

    private User $hr;

    private User $director;

    private User $ca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->ca = $this->actor('ca');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, int|null> $rooms doctor id => room id */
    private function publish(array $doctors, array $rooms = [], string $start = '08:00', string $end = '17:00'): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        foreach ($doctors as $doctor) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), [
                'staff_user_id' => $doctor->id, 'date' => $this->week->toDateString(), 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0,
                'session_type' => 'morning', 'branch_room_id' => $rooms[$doctor->id] ?? null, 'branch_id' => $this->branch->id,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        Date::setTestNow(CarbonImmutable::parse($this->week->toDateString().' 10:00', $this->branch->timezone));
    }

    private function waiting(User $doctor): Visit
    {
        $visit = $this->consultationVisit($this->ca, $doctor);
        $this->send($this->ca, $visit);

        return $visit->refresh();
    }

    /** @return array<string, array<string, mixed>|null> visit number => doctorWarning, from the Registration board */
    private function registrationWarnings(?User $viewer = null): array
    {
        $this->selectBranch($viewer ?? $this->ca);
        $rows = $this->postJson(route('registration.search'))->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $r): array => [$r['visitNumber'] => $r['doctorWarning'] ?? null])->all();
    }

    private function serve(User $doctor): void
    {
        $visit = $this->waiting($doctor);
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version]);
    }

    private function warningAt(User $doctor, int $day, string $time): ?array
    {
        Date::setTestNow(CarbonImmutable::parse($this->week->addDays($day)->toDateString().' '.$time, $this->branch->timezone));

        return app(DoctorWarningProjection::class)->forDoctors($this->ca, $this->branch, [$doctor->id])[$doctor->id];
    }

    private function approvedLeave(User $doctor, string $from, string $until): void
    {
        $day = $this->week->toDateString();
        $type = DB::table('hr_leave_types')->where('organisation_id', $this->organisation->id)->where('code', 'AL')->value('id')
            ?? DB::table('hr_leave_types')->insertGetId(['organisation_id' => $this->organisation->id, 'code' => 'AL', 'name' => 'Synthetic annual', 'is_paid' => true,
                'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'user_id' => $doctor->id,
            'leave_type_id' => $type, 'branch_id' => $this->branch->id, 'start_date' => $day, 'end_date' => $day,
            'start_part' => 'full', 'end_part' => 'full', 'slot_start' => "$day $from:00", 'slot_end' => "$day $until:00",
            'year' => (int) substr($day, 0, 4), 'halves' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->director->id, 'decided_at' => now()]);
    }

    public function test_warning_tracks_exact_shift_start_and_end_without_changing_assignment(): void
    {
        $doctor = $this->doctor();
        $this->publish([$doctor]);
        $visit = $this->waiting($doctor);
        $this->assertSame(['code' => 'off_roster', 'label' => 'Off duty now'], $this->warningAt($doctor, 0, '07:59:59'));
        $this->assertNull($this->warningAt($doctor, 0, '08:00:00'));
        $this->assertNull($this->warningAt($doctor, 0, '16:59:59'));
        $this->assertSame('off_roster', $this->warningAt($doctor, 0, '17:00:00')['code']);
        $this->assertSame('Off duty now', $this->warningAt($doctor, 0, '18:00:00')['label']);
        $this->assertSame($doctor->id, $visit->refresh()->assigned_doctor_user_id);
    }

    public function test_full_shift_leave_is_labelled_leave_instead_of_missing_roster(): void
    {
        $doctor = $this->doctor();
        $this->publish([$doctor]);
        $this->approvedLeave($doctor, '08:00', '17:00');
        $this->assertSame(['code' => 'on_leave', 'label' => 'On leave now'], $this->warningAt($doctor, 0, '08:00:00'));
        $this->assertSame('on_leave', $this->warningAt($doctor, 0, '10:00:00')['code']);
        $this->assertSame('off_roster', $this->warningAt($doctor, 0, '17:00:00')['code']);
    }

    public function test_partial_leave_starts_exactly_and_warning_clears_on_return(): void
    {
        $doctor = $this->doctor();
        $this->publish([$doctor]);
        $this->approvedLeave($doctor, '10:00', '12:00');
        $this->assertNull($this->warningAt($doctor, 0, '09:59:59'));
        $this->assertSame('on_leave', $this->warningAt($doctor, 0, '10:00:00')['code']);
        $this->assertSame('on_leave', $this->warningAt($doctor, 0, '11:59:59')['code']);
        $this->assertNull($this->warningAt($doctor, 0, '12:00:00'));
    }

    public function test_overnight_shift_is_current_after_midnight_until_exact_end(): void
    {
        $doctor = $this->doctor();
        $this->publish([$doctor], [], '22:00', '06:00');
        $this->assertSame('off_roster', $this->warningAt($doctor, 0, '21:59:59')['code']);
        $this->assertNull($this->warningAt($doctor, 0, '22:00:00'));
        $this->assertNull($this->warningAt($doctor, 1, '00:00:00'));
        $this->assertNull($this->warningAt($doctor, 1, '05:59:59'));
        $this->assertSame('off_roster', $this->warningAt($doctor, 1, '06:00:00')['code']);
    }
}

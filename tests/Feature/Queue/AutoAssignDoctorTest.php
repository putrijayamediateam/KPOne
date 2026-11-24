<?php

namespace Tests\Feature\Queue;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Assignment\DoctorAssignmentService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * AUTO-1 (owner, 24 Nov 2026): a blank doctor at registration is filled from today's approved roster by the fixed rules
 * (free first, residents before locums, lowest target progress, cap when everyone is busy, follow-up doctor), only when the
 * Director switched it on for the branch and the day's roster is published. The CA's page never receives sales or targets.
 */
class AutoAssignDoctorTest extends QueueTestCase
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

    /** Publish this Monday for the given doctors and freeze the clock at 10:00 that day. */
    private function publish(array $doctors, array $rooms = []): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        foreach ($doctors as $doctor) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), [
                'staff_user_id' => $doctor->id, 'date' => $this->week->toDateString(), 'start_time' => '08:00', 'end_time' => '17:00',
                'break_minutes' => 0, 'session_type' => 'morning', 'branch_room_id' => $rooms[$doctor->id] ?? null, 'branch_id' => $this->branch->id,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        Date::setTestNow(CarbonImmutable::parse($this->week->toDateString().' 10:00', $this->branch->timezone));
    }

    private function enable(): void
    {
        DB::table('branch_auto_assign_settings')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'enabled' => true,
            'lock_version' => 1, 'updated_by_user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function payload(?User $doctor, array $overrides = [], ?Patient $patient = null): array
    {
        $reason = app(VisitReasonService::class)->create($this->ca, 'Synthetic auto reason '.Str::random(4));

        return $this->visitAttributes($patient ?? $this->patient($this->ca), [
            'visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor?->id, 'visit_reason' => null,
            'visit_reason_public_ids' => [$reason->public_id], ...$overrides,
        ]);
    }

    private function registerAs(array $payload): TestResponse
    {
        $this->selectBranch($this->ca);

        return $this->post(route('registration.store'), $payload);
    }

    public function test_off_by_default_and_without_a_published_roster_a_blank_doctor_is_still_refused(): void
    {
        $a = $this->doctor();
        $this->registerAs($this->payload(null))->assertSessionHasErrors('assigned_doctor_user_id');

        $this->enable();
        $this->registerAs($this->payload(null))->assertSessionHasErrors('assigned_doctor_user_id');
        $this->assertSame(0, Visit::query()->count());

        // Rostered but the switch is off at another branch: unchanged.
        $this->publish([$a]);
        DB::table('branch_auto_assign_settings')->update(['enabled' => false]);
        $this->registerAs($this->payload(null))->assertSessionHasErrors('assigned_doctor_user_id');
    }

    public function test_a_blank_doctor_is_filled_from_the_roster_and_only_rostered_doctors_are_picked(): void
    {
        $rostered = $this->doctor();
        $offRoster = $this->doctor(); // eligible at the branch, but not on today's roster
        $this->publish([$rostered]);
        $this->enable();

        $this->registerAs($this->payload(null))->assertSessionHasNoErrors()->assertRedirect(route('registration.index'));
        $visit = Visit::query()->sole();
        $this->assertSame($rostered->id, $visit->assigned_doctor_user_id);
        $this->assertNotSame($offRoster->id, $visit->assigned_doctor_user_id);
        $this->assertSame(QueueEntry::STATUS_WAITING, QueueEntry::query()->sole()->status);

        $meta = json_decode((string) DB::table('audit_logs')->where('event', 'visit.created')->value('metadata'), true);
        $this->assertSame('auto', $meta['doctor_source']);
        $this->assertSame('next_in_turn', $meta['doctor_reason_code']);
        foreach (array_keys($meta) as $key) {
            $this->assertDoesNotMatchRegularExpression('/sales|target|progress|percent|sen\b/i', $key);
        }
    }

    public function test_a_doctor_who_is_serving_or_has_a_waiting_patient_is_not_free(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();
        $this->publish([$a, $b]);
        $this->enable();

        // A is serving someone.
        $busy = $this->consultationVisit($this->ca, $a);
        $entry = $this->send($this->ca, $busy);
        $this->selectBranch($a);
        app(QueueEntryService::class)->call($a, $busy, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $busy->lock_version, 'queue_lock_version' => $entry->lock_version]);

        $this->registerAs($this->payload(null))->assertSessionHasNoErrors();
        $this->assertSame($b->id, Visit::query()->latest('id')->first()->assigned_doctor_user_id);

        // B now has a waiting patient and A is still serving: nobody is free, so the lowest sales takes the next one.
        $this->registerAs($this->payload(null))->assertSessionHasNoErrors();
        $last = Visit::query()->latest('id')->first();
        $meta = json_decode((string) DB::table('audit_logs')->where('event', 'visit.created')->latest('id')->value('metadata'), true);
        $this->assertSame('all_busy_lowest_sales', $meta['doctor_reason_code']);
        $this->assertContains($last->assigned_doctor_user_id, [$a->id, $b->id]);
    }

    public function test_a_busy_doctor_with_three_waiting_is_skipped_when_everyone_is_busy(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();
        $this->publish([$a, $b]);
        $this->enable();
        // A (lower id) already has three waiting; B has one. Both have waiting patients, so neither is free.
        foreach (range(1, 3) as $i) {
            $this->send($this->ca, $this->consultationVisit($this->ca, $a));
        }
        $this->send($this->ca, $this->consultationVisit($this->ca, $b));

        $this->registerAs($this->payload(null))->assertSessionHasNoErrors();
        $this->assertSame($b->id, Visit::query()->latest('id')->first()->assigned_doctor_user_id);
    }

    public function test_the_previous_doctor_of_the_last_30_days_is_offered_first_when_free(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();
        $this->publish([$a, $b]);
        $this->enable();
        $patient = $this->patient($this->ca);
        $this->register($this->ca, $patient, ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $b->id, 'visit_reason' => 'Synthetic earlier reason', 'confirm_repeat' => true]);

        $this->registerAs($this->payload(null, ['confirm_repeat' => true], $patient))->assertSessionHasNoErrors();
        $visit = Visit::query()->latest('id')->first();
        $this->assertSame($b->id, $visit->assigned_doctor_user_id);
        $meta = json_decode((string) DB::table('audit_logs')->where('event', 'visit.created')->latest('id')->value('metadata'), true);
        $this->assertSame('follow_up_previous', $meta['doctor_reason_code']);
    }

    public function test_choosing_another_doctor_needs_a_fixed_reason_and_is_audited_as_an_override(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();
        $this->publish([$a, $b]);
        $this->enable();

        $pick = app(DoctorAssignmentService::class)->recommend($this->ca, $this->branch, CarbonImmutable::now(), null)['doctorId'];
        $other = $pick === $a->id ? $b : $a;

        $this->registerAs($this->payload($other))->assertSessionHasErrors('doctor_override_reason');
        $this->assertSame(0, Visit::query()->count());
        $this->registerAs($this->payload($other, ['doctor_override_reason' => 'not a code']))->assertSessionHasErrors('doctor_override_reason');

        $this->registerAs($this->payload($other, ['doctor_override_reason' => 'patient_requested']))->assertSessionHasNoErrors();
        $meta = json_decode((string) DB::table('audit_logs')->where('event', 'visit.created')->latest('id')->value('metadata'), true);
        $this->assertSame('override', $meta['doctor_source']);
        $this->assertSame('ca_override_patient_requested', $meta['doctor_reason_code']);

        // Choosing the recommended doctor needs no reason.
        $pick = app(DoctorAssignmentService::class)->recommend($this->ca, $this->branch, CarbonImmutable::now(), null)['doctorId'];
        $this->registerAs($this->payload(User::query()->findOrFail($pick)))->assertSessionHasNoErrors();
        $meta = json_decode((string) DB::table('audit_logs')->where('event', 'visit.created')->latest('id')->value('metadata'), true);
        $this->assertSame('manual', $meta['doctor_source']);
    }

    public function test_a_retry_with_the_same_key_returns_the_same_visit_and_doctor(): void
    {
        $a = $this->doctor();
        $this->doctor();
        $this->publish([$a]);
        $this->enable();
        $payload = $this->payload(null);

        $this->registerAs($payload)->assertSessionHasNoErrors();
        $first = Visit::query()->sole();
        $audits = DB::table('audit_logs')->count();
        $this->registerAs($payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Visit::query()->count());
        $this->assertSame($first->assigned_doctor_user_id, Visit::query()->sole()->assigned_doctor_user_id);
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_the_ca_page_lists_only_rostered_doctors_with_free_and_waiting_and_never_sales(): void
    {
        $rostered = $this->doctor();
        $this->doctor();
        $this->publish([$rostered]);
        $this->enable();
        DB::table('sales_targets')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
            'month' => CarbonImmutable::now($this->branch->timezone)->startOfMonth()->toDateString(), 'doctor_user_id' => $rostered->id, 'amount_sen' => 1000000,
            'lock_version' => 1, 'created_by_user_id' => $this->director->id, 'updated_by_user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->selectBranch($this->ca);
        $response = $this->get(route('registration.create'))->assertOk();
        $options = $response->viewData('page')['props']['options'];
        $this->assertTrue($options['autoAssign']['available']);
        $this->assertSame([['id' => $rostered->id, 'name' => $rostered->name, 'free' => true, 'waiting' => 0, 'room' => true]], $options['doctors']);
        $json = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString('1000000', $json);
        $this->assertStringNotContainsString('amount_sen', $json);
        $this->assertStringNotContainsString('percent', $json);
    }

    public function test_only_the_director_switches_it_and_a_stale_page_is_refused_and_audited(): void
    {
        $this->selectBranch($this->ca);
        $this->post(route('registration-settings.auto-assign'), ['expected_branch_id' => $this->branch->id, 'enabled' => true, 'lock_version' => 0])->assertForbidden();

        $this->selectBranch($this->director);
        $this->post(route('registration-settings.auto-assign'), ['expected_branch_id' => $this->branch->id, 'enabled' => true, 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertTrue((bool) DB::table('branch_auto_assign_settings')->value('enabled'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'registration.auto_assign.changed')->count());

        $this->post(route('registration-settings.auto-assign'), ['expected_branch_id' => $this->branch->id, 'enabled' => false, 'lock_version' => 0])->assertSessionHasErrors('lock_version');
        $this->assertTrue((bool) DB::table('branch_auto_assign_settings')->value('enabled'));
        $this->post(route('registration-settings.auto-assign'), ['expected_branch_id' => $this->branch->id, 'enabled' => false, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertFalse((bool) DB::table('branch_auto_assign_settings')->value('enabled'));
    }

    public function test_a_doctor_with_no_room_today_is_never_picked_once_the_branch_has_rooms(): void
    {
        $withRoom = $this->doctor();
        $noRoom = $this->doctor();
        $room = (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'kind' => 'consultation',
            'name' => 'Synthetic Room A', 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]);
        // The lower id would win a plain tie, so a pick of $withRoom proves the room rule, not the id order.
        [$first, $second] = $noRoom->id < $withRoom->id ? [$noRoom, $withRoom] : [$withRoom, $noRoom];
        $this->publish([$first, $second], [$withRoom->id => $room]);
        $this->enable();

        $this->registerAs($this->payload(null))->assertSessionHasNoErrors();
        $this->assertSame($withRoom->id, Visit::query()->sole()->assigned_doctor_user_id);

        $list = app(DoctorAssignmentService::class)->recommend($this->ca, $this->branch, CarbonImmutable::now(), null)['list'];
        $byId = collect($list)->keyBy('id');
        $this->assertFalse($byId[$noRoom->id]['room']);
        $this->assertFalse($byId[$noRoom->id]['free']);
        $this->assertSame($noRoom->id, $list[count($list) - 1]['id'], 'listed last');
    }

    public function test_a_doctor_who_is_not_on_todays_roster_cannot_be_chosen_even_by_a_direct_request(): void
    {
        $rostered = $this->doctor();
        $offRoster = $this->doctor();
        $this->publish([$rostered]);
        $this->enable();

        $this->registerAs($this->payload($offRoster, ['doctor_override_reason' => 'other']))->assertSessionHasErrors('assigned_doctor_user_id');
        $this->assertSame(0, Visit::query()->count());
        $this->registerAs($this->payload($rostered))->assertSessionHasNoErrors();
        $this->assertSame($rostered->id, Visit::query()->sole()->assigned_doctor_user_id);
    }

    public function test_a_retry_with_a_panel_note_is_a_replay_not_a_conflict_and_survives_the_switch_being_turned_off(): void
    {
        $a = $this->doctor();
        $this->publish([$a]);
        $this->enable();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic Auto Panel']);
        $payload = $this->payload(null, ['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_member_reference' => 'SYN-1',
            'coverage_panel_note' => 'Synthetic note', 'panel_note_panel_id' => $panel->id]);

        $this->registerAs($payload)->assertSessionHasNoErrors();
        $first = Visit::query()->sole();
        $audits = DB::table('audit_logs')->count();

        $this->registerAs($payload)->assertSessionHasNoErrors();
        DB::table('branch_auto_assign_settings')->update(['enabled' => false]);
        $this->registerAs($payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Visit::query()->count());
        $this->assertSame($first->assigned_doctor_user_id, Visit::query()->sole()->assigned_doctor_user_id);
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_when_auto_cannot_choose_anyone_it_says_why(): void
    {
        $a = $this->doctor();
        DB::table('branch_rooms')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'kind' => 'consultation',
            'name' => 'Synthetic Room B', 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->publish([$a]); // rostered, but the branch has rooms and this shift has none
        $this->enable();

        $this->registerAs($this->payload(null))->assertSessionHasErrors(['assigned_doctor_user_id' => "No doctor on today's roster has a room, so Auto cannot choose one. Choose a doctor yourself."]);
        $this->assertSame(0, Visit::query()->count());
    }

    public function test_when_nobody_is_on_the_published_roster_auto_says_so(): void
    {
        $this->doctor();
        $this->publish([$this->actor('ca')]); // a published week with staff on it, but no doctor
        $this->enable();

        $this->registerAs($this->payload(null))->assertSessionHasErrors(['assigned_doctor_user_id' => "No doctor is on today's roster at this branch, so Auto cannot choose one."]);
        $this->assertSame(0, Visit::query()->count());
    }

    public function test_the_ordering_rules(): void
    {
        $service = app(DoctorAssignmentService::class);
        $row = fn (int $id, ?float $percent, int $perPatient = 0, int $waiting = 0): array => ['id' => $id, 'percent' => $percent, 'perPatient' => $perPatient, 'waiting' => $waiting];
        $ids = fn (array $rows): array => array_column($rows, 'id');

        // Lowest progress first.
        $this->assertSame([2, 1, 3], $ids($service->freeOrder([$row(1, 50), $row(2, 10), $row(3, 90)])));
        // Within 5 points of the lowest: the lower sales per patient wins (owner's example: Dr 2 is more efficient).
        $this->assertSame([2, 1], $ids($service->freeOrder([$row(1, 40, 4000), $row(2, 43, 3500)])));
        // More than 5 points apart: progress decides.
        $this->assertSame([1, 2], $ids($service->freeOrder([$row(1, 40, 4000), $row(2, 46, 100)])));
        // No target: after everyone with one.
        $this->assertSame([3, 2, 1], $ids($service->freeOrder([$row(1, null, 100), $row(2, null, 50), $row(3, 99)])));
        // Full ties: fewer waiting, then id.
        $this->assertSame([2, 1], $ids($service->freeOrder([$row(1, 10, 0, 2), $row(2, 10, 0, 0)])));
        $this->assertSame([1, 2], $ids($service->freeOrder([$row(2, 10), $row(1, 10)])));
    }
}

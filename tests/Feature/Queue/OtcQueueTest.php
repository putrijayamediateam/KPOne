<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

class OtcQueueTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @param array<string, mixed> $f */
    private function enter(array $f): OtcQueueEntry
    {
        return app(OtcQueueService::class)->enter($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id]);
    }

    /** @param array<string, mixed> $f */
    private function row(array $f): array
    {
        return app(VisitDirectoryService::class)->search($f['ca'], [])['data']->firstWhere('visitNumber', $f['visit']->visit_number);
    }

    public function test_an_otc_patient_takes_a_b_number_and_waits_in_a_list_of_their_own(): void
    {
        $f = $this->fixture();
        $this->assertTrue($this->row($f)['can']['sendToWaiting']);
        $this->assertNull($this->row($f)['queueNumber']);

        $entry = $this->enter($f);

        $this->assertSame(1, $entry->queue_number);
        $this->assertSame(OtcQueueEntry::STATUS_WAITING, $entry->status);
        $this->assertSame('B-001', $this->row($f)['queueNumber']);
        $this->assertSame('waiting', $this->row($f)['queueStatus']);
        $this->assertFalse($this->row($f)['can']['sendToWaiting']);
        $this->assertTrue($this->row($f)['can']['callDispensary']);
        $this->assertSame(0, QueueEntry::query()->count(), 'the consultation queue is untouched');
    }

    public function test_sending_to_waiting_twice_returns_the_same_entry_and_consultation_visits_are_refused(): void
    {
        $f = $this->fixture();
        $this->assertSame($this->enter($f)->id, $this->enter($f)->id);
        $this->assertSame(1, OtcQueueEntry::query()->count());

        $g = $this->fixture('consultation');
        $this->expectException(ValidationException::class);
        app(OtcQueueService::class)->enter($g['ca'], $g['visit'], ['expected_branch_id' => $g['visit']->branch_id]);
    }

    public function test_numbers_are_in_order_never_reused_on_the_same_day_and_restart_the_next_day(): void
    {
        $f = $this->fixture();
        $first = $this->enter($f);
        $second = $this->register($f['ca'], $this->patient());
        $secondEntry = app(OtcQueueService::class)->enter($f['ca'], $second, ['expected_branch_id' => $second->branch_id]);
        $this->assertSame([1, 2], [$first->queue_number, $secondEntry->queue_number]);

        // The first patient leaves the list (dispensing); their number is not handed to anyone else today.
        app(OtcDispensaryService::class)->open($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id]);
        $third = $this->register($f['ca'], $this->patient());
        $thirdEntry = app(OtcQueueService::class)->enter($f['ca'], $third, ['expected_branch_id' => $third->branch_id]);
        $this->assertSame(3, $thirdEntry->queue_number);

        $this->travel(1)->day();
        $next = $this->register($f['ca'], $this->patient());
        $nextEntry = app(OtcQueueService::class)->enter($f['ca'], $next, ['expected_branch_id' => $next->branch_id]);
        $this->assertSame(1, $nextEntry->queue_number, 'a new clinic day starts again at 001');
    }

    public function test_the_otc_series_has_its_own_counter_and_leaves_the_consultation_series_alone(): void
    {
        $f = $this->fixture();
        $this->enter($f);

        $counters = DB::table('queue_number_counters')->get(['series', 'next_value']);
        $this->assertSame(['B'], $counters->pluck('series')->all());
        $this->assertSame(2, (int) $counters->sole()->next_value);
        $this->assertSame(0, QueueEntry::query()->count());
    }

    public function test_dispense_works_without_a_call_and_takes_the_patient_off_the_list(): void
    {
        $f = $this->fixture();
        $entry = $this->enter($f);

        $this->open($f);

        $entry->refresh();
        $this->assertSame(OtcQueueEntry::STATUS_REMOVED, $entry->status);
        $this->assertSame(OtcQueueEntry::REASON_DISPENSING, $entry->removal_reason);
        $this->assertSame(0, QueueCall::query()->count(), 'no call was needed');
        $this->assertFalse($this->row($f)['can']['callDispensary']);
    }

    public function test_dispense_still_works_for_a_patient_who_never_joined_the_list(): void
    {
        $f = $this->fixture();

        $case = $this->open($f);

        $this->assertSame(DispensaryCase::TYPE_OTC, $case->case_type);
        $this->assertSame(0, OtcQueueEntry::query()->count());
    }

    public function test_the_ca_calls_the_b_number_to_the_dispensary_and_the_tv_shows_it(): void
    {
        $f = $this->fixture();
        $this->enter($f);
        $branch = $f['visit']->branch;

        $call = app(RoomCallService::class)->callOtcToDispensary($f['ca'], $f['visit'], null, ['expected_branch_id' => $f['visit']->branch_id]);

        $this->assertNull($call->queue_entry_id);
        $this->assertSame('B', $call->queue_series);
        $this->assertSame(QueueCall::SERVICE_DISPENSARY, $call->service);
        $this->assertFalse($call->is_recall);
        $feed = app(QueueDisplayFeedService::class)->feed($branch);
        $this->assertSame('B-001', $feed['calls'][0]['number']);
        $this->assertSame('dispensary', $feed['calls'][0]['service']);
        $this->assertStringNotContainsString($f['visit']->patient->full_name, json_encode($feed, JSON_THROW_ON_ERROR), 'the feed carries no patient identity');
        $this->assertSame('waiting', $this->row($f)['queueStatus'], 'a call only announces');
    }

    public function test_in_name_mode_an_otc_call_shows_the_full_registered_name(): void
    {
        $f = $this->fixture();
        $this->enter($f);
        app(QueueDisplayAdministrationService::class)->updateSettings($this->actor('ca_supervisor'), $f['visit']->branch, [
            'ticker_text' => null, 'youtube_url' => null, 'poster_seconds' => 10, 'call_display_mode' => 'name', 'lock_version' => 0,
        ]);
        app(RoomCallService::class)->callOtcToDispensary($f['ca'], $f['visit'], null, ['expected_branch_id' => $f['visit']->branch_id]);

        $call = app(QueueDisplayFeedService::class)->feed($f['visit']->branch)['calls'][0];

        $this->assertSame($f['visit']->patient->full_name, $call['name']);
        $this->assertSame('B-001', $call['number']);
    }

    public function test_a_repeat_call_is_a_recall_after_the_cooldown_and_refused_inside_it(): void
    {
        $f = $this->fixture();
        $this->enter($f);
        $calls = app(RoomCallService::class);
        $attributes = ['expected_branch_id' => $f['visit']->branch_id];
        $calls->callOtcToDispensary($f['ca'], $f['visit'], null, $attributes);

        try {
            $calls->callOtcToDispensary($f['ca'], $f['visit'], null, $attributes);
            $this->fail('A second call inside the cooldown must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('queue', $e->errors());
        }

        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        $again = $calls->callOtcToDispensary($f['ca'], $f['visit'], null, $attributes);
        $this->assertTrue($again->is_recall);
    }

    public function test_only_a_waiting_otc_patient_can_be_called(): void
    {
        $f = $this->fixture();
        try {
            app(RoomCallService::class)->callOtcToDispensary($f['ca'], $f['visit'], null, ['expected_branch_id' => $f['visit']->branch_id]);
            $this->fail('A patient who is not on the list cannot be called.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('queue', $e->errors());
        }

        $this->enter($f);
        $this->open($f);
        $this->expectException(ValidationException::class);
        app(RoomCallService::class)->callOtcToDispensary($f['ca'], $f['visit'], null, ['expected_branch_id' => $f['visit']->branch_id]);
    }

    public function test_cancelling_the_visit_takes_the_patient_off_the_list(): void
    {
        $f = $this->fixture();
        $entry = $this->enter($f);
        $visit = $f['visit']->refresh();

        app(VisitAdministrationService::class)->cancel($visit, [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => $visit->lock_version, 'cancellation_reason' => 'Synthetic cancellation',
        ], $f['ca']);

        $this->assertSame(Visit::STATUS_CANCELLED, $visit->refresh()->status);
        $this->assertSame(OtcQueueEntry::REASON_CANCELLED, $entry->refresh()->removal_reason);
    }

    public function test_the_http_routes_send_to_waiting_and_call(): void
    {
        $f = $this->fixture();
        $visit = $f['visit'];

        $this->post(route('queue.store', $visit), ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version])
            ->assertRedirect(route('registration.index'));
        $this->assertSame(1, OtcQueueEntry::query()->count());

        $this->post(route('otc-queue.call', $visit), ['expected_branch_id' => $visit->branch_id])->assertRedirect(route('registration.index'));
        $this->assertSame(1, QueueCall::query()->where('queue_series', 'B')->count());

        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor, $visit->branch);
        $this->post(route('otc-queue.call', $visit), ['expected_branch_id' => $visit->branch_id])->assertForbidden();
    }

    public function test_the_database_requires_a_call_to_point_at_exactly_one_entry(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint.');
        }
        $f = $this->fixture();
        $entry = $this->enter($f);

        $this->expectException(QueryException::class);
        DB::table('queue_calls')->insert([
            'organisation_id' => $entry->organisation_id, 'branch_id' => $entry->branch_id, 'queue_entry_id' => null, 'otc_queue_entry_id' => null,
            'queue_series' => 'A', 'service' => 'dispensary', 'is_recall' => false, 'queue_number' => 1, 'operational_date' => now()->toDateString(),
            'called_by_user_id' => $f['ca']->id, 'called_at' => now(), 'created_at' => now(),
        ]);
    }

    public function test_a_number_format_is_a_series_letter_and_three_digits(): void
    {
        $this->assertSame('A-001', QueueNumberFormat::format(1));
        $this->assertSame('B-014', QueueNumberFormat::format(14, QueueNumberFormat::OTC));
    }
}

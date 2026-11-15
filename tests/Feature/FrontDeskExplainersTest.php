<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\DepartmentSpace\Explain\FrontDeskExplainers;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Queue\QueueTestCase;

/** EXPLAIN-1: the four Queue and Front desk explainers of the Ask panel. */
class FrontDeskExplainersTest extends QueueTestCase
{
    private const KEYS = ['front_desk.waits_week', 'front_desk.waits_today', 'front_desk.busiest_hours', 'front_desk.left_waiting'];

    private const TZ = 'Asia/Kuala_Lumpur';

    private int $number = 0;

    private int $patientId;

    private User $doctorOne;

    private User $doctorTwo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patientId = $this->patient($this->actor('ca'), ['full_name' => 'Zainal Uniquename'])->id;
        $this->doctorOne = $this->doctor();
        $this->doctorTwo = $this->doctor();
        // Wednesday 11 Nov 2026, 15:00 in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 15:00', self::TZ));
    }

    /** @param array{priority?: string, doctor?: User|null, branch?: int, status?: string, removed?: string|null} $o */
    private function entry(string $queued, ?string $called, array $o = []): QueueEntry
    {
        $branchId = $o['branch'] ?? $this->branch->id;
        $visit = Visit::factory()->create([
            'organisation_id' => $this->organisation->id,
            'branch_id' => $branchId,
            'patient_id' => $this->patientId,
            'visit_type' => 'consultation',
            'visit_reason' => 'Synthetic explainer reason',
            'priority' => $o['priority'] ?? 'normal',
            'assigned_doctor_user_id' => array_key_exists('doctor', $o) ? $o['doctor']?->id : $this->doctorOne->id,
        ]);
        $q = CarbonImmutable::parse($queued, self::TZ);
        $c = $called === null ? null : CarbonImmutable::parse($called, self::TZ);
        $status = $o['status'] ?? ($c === null ? 'waiting' : 'removed');
        $removed = array_key_exists('removed', $o) ? ($o['removed'] === null ? null : CarbonImmutable::parse($o['removed'], self::TZ)) : ($status === 'removed' ? ($c ?? $q)->addMinutes(30) : null);

        return QueueEntry::factory()->create([
            'visit_id' => $visit->id,
            'organisation_id' => $this->organisation->id,
            'branch_id' => $branchId,
            'operational_date' => $q->toDateString(),
            'queue_number' => ++$this->number,
            'status' => $status,
            'queued_at' => $q->utc(),
            'called_at' => $c?->utc(),
            'called_by_user_id' => $c === null ? null : $this->actor('ca')->id,
            'removed_at' => $removed?->utc(),
        ]);
    }

    private function ask(string $key): array
    {
        return $this->postJson('/spaces/front_desk/ask', ['question' => $key])->assertOk()->json();
    }

    public function test_each_question_needs_both_permissions_and_every_other_role_gets_403(): void
    {
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $both = in_array('visits.view.branch', $permissions, true) && in_array('queue.view.branch', $permissions, true);
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            foreach (self::KEYS as $key) {
                $this->postJson('/spaces/front_desk/ask', ['question' => $key])->assertStatus($both ? 200 : 403);
            }
        }
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->postJson('/spaces/pharmacy/ask', ['question' => self::KEYS[0]])->assertNotFound();
        $this->getJson('/spaces/front_desk')->assertOk();
    }

    public function test_the_week_answer_names_the_biggest_change_with_what_coincided_and_the_survivor_footnotes(): void
    {
        // Last week Monday 2 Nov and Tuesday 3 Nov; this week Monday 9 Nov and Tuesday 10 Nov. Same cut: Wednesday 15:00.
        foreach ([['2026-11-02', '2026-11-03'], ['2026-11-09', '2026-11-10']] as $week => [$mon, $tue]) {
            for ($i = 0; $i < 10; $i++) {
                $this->entry("$mon 09:00", "$mon 09:10");
                $doctor = $week === 0 && $i % 2 === 1 ? $this->doctorTwo : $this->doctorOne;
                $this->entry("$tue 14:00", $tue.' '.($week === 0 ? '14:10' : '14:40'), ['doctor' => $doctor]);
            }
        }
        $this->entry('2026-11-11 14:19', null);
        $this->entry('2026-11-10 09:30', '2026-11-10 09:35', ['priority' => 'urgent']);
        $this->entry('2026-11-10 23:50', '2026-11-11 00:10');

        $this->selectBranch($this->actor('ca'));
        $json = $this->ask('front_desk.waits_week');

        $this->assertSame(
            'Average wait was 15 min longer this week (25 min) than last week (10 min). Patients counted: 20 this week, 20 last week, same days and time. '
            .'Biggest change: Tue afternoon (+30 min; coincided with only 1 doctor calling patients instead of 2). '
            .'1 patient is still waiting now, the longest for 41 min. '
            .'Urgent patients (1 this week, 0 last week) are left out of these averages. '
            .'1 patient who was called on a later day was left out.',
            $json['sentence'],
        );
        $this->assertSame(['Day and time', 'This week', 'Last week', 'Change'], $json['columns']);
        $this->assertSame([['All days so far', '25 min this week', '10 min last week', '+15 min'], ['Tue afternoon', '40 min this week', '10 min last week', '+30 min']], $json['rows']);
        $this->assertSame('/queue', $json['href']);
        $this->assertSame(['key', 'label', 'sentence', 'columns', 'rows', 'asOf', 'href'], array_keys($json));
    }

    public function test_a_small_sample_says_not_enough_data_and_never_guesses(): void
    {
        $this->selectBranch($this->actor('ca'));
        foreach (['front_desk.waits_week', 'front_desk.waits_today', 'front_desk.busiest_hours'] as $key) {
            $this->assertStringStartsWith('Not enough data to', $this->ask($key)['sentence'], $key);
        }
        $json = $this->ask('front_desk.waits_week');
        $this->assertSame('Not enough data to compare waits (0 patients called this week, 0 last week; 20 are needed in each).', $json['sentence']);
        $this->assertSame([], $json['rows']);

        for ($i = 0; $i < 19; $i++) {
            $this->entry('2026-11-09 09:00', '2026-11-09 09:30');
        }
        for ($i = 0; $i < 25; $i++) {
            $this->entry('2026-11-02 09:00', '2026-11-02 09:10');
        }
        $this->assertStringContainsString('(19 patients called this week, 25 last week;', $this->ask('front_desk.waits_week')['sentence']);
    }

    public function test_left_before_called_counts_only_removed_entries_without_a_call(): void
    {
        $this->selectBranch($this->actor('ca'));
        $this->assertStringStartsWith('Nobody was removed or cancelled while waiting', $this->ask('front_desk.left_waiting')['sentence']);

        for ($i = 0; $i < 6; $i++) {
            $this->entry('2026-11-10 14:00', null, ['status' => 'removed', 'removed' => '2026-11-10 14:24']);
        }
        $this->entry('2026-11-10 10:00', null, ['status' => 'removed', 'removed' => '2026-11-10 10:05']);
        $this->entry('2026-11-10 10:00', '2026-11-10 10:10');
        $this->entry('2026-11-11 14:00', null);
        $this->entry('2026-11-03 14:00', null, ['status' => 'removed', 'removed' => '2026-11-03 14:10']);

        $json = $this->ask('front_desk.left_waiting');
        $this->assertSame(
            '7 patients were removed or cancelled while waiting this week, against 1 last week (same days and time). '
            .'Most (6) were in the afternoon. Half of them had waited 24 min or less before they left. KPOne does not record why a patient leaves.',
            $json['sentence'],
        );
        $this->assertSame([['Morning', '1 this week', '0 last week'], ['Afternoon', '6 this week', '1 last week'], ['Evening', '0 this week', '0 last week']], $json['rows']);
    }

    public function test_busiest_hours_use_arrivals_and_doctors_who_had_a_patient_called(): void
    {
        foreach (range(1, 10) as $day) {
            $date = CarbonImmutable::parse('2026-11-11', self::TZ)->subDays($day)->toDateString();
            for ($i = 0; $i < 6; $i++) {
                $this->entry("$date 10:00", "$date 10:20", ['doctor' => $i % 2 === 0 ? $this->doctorOne : $this->doctorTwo]);
            }
            $this->entry("$date 15:00", "$date 15:05");
        }
        $this->selectBranch($this->actor('ca'));
        $json = $this->ask('front_desk.busiest_hours');

        $this->assertStringStartsWith('Busiest hours, busiest first: 10:00 (6 a day) and 15:00 (1 a day), over the last 4 weeks.', $json['sentence']);
        $this->assertStringContainsString('At 10:00, 3.0 patients arrived per doctor who had a patient called, against 2.3 across all hours, and the average wait was 20 min.', $json['sentence']);
        $this->assertSame(['Hour', 'Arrivals a day', 'Average wait', 'Arrivals per doctor'], $json['columns']);
        $this->assertSame(['10:00', '6 arrivals a day', '20 min average wait', '3.0 per doctor'], $json['rows'][0]);
    }

    public function test_only_the_active_branch_is_read(): void
    {
        $other = $this->organisation->branches()->whereKeyNot($this->branch->id)->firstOrFail();
        for ($i = 0; $i < 30; $i++) {
            $this->entry('2026-11-09 09:00', '2026-11-09 09:50', ['branch' => $other->id]);
            $this->entry('2026-11-02 09:00', '2026-11-02 09:05', ['branch' => $other->id]);
        }
        $this->selectBranch($this->actor('ca'));

        $this->assertStringStartsWith('Not enough data to compare waits (0 patients called this week, 0 last week', $this->ask('front_desk.waits_week')['sentence']);
    }

    public function test_no_answer_carries_a_name_a_queue_number_or_an_id(): void
    {
        foreach ([['2026-11-02', '2026-11-03'], ['2026-11-09', '2026-11-10']] as $week => [$mon, $tue]) {
            for ($i = 0; $i < 12; $i++) {
                $this->entry("$mon 09:00", "$mon 09:10");
                $this->entry("$tue 14:00", $tue.' '.($week === 0 ? '14:10' : '14:50'));
            }
        }
        $this->entry('2026-11-10 14:00', null, ['status' => 'removed', 'removed' => '2026-11-10 14:20']);
        $this->selectBranch($this->actor('ca'));

        foreach (self::KEYS as $key) {
            $text = json_encode($this->ask($key), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Uniquename', $text, $key);
            $this->assertStringNotContainsString('Zainal', $text, $key);
            $this->assertDoesNotMatchRegularExpression('/\b[A-Z]-\d{3}\b/', $text, $key);
            $this->assertDoesNotMatchRegularExpression('/@|\d{9,}|KPV-|KP-\d/', $text, $key);
            $this->assertSame(['key', 'label', 'sentence', 'columns', 'rows', 'asOf', 'href'], array_keys(json_decode($text, true)), $key);
        }
    }

    public function test_each_answer_reads_the_queue_once_and_writes_only_the_structural_audit_row(): void
    {
        $this->entry('2026-11-10 09:00', '2026-11-10 09:10');
        $this->selectBranch($this->actor('ca'));
        $before = DB::table('queue_entries')->count();
        foreach (self::KEYS as $key) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->ask($key);
            $reads = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], '"queue_entries"') && str_starts_with($q['query'], 'select'));
            DB::disableQueryLog();
            $this->assertCount(1, $reads, $key);
        }
        $this->assertSame($before, DB::table('queue_entries')->count());
        $rows = AuditLog::query()->where('event', 'space_ask.answered')->get();
        $this->assertCount(4, $rows);
        $this->assertSame(['question'], array_keys((array) $rows->first()->metadata));
    }

    public function test_a_busy_branch_is_answered_from_one_bounded_read(): void
    {
        $base = $this->entry('2026-11-10 09:00', '2026-11-10 09:10');
        $visit = Visit::query()->findOrFail($base->visit_id)->getAttributes();
        $entry = $base->getAttributes();
        $visits = $queues = [];
        for ($i = 1; $i <= 6000; $i++) {
            $id = 500000 + $i;
            $day = CarbonImmutable::parse('2026-11-11', self::TZ)->subDays(1 + intdiv($i, 250))->setTime(8 + $i % 9, $i % 50);
            $v = $visit;
            $v['id'] = $id;
            $v['visit_number'] = 'EXPL-V-'.$i;
            $v['idempotency_key'] = (string) Str::uuid();
            $visits[] = $v;
            $q = $entry;
            unset($q['id']);
            $q['visit_id'] = $id;
            $q['queue_number'] = 100000 + $i;
            $q['operational_date'] = $day->toDateString();
            $q['queued_at'] = $day->utc()->toDateTimeString();
            $q['called_at'] = $day->addMinutes(10 + $i % 40)->utc()->toDateTimeString();
            $q['removed_at'] = $day->addMinutes(50)->utc()->toDateTimeString();
            $queues[] = $q;
        }
        foreach (array_chunk($visits, 300) as $chunk) {
            DB::table('visits')->insert($chunk);
        }
        foreach (array_chunk($queues, 300) as $chunk) {
            DB::table('queue_entries')->insert($chunk);
        }
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $json = $this->ask('front_desk.busiest_hours');
        $this->assertStringStartsWith('Busiest hours, busiest first:', $json['sentence']);
        // Deterministic bounds on a big branch, with no clock: each explainer reads the queue once, only inside a
        // window of at most 30 days, and takes a pinned number of queries in all, so a read per entry would fail.
        $queries = ['waitsWeek' => 0, 'waitsToday' => 0, 'busiestHours' => 0, 'leftWaiting' => 0];
        foreach ($queries as $method => $_) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(FrontDeskExplainers::class)->{$method}($ca);
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            $reads = array_values(array_filter($log, fn (array $q): bool => str_contains($q['query'], '"queue_entries"') && str_starts_with($q['query'], 'select')));
            $this->assertCount(1, $reads, $method.' reads the queue once');

            $dates = array_values(array_filter($reads[0]['bindings'], fn ($b): bool => is_string($b) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b) === 1));
            $this->assertCount(2, $dates, $method.' reads inside a date window');
            $days = CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse($dates[1]), true);
            $this->assertLessThanOrEqual(30, $days, $method.' window is at most 30 days');

            $this->assertCount(1, $log, $method.' takes one query in all: '.implode(' | ', array_map(fn (array $q): string => substr($q['query'], 0, 70), $log)));
        }
    }
}

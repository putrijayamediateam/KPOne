<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Policies\QueueEntryPolicy;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReportEvidence;

class PilotBoardScaleTest extends QueueTestCase
{
    use ReportEvidence;

    public function test_four_hundred_waiting_visits_have_bounded_registration_queue_and_tv_reads(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca_supervisor');
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $visits = $queues = [];
        for ($index = 1; $index < 400; $index++) {
            $id = 100000 + $index;
            $v = $visit->getAttributes();
            $v['id'] = $id;
            $v['visit_number'] = 'SCALE-V-'.$index;
            $v['idempotency_key'] = (string) Str::uuid();
            $visits[] = $v;
            $q = $queue->getAttributes();
            unset($q['id']);
            $q['visit_id'] = $id;
            $q['queue_number'] = $index + 1;
            $queues[] = $q;
        }
        foreach (array_chunk($visits, 100) as $chunk) {
            DB::table('visits')->insert($chunk);
        }
        foreach (array_chunk($queues, 100) as $chunk) {
            DB::table('queue_entries')->insert($chunk);
        }
        $before = $this->sourceEvidence();
        foreach (['registration' => fn () => app(VisitDirectoryService::class)->search($ca, []), 'queue' => fn () => app(QueueDirectoryService::class)->snapshot($ca), 'tv' => fn () => app(QueueDisplayFeedService::class)->feed($this->branch)] as $key => $read) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $output = $read();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual($key === 'registration' ? 35 : 25, count($queries), $key);
            $this->assertLessThan(100000, strlen(json_encode($output, JSON_THROW_ON_ERROR)), $key);
            if ($key === 'registration') {
                $this->assertSame(400, $output['total']);
                $this->assertCount(25, $output['data']);
                $assignments = array_filter($queries, fn ($query): bool => str_starts_with($query['query'], 'select exists') && str_contains($query['query'], 'staff_branch_assignments'));
                $this->assertLessThanOrEqual(3, count($assignments));
            }
        }
        $this->assertSame($before, $this->sourceEvidence());
    }

    public function test_request_local_board_hints_equal_existing_policies_for_every_catalogue_role(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $queue->setRelation('visit', $visit);
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $policy = app(QueueEntryPolicy::class);
            $hint = ($policy->boardHints($actor, $this->branch))($queue);
            $this->assertSame($policy->view($actor, $queue), $hint['view'], $role.' view');
            $this->assertSame($policy->call($actor, $queue), $hint['call'], $role.' call');
            $foreign = new QueueEntry;
            $foreign->forceFill(['organisation_id' => $actor->organisation_id + 999, 'branch_id' => $this->branch->id]);
            $this->assertSame(['view' => false, 'call' => false], ($policy->boardHints($actor, $this->branch))($foreign));
        }
        $this->selectBranch($doctor);
        $policy = app(QueueEntryPolicy::class);
        $this->assertSame(['view' => true, 'call' => true], ($policy->boardHints($doctor, $this->branch))($queue));
    }
}

<?php

namespace Tests\Feature\Queue;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\QueueCallStatsService;
use App\Domain\Queue\Models\QueueCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

class QueueCallStatsBoundaryTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->branch->forceFill(['timezone' => 'Asia/Kuala_Lumpur'])->save();
        Date::setTestNow(CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc());
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function recordFor(Branch $branch, string $day, bool $recall = false): void
    {
        $original = $this->branch;
        $this->branch = $branch;
        try {
            $actor = $this->actor('ca', $branch);
            $entry = $this->send($actor, $this->consultationVisit($actor, $this->doctor($branch)));
            (new QueueCall)->forceFill([
                'organisation_id' => $branch->organisation_id,
                'branch_id' => $branch->id,
                'queue_entry_id' => $entry->id,
                'queue_series' => 'A',
                'service' => 'consultation',
                'is_recall' => $recall,
                'room_name' => 'Synthetic room',
                'queue_number' => $entry->queue_number,
                'operational_date' => $day,
                'called_by_user_id' => $actor->id,
                'called_at' => now()->utc(),
            ])->save();
        } finally {
            $this->branch = $original;
        }
    }

    private function otherBranch(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)
            ->where('id', '!=', $this->branch->id)->where('is_active', true)->firstOrFail();
    }

    public function test_local_midnight_rolls_the_operational_day_without_mixing_other_branches_or_recalls(): void
    {
        $this->recordFor($this->branch, '2026-08-25');
        $this->recordFor($this->branch, '2026-08-26');
        $this->recordFor($this->branch, '2026-08-26', true);
        $this->recordFor($this->otherBranch(), '2026-08-26', true);
        $service = app(QueueCallStatsService::class);

        Date::setTestNow(CarbonImmutable::parse('2026-08-25 23:59:59', $this->branch->timezone)->utc());
        $before = $service->today($this->branch);
        $this->assertSame('2026-08-25', $before['operationalDate']);
        $this->assertSame(1, $before['total']);
        $this->assertSame(0, $before['recalls']);

        Date::setTestNow(CarbonImmutable::parse('2026-08-26 00:00:00', $this->branch->timezone)->utc());
        $after = $service->today($this->branch);
        $this->assertSame('2026-08-26', $after['operationalDate']);
        $this->assertSame(2, $after['total']);
        $this->assertSame(1, $after['recalls']);
        $this->assertSame(['calls' => 1, 'recalls' => 1], $after['byService']['consultation']);
        $this->assertSame([['room' => 'Synthetic room', 'calls' => 2]], $after['byRoom']);
    }

    public function test_a_branch_supervisor_cannot_select_an_unassigned_branch_to_read_its_counts(): void
    {
        $other = $this->otherBranch();
        $this->recordFor($this->branch, '2026-08-25');
        $this->recordFor($other, '2026-08-25');
        $this->recordFor($other, '2026-08-25', true);
        $this->actingAs($this->actor('ca_supervisor'));

        $this->get(route('queue-display.settings', ['branch' => $other->id]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('branch.id', $this->branch->id)
                ->where('callsToday.total', 1)
                ->where('callsToday.recalls', 0));
    }

    public function test_a_tv_account_cannot_read_the_settings_statistics(): void
    {
        $this->recordFor($this->branch, '2026-08-25');
        $this->actingAs($this->actor('queue_display'));
        $this->get(route('queue-display.settings'))->assertForbidden();
    }
}

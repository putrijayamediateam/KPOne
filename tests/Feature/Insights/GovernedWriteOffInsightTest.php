<?php

namespace Tests\Feature\Insights;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-15b: "Recorded wastage" also counts approved STK-5 write-offs, and leaves out a write-off whose reversal was approved. */
final class GovernedWriteOffInsightTest extends VisitTestCase
{
    /** @var array<string, mixed> */
    private array $f;

    private Branch $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at = $this->branch;
    }

    private function actorAt(string $role): User
    {
        $actor = (new GovernedStockFixture($this->at))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->at);

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function proposal(array $overrides = []): array
    {
        return ['expected_branch_id' => $this->at->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $this->f['balance']->id,
            'balance_version' => DB::table('inventory_stock_balances')->where('id', $this->f['balance']->id)->value('lock_version'), 'unit' => 'unit', ...$overrides];
    }

    /** @return array<string, mixed> */
    private function command(int $caseId, ?string $quantity = null): array
    {
        return ['expected_branch_id' => $this->at->id, 'lock_version' => DB::table('inventory_governed_actions')->where('case_id', $caseId)->max('lock_version'),
            'retry_key' => (string) Str::uuid(), ...($quantity !== null ? ['quantity' => $quantity, 'unit' => 'unit'] : [])];
    }

    private function writeOff(string $quantity, string $reason, bool $approve = true): int
    {
        app(BranchAccessService::class)->select($this->f['proposer'], $this->at);
        $id = app(GovernedStockService::class)->propose($this->f['proposer'], 'writeoff', $this->proposal(['quantity' => $quantity, 'reason' => $reason, 'disposal_reference' => 'SYN-DISPOSAL']));
        if ($approve) {
            app(GovernedStockService::class)->act($this->actorAt('finance_officer'), $id, 'approved', $this->command($id));
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function summary(string $date): array
    {
        return app(ClinicInsightsReportService::class)->report($this->actorAt('director'), 'inventory', $this->at->code, $date, $date, null)['report']['summary'];
    }

    public function test_recorded_wastage_counts_approved_write_offs_once_and_leaves_out_reversed_and_pending_ones(): void
    {
        // Fixed branch-local morning, so "today" and "yesterday" never straddle Kuala Lumpur midnight.
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $this->f = (new GovernedStockFixture($this->branch))->build('20.000');
        $service = app(GovernedStockService::class);

        // A governed count loss of 2 posts stocktake_loss, which Recorded wastage already counted before STK-15b.
        app(BranchAccessService::class)->select($this->f['proposer'], $this->branch);
        $count = $service->propose($this->f['proposer'], 'count', $this->proposal());
        $service->act($this->f['proposer'], $count, 'counted', $this->command($count, '18.000'));
        $service->act($this->actorAt('ca_supervisor'), $count, 'recounted', $this->command($count, '18.000'));
        $service->act($this->actorAt('finance_officer'), $count, 'approved', $this->command($count));

        $damaged = $this->writeOff('1.500', 'damaged');
        $reversed = $this->writeOff('0.500', 'contaminated');
        $this->writeOff('3.000', 'other_approved', approve: false);
        app(BranchAccessService::class)->select($this->f['proposer'], $this->branch);
        $reversal = $service->propose($this->f['proposer'], 'reversal', $this->proposal(['reverses_case_id' => $reversed]));
        $service->act($this->actorAt('finance_officer'), $reversal, 'approved', $this->command($reversal));

        // A reversal that is proposed but never approved leaves the damaged write-off counted.
        app(BranchAccessService::class)->select($this->f['proposer'], $this->branch);
        $service->propose($this->f['proposer'], 'reversal', $this->proposal(['reverses_case_id' => $damaged]));

        // An expiry write-off: the batch expires today.
        $this->f['batch']->forceFill(['expiry_date' => now($this->branch->timezone)->toDateString()])->save();
        $this->writeOff('1.000', 'expired');
        $this->assertSame(['expiry_writeoff', 'stock_writeoff', 'stock_writeoff'], DB::table('stock_movements')->whereIn('movement_type', ['expiry_writeoff', 'stock_writeoff'])->orderBy('movement_type')->pluck('movement_type')->all());

        // 2 (count loss) + 1.5 (damaged) + 1 (expired). The reversed 0.5 and the pending 3 are left out.
        $this->assertSame(4.5, (float) $this->summary(now($this->branch->timezone)->toDateString())['wastageUnits']);
        // A period before any of it counts nothing.
        $this->assertSame(0.0, (float) $this->summary(now($this->branch->timezone)->subDay()->toDateString())['wastageUnits']);
        // Treatment-room waste stays on its own card.
        $this->assertSame(0.0, (float) $this->summary(now($this->branch->timezone)->toDateString())['treatmentWasteUnits']);
    }

    public function test_a_write_off_reversed_on_a_later_day_leaves_its_own_day(): void
    {
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $this->f = (new GovernedStockFixture($this->branch))->build('10.000');
        $day = now($this->branch->timezone)->toDateString();
        $id = $this->writeOff('2.000', 'damaged');
        $this->assertSame(2.0, (float) $this->summary($day)['wastageUnits']);

        $this->travel(1)->days();
        app(BranchAccessService::class)->select($this->f['proposer'], $this->branch);
        $reversal = app(GovernedStockService::class)->propose($this->f['proposer'], 'reversal', $this->proposal(['reverses_case_id' => $id]));
        app(GovernedStockService::class)->act($this->actorAt('finance_officer'), $reversal, 'approved', $this->command($reversal));
        $this->assertSame(0.0, (float) $this->summary($day)['wastageUnits']);
    }

    public function test_a_write_off_counts_only_under_its_own_branch_and_once_across_branches(): void
    {
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $this->f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->writeOff('1.000', 'damaged');
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        $this->at = $other;
        $this->f = (new GovernedStockFixture($other))->build('10.000');
        $this->writeOff('0.750', 'contaminated');

        $day = now($this->branch->timezone)->toDateString();
        $director = $this->actorAt('director');
        $report = fn (string $branches): float => (float) app(ClinicInsightsReportService::class)->report($director, 'inventory', $branches, $day, $day, null)['report']['summary']['wastageUnits'];
        $this->assertSame(1.0, $report($this->branch->code));
        $this->assertSame(0.75, $report($other->code));
        // Every branch together: each write-off counted once.
        $this->assertSame(1.75, $report('all'));
    }
}

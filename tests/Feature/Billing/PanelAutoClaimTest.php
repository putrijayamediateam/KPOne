<?php

namespace Tests\Feature\Billing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimAutoCreator;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PNL-28a: a Prepared Panel claim is created by the system when a Panel bill is approved, and by the
 * "Create claims for waiting invoices" button. The automatic claim is off in the test environment, so
 * these tests switch it on.
 */
class PanelAutoClaimTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    private function panel(): Panel
    {
        return Panel::factory()->create(['organisation_id' => $this->organisation->id]);
    }

    public function test_approving_a_panel_bill_prepares_one_claim_and_the_audit_names_the_approver(): void
    {
        config(['panel-claims.auto_create' => true]);
        [$allocation, $invoice] = $this->approvedAllocation($this->panel());

        $claim = PanelClaim::query()->sole();
        $this->assertSame('prepared', $claim->status);
        $this->assertSame($allocation->id, $claim->coverage_allocation_id);
        $this->assertSame(3000, $claim->claim_amount_sen);
        $this->assertSame($this->officer()->id, $claim->created_by_user_id);
        $event = DB::table('panel_claim_events')->where('panel_claim_id', $claim->id)->sole();
        $this->assertSame('prepared', $event->event_type);
        $this->assertSame(PanelClaimService::AUTO_NOTE, $event->note);

        $audit = AuditLog::query()->where('event', 'panel_claim.auto_created')->sole();
        $this->assertTrue($audit->metadata['system_step']);
        $this->assertSame($this->officer()->id, $audit->metadata['approved_by_user_id']);
        $this->assertSame($invoice->id, $claim->invoice_id);
    }

    public function test_the_approval_stands_when_the_claim_step_fails(): void
    {
        config(['panel-claims.auto_create' => true]);
        // A database error inside the claim step (a trigger that refuses the insert) must not undo the approval.
        DB::unprepared('create or replace function pnl28a_refuse() returns trigger language plpgsql as $$ begin raise exception \'refused for test\'; end $$');
        DB::unprepared('create trigger pnl28a_refuse before insert on panel_claims for each row execute function pnl28a_refuse()');

        try {
            [$allocation] = $this->approvedAllocation($this->panel());
        } finally {
            DB::unprepared('drop trigger if exists pnl28a_refuse on panel_claims');
        }

        $this->assertSame('approved', $allocation->status);
        $this->assertSame(0, PanelClaim::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'panel_claim.auto_create_failed')->count());
    }

    public function test_the_system_step_is_skipped_when_a_claim_already_exists_or_the_invoice_is_held(): void
    {
        [$allocation, $invoice] = $this->approvedAllocation($this->panel());
        $service = app(PanelClaimService::class);
        $officer = $this->officer();

        DB::table('invoices')->where('id', $invoice->id)->update(['correction_hold' => true]);
        $this->assertSame('held_for_correction', $service->prepareAutomatically($officer, $allocation->refresh(), false));
        DB::table('invoices')->where('id', $invoice->id)->update(['correction_hold' => false]);

        $first = $service->prepareAutomatically($officer, $allocation->refresh(), false);
        $this->assertInstanceOf(PanelClaim::class, $first);
        $this->assertFalse(AuditLog::query()->where('event', 'panel_claim.auto_created')->sole()->metadata['system_step']);
        $this->assertSame('already_claimed', $service->prepareAutomatically($officer, $allocation->refresh(), false));
        $this->assertSame(1, PanelClaim::query()->count());
    }

    public function test_the_button_prepares_waiting_claims_reports_what_is_left_and_is_audited(): void
    {
        $panel = $this->panel();
        $this->approvedAllocation($panel);
        $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->assertSame(0, PanelClaim::query()->count());

        $result = app(PanelClaimAutoCreator::class)->createWaiting($this->officer(), $this->branch->id, 1);
        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(1, $result['remaining']);

        $this->post(route('panel-claims.auto-create'), ['expected_branch_id' => $this->branch->id])
            ->assertRedirect(route('panel-claims.index', ['tab' => 'pending']));
        $this->assertSame(2, PanelClaim::query()->where('status', 'prepared')->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'panel_claim.bulk_auto_created')->count());
        $this->get(route('panel-claims.index', ['tab' => 'pending']))->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.pending', 0)->where('work.tabs.all', 2));
    }

    public function test_only_a_panel_officer_at_the_branch_can_press_the_button(): void
    {
        $this->approvedAllocation($this->panel());
        $this->selectBranch($ca = $this->actor('ca'), $this->branch);

        $this->actingAs($ca)->post(route('panel-claims.auto-create'), ['expected_branch_id' => $this->branch->id])->assertForbidden();
        $this->assertSame(0, PanelClaim::query()->count());
    }

    public function test_a_prepared_claim_past_its_due_date_is_overdue_and_a_submitted_one_never_is(): void
    {
        $panel = $this->panel();
        [$allocation] = $this->approvedAllocation($panel);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $claim = app(PanelClaimService::class)->prepareAutomatically($officer, $allocation->refresh(), false);
        DB::table('panels')->where('id', $panel->id)->update(['claim_due_days' => 2]);
        $visitId = DB::table('invoices')->where('id', $claim->invoice_id)->value('visit_id');
        DB::table('visits')->where('id', $visitId)->update(['registered_at' => now()->subDays(3)]);

        $this->get(route('panel-claims.index', ['tab' => 'overdue']))->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.overdue', 1)->where('work.summary.overdue.count', 1)->where('work.summary.overdue.amountSen', 3000)
            ->has('work.claims.data', 1)->where('work.claims.data.0.status', 'Prepared')->where('work.claims.data.0.overdue', true));

        $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->get(route('panel-claims.index', ['tab' => 'overdue']))->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.overdue', 0)->has('work.claims.data', 0));
    }

    public function test_follow_up_leaves_out_a_claim_that_was_never_submitted(): void
    {
        [$allocation] = $this->approvedAllocation($this->panel());
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $claim = app(PanelClaimService::class)->prepareAutomatically($officer, $allocation->refresh(), true);
        $this->travel(40)->days();

        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->has('claims.data', 0));
        $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->travel(31)->days();
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->has('claims.data', 1));
    }

    public function test_the_claim_page_words_an_automatic_claim_and_the_event_note_never_shows(): void
    {
        config(['panel-claims.auto_create' => true]);
        $this->approvedAllocation($this->panel());
        $claim = PanelClaim::query()->sole();
        $this->selectBranch($this->officer(), $this->branch);

        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.events.0.auto', true)->where('claim.events.0.approvedBy', $this->officer()->name)->where('claim.events.0.note', null));
        $this->get(route('panel-claims.index', ['tab' => 'all']))->assertInertia(fn (Assert $page) => $page
            ->where('work.claims.data.0.remarks', null));
    }
}

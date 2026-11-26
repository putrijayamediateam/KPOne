<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\HandCost;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\HandCostPageService;
use App\Domain\Organisation\Inventory\Services\HandCostService;
use App\Domain\Organisation\Inventory\Services\InventoryStaffNames;
use App\Domain\Organisation\Inventory\Services\ReceiptCorrectionService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\HandCostFixture;

final class HandCostPageTest extends VisitTestCase
{
    private function opening(): StockMovement
    {
        $f = (new GovernedStockFixture($this->branch))->build();

        return StockMovement::query()->where('inventory_batch_id', $f['batch']->id)->sole();
    }

    private function input(StockMovement $movement): array
    {
        return ['expected_branch_id' => $this->branch->id, 'movement_public_id' => $movement->public_id,
            'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'cost_total_sen' => 0,
            'reason_code' => 'supplier_invoice_found', 'reference' => 'SYN-HAND-HTTP'];
    }

    private function decision(string $status = 'accepted'): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => $status, 'reason' => $status === 'rejected' ? 'Synthetic rejection' : null];
    }

    public function test_only_cost_viewers_with_enter_or_check_can_open_the_page_and_inventory_link(): void
    {
        foreach (['finance_officer' => true, 'store_admin' => true, 'director' => true, 'account_assistant' => false, 'panel_officer' => false, 'ca_supervisor' => false] as $role => $allowed) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $response = $this->get(route('inventory.hand-costs.index'));
            if ($allowed) {
                $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Inventory/HandCosts')->where('costs.expectedBranchId', $this->branch->id));
                $this->get(route('inventory.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('referenceData.canFillCosts', true));
            } else {
                $response->assertForbidden();
            }
        }
        $viewer = $this->actor('account_assistant');
        $viewer->givePermissionTo(HandCostService::VIEW);
        $this->selectBranch($viewer);
        $this->get(route('inventory.hand-costs.index'))->assertForbidden();
        $enterer = $this->actor('ca');
        $enterer->givePermissionTo(HandCostService::ENTER);
        $this->selectBranch($enterer);
        $this->get(route('inventory.hand-costs.index'))->assertForbidden();
    }

    public function test_http_entry_retry_self_refusal_independent_acceptance_and_replacement_keep_readonly_history(): void
    {
        $movement = $this->opening();
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $this->selectBranch($finance);
        $input = $this->input($movement);
        $this->post(route('inventory.hand-costs.store'), $input)->assertRedirect(route('inventory.hand-costs.index'));
        $this->post(route('inventory.hand-costs.store'), $input)->assertRedirect(route('inventory.hand-costs.index'));
        $cost = HandCost::query()->sole();
        $page = app(HandCostPageService::class)->page($finance, []);
        $this->assertTrue($page['rows'][0]['history'][0]['selfEntered']);
        $this->assertFalse($page['rows'][0]['history'][0]['canCheck']);
        $this->assertSame('You', $page['rows'][0]['history'][0]['enteredBy']);
        $this->assertSame('0.0000', $page['rows'][0]['history'][0]['perUnit']);
        $this->postJson(route('inventory.hand-costs.decide', $cost->public_id), $this->decision())->assertForbidden();
        $this->selectBranch($director);
        $page = app(HandCostPageService::class)->page($director, []);
        $this->assertTrue($page['rows'][0]['history'][0]['canCheck']);
        $this->assertFalse($page['rows'][0]['canEnter']);
        $this->post(route('inventory.hand-costs.decide', $cost->public_id), $this->decision())->assertRedirect(route('inventory.hand-costs.index'));
        $this->selectBranch($finance);
        $page = app(HandCostPageService::class)->page($finance, []);
        $this->assertTrue($page['rows'][0]['canEnter']);
        $this->assertSame($cost->public_id, $page['rows'][0]['acceptedId']);
        $this->post(route('inventory.hand-costs.store'), [...$input, 'idempotency_key' => (string) Str::uuid(), 'cost_total_sen' => 1234])->assertRedirect();
        $this->selectBranch($director);
        $replacement = HandCost::query()->where('status', 'waiting')->sole();
        $this->post(route('inventory.hand-costs.decide', $replacement->public_id), $this->decision('rejected'))->assertRedirect();
        $page = app(HandCostPageService::class)->page($director, []);
        $this->assertSame(['rejected', 'accepted'], array_column($page['rows'][0]['history'], 'status'));
        $this->assertSame($cost->public_id, $page['rows'][0]['acceptedId']);
        $json = json_encode($page, JSON_THROW_ON_ERROR);
        foreach (['payload_hash', 'idempotency_key', 'stockValueSen', 'cogsSen', 'marginSen'] as $privateKey) {
            $this->assertStringNotContainsString($privateKey, $json);
        }
    }

    public function test_actual_receipts_are_excluded_and_reversed_waiting_cost_remains_rejectable(): void
    {
        $fixture = new HandCostFixture($this->branch);
        $actual = $fixture->receipt(['cost_total_sen' => 250]);
        $f = $fixture->receipt();
        $this->selectBranch($f['finance']);
        $cost = app(HandCostService::class)->enter($f['finance'], $this->input($f['movement']));
        $correction = $fixture->correction($f);
        $this->selectBranch($f['receiver']);
        app(ReceiptCorrectionService::class)->reverse($f['receiver'], $correction['review'], $correction['input']);
        $this->selectBranch($f['director']);
        $page = app(HandCostPageService::class)->page($f['director'], ['state' => 'waiting']);
        $this->assertCount(1, $page['rows']);
        $this->assertSame($f['movement']->public_id, $page['rows'][0]['publicId']);
        $this->assertTrue($page['rows'][0]['reversed']);
        $this->assertFalse($page['rows'][0]['canEnter']);
        $this->assertTrue($page['rows'][0]['history'][0]['canCheck']);
        $this->assertNotContains($actual['movement']->public_id, array_column(app(HandCostPageService::class)->page($f['director'], [])['rows'], 'publicId'));
        $this->postJson(route('inventory.hand-costs.decide', $cost->public_id), $this->decision())->assertUnprocessable();
        $this->post(route('inventory.hand-costs.decide', $cost->public_id), $this->decision('rejected'))->assertRedirect();
        $this->assertSame('rejected', $cost->fresh()->status);
    }

    public function test_directory_is_organisation_scoped_and_names_follow_existing_staff_visibility(): void
    {
        $own = $this->opening();
        $org = Organisation::query()->create(['code' => 'SYN-HAND-OTHER', 'name' => 'Synthetic other organisation']);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-HAND-OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = (new GovernedStockFixture($branch))->build();
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $page = app(HandCostPageService::class)->page($actor, []);
        $this->assertSame([$own->public_id], array_column($page['rows'], 'publicId'));
        $this->assertSame([], app(HandCostPageService::class)->page($actor, ['q' => $foreign['sku']->sku_code])['rows']);
        app(HandCostService::class)->enter($actor, $this->input($own));
        $checker = $this->actor('store_admin');
        $this->selectBranch($checker);
        $expected = app(InventoryStaffNames::class)->resolver($checker, [$actor->id]);
        $this->assertSame($expected($actor->id), app(HandCostPageService::class)->page($checker, [])['rows'][0]['history'][0]['enteredBy']);
        $this->get(route('inventory.hand-costs.index'))->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
    }
}

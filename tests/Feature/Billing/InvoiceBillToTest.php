<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\InvoiceBillToRevision;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceActivityService;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceBillToService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\InvoiceNoteService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceBillToTest extends BillingTestCase
{
    private function version(int $invoiceId): int
    {
        return (int) (DB::table('invoice_bill_to_controls')->where('invoice_id', $invoiceId)->value('lock_version') ?? 0);
    }

    private function recipient(): array
    {
        return ['party_type' => 'company', 'display_name' => 'Synthetic recipient sentinel', 'company_name' => 'Synthetic Company', 'address_line_1' => '42 Synthetic Avenue', 'address_line_2' => 'Suite 2', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'postcode' => '50000', 'country_code' => 'MY'];
    }

    private function command(array $extra = []): array
    {
        return [...['expected_branch_id' => $this->branch->id, 'lock_version' => (int) (DB::table('invoice_bill_to_controls')->value('lock_version') ?? 0), 'operation' => 'set', 'recipient' => $this->recipient(), 'idempotency_key' => (string) Str::uuid()], ...$extra];
    }

    private function evidence(): array
    {
        $tables = ['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'invoice_adjustments', 'invoice_adjustment_reversals', 'panel_claims', 'panel_claim_lines', 'panel_claim_events', 'panel_claim_payments', 'visits', 'billing_document_counters', 'panels'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_each_bill_to_action_leaves_invoice_and_all_financial_evidence_identical_including_paid_completed_visit(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        app(InvoiceAdjustmentService::class)->add($finance, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => 'deduct', 'input_type' => 'fixed', 'input_value' => '500', 'reason' => 'Synthetic adjustment reason', 'idempotency_key' => (string) Str::uuid(), 'confirm_above_limit' => true]);
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $this->selectBranch($ca);
        app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2500, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $responsibility = app(ResponsibilityService::class);
        foreach (['panel' => 4000, 'deferment' => 3000] as $kind => $amount) {
            $proposal = $responsibility->propose($ca, $visit, $invoice->refresh(), $kind, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'panel_id' => $panel->id, 'reason' => 'Synthetic responsibility', 'due_date' => now()->addDay()->toDateString()]);
            DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $finance->id, 'capability' => $kind, 'limit_sen' => 10000]);
            $this->selectBranch($finance);
            $responsibility->approve($finance, $visit, $invoice->refresh(), $kind, $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
            $this->selectBranch($ca);
        }
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        $allocation = CoverageAllocation::query()->where('status', 'approved')->sole();
        $claim = app(PanelClaimService::class)->prepare($officer, $allocation, ['expected_branch_id' => $visit->branch_id]);
        $this->selectBranch($ca);
        $url = route('billing.bill-to', [$visit, $invoice]);
        $before = $this->evidence();
        $state = app(FinancialLedger::class)->state($invoice->refresh());
        foreach ([$this->command(), $this->command(['recipient' => [...$this->recipient(), 'party_type' => 'panel', 'display_name' => 'Synthetic changed recipient']]), $this->command(['operation' => 'clear', 'recipient' => null]), $this->command()] as $command) {
            $command['lock_version'] = $this->version($invoice->id);
            $this->postJson($url, $command)->assertOk();
            $this->assertSame($before, $this->evidence(), 'Each Bill to action must preserve invoice and all financial evidence');
            $this->assertSame($state, app(FinancialLedger::class)->state($invoice->fresh()));
        }
        $this->assertSame(['set', 'changed', 'cleared', 'set'], InvoiceBillToRevision::query()->orderBy('id')->pluck('kind')->all());
        $this->assertSame('completed', $visit->fresh()->status);
        $this->selectBranch($officer);
        $this->get(route('panel-claims.show', $claim))->assertOk()->assertDontSee('Synthetic recipient sentinel')->assertDontSee('Synthetic changed recipient');
    }

    public function test_minimal_person_and_optional_partial_address_are_not_prefilled_and_reprint_is_current(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.bill-to', [$visit, $invoice]);
        $this->postJson($url, $this->command(['recipient' => ['party_type' => 'person', 'display_name' => 'Synthetic Guardian']]))->assertOk();
        $current = app(InvoiceBillToService::class)->page($ca, $visit, $invoice, $visit->branch_id, true);
        $this->assertNull($current['current']['company_name']);
        foreach (['address_line_1', 'address_line_2', 'city', 'state', 'postcode', 'country_code'] as $field) {
            $this->assertNull($current['current'][$field]);
        }
        $this->postJson($url, $this->command(['recipient' => ['party_type' => 'person', 'display_name' => 'Synthetic Guardian Two', 'city' => 'Synthetic City', 'country_code' => 'my']]))->assertOk();
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billTo.display_name', 'Synthetic Guardian Two')->where('billTo.country_code', 'MY')->where('billTo.address_line_1', null));
        $this->postJson($url, $this->command(['operation' => 'clear', 'recipient' => null]))->assertOk();
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billTo', null)->where('billing.patient.name', $visit->patient->full_name));
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertJsonPath('current', null)->assertJsonPath('data.0.kind', 'cleared')->assertJsonPath('data.1.recipient.display_name', 'Synthetic Guardian Two')->assertJsonPath('data.2.recipient.display_name', 'Synthetic Guardian');
    }

    public function test_exact_permission_grants_and_read_only_panel_officer(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.bill-to', [$visit, $invoice]);
        $this->postJson($url, $this->command())->assertOk();
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $view = in_array($role, ['ca', 'ca_supervisor', 'finance_officer', 'director', 'panel_officer'], true);
            $manage = $view && $role !== 'panel_officer';
            $this->assertSame($view, in_array('billing.bill_to.view.branch', $permissions, true));
            $this->assertSame($manage, in_array('billing.bill_to.manage.branch', $permissions, true));
            $response = $this->getJson($url.'?expected_branch_id='.$visit->branch_id);
            $response->assertStatus($view ? 200 : 403);
            if ($view) {
                $response->assertJsonPath('canManage', $manage)->assertJsonPath('current.display_name', 'Synthetic recipient sentinel');
            }
            if (! $manage) {
                $this->postJson($url, $this->command(['operation' => 'clear', 'recipient' => null]))->assertForbidden();
            }
        }
        $this->assertDatabaseCount('invoice_bill_to_revisions', 1);
    }

    public function test_retries_payload_conflicts_stale_versions_validation_and_noop_do_not_append(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.bill-to', [$visit, $invoice]);
        $command = $this->command();
        $this->postJson($url, $command)->assertOk()->assertJsonPath('version', 1);
        $this->postJson($url, $command)->assertOk()->assertJsonPath('version', 1);
        $this->postJson($url, [...$command, 'recipient' => [...$this->recipient(), 'display_name' => 'Conflict']])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson($url, $this->command(['lock_version' => 0]))->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->postJson($url, $this->command())->assertUnprocessable()->assertJsonValidationErrors('recipient');
        foreach (['display_name' => '', 'party_type' => 'unknown', 'country_code' => 'MYS', 'email' => 'no@synthetic.test', 'phone' => '123', 'tax_id' => 'FORBIDDEN', 'registration_number' => 'FORBIDDEN', 'nric' => 'FORBIDDEN', 'address_line_1' => '<script>x</script>', 'city' => "bad\ntext", 'company_name' => str_repeat('a', 201), 'postcode' => str_repeat('x', 21)] as $field => $value) {
            $this->postJson($url, $this->command(['recipient' => [...$this->recipient(), $field => $value]]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('invoice_bill_to_revisions', 1);
        $this->assertDatabaseCount('invoice_bill_to_commands', 1);
        $clear = $this->command(['operation' => 'clear', 'recipient' => null]);
        $this->postJson($url, $clear)->assertOk();
        $this->postJson($url, $clear)->assertOk();
        $this->postJson($url, $this->command(['operation' => 'clear', 'recipient' => null]))->assertUnprocessable();
        $this->assertSame(2, $this->version($invoice->id));
        $this->assertNull(InvoiceBillToRevision::query()->latest('id')->firstOrFail()->recipient);
    }

    public function test_private_history_encryption_minimised_audit_generic_activity_and_doctor_exclusion(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(0);
        $url = route('billing.bill-to', [$visit, $invoice]);
        $this->postJson($url, $this->command())->assertOk();
        $raw = DB::table('invoice_bill_to_revisions')->sole();
        $this->assertStringNotContainsString('Synthetic recipient sentinel', $raw->recipient);
        $this->assertArrayNotHasKey('recipient', InvoiceBillToRevision::query()->sole()->toArray());
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertHeaderContains('Cache-Control', 'no-store')->assertJsonPath('data.0.recipient.display_name', 'Synthetic recipient sentinel');
        $this->get(route('billing.show', $visit))->assertOk()->assertDontSee('Synthetic recipient sentinel');
        $audit = DB::table('audit_logs')->where('event', 'billing.bill_to_set')->sole();
        $this->assertSame(['revision_id' => $raw->id, 'fields' => InvoiceBillToService::FIELDS], json_decode($audit->metadata, true));
        $activity = app(InvoiceActivityService::class)->page($ca, $visit, $invoice, false, $visit->branch_id, null);
        $this->assertContains('Bill to set', array_column($activity['data'], 'message'));
        $this->assertStringNotContainsString('Synthetic recipient sentinel', json_encode($activity));
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $this->selectBranch($doctor);
        $this->get(route('visits.invoice', $visit))->assertOk()->assertInertia(fn (Assert $p) => $p->where('invoice.billTo', null)->where('invoice.notes', null)->where('invoice.financial', null));
        $activity = app(InvoiceActivityService::class)->page($doctor, $visit->fresh(), null, true, $visit->branch_id, null);
        $this->assertStringNotContainsString('Bill to', json_encode($activity));
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertForbidden();
    }

    public function test_all_generic_activity_types_are_hidden_without_view_even_for_a_financial_viewer(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $service = app(InvoiceBillToService::class);
        $service->write($ca, $visit, $invoice, $this->command());
        $service->write($ca, $visit, $invoice, $this->command(['recipient' => [...$this->recipient(), 'display_name' => 'Synthetic Changed']]));
        $service->write($ca, $visit, $invoice, $this->command(['operation' => 'clear', 'recipient' => null]));
        $activity = app(InvoiceActivityService::class)->page($ca, $visit, $invoice, false, $visit->branch_id, null);
        foreach (['Bill to set', 'Bill to changed', 'Bill to cleared'] as $message) {
            $this->assertContains($message, array_column($activity['data'], 'message'));
        }
        $this->assertStringNotContainsString('Synthetic recipient sentinel', json_encode($activity));
        $ca->syncRoles([]);
        $ca->givePermissionTo('billing.view.branch');
        $activity = app(InvoiceActivityService::class)->page($ca, $visit, $invoice, false, $visit->branch_id, null);
        $this->assertStringNotContainsString('Bill to', json_encode($activity));
        $this->getJson(route('billing.bill-to', [$visit, $invoice]).'?expected_branch_id='.$visit->branch_id)->assertForbidden();
    }

    public function test_invoice_print_requires_both_view_and_existing_print_permission_and_receipt_never_includes_bill_to_or_notes(): void
    {
        [, $ca,$visit,$invoice] = $this->finalizedFixture();
        $url = route('billing.bill-to', [$visit, $invoice]);
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 100, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        app(InvoiceNoteService::class)->write($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => 0, 'operation' => 'added', 'body' => 'Synthetic secret note print sentinel', 'idempotency_key' => (string) Str::uuid()]);
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billTo', null));
        $this->postJson($url, $this->command())->assertOk();
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billTo.display_name', 'Synthetic recipient sentinel')->where('billing.billTo', null)->where('billing.notes', null)->where('billing.patient.name', $visit->patient->full_name))->assertDontSee('Synthetic secret note print sentinel');
        $this->get(route('billing.receipt', [$visit, $invoice, $payment]))->assertOk()->assertInertia(fn (Assert $p) => $p->missing('billTo')->where('billing.billTo', null)->where('billing.notes', null))->assertDontSee('Synthetic recipient sentinel')->assertDontSee('Synthetic secret note print sentinel');
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.print', [$visit, $invoice]))->assertForbidden();
        $this->selectBranch($ca);
        $ca->syncRoles([]);
        $ca->givePermissionTo('billing.print.branch');
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billTo', null))->assertDontSee('Synthetic recipient sentinel');
    }

    public function test_voided_history_remains_on_original_and_replacement_is_not_prefilled(): void
    {
        [, $ca,$visit,$invoice] = $this->finalizedFixture();
        $url = route('billing.bill-to', [$visit, $invoice]);
        $this->postJson($url, $this->command())->assertOk();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        app(InvoiceCorrectionService::class)->void($finance, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'reason' => 'Synthetic correction']);
        $before = $this->evidence();
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertOk()->assertJsonPath('current.display_name', 'Synthetic recipient sentinel')->assertJsonPath('canManage', false);
        $this->postJson($url, $this->command(['operation' => 'clear', 'recipient' => null]))->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertSame($before, $this->evidence());
        $this->selectBranch($ca);
        $replacement = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $replacement = app(BillingBuilderService::class)->finalize($ca, $visit, $replacement, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $replacement->lock_version]);
        $this->getJson(route('billing.bill-to', [$visit, $replacement]).'?expected_branch_id='.$visit->branch_id)->assertOk()->assertJsonPath('current', null)->assertJsonCount(0, 'data')->assertJsonPath('version', 0);
        $this->assertDatabaseCount('invoice_bill_to_revisions', 1);
    }

    public function test_drafts_branch_scope_and_fresh_grant_revocation_fail_closed(): void
    {
        [, $ca,$visit] = $this->billingFixture();
        $invoice = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $url = route('billing.bill-to', [$visit, $invoice]);
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertNotFound();
        $this->postJson($url, $this->command())->assertNotFound();
        $invoice = app(BillingBuilderService::class)->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
        $this->postJson($url, $this->command())->assertOk();
        $other = Branch::query()->where('id', '!=', $visit->branch_id)->firstOrFail();
        $this->selectBranch($this->actor('ca', $other), $other);
        $this->getJson($url.'?expected_branch_id='.$other->id)->assertNotFound();
        $this->postJson($url, $this->command(['expected_branch_id' => $other->id]))->assertNotFound();
        $this->selectBranch($ca);
        $ca->load(['roles.permissions', 'permissions']);
        $this->assertTrue($ca->can('billing.bill_to.manage.branch'));
        DB::table('role_has_permissions')->where('role_id', Role::findByName('ca')->id)->where('permission_id', Permission::findByName('billing.bill_to.manage.branch')->id)->delete();
        try {
            app(InvoiceBillToService::class)->write($ca, $visit, $invoice, $this->command(['operation' => 'clear', 'recipient' => null]));
            $this->fail('Revoked grant was accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseCount('invoice_bill_to_revisions', 1);
    }

    public function test_postgres_retains_revisions_and_commands_and_audit_failure_rolls_back(): void
    {
        [, $ca,$visit,$invoice] = $this->finalizedFixture();
        $service = app(InvoiceBillToService::class);
        $service->write($ca, $visit, $invoice, $this->command());
        $revision = InvoiceBillToRevision::query()->sole();
        try {
            $revision->forceFill(['recipient' => null])->save();
            $this->fail('Revision changed');
        } catch (LogicException) {
            $this->assertSame('Synthetic recipient sentinel', $revision->fresh()->recipient['display_name']);
        }
        foreach ([fn () => DB::table('invoice_bill_to_revisions')->update(['recipient' => 'changed']), fn () => DB::table('invoice_bill_to_revisions')->delete(), fn () => DB::table('invoice_bill_to_commands')->delete(), fn () => DB::table('invoice_bill_to_controls')->delete(), fn () => DB::table('invoice_bill_to_controls')->update(['lock_version' => 9])] as $action) {
            try {
                DB::transaction($action);
                $this->fail('Database allowed evidence mutation');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $before = $this->evidence();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit refusal'));
        try {
            app(InvoiceBillToService::class)->write($ca, $visit, $invoice, $this->command(['operation' => 'clear', 'recipient' => null]));
            $this->fail('Audit failure accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit refusal', $e->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(1, $this->version($invoice->id));
        $this->assertDatabaseCount('invoice_bill_to_revisions', 1);
        $this->assertDatabaseCount('invoice_bill_to_commands', 1);
    }

    public function test_pagination_is_scoped_and_permission_migration_is_additive_and_replay_safe(): void
    {
        [, $ca,$visit,$invoice] = $this->finalizedFixture();
        $service = app(InvoiceBillToService::class);
        for ($i = 0; $i < 23; $i++) {
            $service->write($ca, $visit, $invoice, $this->command(['recipient' => [...$this->recipient(), 'display_name' => 'Synthetic revision '.$i]]));
        }
        $first = $service->page($ca, $visit, $invoice, $visit->branch_id);
        $second = $service->page($ca, $visit, $invoice, $visit->branch_id, false, $first['nextCursor']);
        $this->assertCount(20, $first['data']);
        $this->assertCount(3, $second['data']);
        $this->assertNull($second['nextCursor']);
        $this->assertCount(23, array_unique(array_column([...$first['data'], ...$second['data']], 'id')));
        $this->selectBranch($this->actor('panel_officer'));
        $this->getJson(route('billing.bill-to', [$visit, $invoice]).'?expected_branch_id='.$visit->branch_id.'&cursor='.urlencode($first['nextCursor']))->assertUnprocessable();
        $role = Role::findByName('ca');
        $role->givePermissionTo(Permission::findOrCreate('synthetic.extra.branch', 'web'));
        $migration = require base_path('database/migrations/2026_10_19_000400_grant_bill_to_permissions.php');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }
}

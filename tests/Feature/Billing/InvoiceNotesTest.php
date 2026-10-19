<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\InvoiceNoteEvent;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceActivityService;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceNoteService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InvoiceNotesTest extends BillingTestCase
{
    private function command(array $extra = []): array
    {
        return [...['expected_branch_id' => $this->branch->id, 'lock_version' => (int) (DB::table('invoice_note_streams')->value('lock_version') ?? 0), 'operation' => 'added', 'body' => 'Synthetic private billing note sentinel', 'idempotency_key' => (string) Str::uuid()], ...$extra];
    }

    private function evidence(): array
    {
        $tables = ['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'invoice_adjustments', 'invoice_adjustment_reversals', 'panel_claims', 'panel_claim_lines', 'panel_claim_events', 'panel_claim_payments', 'visits', 'billing_document_counters'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_each_note_action_leaves_invoice_and_all_financial_evidence_identical_including_paid_completed_visit(): void
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
        $url = route('billing.notes', [$visit, $invoice]);
        $before = $this->evidence();
        $state = app(FinancialLedger::class)->state($invoice->refresh());
        $this->postJson($url, $this->command())->assertOk();
        $this->assertSame($before, $this->evidence(), 'Add note must not mutate any financial evidence');
        $first = InvoiceNoteEvent::query()->sole();
        $this->postJson($url, $this->command(['operation' => 'corrected', 'target' => $first->public_id, 'reason_code' => 'replaced', 'body' => 'Synthetic corrected billing note']))->assertOk();
        $this->assertSame($before, $this->evidence(), 'Correction must not mutate financial evidence');
        $replacement = InvoiceNoteEvent::query()->latest('id')->firstOrFail();
        $this->postJson($url, $this->command(['operation' => 'retracted', 'target' => $replacement->public_id, 'reason_code' => 'entered_in_error', 'body' => null]))->assertOk();
        $this->assertSame($before, $this->evidence(), 'Own retraction must not mutate financial evidence');
        $this->postJson($url, $this->command())->assertOk();
        $other = InvoiceNoteEvent::query()->latest('id')->firstOrFail();
        $this->selectBranch($finance);
        $this->postJson($url, $this->command(['operation' => 'retracted', 'target' => $other->public_id, 'reason_code' => 'duplicate', 'body' => null]))->assertOk();
        $this->assertSame($before, $this->evidence(), 'Elevated retraction must not mutate financial evidence');
        $this->assertSame($state, app(FinancialLedger::class)->state($invoice->fresh()));
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(5, InvoiceNoteEvent::query()->count());
    }

    public function test_permissions_matrix_and_add_includes_own_retraction_but_not_someone_elses(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.notes', [$visit, $invoice]);
        $this->postJson($url, $this->command())->assertOk();
        $note = InvoiceNoteEvent::query()->sole();
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['ca', 'ca_supervisor', 'finance_officer', 'director'], true);
            $this->assertSame($allowed, in_array('billing.notes.view.branch', $permissions, true));
            $this->assertSame($allowed, in_array('billing.notes.add.branch', $permissions, true));
            $this->assertSame(in_array($role, ['ca_supervisor', 'finance_officer', 'director'], true), in_array('billing.notes.retract.branch', $permissions, true));
            $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertStatus($allowed ? 200 : 403);
            if (! $allowed) {
                $this->postJson($url, $this->command())->assertForbidden();
            }
        }
        $otherCa = $this->actor('ca');
        $this->selectBranch($otherCa);
        $this->postJson($url, $this->command(['operation' => 'retracted', 'target' => $note->public_id, 'reason_code' => 'duplicate', 'body' => null]))->assertForbidden();
        $this->selectBranch($ca);
        $this->postJson($url, $this->command(['operation' => 'retracted', 'target' => $note->public_id, 'reason_code' => 'duplicate', 'body' => null]))->assertOk();
    }

    public function test_branch_retraction_grant_is_authoritative_even_without_an_add_grant(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.notes', [$visit, $invoice]);
        $this->postJson($url, $this->command())->assertOk();
        $note = InvoiceNoteEvent::query()->sole();
        $ca->syncRoles([]);
        $ca->givePermissionTo(['billing.view.branch', 'billing.notes.view.branch', 'billing.notes.retract.branch']);
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertOk()->assertJsonPath('data.0.canRetract', true)->assertJsonPath('data.0.canCorrect', false)->assertJsonPath('canAdd', false);
        $this->postJson($url, $this->command())->assertForbidden();
        $this->postJson($url, $this->command(['operation' => 'retracted', 'target' => $note->public_id, 'reason_code' => 'duplicate', 'body' => null]))->assertOk();
    }

    public function test_retry_conflicts_stale_versions_and_already_resolved_targets_leave_stream_consistent(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.notes', [$visit, $invoice]);
        $command = $this->command();
        $this->postJson($url, $command)->assertOk()->assertJsonPath('version', 1);
        $this->postJson($url, $command)->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('invoice_note_events', 1);
        $this->assertDatabaseCount('invoice_note_commands', 1);
        $this->postJson($url, [...$command, 'body' => 'Different'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson($url, $this->command(['lock_version' => 0]))->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $note = InvoiceNoteEvent::query()->sole();
        $retract = $this->command(['operation' => 'retracted', 'target' => $note->public_id, 'reason_code' => 'duplicate', 'body' => null]);
        $this->postJson($url, $retract)->assertOk();
        $this->postJson($url, $retract)->assertOk();
        $this->postJson($url, $this->command(['operation' => 'retracted', 'target' => $note->public_id, 'reason_code' => 'duplicate', 'body' => null]))->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->assertSame(2, DB::table('invoice_note_streams')->value('lock_version'));
        $this->assertDatabaseCount('invoice_note_events', 2);
    }

    public function test_plain_text_and_encryption_with_no_content_in_generic_log_print_or_audit(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.notes', [$visit, $invoice]);
        foreach (['', str_repeat('x', 501), '<script>alert(1)</script>', "bad\0text"] as $body) {
            $this->postJson($url, $this->command(['body' => $body]))->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $text = 'Synthetic private note & < 10';
        $this->postJson($url, $this->command(['body' => $text]))->assertOk();
        $raw = DB::table('invoice_note_events')->sole();
        $this->assertStringNotContainsString($text, $raw->body);
        $this->assertSame($text, InvoiceNoteEvent::query()->sole()->body);
        $this->assertArrayNotHasKey('body', InvoiceNoteEvent::query()->sole()->toArray());
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertJsonPath('data.0.body', $text);
        $activity = app(InvoiceActivityService::class)->page($ca, $visit, $invoice, false, $visit->branch_id, null);
        $this->assertContains('Invoice note added', array_column($activity['data'], 'message'));
        $this->assertStringNotContainsString($text, json_encode($activity));
        $audit = DB::table('audit_logs')->where('event', 'billing.note_added')->sole();
        $this->assertSame(['record_version' => 1], json_decode($audit->metadata, true));
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billing.notes', null))->assertDontSee($text);
        $this->get(route('billing.show', $visit))->assertOk()->assertDontSee($text);
    }

    public function test_branch_scope_revocation_and_doctor_projection_have_no_note_leaks(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(0);
        $url = route('billing.notes', [$visit, $invoice]);
        $this->postJson($url, $this->command())->assertOk();
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $this->selectBranch($doctor);
        $this->get(route('visits.invoice', $visit))->assertOk()->assertInertia(fn (Assert $p) => $p->where('invoice.notes', null)->where('invoice.financial', null))->assertDontSee('Synthetic private billing note sentinel');
        $activity = app(InvoiceActivityService::class)->page($doctor, $visit->fresh(), null, true, $visit->branch_id, null);
        $this->assertStringNotContainsString('Invoice note', json_encode($activity));
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertForbidden();
        $other = Branch::query()->where('id', '!=', $this->branch->id)->firstOrFail();
        $this->selectBranch($this->actor('ca', $other), $other);
        $this->getJson($url.'?expected_branch_id='.$other->id)->assertNotFound();
        $this->postJson($url, $this->command(['expected_branch_id' => $other->id]))->assertNotFound();
        $this->selectBranch($ca);
        $ca->roles()->detach();
        $ca->unsetRelation('roles');
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertForbidden();
    }

    public function test_history_paginates_and_cursor_is_bound_to_actor_scope(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $service = app(InvoiceNoteService::class);
        for ($i = 0; $i < 23; $i++) {
            $service->write($ca, $visit, $invoice, $this->command(['body' => 'Synthetic billing note '.$i]));
        }
        $first = $service->page($ca, $visit, $invoice, $visit->branch_id);
        $this->assertCount(20, $first['data']);
        $second = $service->page($ca, $visit, $invoice, $visit->branch_id, $first['nextCursor']);
        $this->assertCount(3, $second['data']);
        $this->assertNull($second['nextCursor']);
        $this->assertCount(23, array_unique(array_column([...$first['data'], ...$second['data']], 'id')));
        $this->selectBranch($this->actor('finance_officer'));
        $this->getJson(route('billing.notes', [$visit, $invoice]).'?expected_branch_id='.$visit->branch_id.'&cursor='.urlencode($first['nextCursor']))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    public function test_postgres_and_model_evidence_are_immutable_and_stream_scope_cannot_be_rebound(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $this->postJson(route('billing.notes', [$visit, $invoice]), $this->command())->assertOk();
        $note = InvoiceNoteEvent::query()->sole();
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $note->forceFill(['body' => 'Changed'])->save() : $note->delete();
                $this->fail('Model mutation accepted');
            } catch (LogicException) {
                $this->assertSame('Synthetic private billing note sentinel', $note->fresh()->body);
            }
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ([fn () => DB::table('invoice_note_events')->update(['body' => 'changed']), fn () => DB::table('invoice_note_events')->delete(), fn () => DB::table('invoice_note_commands')->delete(), fn () => DB::table('invoice_note_streams')->delete(), fn () => DB::table('invoice_note_streams')->update(['lock_version' => 9])] as $action) {
            try {
                DB::transaction($action);
                $this->fail('PostgreSQL accepted note evidence mutation');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_note_timestamps_preserve_the_utc_instant_with_a_non_utc_postgres_session(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        DB::statement("SET LOCAL TIME ZONE 'Asia/Kuala_Lumpur'");
        $this->travelTo(CarbonImmutable::parse('2026-10-19T01:00:00Z'));
        app(InvoiceNoteService::class)->write($ca, $visit, $invoice, $this->command());
        $this->assertSame(now()->timestamp, InvoiceNoteEvent::query()->sole()->created_at->timestamp);
        $page = app(InvoiceNoteService::class)->page($ca, $visit, $invoice, $visit->branch_id);
        $this->assertSame('19 Oct 2026, 9:00 AM', $page['data'][0]['occurredAt']);
        $this->travelBack();
    }

    public function test_audit_failure_rolls_back_stream_event_and_retry_command_without_financial_changes(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $before = $this->evidence();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        try {
            app(InvoiceNoteService::class)->write($ca, $visit, $invoice, $this->command());
            $this->fail('Audit failure must abort note action');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('invoice_note_streams', 0);
        $this->assertDatabaseCount('invoice_note_events', 0);
        $this->assertDatabaseCount('invoice_note_commands', 0);
        $this->assertSame($before, $this->evidence());
    }

    public function test_draft_invoice_has_no_note_context_and_cannot_be_read_or_written(): void
    {
        [, $ca, $visit] = $this->billingFixture();
        $invoice = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $this->assertNull(app(InvoiceNoteService::class)->link($ca, $visit, $invoice));
        $before = $this->evidence();
        $url = route('billing.notes', [$visit, $invoice]);
        $this->getJson($url.'?expected_branch_id='.$visit->branch_id)->assertNotFound();
        $this->postJson($url, $this->command())->assertNotFound();
        $this->assertDatabaseCount('invoice_note_events', 0);
        $this->assertSame($before, $this->evidence());
    }

    public function test_permission_migration_is_additive_and_replay_safe(): void
    {
        $permission = Permission::findOrCreate('synthetic.extra.branch', 'web');
        $role = Role::findByName('ca');
        $role->givePermissionTo($permission);
        $billToBefore = Permission::query()->where('name', 'like', 'billing.bill_to.%')->orderBy('name')->get(['id', 'name', 'guard_name'])->toJson();
        $migration = require base_path('database/migrations/2026_10_19_000200_grant_invoice_note_permissions.php');
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertTrue($role->fresh()->hasPermissionTo('synthetic.extra.branch'));
        $this->assertFalse(Permission::query()->where('name', 'billing.notes.retract.own')->exists());
        $this->assertSame($billToBefore, Permission::query()->where('name', 'like', 'billing.bill_to.%')->orderBy('name')->get(['id', 'name', 'guard_name'])->toJson());
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/** @phpstan-type Worker array{process: Process, input: InputStream} */
class PostgresDispensaryInventoryRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_dispensary_inventory_observer';

    /** @var list<int> */
    private array $organisationIds = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Phase 3A regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Phase 3A concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['dispensary_cases', 'dispensary_items', 'inventory_stock_balances', 'stock_movements'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Phase 3A regressions.');
            }
        }
        Config::set('database.connections.'.self::OBSERVER, config('database.connections.'.config('database.default')));
        DB::purge(self::OBSERVER);
    }

    protected function tearDown(): void
    {
        foreach ($this->inputs as $input) {
            if (! $input->isClosed()) {
                $input->close();
            }
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ($this->organisationIds as $id) {
            $this->deleteOrganisation($id);
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_double_send_creates_one_handoff_case_queue_transition_and_audit(): void
    {
        $f = $this->fixture();
        $args = ['send', (string) $f['doctor']->id, (string) $f['branch']->id, $f['visit']->visit_number, (string) $f['plan']->lock_version];
        $output = $this->race([$this->worker($args), $this->worker($args)], $f['patient']);
        $this->assertSame(1, substr_count($output, 'SENT'));
        $this->assertSame(1, substr_count($output, 'STALE') + substr_count($output, 'DENIED'));
        $this->assertSame(1, DispensaryCase::query()->where('treatment_plan_id', $f['plan']->id)->count());
        $this->assertSame(1, DispensaryHandoff::query()->where('organisation_id', $f['organisation']->id)->where('status', 'open')->count());
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'treatment_plan.sent_to_dispensary')->count());
    }

    public function test_send_and_plan_edit_serialize_without_lost_update(): void
    {
        $f = $this->fixture();
        $workers = [
            $this->worker(['send', (string) $f['doctor']->id, (string) $f['branch']->id, $f['visit']->visit_number, '1']),
            $this->worker(['plan-edit', (string) $f['doctor']->id, (string) $f['branch']->id, $f['visit']->visit_number, (string) $f['plan']->id, '1']),
        ];
        $output = $this->race($workers, $f['visit']);
        $this->assertSame(1, substr_count($output, 'SENT') + substr_count($output, 'PLAN_SAVED'));
        $this->assertSame(1, substr_count($output, 'STALE') + substr_count($output, 'DENIED'));
        $this->assertContains($f['plan']->refresh()->status, [TreatmentPlan::STATUS_IN_PROGRESS, TreatmentPlan::STATUS_READY_FOR_DISPENSING]);
    }

    public function test_two_cas_start_one_case_once(): void
    {
        $f = $this->sentFixture();
        $workers = [
            $this->worker(['start', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, '1']),
            $this->worker(['start', (string) $f['ca2']->id, (string) $f['branch']->id, $f['case']->public_id, '1']),
        ];
        $output = $this->race($workers, $f['patient']);
        $this->assertSame(1, substr_count($output, 'STARTD'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertNotNull($f['case']->refresh()->current_handler_user_id);
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'dispensary.started')->count());
    }

    public function test_concurrent_item_edits_have_one_versioned_winner(): void
    {
        $f = $this->startedFixture();
        $args = ['update-item', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version, $f['item']->public_id, (string) $f['item']->lock_version, 'not_dispensed', '0.000', 'patient_declined'];
        $output = $this->race([$this->worker($args), $this->worker($args)], $f['patient']);
        $this->assertSame(1, substr_count($output, 'ITEM_UPDATED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(2, $f['item']->refresh()->lock_version);
    }

    public function test_duplicate_complete_commits_once_without_duplicate_movement(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $args = ['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version];
        $output = $this->race([$this->worker($args), $this->worker($args)], $f['patient']);
        $this->assertSame(1, substr_count($output, 'COMPLETED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'dispense')->count());
        $this->assertSame('6.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->value('quantity'));

        $movement = DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'dispense')->sole();
        try {
            DB::transaction(fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['quantity' => '3.000']));
            $this->fail('Deferred reconciliation accepted movement quantity that differed from its allocation.');
        } catch (QueryException|PDOException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame('4.000', (string) DB::table('stock_movements')->where('id', $movement->id)->value('quantity'));
    }

    public function test_two_cases_consuming_last_stock_never_make_balance_negative(): void
    {
        $f = $this->completableFixture('10.000', '8.000');
        $second = $this->additionalCompletableCase($f, '8.000');
        $workers = [
            $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['complete', (string) $f['ca2']->id, (string) $f['branch']->id, $second['case']->public_id, (string) $second['case']->lock_version]),
        ];
        $output = $this->race($workers, $f['balance']);
        $this->assertSame(1, substr_count($output, 'COMPLETED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertGreaterThanOrEqual(0, (float) $f['balance']->refresh()->quantity);
        $this->assertSame(8.0, (float) DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'dispense')->sum('quantity'));
    }

    public function test_transfer_and_dispense_from_same_balance_serialize_and_reconcile(): void
    {
        $f = $this->completableFixture('10.000', '7.000');
        $workers = [
            $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['transfer', (string) $f['ca2']->id, (string) $f['branch']->id, $f['location']->public_id, $f['destination']->public_id, $f['sku']->public_id, $f['batch']->public_id, '5.000']),
        ];
        $output = $this->race($workers, $f['balance']);
        $this->assertSame(1, substr_count($output, 'COMPLETED') + substr_count($output, 'TRANSFERRED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertGreaterThanOrEqual(0, (float) $f['balance']->refresh()->quantity);
    }

    public function test_allergy_mutation_winning_before_complete_rejects_stock_movement(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $leader = $this->worker(['allergy-mutate', (string) $f['profile']->id]);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $output = $this->leaderThenFollower($leader, $follower);
        $this->assertStringContainsString('STALE', $output);
        $this->assertDatabaseMissing('stock_movements', ['organisation_id' => $f['organisation']->id, 'movement_type' => 'dispense']);
    }

    public function test_plan_version_change_winning_before_complete_rejects_handoff(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $leader = $this->worker(['plan-version', (string) $f['plan']->id]);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $this->assertStringContainsString('STALE', $this->leaderThenFollower($leader, $follower));
        $this->assertDatabaseMissing('stock_movements', ['organisation_id' => $f['organisation']->id, 'movement_type' => 'dispense']);
    }

    public function test_return_and_complete_have_exactly_one_state_winner(): void
    {
        $f = $this->zeroCompletableFixture();
        $workers = [
            $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['return', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
        ];
        $output = $this->race($workers, $f['patient']);
        $this->assertSame(1, substr_count($output, 'COMPLETED') + substr_count($output, 'RETURND'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_acknowledgement_and_proposal_change_never_cross_authorize_quantities(): void
    {
        $f = $this->patientDeclinedFixture();
        $workers = [
            $this->worker(['acknowledge', (string) $f['doctor']->id, (string) $f['branch']->id, $f['exception']->public_id, (string) $f['case']->lock_version, (string) $f['item']->lock_version]),
            $this->worker(['proposal', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version, $f['item']->public_id, (string) $f['item']->lock_version, 'partial', '0.500', 'patient_declined']),
        ];
        $output = $this->race($workers, $f['visit']);
        $this->assertSame(1, substr_count($output, 'ITEM_UPDATED'));
        $this->assertSame(1, substr_count($output, 'ACKNOWLEDGED') + substr_count($output, 'STALE'));
        $item = $f['item']->refresh();
        $ack = $item->exceptions()->where('status', DispensaryItemException::STATUS_ACKNOWLEDGED)->latest('id')->first();
        $this->assertTrue(! $ack || $ack->proposed_quantity_dispensed === $item->quantity_dispensed);
    }

    public function test_ca_deactivation_race_fails_closed(): void
    {
        $this->assertAuthorityLossFailsClosed('deactivate');
    }

    public function test_exact_permission_loss_race_fails_closed(): void
    {
        $this->assertAuthorityLossFailsClosed('revoke');
    }

    public function test_branch_assignment_loss_race_fails_closed(): void
    {
        $this->assertAuthorityLossFailsClosed('end-assignment');
    }

    public function test_visit_queue_and_encounter_state_loss_races_fail_closed(): void
    {
        foreach (['visit', 'queue', 'encounter'] as $kind) {
            $f = $this->zeroCompletableFixture();
            $leader = $this->worker(['state-loss', $f['visit']->visit_number, $kind, (string) $f['operator']->id]);
            $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
            $this->assertStringContainsString('STALE', $this->leaderThenFollower($leader, $follower), $kind);
            $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        }
    }

    public function test_late_audit_failure_rolls_back_completion_balances_movements_and_case(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $before = [$f['balance']->quantity, $f['case']->lock_version, $f['item']->lock_version];
        DB::unprepared(<<<'SQL'
            create or replace function kpone_phase3a_fail_audit() returns trigger language plpgsql as $$
            begin
                if new.event in ('dispensary.completed', 'dispensary.updated') then raise exception 'synthetic late audit failure'; end if;
                return new;
            end $$;
            create trigger kpone_phase3a_fail_audit before insert on audit_logs
            for each row execute function kpone_phase3a_fail_audit();
            SQL);
        try {
            $worker = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
            $this->runWorkers([$worker], false);
            $this->assertSame(70, $worker['process']->getExitCode());
            $this->assertStringContainsString('synthetic late audit failure', $worker['process']->getErrorOutput());

            $update = $this->worker(['update-item', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version, $f['item']->public_id, (string) $f['item']->lock_version, 'not_dispensed', '0.000', 'other']);
            $this->runWorkers([$update], false);
            $this->assertSame(70, $update['process']->getExitCode());
            $this->assertStringContainsString('synthetic late audit failure', $update['process']->getErrorOutput());
        } finally {
            DB::unprepared('drop trigger if exists kpone_phase3a_fail_audit on audit_logs; drop function if exists kpone_phase3a_fail_audit()');
        }
        $this->assertSame($before, [$f['balance']->refresh()->quantity, $f['case']->refresh()->lock_version, $f['item']->refresh()->lock_version]);
        $this->assertDatabaseMissing('stock_movements', ['organisation_id' => $f['organisation']->id, 'movement_type' => 'dispense']);
    }

    public function test_wrong_branch_cross_org_and_unrelated_workers_are_privacy_preserving(): void
    {
        $f = $this->zeroCompletableFixture();
        $foreign = $this->fixture();
        $wrongBranch = new Branch;
        $wrongBranch->forceFill(['organisation_id' => $f['organisation']->id, 'code' => 'W'.Str::upper(Str::random(5)), 'name' => 'Synthetic Wrong Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $wrongBranchCa = $this->user($f['organisation'], $wrongBranch, 'ca', $this->caPermissions());
        $unrelated = $this->user($f['organisation'], $f['branch'], 'ca', ['branch_context.switch.branch']);
        $workers = [
            $this->worker(['complete', (string) $foreign['ca']->id, (string) $foreign['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['complete', (string) $wrongBranchCa->id, (string) $wrongBranch->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['complete', (string) $unrelated->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
        ];
        $this->runWorkers($workers);
        foreach ($workers as $worker) {
            $this->assertStringContainsString('DENIED', $worker['process']->getOutput());
        }
        $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'DISP_PG_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Phase 3A PG', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'D'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $operator = $this->user($organisation, $branch, null, ['queue.view.branch', 'queue.enter.branch', 'queue.call.branch', 'visits.view.branch', 'branch_context.switch.organisation']);
        $doctor = $this->user($organisation, $branch, 'resident_doctor', $this->doctorPermissions());
        $ca = $this->user($organisation, $branch, 'ca', $this->caPermissions());
        $ca2 = $this->user($organisation, $branch, 'ca', $this->caPermissions());
        $inventorySupervisor = $this->user($organisation, $branch, 'ca_supervisor', $this->inventorySupervisorPermissions());
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence), 'full_name' => 'Synthetic Dispensary Patient', 'search_name' => 'synthetic dispensary patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic Dispensary reason', 'assigned_doctor_user_id' => $doctor->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $operator->id, 'updated_by_user_id' => $operator->id, 'lock_version' => 1])->save();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $queue = app(QueueEntryService::class)->enter($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $encounter = app(ClinicalEncounterService::class)->start($doctor, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, ['expected_branch_id' => $branch->id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($doctor, $visit, ['expected_branch_id' => $branch->id, 'profile_lock_version' => $profile->lock_version]);
        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'MED-'.Str::upper(Str::random(7)), 'display_name' => 'Synthetic concurrency medicine', 'strength_text' => 'Synthetic strength', 'dosage_form' => 'unit', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, 'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id])->save();
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $branch->id, 'lock_version' => null, 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => '10.000',
            'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null,
            'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]], 'services' => []]);

        return compact('organisation', 'branch', 'operator', 'doctor', 'ca', 'ca2', 'inventorySupervisor', 'patient', 'visit', 'queue', 'encounter', 'profile', 'medicine', 'plan');
    }

    /** @return array<string, mixed> */
    private function sentFixture(): array
    {
        $f = $this->fixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $f['case'] = app(DispensaryHandoffService::class)->send($f['doctor'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => $f['plan']->lock_version]);
        $f['item'] = $f['case']->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();

        return $f;
    }

    /** @return array<string, mixed> */
    private function startedFixture(): array
    {
        $f = $this->sentFixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $f['case'] = app(DispensaryService::class)->start($f['ca'], $f['case'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version]);
        $f['item'] = $f['item']->refresh();

        return $f;
    }

    /** @return array<string, mixed> */
    private function patientDeclinedFixture(): array
    {
        $f = $this->startedFixture();
        $f['item'] = app(DispensaryService::class)->updateItem($f['ca'], $f['case'], $f['item'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version, 'status' => 'not_dispensed', 'quantity_dispensed' => '0.000', 'reason' => 'patient_declined', 'allocations' => []]);
        $f['case']->refresh();
        $f['exception'] = $f['item']->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();

        return $f;
    }

    /** @return array<string, mixed> */
    private function zeroCompletableFixture(): array
    {
        $f = $this->patientDeclinedFixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        app(DispensaryService::class)->acknowledge($f['doctor'], $f['exception'], ['case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);

        return $f;
    }

    /** @return array<string, mixed> */
    private function completableFixture(string $opening, string $actual): array
    {
        $f = $this->startedFixture();
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'code' => 'ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        $mapping = new MedicineCatalogueInventorySku;
        $mapping->forceFill(['organisation_id' => $f['organisation']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $f['inventorySupervisor']->id, 'approved_at' => now()->utc()])->save();
        $location = $this->location($f, 'DISP-A');
        $destination = $this->location($f, 'DISP-B');
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'B-'.Str::upper(Str::random(6)), 'expiry_date' => now()->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        app(InventoryMovementService::class)->openingBalance($f['inventorySupervisor'], ['expected_branch_id' => $f['branch']->id, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $opening]);
        $f['item'] = app(DispensaryService::class)->updateItem($f['ca'], $f['case'], $f['item'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version, 'status' => 'partial', 'quantity_dispensed' => $actual, 'reason' => 'patient_declined', 'allocations' => [[
            'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $actual,
        ]]]);
        $f['case']->refresh();
        $exception = $f['item']->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($f['doctor'], $exception, ['case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);
        $f['sku'] = $sku;
        $f['batch'] = $batch;
        $f['location'] = $location;
        $f['destination'] = $destination;
        $f['balance'] = InventoryStockBalance::query()->where('inventory_location_id', $location->id)->sole();

        return $f;
    }

    /** @param array<string, mixed> $f */
    private function location(array $f, string $suffix): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'code' => $suffix.Str::upper(Str::random(4)), 'name' => 'Synthetic '.$suffix, 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();

        return $location;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function additionalCompletableCase(array $f, string $actual): array
    {
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $f['organisation']->id, 'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence), 'full_name' => 'Synthetic Competing Patient', 'search_name' => 'synthetic competing patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic competing stock reason', 'assigned_doctor_user_id' => $f['doctor']->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $f['operator']->id, 'updated_by_user_id' => $f['operator']->id, 'lock_version' => 1])->save();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $queue = app(QueueEntryService::class)->enter($f['operator'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($f['operator'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $encounter = app(ClinicalEncounterService::class)->start($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $profile = app(PatientAllergyService::class)->declareNoKnown($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'profile_lock_version' => $profile->lock_version]);
        $plan = app(TreatmentPlanService::class)->save($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'lock_version' => null, 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $f['medicine']->public_id, 'quantity_ordered' => '10.000', 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]], 'services' => []]);
        $case = app(DispensaryHandoffService::class)->send($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $plan->lock_version]);
        $case = app(DispensaryService::class)->start($f['ca2'], $case, ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $item = app(DispensaryService::class)->updateItem($f['ca2'], $case, $item, ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version, 'status' => 'partial', 'quantity_dispensed' => $actual, 'reason' => 'patient_declined', 'allocations' => [[
            'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => $actual,
        ]]]);
        $case->refresh();
        $exception = $item->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($f['doctor'], $exception, ['case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version]);

        return compact('patient', 'visit', 'queue', 'encounter', 'profile', 'plan', 'case', 'item');
    }

    private function assertAuthorityLossFailsClosed(string $mode): void
    {
        $f = $this->zeroCompletableFixture();
        $leaderArgs = [$mode, (string) $f['ca']->id];
        if ($mode === 'revoke') {
            $leaderArgs[] = 'dispensary.complete.branch';
        } elseif ($mode === 'end-assignment') {
            $leaderArgs[] = (string) $f['branch']->id;
        }
        $leader = $this->worker($leaderArgs);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $this->assertStringContainsString('DENIED', $this->leaderThenFollower($leader, $follower));
        $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @return list<string> */
    private function doctorPermissions(): array
    {
        return ['consultations.complete.own', 'branch_context.switch.branch', 'queue.view.own', 'queue.call.own', 'encounters.view.own', 'encounters.start.own', 'encounters.update.own', 'allergies.view.own', 'allergies.update.own', 'allergies.review.own', 'treatment_plans.view.own', 'treatment_plans.create.own', 'treatment_plans.update.own', 'treatment_plans.send_to_dispensary.own', 'dispensary.acknowledge_partial.own'];
    }

    /** @return list<string> */
    private function caPermissions(): array
    {
        return ['branch_context.switch.branch', 'dispensary.view.branch', 'dispensary.start.branch', 'dispensary.update.branch', 'dispensary.complete.branch', 'dispensary.return_to_doctor.branch', 'inventory.view.branch', 'inventory.transfer.branch'];
    }

    /** @return list<string> */
    private function inventorySupervisorPermissions(): array
    {
        return [...$this->caPermissions(), 'inventory.opening_balance.branch', 'inventory.transfer.organisation'];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, ?string $role, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Phase 3A User', 'email' => 'disp.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        if ($role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'temporary', 'is_primary' => false, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString()]);

        return $user->refresh();
    }

    /** @param list<string> $arguments @return Worker */
    private function worker(array $arguments): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresDispensaryInventoryWorker.php'), ...$arguments, 'kpone-dispensary-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(40);
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /** @param list<Worker> $workers */
    private function race(array $workers, Model $blocker): string
    {
        DB::beginTransaction();
        try {
            $blocker->newQuery()->whereKey($blocker->getKey())->lockForUpdate()->firstOrFail();
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            foreach ($workers as $worker) {
                $worker['input']->write("GO\n");
                $worker['input']->close();
            }
            foreach ($workers as $worker) {
                $this->waitForDatabaseBlock($worker['process'], $parentPid);
            }
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
        $this->finish($workers);

        return $this->workerOutput($workers);
    }

    private function leaderThenFollower(array $leader, array $follower): string
    {
        $leader['process']->start();
        $this->waitReady([$leader]);
        $leader['input']->write("GO\n");
        $this->waitForOutput($leader['process'], 'LOCKED');
        $leaderPid = $this->workerPid($leader['process']);
        $follower['process']->start();
        $this->waitReady([$follower]);
        $follower['input']->write("GO\n");
        $follower['input']->close();
        $this->waitForDatabaseBlock($follower['process'], $leaderPid);
        $leader['input']->write("COMMIT\n");
        $leader['input']->close();
        $this->finish([$leader, $follower]);

        return $this->workerOutput([$leader, $follower]);
    }

    /** @param list<Worker> $workers */
    private function runWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            $worker['process']->start();
        }
        $this->waitReady($workers);
        foreach ($workers as $worker) {
            $worker['input']->write("GO\n");
            $worker['input']->close();
        }
        $this->finish($workers, false);
    }

    /** @param list<Worker> $workers */
    private function finish(array $workers, bool $requireZero = true): void
    {
        foreach ($workers as $worker) {
            $worker['process']->wait();
            if ($requireZero) {
                $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getOutput().' STDERR: '.$worker['process']->getErrorOutput());
            }
        }
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + 12;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains(str_replace("\r\n", "\n", $worker['process']->getOutput()), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Phase 3A worker did not report READY. '.$this->diagnostics($workers));
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $deadline = microtime(true) + 12;
        do {
            if (str_contains(str_replace("\r\n", "\n", $process->getOutput()), $needle)) {
                return;
            }
            if ($process->isTerminated()) {
                throw new RuntimeException('Phase 3A worker exited before '.$needle.'. '.$this->processDiagnostics($process));
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Phase 3A worker protocol timeout for '.$needle.'. '.$this->processDiagnostics($process));
    }

    private function workerPid(Process $process): int
    {
        preg_match('/READY ([1-9][0-9]*)/', str_replace("\r\n", "\n", $process->getOutput()), $matches);
        $pid = (int) ($matches[1] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Phase 3A worker did not report a backend PID. '.$this->processDiagnostics($process));
        }

        return $pid;
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        $pid = $this->workerPid($process);
        $deadline = microtime(true) + 12;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Phase 3A contention was not reached. '.$this->processDiagnostics($process));
            }
            $result = $this->observer()->selectOne(<<<'SQL'
                select exists (
                    with recursive blockers(pid) as (
                        select unnest(pg_blocking_pids(activity.pid))
                        union
                        select unnest(pg_blocking_pids(blockers.pid)) from blockers
                    ) select 1 from blockers where pid = ?
                ) as expected_blocker
                from pg_stat_activity as activity where pid = ?
                SQL, [$expectedBlockerPid, $pid]);
            if ($result && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Expected Phase 3A PostgreSQL blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    /** @param list<Worker> $workers */
    private function workerOutput(array $workers): string
    {
        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    /** @param list<Worker> $workers */
    private function diagnostics(array $workers): string
    {
        return implode(' | ', array_map(fn ($worker) => $this->processDiagnostics($worker['process']), $workers));
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }

    private function deleteOrganisation(int $id): void
    {
        DB::transaction(function () use ($id): void {
            DB::table('service_deliveries')->where('organisation_id', $id)->delete();
            DB::table('consultation_checkouts')->where('organisation_id', $id)->delete();
            foreach (['stock_movements', 'dispensary_item_batch_allocations', 'dispensary_item_exceptions', 'dispensary_items', 'dispensary_handoffs', 'dispensary_cases', 'inventory_stock_balances', 'medicine_catalogue_inventory_skus', 'medicine_catalogue_aliases', 'inventory_batches', 'inventory_locations', 'inventory_skus', 'inventory_items', 'treatment_plan_service_orders', 'treatment_plan_medicine_orders', 'treatment_plans', 'clinical_service_catalogue_items', 'medicine_catalogue_items', 'clinical_encounter_allergy_reviews', 'patient_allergy_records', 'patient_allergy_profile_versions', 'patient_allergy_profiles', 'audit_logs', 'clinical_encounters', 'queue_entries', 'queue_number_counters', 'visits', 'visit_number_counters', 'patients', 'patient_number_counters'] as $table) {
                DB::table($table)->where('organisation_id', $id)->delete();
            }
            $branchIds = DB::table('branches')->where('organisation_id', $id)->pluck('id');
            DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
            $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $id)->delete();
            DB::table('branches')->where('organisation_id', $id)->delete();
            DB::table('organisations')->where('id', $id)->delete();
        });
    }
}

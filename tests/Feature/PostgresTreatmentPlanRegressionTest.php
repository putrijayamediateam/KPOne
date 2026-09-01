<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Services\AllergyReviewGate;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CurrentClinicalCareService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/** @phpstan-type Worker array{process: Process, input: InputStream} */
class PostgresTreatmentPlanRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_treatment_plan_observer';

    private ?int $organisationId = null;

    /** @var list<int> */
    private array $additionalOrganisationIds = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Treatment Plan regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Treatment Plan concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['treatment_plans', 'treatment_plan_medicine_orders', 'treatment_plan_service_orders'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Treatment Plan regressions.');
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
        $this->tearDownOrganisation();
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_simultaneous_first_plan_creation_is_idempotent(): void
    {
        $fixture = $this->fixture();
        $service = $this->serviceCatalogue($fixture);
        $arguments = ['save-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'SAME'];
        $workers = [$this->worker($arguments), $this->worker($arguments)];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $this->assertSame(2, substr_count($this->workerOutput($workers), 'SAVED'));
        $this->assertSame(1, TreatmentPlan::query()->where('clinical_encounter_id', $fixture['encounter']->id)->count());
        $this->assertSame(1, DB::table('treatment_plan_service_orders')->where('organisation_id', $fixture['organisation']->id)->count());
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $fixture['organisation']->id)->where('event', 'treatment_plan.created')->count());
    }

    public function test_simultaneous_aggregate_saves_have_one_authoritative_winner(): void
    {
        $fixture = $this->fixture();
        $service = $this->serviceCatalogue($fixture);
        $plan = app(TreatmentPlanService::class)->save($fixture['doctor'], $fixture['visit'], $this->servicePayload($fixture, null, $service->public_id, 'BASE'));
        $order = $plan->serviceOrders()->sole();
        $workers = [
            $this->worker(['update-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $order->public_id, 'ALPHA']),
            $this->worker(['update-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $order->public_id, 'BETA']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'SAVED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(2, $plan->refresh()->lock_version);
    }

    public function test_concurrent_medicine_replacement_cannot_lose_an_update(): void
    {
        $fixture = $this->fixture();
        $this->reviewNoKnown($fixture);
        $medicine = $this->medicineCatalogue($fixture, 'A');
        $plan = app(TreatmentPlanService::class)->save($fixture['doctor'], $fixture['visit'], [
            'expected_branch_id' => $fixture['branch']->id,
            'lock_version' => null,
            'medicines' => [[
                'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => 1,
                'dosage' => 'Synthetic baseline dosage', 'frequency' => 'Synthetic frequency', 'duration' => null,
                'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
            ]],
            'services' => [],
        ]);
        $order = $plan->medicineOrders()->sole();
        $workers = [
            $this->worker(['update-medicine', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $order->public_id, 'ALPHA']),
            $this->worker(['withdraw-all', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', '-', 'WITHDRAW']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'SAVED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(1, DB::table('treatment_plan_medicine_orders')->where('organisation_id', $fixture['organisation']->id)->count());
        $this->assertSame(2, $plan->refresh()->lock_version);
    }

    public function test_concurrent_service_update_and_withdrawal_cannot_lose_an_update(): void
    {
        $fixture = $this->fixture();
        $service = $this->serviceCatalogue($fixture);
        $plan = app(TreatmentPlanService::class)->save($fixture['doctor'], $fixture['visit'], $this->servicePayload($fixture, null, $service->public_id, 'BASE'));
        $order = $plan->serviceOrders()->sole();
        $workers = [
            $this->worker(['update-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $order->public_id, 'UPDATE']),
            $this->worker(['withdraw-all', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', '-', 'WITHDRAW']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'SAVED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(2, $plan->refresh()->lock_version);
        $this->assertSame(1, DB::table('treatment_plan_service_orders')->where('organisation_id', $fixture['organisation']->id)->count());
    }

    public function test_encounter_note_and_treatment_plan_are_independent_concurrent_aggregates(): void
    {
        $fixture = $this->fixture();
        $service = $this->serviceCatalogue($fixture);
        $workers = [
            $this->worker(['save-note', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, (string) $fixture['encounter']->lock_version, '-', 'NOTE']),
            $this->worker(['save-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'PLAN']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['visit']);

        $output = $this->workerOutput($workers);
        $this->assertStringContainsString('NOTE_SAVED', $output);
        $this->assertStringContainsString('SAVED', $output);
        $this->assertSame('Synthetic independent Treatment Plan race note NOTE', $fixture['encounter']->refresh()->clinical_note);
        $this->assertDatabaseHas('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
    }

    public function test_save_racing_permission_loss_fails_closed_after_real_actor_lock_contention(): void
    {
        $fixture = $this->fixture();
        $service = $this->serviceCatalogue($fixture);
        $leader = $this->worker(['revoke-permission', (string) $fixture['doctor']->id, 'treatment_plans.create.own']);
        $leader['process']->start();
        $this->waitReady([$leader]);
        $leader['input']->write("GO\n");
        $this->waitForOutput($leader['process'], 'LOCKED');
        $leaderPid = $this->workerPid($leader['process']);
        $follower = $this->worker(['save-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'AUTH']);
        $follower['process']->start();
        $this->waitReady([$follower]);
        $follower['input']->write("GO\n");
        $follower['input']->close();
        $this->waitForDatabaseBlock($follower['process'], $leaderPid);
        $leader['input']->write("COMMIT\n");
        $leader['input']->close();
        $leader['process']->wait();
        $follower['process']->wait();

        $this->assertStringContainsString('DENIED', $follower['process']->getOutput());
        $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
    }

    public function test_save_racing_account_role_and_assignment_loss_fails_closed(): void
    {
        foreach (['deactivate', 'remove-role', 'end-assignment'] as $index => $mode) {
            $fixture = $index === 0 ? $this->fixture() : $this->secondFixture('TREATMENT_AUTH_'.$index);
            $service = $this->serviceCatalogue($fixture);
            $leaderArguments = [$mode, (string) $fixture['doctor']->id];
            if ($mode === 'end-assignment') {
                $leaderArguments[] = (string) $fixture['branch']->id;
            }
            $leader = $this->worker($leaderArguments);
            $leader['process']->start();
            $this->waitReady([$leader]);
            $leader['input']->write("GO\n");
            $this->waitForOutput($leader['process'], 'LOCKED');
            $leaderPid = $this->workerPid($leader['process']);
            $follower = $this->worker(['save-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'AUTH-'.$mode]);
            $follower['process']->start();
            $this->waitReady([$follower]);
            $follower['input']->write("GO\n");
            $follower['input']->close();
            $this->waitForDatabaseBlock($follower['process'], $leaderPid);
            $leader['input']->write("COMMIT\n");
            $leader['input']->close();
            $leader['process']->wait();
            $follower['process']->wait();

            $this->assertStringContainsString('DENIED', $follower['process']->getOutput(), $mode);
            $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
        }
    }

    public function test_save_racing_visit_queue_or_encounter_state_change_revalidates_current_care(): void
    {
        foreach (['reassign', 'remove-queue', 'change-encounter-owner'] as $index => $mode) {
            $fixture = $index === 0 ? $this->fixture() : $this->secondFixture('TREATMENT_STATE_'.$index);
            $service = $this->serviceCatalogue($fixture);
            $other = $this->user($fixture['organisation'], $fixture['branch'], 'resident_doctor', $this->clinicalPermissions());
            $leaderArguments = [$mode, $fixture['visit']->visit_number];
            $leaderArguments[] = $mode === 'remove-queue'
                ? (string) $fixture['operator']->id
                : (string) $other->id;
            $leader = $this->worker($leaderArguments);
            $leader['process']->start();
            $this->waitReady([$leader]);
            $leader['input']->write("GO\n");
            $this->waitForOutput($leader['process'], 'LOCKED');
            $leaderPid = $this->workerPid($leader['process']);
            $follower = $this->worker(['save-service', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'STATE-'.$mode]);
            $follower['process']->start();
            $this->waitReady([$follower]);
            $follower['input']->write("GO\n");
            $follower['input']->close();
            $this->waitForDatabaseBlock($follower['process'], $leaderPid);
            $leader['input']->write("COMMIT\n");
            $leader['input']->close();
            $leader['process']->wait();
            $follower['process']->wait();

            $this->assertStringContainsString('DENIED', $follower['process']->getOutput(), $mode);
            $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
        }
    }

    public function test_allergy_profile_mutation_and_medicine_save_serialize_the_exact_safety_version(): void
    {
        $fixture = $this->fixture();
        $profile = $this->reviewNoKnown($fixture);
        $medicine = $this->medicineCatalogue($fixture, 'RACE');
        $workers = [
            $this->worker(['save-medicine', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $medicine->public_id, 'ORDER']),
            $this->worker(['add-allergy', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, (string) $profile->lock_version, '-', 'PROFILE']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertStringContainsString('ALLERGY_MUTATED', $output);
        $this->assertSame(1, substr_count($output, 'SAVED') + substr_count($output, 'STALE'));
        $currentVersion = (int) DB::table('patient_allergy_profiles')->where('id', $profile->id)->value('lock_version');
        $this->assertSame($profile->lock_version + 1, $currentVersion);
        $orderVersion = DB::table('treatment_plan_medicine_orders')->where('organisation_id', $fixture['organisation']->id)->value('allergy_profile_version_validated');
        if (str_contains($output, 'SAVED')) {
            $this->assertSame($profile->lock_version, (int) $orderVersion);
        } else {
            $this->assertNull($orderVersion);
        }

        $staleFixture = $this->secondFixture('TREATMENT_STALE_REVIEW');
        $staleProfile = $this->reviewNoKnown($staleFixture);
        $medicineA = $this->medicineCatalogue($staleFixture, 'STALE-A');
        $medicineB = $this->medicineCatalogue($staleFixture, 'STALE-B');
        $service = app(TreatmentPlanService::class);
        $plan = $service->save($staleFixture['doctor'], $staleFixture['visit'], [
            'expected_branch_id' => $staleFixture['branch']->id,
            'lock_version' => null,
            'medicines' => [
                $this->medicinePayload($medicineA),
                $this->medicinePayload($medicineB),
            ],
            'services' => [],
        ]);
        $orderA = $plan->medicineOrders()->where('position', 1)->sole();
        $orderB = $plan->medicineOrders()->where('position', 2)->sole();
        $validatedVersions = [
            $orderA->id => $orderA->allergy_profile_version_validated,
            $orderB->id => $orderB->allergy_profile_version_validated,
        ];

        app(PatientAllergyService::class)->add($staleFixture['doctor'], $staleFixture['visit'], [
            'expected_branch_id' => $staleFixture['branch']->id,
            'profile_lock_version' => $staleProfile->lock_version,
            'allergen_text' => 'Synthetic PostgreSQL stale-review mutation',
            'category' => 'medication',
            'reaction_text' => null,
            'severity' => null,
        ]);

        $plan = $service->save($staleFixture['doctor'], $staleFixture['visit'], [
            'expected_branch_id' => $staleFixture['branch']->id,
            'lock_version' => $plan->lock_version,
            'medicines' => [
                $this->medicinePayload($medicineB, $orderB->public_id),
                $this->medicinePayload($medicineA, $orderA->public_id),
            ],
            'services' => [],
        ]);
        $this->assertSame(1, $orderB->refresh()->position);
        $this->assertSame(2, $orderA->refresh()->position);
        $this->assertSame($validatedVersions[$orderA->id], $orderA->allergy_profile_version_validated);
        $this->assertSame($validatedVersions[$orderB->id], $orderB->allergy_profile_version_validated);

        $plan = $service->save($staleFixture['doctor'], $staleFixture['visit'], [
            'expected_branch_id' => $staleFixture['branch']->id,
            'lock_version' => $plan->lock_version,
            'medicines' => [$this->medicinePayload($medicineA, $orderA->public_id)],
            'services' => [],
        ]);
        $this->assertSame('withdrawn', $orderB->refresh()->status);
        $this->assertSame($validatedVersions[$orderB->id], $orderB->allergy_profile_version_validated);

        $changedMedicine = $this->medicinePayload($medicineA, $orderA->public_id);
        $changedMedicine['dosage'] = 'Changed while Allergy review is stale';
        try {
            $service->save($staleFixture['doctor'], $staleFixture['visit'], [
                'expected_branch_id' => $staleFixture['branch']->id,
                'lock_version' => $plan->lock_version,
                'medicines' => [$changedMedicine],
                'services' => [],
            ]);
            $this->fail('A clinically meaningful medicine edit used a stale Allergy review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allergy_review', $exception->errors());
        }

        try {
            $service->save($staleFixture['doctor'], $staleFixture['visit'], [
                'expected_branch_id' => $staleFixture['branch']->id,
                'lock_version' => $plan->lock_version,
                'medicines' => [
                    $this->medicinePayload($medicineA, $orderA->public_id),
                    $this->medicinePayload($this->medicineCatalogue($staleFixture, 'STALE-NEW')),
                ],
                'services' => [],
            ]);
            $this->fail('A new medicine used a stale Allergy review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allergy_review', $exception->errors());
        }

        $this->assertSame($plan->lock_version, $plan->refresh()->lock_version);
        $this->assertSame($validatedVersions[$orderA->id], $orderA->refresh()->allergy_profile_version_validated);

        $currentProfile = $staleProfile->refresh();
        app(PatientAllergyService::class)->review($staleFixture['doctor'], $staleFixture['visit'], [
            'expected_branch_id' => $staleFixture['branch']->id,
            'profile_lock_version' => $currentProfile->lock_version,
        ]);
        $changedMedicine['dosage'] = 'Changed after current Allergy review';
        $plan = $service->save($staleFixture['doctor'], $staleFixture['visit'], [
            'expected_branch_id' => $staleFixture['branch']->id,
            'lock_version' => $plan->lock_version,
            'medicines' => [$changedMedicine],
            'services' => [],
        ]);

        $this->assertSame('Changed after current Allergy review', $orderA->refresh()->dosage);
        $this->assertSame($currentProfile->lock_version, $orderA->allergy_profile_version_validated);
        $this->assertSame($plan->lock_version, $plan->refresh()->lock_version);
    }

    public function test_catalogue_inactivation_serializes_before_new_medicine_and_service_orders(): void
    {
        foreach (['medicine', 'service'] as $index => $type) {
            $fixture = $index === 0 ? $this->fixture() : $this->secondFixture('TREATMENT_CATALOGUE_'.$index);
            if ($type === 'medicine') {
                $this->reviewNoKnown($fixture);
                $catalogue = $this->medicineCatalogue($fixture, 'INACTIVE');
            } else {
                $catalogue = $this->serviceCatalogue($fixture);
            }
            $leader = $this->worker(['inactivate-'.$type, $catalogue->public_id]);
            $leader['process']->start();
            $this->waitReady([$leader]);
            $leader['input']->write("GO\n");
            $this->waitForOutput($leader['process'], 'LOCKED');
            $leaderPid = $this->workerPid($leader['process']);
            $follower = $this->worker([
                'save-'.$type,
                (string) $fixture['doctor']->id,
                $fixture['visit']->visit_number,
                (string) $fixture['branch']->id,
                'null',
                $catalogue->public_id,
                'INACTIVE',
            ]);
            $follower['process']->start();
            $this->waitReady([$follower]);
            $follower['input']->write("GO\n");
            $follower['input']->close();
            $this->waitForDatabaseBlock($follower['process'], $leaderPid);
            $leader['input']->write("COMMIT\n");
            $leader['input']->close();
            $leader['process']->wait();
            $follower['process']->wait();

            $this->assertStringContainsString('STALE', $follower['process']->getOutput(), $type);
            $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
        }
    }

    public function test_late_audit_failure_rolls_back_plan_children_and_version(): void
    {
        $fixture = $this->fixture();
        $serviceItem = $this->serviceCatalogue($fixture);
        $failingAudit = new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                if (str_starts_with($event, 'treatment_plan.')) {
                    throw new RuntimeException('Injected Treatment Plan audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new TreatmentPlanService(app(CurrentClinicalCareService::class), app(AllergyReviewGate::class), $failingAudit);

        try {
            $service->save($fixture['doctor'], $fixture['visit'], $this->servicePayload($fixture, null, $serviceItem->public_id, 'ROLLBACK'));
            $this->fail('Expected late Treatment Plan audit failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
            $this->assertSame(0, DB::table('treatment_plan_service_orders')->where('organisation_id', $fixture['organisation']->id)->count());
        }

        $plan = app(TreatmentPlanService::class)->save(
            $fixture['doctor'],
            $fixture['visit'],
            $this->servicePayload($fixture, null, $serviceItem->public_id, 'BASE'),
        );
        $order = $plan->serviceOrders()->sole();
        $update = [
            'expected_branch_id' => $fixture['branch']->id,
            'lock_version' => $plan->lock_version,
            'medicines' => [],
            'services' => [[
                'public_id' => $order->public_id,
                'catalogue_public_id' => null,
                'quantity_ordered' => 2,
                'clinical_instruction' => 'Synthetic late rollback update',
            ]],
        ];

        try {
            $service->save($fixture['doctor'], $fixture['visit'], $update);
            $this->fail('Expected late Treatment Plan update audit failure.');
        } catch (RuntimeException) {
            $this->assertSame(1, $plan->refresh()->lock_version);
            $this->assertSame('1.000', $order->refresh()->quantity_ordered);
            $this->assertSame('Synthetic BASE', $order->clinical_instruction);
            $this->assertSame(1, AuditLog::query()->where('organisation_id', $fixture['organisation']->id)->where('event', 'treatment_plan.created')->count());
            $this->assertSame(0, AuditLog::query()->where('organisation_id', $fixture['organisation']->id)->where('event', 'treatment_plan.updated')->count());
        }
    }

    public function test_cross_organisation_worker_probe_is_privacy_preserving(): void
    {
        $fixture = $this->fixture();
        $other = $this->secondFixture('TREATMENT_PLAN_OTHER');
        $service = $this->serviceCatalogue($fixture);
        $worker = $this->worker(['save-service', (string) $fixture['doctor']->id, $other['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'PROBE']);
        $this->runTogether([$worker]);

        $this->assertStringContainsString('DENIED', $worker['process']->getOutput());
        $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $other['encounter']->id]);

        $wrongDoctor = $this->user($fixture['organisation'], $fixture['branch'], 'resident_doctor', $this->clinicalPermissions());
        $sameOrganisationProbe = $this->worker(['save-service', (string) $wrongDoctor->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', $service->public_id, 'WRONG-DOCTOR']);
        $this->runTogether([$sameOrganisationProbe]);

        $this->assertStringContainsString('DENIED', $sameOrganisationProbe['process']->getOutput());
        $this->assertDatabaseMissing('treatment_plans', ['clinical_encounter_id' => $fixture['encounter']->id]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = $this->organisation('TREATMENT_PLAN_PG');
        $this->organisationId = $organisation->id;
        $branch = $this->branch($organisation, 'ONE');
        $operator = $this->user($organisation, $branch, null, ['queue.view.branch', 'queue.enter.branch', 'queue.call.branch', 'visits.view.branch', 'branch_context.switch.organisation']);
        $doctor = $this->user($organisation, $branch, 'resident_doctor', $this->clinicalPermissions());
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => sprintf('KP-%08d', 95_000_000 + $this->sequence), 'full_name' => 'Synthetic Treatment Patient', 'search_name' => 'synthetic treatment patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 95_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic Treatment Plan reason', 'assigned_doctor_user_id' => $doctor->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $operator->id, 'updated_by_user_id' => $operator->id, 'lock_version' => 1])->save();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $queue = app(QueueEntryService::class)->enter($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $encounter = app(ClinicalEncounterService::class)->start($doctor, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);

        return compact('organisation', 'branch', 'operator', 'doctor', 'patient', 'visit', 'queue', 'encounter');
    }

    /** @return array<string, mixed> */
    private function secondFixture(string $prefix): array
    {
        $original = $this->organisationId;
        $fixture = $this->fixture();
        $this->additionalOrganisationIds[] = (int) $this->organisationId;
        $this->organisationId = $original;

        return $fixture;
    }

    /** @return list<string> */
    private function clinicalPermissions(): array
    {
        return ['branch_context.switch.branch', 'queue.view.own', 'queue.call.own', 'encounters.view.own', 'encounters.start.own', 'encounters.update.own', 'allergies.view.own', 'allergies.update.own', 'allergies.review.own', 'treatment_plans.view.own', 'treatment_plans.create.own', 'treatment_plans.update.own'];
    }

    private function organisation(string $prefix): Organisation
    {
        $model = new Organisation;
        $model->forceFill(['code' => $prefix.'_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Treatment Plan PG', 'is_active' => true])->save();

        return $model;
    }

    private function branch(Organisation $organisation, string $code): Branch
    {
        $model = new Branch;
        $model->forceFill(['organisation_id' => $organisation->id, 'code' => $code.Str::upper(Str::random(3)), 'name' => 'Synthetic '.$code, 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();

        return $model;
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, ?string $role, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Treatment User', 'email' => 'treatment.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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

    /** @param array<string, mixed> $fixture */
    private function serviceCatalogue(array $fixture): ClinicalServiceCatalogueItem
    {
        $item = new ClinicalServiceCatalogueItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id, 'code' => 'SVC-'.Str::upper(Str::random(6)), 'display_name' => 'Synthetic service', 'order_unit' => 'service', 'is_active' => true, 'created_by_user_id' => $fixture['doctor']->id, 'updated_by_user_id' => $fixture['doctor']->id])->save();

        return $item;
    }

    /** @param array<string, mixed> $fixture */
    private function medicineCatalogue(array $fixture, string $suffix): MedicineCatalogueItem
    {
        $item = new MedicineCatalogueItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id, 'code' => 'MED-'.$suffix.Str::upper(Str::random(5)), 'display_name' => 'Synthetic medicine '.$suffix, 'strength_text' => null, 'dosage_form' => null, 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, 'is_active' => true, 'created_by_user_id' => $fixture['doctor']->id, 'updated_by_user_id' => $fixture['doctor']->id])->save();

        return $item;
    }

    /** @param array<string, mixed> $fixture */
    private function reviewNoKnown(array $fixture): PatientAllergyProfile
    {
        $service = app(PatientAllergyService::class);
        $profile = $service->declareNoKnown($fixture['doctor'], $fixture['visit'], ['expected_branch_id' => $fixture['branch']->id, 'profile_lock_version' => null]);
        $service->review($fixture['doctor'], $fixture['visit'], ['expected_branch_id' => $fixture['branch']->id, 'profile_lock_version' => $profile->lock_version]);

        return $profile;
    }

    /** @return array<string, mixed> */
    private function medicinePayload(MedicineCatalogueItem $item, ?string $publicId = null): array
    {
        return ['public_id' => $publicId, 'catalogue_public_id' => $publicId ? null : $item->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function servicePayload(array $fixture, ?int $version, string $cataloguePublicId, string $suffix): array
    {
        return ['expected_branch_id' => $fixture['branch']->id, 'lock_version' => $version, 'medicines' => [], 'services' => [['public_id' => null, 'catalogue_public_id' => $cataloguePublicId, 'quantity_ordered' => 1, 'clinical_instruction' => 'Synthetic '.$suffix]]];
    }

    /**
     * @param  list<string>  $arguments
     * @return Worker
     */
    private function worker(array $arguments): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresTreatmentPlanWorker.php'), ...$arguments, 'kpone-treatment-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(30);
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /** @param list<Worker> $workers */
    private function runTogetherWhileParentBlocks(array $workers, Model $blocker): void
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
        foreach ($workers as $worker) {
            $worker['process']->wait();
            $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        }
    }

    /** @param list<Worker> $workers */
    private function runTogether(array $workers): void
    {
        foreach ($workers as $worker) {
            $worker['process']->start();
        }
        $this->waitReady($workers);
        foreach ($workers as $worker) {
            $worker['input']->write("GO\n");
            $worker['input']->close();
        }
        foreach ($workers as $worker) {
            $worker['process']->wait();
            $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        }
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains(str_replace("\r\n", "\n", $worker['process']->getOutput()), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Treatment Plan worker did not report READY.');
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains(str_replace("\r\n", "\n", $process->getOutput()), $needle)) {
                return;
            }
            if ($process->isTerminated()) {
                throw new RuntimeException('Treatment Plan worker exited before reporting '.$needle.'. Output: '.$process->getOutput().' Error: '.$process->getErrorOutput());
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Treatment Plan worker protocol timeout waiting for '.$needle.'. Output: '.$process->getOutput().' Error: '.$process->getErrorOutput());
    }

    private function workerPid(Process $process): int
    {
        preg_match('/READY ([1-9][0-9]*)/', str_replace("\r\n", "\n", $process->getOutput()), $matches);
        $pid = (int) ($matches[1] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Treatment Plan worker did not report a valid backend PID.');
        }

        return $pid;
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        $pid = $this->workerPid($process);
        $deadline = microtime(true) + 10;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Treatment Plan worker exited before blocking was observed. Output: '.$process->getOutput().' Error: '.$process->getErrorOutput());
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
        throw new RuntimeException('Expected Treatment Plan PostgreSQL blocking was not observed. Output: '.$process->getOutput().' Error: '.$process->getErrorOutput());
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

    private function tearDownOrganisation(): void
    {
        $ids = array_values(array_filter([$this->organisationId, ...$this->additionalOrganisationIds]));
        foreach ($ids as $id) {
            $this->deleteOrganisation((int) $id);
        }
        $this->organisationId = null;
        $this->additionalOrganisationIds = [];
    }

    private function deleteOrganisation(int $id): void
    {
        DB::table('treatment_plan_service_orders')->where('organisation_id', $id)->delete();
        DB::table('treatment_plan_medicine_orders')->where('organisation_id', $id)->delete();
        DB::table('treatment_plans')->where('organisation_id', $id)->delete();
        DB::table('clinical_service_catalogue_items')->where('organisation_id', $id)->delete();
        DB::table('medicine_catalogue_items')->where('organisation_id', $id)->delete();
        DB::transaction(function () use ($id): void {
            DB::table('clinical_encounter_allergy_reviews')->where('organisation_id', $id)->delete();
            DB::table('patient_allergy_records')->where('organisation_id', $id)->delete();
            DB::table('patient_allergy_profile_versions')->where('organisation_id', $id)->delete();
            DB::table('patient_allergy_profiles')->where('organisation_id', $id)->delete();
        });
        DB::table('audit_logs')->where('organisation_id', $id)->delete();
        DB::table('clinical_encounters')->where('organisation_id', $id)->delete();
        DB::table('queue_entries')->where('organisation_id', $id)->delete();
        DB::table('queue_number_counters')->where('organisation_id', $id)->delete();
        DB::table('visits')->where('organisation_id', $id)->delete();
        DB::table('visit_number_counters')->where('organisation_id', $id)->delete();
        DB::table('patients')->where('organisation_id', $id)->delete();
        DB::table('patient_number_counters')->where('organisation_id', $id)->delete();
        $branchIds = DB::table('branches')->where('organisation_id', $id)->pluck('id');
        DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
        $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
        DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->where('organisation_id', $id)->delete();
        DB::table('branches')->where('organisation_id', $id)->delete();
        DB::table('organisations')->where('id', $id)->delete();
    }
}

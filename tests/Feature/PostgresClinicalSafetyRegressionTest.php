<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyProfileVersion;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\ClinicalSafetyDirectoryService;
use App\Domain\Clinical\Services\CurrentClinicalCareService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\PatientProblemService;
use App\Domain\Identity\Models\StaffProfile;
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
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class PostgresClinicalSafetyRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_clinical_safety_observer';

    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Clinical safety regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Clinical safety concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach ([
            'patient_allergy_profiles', 'patient_allergy_profile_versions', 'patient_allergy_records',
            'clinical_encounter_allergy_reviews', 'patient_problem_records', 'clinical_encounters',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Clinical safety regressions.');
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

    public function test_simultaneous_first_profile_creation_commits_one_coherent_aggregate(): void
    {
        $installedFunctions = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_proc
            INNER JOIN pg_namespace ON pg_namespace.oid = pg_proc.pronamespace
            WHERE pg_namespace.nspname = current_schema()
              AND pg_proc.proname IN (
                  'kpone_validate_allergy_profile_consistency',
                  'kpone_validate_encounter_allergy_review_patient'
              )
        SQL);
        $this->assertSame(
            2,
            $installedFunctions,
            'Laravel schema refresh must reinstall both Clinical Safety trigger functions.',
        );

        $fixture = $this->fixture();
        $workers = [
            $this->safetyWorker(['add', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', 'ALPHA']),
            $this->safetyWorker(['add', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', 'BETA']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'ADDED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $profile = PatientAllergyProfile::query()->where('patient_id', $fixture['patient']->id)->firstOrFail();
        $this->assertSame(PatientAllergyProfile::STATUS_HAS_ALLERGIES, $profile->status);
        $this->assertSame(1, $profile->lock_version);
        $this->assertSame(1, DB::table('patient_allergy_records')->where('patient_allergy_profile_id', $profile->id)->count());
        $this->assertSame(1, DB::table('patient_allergy_profile_versions')->where('patient_allergy_profile_id', $profile->id)->count());
    }

    public function test_no_known_declaration_racing_first_allergy_serializes_to_a_coherent_state(): void
    {
        $fixture = $this->fixture();
        $workers = [
            $this->safetyWorker(['declare', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null']),
            $this->safetyWorker(['add', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', 'RACE']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'STALE'));
        $profile = PatientAllergyProfile::query()->where('patient_id', $fixture['patient']->id)->firstOrFail();
        $active = PatientAllergyRecord::query()->where('patient_allergy_profile_id', $profile->id)->where('status', 'active')->count();
        $this->assertTrue(
            ($profile->status === PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES && $active === 0)
            || ($profile->status === PatientAllergyProfile::STATUS_HAS_ALLERGIES && $active === 1),
        );
        $this->assertSame(1, $profile->lock_version);
    }

    public function test_simultaneous_allergy_edits_allow_one_versioned_winner(): void
    {
        $fixture = $this->fixture();
        $record = $this->addAllergy($fixture, null);
        $workers = [
            $this->safetyWorker(['edit', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $record->public_id, 'ALPHA']),
            $this->safetyWorker(['edit', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $record->public_id, 'BETA']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'UPDATED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $profile = $record->profile->refresh();
        $this->assertSame(2, $profile->lock_version);
        $this->assertSame(2, $profile->versions()->count());
    }

    public function test_final_allergy_error_racing_new_allergy_cannot_commit_an_inconsistent_profile(): void
    {
        $fixture = $this->fixture();
        $record = $this->addAllergy($fixture, null);
        $workers = [
            $this->safetyWorker(['error', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', $record->public_id]),
            $this->safetyWorker(['add', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', 'NEW']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $this->assertSame(1, substr_count($this->workerOutput($workers), 'STALE'));
        $profile = $record->profile->refresh();
        $active = $profile->allergyRecords()->where('status', 'active')->count();
        $this->assertTrue(
            ($profile->status === PatientAllergyProfile::STATUS_UNKNOWN && $active === 0)
            || ($profile->status === PatientAllergyProfile::STATUS_HAS_ALLERGIES && $active === 2),
        );
        $this->assertSame(2, $profile->lock_version);
    }

    public function test_deferred_trigger_serializes_concurrent_last_active_allergy_changes(): void
    {
        $fixture = $this->fixture();
        $first = $this->addAllergy($fixture, null);
        $second = $this->addAllergy($fixture, 1);
        $profile = $first->profile->refresh();
        $leader = $this->safetyWorker([
            'raw-error-hold', (string) $profile->id, (string) $first->id, (string) $fixture['doctor']->id,
        ]);
        $follower = $this->safetyWorker([
            'raw-error-commit', (string) $profile->id, (string) $second->id, (string) $fixture['doctor']->id,
        ]);

        $leader['process']->start();
        $this->waitReady([$leader]);
        $leader['input']->write("GO\n");
        $this->waitForOutput($leader['process'], 'LOCKED');
        preg_match('/READY ([1-9][0-9]*)/', $leader['process']->getOutput(), $matches);
        $leaderPid = (int) ($matches[1] ?? 0);

        $follower['process']->start();
        $this->waitReady([$follower]);
        $follower['input']->write("GO\n");
        $follower['input']->close();
        $this->waitForOutput($follower['process'], 'MUTATED');
        $this->waitForDatabaseBlock($follower['process'], $leaderPid);

        $leader['input']->write("COMMIT\n");
        $leader['input']->close();
        $leader['process']->wait();
        $follower['process']->wait();

        $output = $this->workerOutput([$leader, $follower]);
        $this->assertSame(1, substr_count($output, 'COMMITTED'));
        $this->assertSame(1, substr_count($output, 'REJECTED'));
        $this->assertSame(PatientAllergyProfile::STATUS_HAS_ALLERGIES, $profile->refresh()->status);
        $this->assertSame(1, $profile->allergyRecords()->where('status', PatientAllergyRecord::STATUS_ACTIVE)->count());
    }

    public function test_deferred_trigger_validates_source_and_destination_when_record_ownership_moves(): void
    {
        $fixture = $this->fixture();
        $record = $this->addAllergy($fixture, null);
        $secondPatient = new Patient;
        $secondPatient->forceFill([
            'organisation_id' => $fixture['organisation']->id,
            'patient_number' => sprintf('KP-%08d', 97_000_000 + $this->sequence),
            'full_name' => 'Synthetic Trigger Destination Patient',
            'search_name' => 'synthetic trigger destination patient',
            'sex' => 'unknown',
            'lock_version' => 1,
        ])->save();

        [$secondProfile] = DB::transaction(function () use ($fixture, $secondPatient): array {
            $secondProfile = new PatientAllergyProfile;
            $secondProfile->forceFill([
                'organisation_id' => $fixture['organisation']->id,
                'patient_id' => $secondPatient->id,
                'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES,
                'reviewed_at' => now()->utc(),
                'reviewed_by_user_id' => $fixture['doctor']->id,
                'updated_by_user_id' => $fixture['doctor']->id,
                'lock_version' => 1,
            ])->save();
            $version = new PatientAllergyProfileVersion;
            $version->forceFill([
                'organisation_id' => $fixture['organisation']->id,
                'patient_allergy_profile_id' => $secondProfile->id,
                'version' => 1,
                'resulting_status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES,
                'changed_at' => now()->utc(),
                'changed_by_user_id' => $fixture['doctor']->id,
            ])->save();
            $secondRecord = new PatientAllergyRecord;
            $secondRecord->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $fixture['organisation']->id,
                'patient_allergy_profile_id' => $secondProfile->id,
                'allergen_text' => 'Synthetic destination allergen',
                'category' => null,
                'reaction_text' => null,
                'severity' => null,
                'status' => PatientAllergyRecord::STATUS_ACTIVE,
                'recorded_at' => now()->utc(),
                'recorded_by_user_id' => $fixture['doctor']->id,
                'updated_by_user_id' => $fixture['doctor']->id,
                'entered_in_error_at' => null,
                'entered_in_error_by_user_id' => null,
            ])->save();

            return [$secondProfile, $secondRecord];
        });

        try {
            DB::transaction(function () use ($record, $secondProfile): void {
                DB::table('patient_allergy_records')
                    ->where('id', $record->id)
                    ->update(['patient_allergy_profile_id' => $secondProfile->id]);
            });
            $this->fail('Expected the source Allergy Profile consistency trigger to reject the move.');
        } catch (QueryException|PDOException $exception) {
            $this->assertSame('P0001', $exception->getCode());
            $this->assertDatabaseHas('patient_allergy_records', [
                'id' => $record->id,
                'patient_allergy_profile_id' => $record->patient_allergy_profile_id,
            ]);
        }
    }

    public function test_encounter_review_racing_profile_mutation_is_current_or_detectably_stale(): void
    {
        $fixture = $this->fixture();
        $profile = $this->declareNoKnown($fixture, null);
        $workers = [
            $this->safetyWorker(['review', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1']),
            $this->safetyWorker(['add', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, '1', 'MUTATION']),
        ];

        $this->runTogetherWhileParentBlocks($workers, $fixture['patient']);

        $profile->refresh();
        $review = DB::table('clinical_encounter_allergy_reviews')->where('clinical_encounter_id', $fixture['encounter']->id)->first();
        if ($review) {
            $this->assertLessThanOrEqual($profile->lock_version, (int) $review->allergy_profile_lock_version_reviewed);
            if ((int) $review->allergy_profile_lock_version_reviewed !== $profile->lock_version) {
                $gate = $this->safetyWorker(['gate', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id]);
                $this->runTogether([$gate]);
                $this->assertStringContainsString('STALE', $gate['process']->getOutput());
            }
        } else {
            $this->assertStringContainsString('STALE', $this->workerOutput($workers));
        }
    }

    public function test_allergy_directory_projection_holds_a_shared_profile_lock(): void
    {
        $fixture = $this->fixture();
        $this->addAllergy($fixture, null);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $projection = app(ClinicalSafetyDirectoryService::class)
            ->allergies($fixture['doctor'], $fixture['encounter']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(PatientAllergyProfile::STATUS_HAS_ALLERGIES, $projection['status']);
        $this->assertTrue(collect($queries)->contains(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'patient_allergy_profiles')
                && str_contains($sql, 'for share');
        }), 'The Allergy projection must hold a shared Profile lock while it reads dependent safety state.');
    }

    public function test_allergy_mutation_revalidates_clinician_authority_loss(): void
    {
        foreach (['deactivate', 'change-role', 'change-assignment', 'permission'] as $mode) {
            $fixture = $this->fixture(true);
            $role = Role::findByName('resident_doctor', 'web');
            if ($mode === 'permission') {
                $role->revokePermissionTo('allergies.update.own');
                $mutation = $this->safetyWorker(['revoke-permission', (string) $fixture['doctor']->id, 'allergies.update.own']);
            } else {
                $arguments = match ($mode) {
                    'deactivate' => [$mode, (string) $fixture['doctor']->id],
                    'change-role' => [$mode, (string) $fixture['administrator']->id, (string) $fixture['doctor']->id],
                    default => [$mode, (string) $fixture['administrator']->id, (string) $fixture['profile']->id, (string) $fixture['assignment']->id],
                };
                $mutation = $this->visitWorker($arguments);
            }

            try {
                $mutation['process']->start();
                $this->waitReady([$mutation]);
                $mutation['input']->write("GO\n");
                $this->waitForOutput($mutation['process'], 'LOCKED');
                $clinical = $this->safetyWorker(['add', (string) $fixture['doctor']->id, $fixture['visit']->visit_number, (string) $fixture['branch']->id, 'null', 'AUTH']);
                $clinical['process']->start();
                $this->waitReady([$clinical]);
                $clinical['input']->write("GO\n");
                $clinical['input']->close();
                $this->waitForDatabaseBlock($clinical['process']);
                $mutation['input']->write("COMMIT\n");
                $mutation['input']->close();
                $mutation['process']->wait();
                $clinical['process']->wait();
                $this->assertStringContainsString('STALE', $clinical['process']->getOutput());
                $this->assertDatabaseMissing('patient_allergy_profiles', ['patient_id' => $fixture['patient']->id]);
            } finally {
                if ($mode === 'permission') {
                    $role->givePermissionTo('allergies.update.own');
                }
                $this->tearDownOrganisation();
            }
        }
    }

    public function test_wrong_doctor_and_cross_organisation_workers_cannot_probe(): void
    {
        $fixture = $this->fixture();
        $otherDoctor = $this->user($fixture['organisation'], $fixture['branch'], 'resident_doctor', $this->clinicalPermissions());
        $outsiderOrganisation = $this->organisation('CLINICAL_SAFETY_OUT');
        $outsiderBranch = $this->branch($outsiderOrganisation, 'OUT');
        $outsider = $this->user($outsiderOrganisation, $outsiderBranch, 'resident_doctor', $this->clinicalPermissions());

        foreach ([[$otherDoctor, $fixture['branch']], [$outsider, $outsiderBranch]] as [$actor, $branch]) {
            $worker = $this->safetyWorker(['add', (string) $actor->id, $fixture['visit']->visit_number, (string) $branch->id, 'null', 'PROBE']);
            $this->runTogether([$worker]);
            $this->assertStringContainsString('STALE', $worker['process']->getOutput());
        }
        $this->assertDatabaseMissing('patient_allergy_profiles', ['patient_id' => $fixture['patient']->id]);

        $this->deleteUser($outsider);
        $outsiderBranch->delete();
        $outsiderOrganisation->delete();
    }

    public function test_late_audit_failures_roll_back_allergy_review_and_problem_aggregates(): void
    {
        $fixture = $this->fixture();
        $failingAudit = new class extends AuditRecorder
        {
            public function record(
                string $event,
                ?Model $subject = null,
                array $metadata = [],
                ?User $actor = null,
                ?Branch $branch = null,
                ?int $organisationId = null,
            ): ?AuditLog {
                if (in_array($event, ['allergy_record.created', 'encounter.allergy_reviewed', 'problem.created'], true)) {
                    throw new RuntimeException('Injected Clinical safety audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $allergies = new PatientAllergyService(app(CurrentClinicalCareService::class), $failingAudit);
        try {
            $allergies->add($fixture['doctor'], $fixture['visit'], $this->allergyPayload($fixture, null));
            $this->fail('Expected Allergy rollback failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('patient_allergy_profiles', ['patient_id' => $fixture['patient']->id]);
            $this->assertDatabaseCount('patient_allergy_profile_versions', 0);
            $this->assertDatabaseCount('patient_allergy_records', 0);
        }

        $profile = $this->declareNoKnown($fixture, null);
        try {
            $allergies->review($fixture['doctor'], $fixture['visit'], [
                'expected_branch_id' => $fixture['branch']->id,
                'profile_lock_version' => $profile->lock_version,
            ]);
            $this->fail('Expected Encounter Allergy Review rollback failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('clinical_encounter_allergy_reviews', 0);
        }

        $problems = new PatientProblemService(app(CurrentClinicalCareService::class), $failingAudit);
        try {
            $problems->add($fixture['doctor'], $fixture['visit'], [
                'expected_branch_id' => $fixture['branch']->id,
                'condition_text' => 'Synthetic rollback condition',
            ]);
            $this->fail('Expected Problem rollback failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('patient_problem_records', 0);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(bool $security = false): array
    {
        $organisation = $this->organisation('CLINICAL_SAFETY_PG');
        $this->organisationId = $organisation->id;
        $branch = $this->branch($organisation, 'ONE');
        $operator = $this->user($organisation, $branch, null, [
            'queue.view.branch', 'queue.enter.branch', 'queue.call.branch',
            'visits.view.branch', 'branch_context.switch.organisation',
        ]);
        $doctor = $this->user($organisation, $branch, 'resident_doctor', $this->clinicalPermissions());
        $profile = $doctor->staffProfile;
        $assignment = $profile->branchAssignments()->firstOrFail();
        $administrator = $security
            ? $this->user($organisation, $branch, 'director', ['access.manage.organisation'])
            : null;
        if ($security) {
            Role::findOrCreate('ca', 'web');
        }
        $patient = new Patient;
        $patient->forceFill([
            'organisation_id' => $organisation->id,
            'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence),
            'full_name' => 'Synthetic Clinical Safety Patient '.$this->sequence,
            'search_name' => 'synthetic clinical safety patient '.$this->sequence,
            'sex' => 'unknown',
            'lock_version' => 1,
        ])->save();
        $visit = new Visit;
        $visit->forceFill([
            'organisation_id' => $organisation->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++),
            'idempotency_key' => (string) Str::uuid(),
            'visit_type' => 'consultation',
            'status' => Visit::STATUS_REGISTERED,
            'priority' => 'normal',
            'visit_reason' => 'Synthetic Clinical safety reason',
            'assigned_doctor_user_id' => $doctor->id,
            'coverage_type' => 'self_pay',
            'registered_at' => now()->utc(),
            'registered_by_user_id' => $operator->id,
            'updated_by_user_id' => $operator->id,
            'lock_version' => 1,
        ])->save();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $queue = app(QueueEntryService::class)->enter($operator, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
        ]);
        $queue = app(QueueEntryService::class)->call($operator, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
        ]);
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $encounter = app(ClinicalEncounterService::class)->start($doctor, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
        ]);

        return compact('organisation', 'branch', 'operator', 'doctor', 'profile', 'assignment', 'administrator', 'patient', 'visit', 'queue', 'encounter');
    }

    /** @return list<string> */
    private function clinicalPermissions(): array
    {
        return [
            'branch_context.switch.branch', 'queue.view.own', 'queue.call.own',
            'encounters.view.own', 'encounters.start.own', 'encounters.update.own',
            'allergies.view.own', 'allergies.update.own', 'allergies.review.own',
            'problems.view.own', 'problems.update.own',
        ];
    }

    private function organisation(string $prefix): Organisation
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => $prefix.'_'.Str::upper(Str::random(8)),
            'name' => 'Synthetic Clinical Safety PG',
            'is_active' => true,
        ])->save();

        return $organisation;
    }

    private function branch(Organisation $organisation, string $code): Branch
    {
        $branch = new Branch;
        $branch->forceFill([
            'organisation_id' => $organisation->id,
            'code' => $code,
            'name' => 'Synthetic '.$code,
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();

        return $branch;
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, ?string $role, array $permissions): User
    {
        $user = new User;
        $user->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'Synthetic Clinical Safety User',
            'email' => 'clinical.safety.pg.'.Str::lower(Str::random(10)).'@kpone.test',
            'is_active' => true,
        ])->save();
        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        return $user->refresh();
    }

    /** @param array<string, mixed> $fixture */
    private function addAllergy(array $fixture, ?int $version): PatientAllergyRecord
    {
        return app(PatientAllergyService::class)->add(
            $fixture['doctor'],
            $fixture['visit'],
            $this->allergyPayload($fixture, $version),
        );
    }

    /** @param array<string, mixed> $fixture */
    private function declareNoKnown(array $fixture, ?int $version): PatientAllergyProfile
    {
        return app(PatientAllergyService::class)->declareNoKnown($fixture['doctor'], $fixture['visit'], [
            'expected_branch_id' => $fixture['branch']->id,
            'profile_lock_version' => $version,
        ]);
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function allergyPayload(array $fixture, ?int $version): array
    {
        return [
            'expected_branch_id' => $fixture['branch']->id,
            'profile_lock_version' => $version,
            'allergen_text' => 'Synthetic PostgreSQL allergen',
            'category' => 'medication',
            'reaction_text' => null,
            'severity' => null,
        ];
    }

    private function safetyWorker(array $arguments): array
    {
        return $this->process(base_path('tests/Support/PostgresClinicalSafetyWorker.php'), $arguments);
    }

    private function visitWorker(array $arguments): array
    {
        return $this->process(base_path('tests/Support/PostgresVisitRegistrationWorker.php'), $arguments);
    }

    private function process(string $script, array $arguments): array
    {
        $input = new InputStream;
        $name = 'kpone-clinical-safety-pg-'.Str::lower(Str::random(10));
        $process = new Process([PHP_BINARY, $script, ...$arguments, $name], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    private function runTogetherWhileParentBlocks(array $workers, Patient $patient): void
    {
        DB::beginTransaction();
        try {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
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

    private function waitReady(array $workers): void
    {
        $timeoutSeconds = ContentionTimeouts::readyTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Clinical safety worker did not report ready within %ds (elapsed %.2fs). %s', $timeoutSeconds, microtime(true) - $started, $this->diagnostics($workers)));
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if (str_contains($process->getOutput(), $needle)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Clinical safety worker protocol timeout for %s within %ds (elapsed %.2fs). %s', $needle, $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
    }

    private function waitForDatabaseBlock(Process $process, ?int $expectedBlockerPid = null): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Clinical safety worker did not report a valid PostgreSQL backend PID. '.$this->processDiagnostics($process));
        }
        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Clinical safety worker exited before PostgreSQL blocking was observed. '.$this->processDiagnostics($process));
            }
            $result = $this->observer()->selectOne(
                <<<'SQL'
                    select
                        cardinality(pg_blocking_pids(pid)) as blocker_count,
                        case when ?::integer is null then false else exists (
                            with recursive blockers(pid) as (
                                select unnest(pg_blocking_pids(activity.pid))
                                union
                                select unnest(pg_blocking_pids(blockers.pid)) from blockers
                            ) select 1 from blockers where pid = ?::integer
                        ) end as expected_blocker
                    from pg_stat_activity as activity where pid = ?
                    SQL,
                [$expectedBlockerPid, $expectedBlockerPid, $pid],
            );
            if ($result !== null && (($expectedBlockerPid === null && (int) $result->blocker_count > 0)
                || ($expectedBlockerPid !== null && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Expected PostgreSQL Clinical safety blocking was not observed within %ds (elapsed %.2fs). %s', $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
    }

    /** @param list<array{process: Process, input: InputStream}> $workers */
    private function diagnostics(array $workers): string
    {
        return implode(' | ', array_map(fn ($worker) => $this->processDiagnostics($worker['process']), $workers));
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim($process->getOutput()).' stderr='.trim($process->getErrorOutput());
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function workerOutput(array $workers): string
    {
        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    private function tearDownOrganisation(): void
    {
        if ($this->organisationId === null) {
            return;
        }
        $id = $this->organisationId;
        DB::transaction(function () use ($id): void {
            DB::table('clinical_encounter_allergy_reviews')->where('organisation_id', $id)->delete();
            DB::table('patient_allergy_records')->where('organisation_id', $id)->delete();
            DB::table('patient_allergy_profile_versions')->where('organisation_id', $id)->delete();
            DB::table('patient_allergy_profiles')->where('organisation_id', $id)->delete();
        });
        DB::table('patient_problem_records')->where('organisation_id', $id)->delete();
        DB::table('audit_logs')->where('organisation_id', $id)->delete();
        DB::table('encounter_diagnoses')->where('organisation_id', $id)->delete();
        DB::table('encounter_vital_observations')->where('organisation_id', $id)->delete();
        DB::table('clinical_encounters')->where('organisation_id', $id)->delete();
        DB::table('queue_entries')->where('organisation_id', $id)->delete();
        DB::table('queue_number_counters')->where('organisation_id', $id)->delete();
        DB::table('visits')->where('organisation_id', $id)->delete();
        DB::table('visit_number_counters')->where('organisation_id', $id)->delete();
        DB::table('patient_identifiers')->where('organisation_id', $id)->delete();
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
        $this->organisationId = null;
    }

    private function deleteUser(User $user): void
    {
        DB::table('staff_branch_assignments')->where('staff_profile_id', $user->staffProfile->id)->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $user->id)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->delete();
        $user->staffProfile()->delete();
        $user->delete();
    }
}

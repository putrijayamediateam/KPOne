<?php

namespace Tests\Feature\Patient;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Family\FamilyVisitGroups;
use App\Domain\Patient\Family\PatientFamily;
use App\Domain\Patient\Family\PatientFamilyMember;
use App\Domain\Patient\Family\PatientFamilyService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientMergeService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;

/** FAM-1: manual family link, its retry and lock rules, and the shared visits.family_group_id. */
class PatientFamilyTest extends VisitTestCase
{
    /** @param array<string, mixed> $overrides */
    private function command(Patient $relative, array $overrides = []): array
    {
        return [
            'operation' => 'add',
            'relative_patient_number' => $relative->patient_number,
            'relative_role' => 'child',
            'patient_role' => 'parent',
            'expected_family_version' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'expected_branch_id' => $this->branch->id,
            ...$overrides,
        ];
    }

    /** The real two-person merge (REG-3), so the database merge guards hold. */
    private function merge(User $proposer, Patient $kept, Patient $other): void
    {
        $this->selectBranch($proposer);
        $service = app(PatientMergeService::class);
        $request = $service->propose($proposer, $kept->refresh(), ['kind' => 'merge', 'other_patient_number' => $other->patient_number, 'reason_code' => 'same_person_registered_twice', 'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version, 'other_lock_version' => $other->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $approver = $this->actor('ca_supervisor');
        $this->selectBranch($approver);
        $service->approve($approver, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
        $other->refresh();
    }

    private function service(): PatientFamilyService
    {
        return app(PatientFamilyService::class);
    }

    /** @param array<string, mixed> $command */
    private function assertRefused(User $actor, Patient $patient, array $command, string $field): void
    {
        try {
            $this->service()->execute($actor, $patient, $command);
            $this->fail("Expected a refusal on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_linking_creates_a_family_and_a_relative_can_join_it(): void
    {
        $ca = $this->actor();
        [$mother, $son, $grandmother] = [$this->patient(), $this->patient(), $this->patient()];
        $this->selectBranch($ca);

        $result = $this->service()->execute($ca, $mother, $this->command($son));
        $this->assertSame(1, $result['familyVersion']);
        $this->assertSame([$mother->patient_number, $son->patient_number], array_column($result['members'], 'patientNumber'));
        $this->assertSame(['Parent', 'Child'], array_column($result['members'], 'roleLabel'));
        $this->assertTrue($result['canManage']);

        // The grandmother joins from her own record: her role is asked, the son's existing role is kept.
        $joined = $this->service()->execute($ca, $grandmother, $this->command($son, ['relative_role' => null, 'patient_role' => 'grandparent']));
        $this->assertSame(2, $joined['familyVersion']);
        $this->assertCount(3, $joined['members']);
        $this->assertDatabaseCount('patient_families', 1);
        $this->assertSame(3, PatientFamilyMember::query()->whereNull('left_at')->count());
        $this->assertSame('child', PatientFamilyMember::query()->where('patient_id', $son->id)->value('role'));
        $this->assertSame(3, count($this->service()->directory($ca, $son)['members']));
    }

    public function test_refusals_for_self_other_family_missing_role_stale_version_and_merged_records(): void
    {
        $ca = $this->actor();
        [$a, $b, $c, $d] = [$this->patient(), $this->patient(), $this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $this->assertRefused($ca, $a, $this->command($a), 'relative_patient_number');
        $this->assertRefused($ca, $a, $this->command($b, ['patient_role' => null]), 'patient_role');
        $this->assertRefused($ca, $a, $this->command($b, ['relative_role' => null]), 'relative_role');
        $this->assertRefused($ca, $a, $this->command($b, ['expected_family_version' => 3]), 'family');

        $this->service()->execute($ca, $a, $this->command($b));
        $this->service()->execute($ca, $c, $this->command($d));
        $this->assertRefused($ca, $a, $this->command($c, ['expected_family_version' => 1]), 'relative_patient_number');
        $this->assertRefused($ca, $a, $this->command($b, ['expected_family_version' => 1]), 'relative_patient_number');
        // A screen that still shows "no family" for a patient who is now in one is stale.
        $this->assertRefused($ca, $a, $this->command($c, ['expected_family_version' => 0]), 'family');

        $merged = $this->patient();
        $kept = $this->patient();
        $this->merge($ca, $kept, $merged);
        $this->selectBranch($ca);
        $this->assertRefused($ca, $kept, $this->command($merged), 'family');
        $this->assertRefused($ca, $merged->refresh(), $this->command($kept), 'family');
        $this->assertDatabaseCount('patient_families', 2);
        $this->assertDatabaseCount('patient_family_commands', 2);
    }

    public function test_an_exact_retry_changes_nothing_and_a_changed_retry_is_refused(): void
    {
        $ca = $this->actor();
        [$a, $b, $c] = [$this->patient(), $this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $command = $this->command($b);
        $this->service()->execute($ca, $a, $command);
        $replayed = $this->service()->execute($ca, $a, $command);
        $this->assertSame(1, $replayed['familyVersion']);
        $this->assertDatabaseCount('patient_family_commands', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'patient.family.changed')->count());

        $this->assertRefused($ca, $a, [...$command, 'relative_patient_number' => $c->patient_number], 'family');
        $this->assertRefused($ca, $a, [...$command, 'relative_role' => 'other_relative'], 'family');
        $this->assertDatabaseCount('patient_family_commands', 1);
    }

    public function test_role_change_and_removal_keep_rows_and_a_family_of_one_closes(): void
    {
        $ca = $this->actor();
        [$a, $b, $c] = [$this->patient(), $this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $this->service()->execute($ca, $a, $this->command($b));
        $this->service()->execute($ca, $a, $this->command($c, ['expected_family_version' => 1, 'patient_role' => null]));

        $changed = $this->service()->execute($ca, $a, ['operation' => 'set_role', 'relative_patient_number' => $c->patient_number, 'relative_role' => 'other_relative', 'expected_family_version' => 2, 'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id]);
        $this->assertSame('Other relative', collect($changed['members'])->firstWhere('patientNumber', $c->patient_number)['roleLabel']);

        $removed = $this->service()->execute($ca, $a, ['operation' => 'remove', 'relative_patient_number' => $c->patient_number, 'expected_family_version' => 3, 'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id]);
        $this->assertSame(4, $removed['familyVersion']);
        $this->assertCount(2, $removed['members']);

        // Removing one of the last two closes the family for both; every row is kept as evidence.
        $closed = $this->service()->execute($ca, $b, ['operation' => 'remove', 'relative_patient_number' => $b->patient_number, 'expected_family_version' => 4, 'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id]);
        $this->assertSame([], $closed['members']);
        $this->assertSame(0, $closed['familyVersion']);
        $this->assertSame([], $this->service()->directory($ca, $a)['members']);
        $this->assertSame(3, PatientFamilyMember::query()->count());
        $this->assertSame(0, PatientFamilyMember::query()->whereNull('left_at')->count());
        $this->assertSame(5, PatientFamily::query()->value('lock_version'));

        // A closed family's members may start a new family.
        $this->service()->execute($ca, $a, $this->command($b));
        $this->assertDatabaseCount('patient_families', 2);
    }

    public function test_writing_needs_the_existing_update_permission_and_the_active_branch(): void
    {
        [$a, $b] = [$this->patient(), $this->patient()];
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);
        $this->assertFalse($this->service()->directory($doctor, $a)['canManage']);
        try {
            $this->service()->execute($doctor, $a, $this->command($b));
            $this->fail('A doctor without patients.update.organisation may not link.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('patient_families', 0);
        }
        $this->postJson(route('patients.family.store', $a), $this->command($b))->assertForbidden();

        $ca = $this->actor();
        $this->selectBranch($ca);
        $this->assertRefused($ca, $a, $this->command($b, ['expected_branch_id' => $this->branch->id + 999]), 'expected_branch_id');

        // A CA whose assignment does not cover the selected branch is refused under lock.
        $elsewhere = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $visitor = $this->actor('ca', $elsewhere);
        $this->selectBranch($visitor, $elsewhere);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $visitor->staffProfile->id)->update(['valid_until' => now()->subDays(1)->toDateString(), 'valid_from' => now()->subDays(5)->toDateString()]);
        try {
            $this->service()->execute($visitor->fresh(), $a, $this->command($b, ['expected_branch_id' => $elsewhere->id]));
            $this->fail('A CA without a current assignment at the branch may not link.');
        } catch (AuthorizationException|HttpException) {
            $this->assertDatabaseCount('patient_families', 0);
        }
    }

    public function test_http_routes_return_no_store_json_and_audit_is_values_free(): void
    {
        $ca = $this->actor();
        [$a, $b] = [$this->patient(), $this->patient(null, ['full_name' => 'Synthetic Family Child'])];
        $this->selectBranch($ca);
        $this->postJson(route('patients.family.store', $a), $this->command($b))
            ->assertOk()->assertJsonPath('familyVersion', 1)->assertJsonCount(2, 'members');
        $response = $this->getJson(route('patients.family.show', $b))->assertOk()->assertJsonPath('members.1.roleLabel', 'Child');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $log = AuditLog::query()->where('event', 'patient.family.changed')->sole();
        $encoded = json_encode($log->metadata, JSON_THROW_ON_ERROR);
        $this->assertSame('created', $log->metadata['action']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $log->metadata['patient_ids']);
        foreach ([$b->full_name, $b->patient_number, $a->patient_number, '+60123456789', 'Child', 'Parent'] as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_family_members_registered_at_one_branch_on_one_day_share_a_key(): void
    {
        $ca = $this->actor();
        [$mother, $son, $stranger] = [$this->patient(), $this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $this->service()->execute($ca, $mother, $this->command($son));

        $first = $this->register($ca, $mother);
        $second = $this->register($ca, $son);
        $alone = $this->register($ca, $stranger);
        $this->assertNotNull($first->family_group_id);
        $this->assertTrue(Str::isUuid($first->family_group_id));
        $this->assertSame($first->family_group_id, $second->family_group_id);
        $this->assertNull($alone->family_group_id);

        $family = PatientFamily::query()->sole();
        $groups = app(FamilyVisitGroups::class);
        $today = now($this->branch->timezone)->toDateString();
        $this->assertSame($groups->key($this->organisation->id, $family->id, $this->branch->id, $today), $first->family_group_id);
        $elsewhere = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $this->assertNotSame($first->family_group_id, $groups->key($this->organisation->id, $family->id, $elsewhere->id, $today));
        $tomorrow = now($this->branch->timezone)->addDay()->toDateString();
        $this->assertNotSame($first->family_group_id, $groups->key($this->organisation->id, $family->id, $this->branch->id, $tomorrow));
    }

    public function test_the_branch_local_day_decides_the_key_across_utc_midnight(): void
    {
        // 16:30 UTC is 00:30 the next day in Kuala Lumpur.
        $this->travelTo(Carbon::parse('2026-11-26 16:30:00', 'UTC'));
        $ca = $this->actor();
        [$a, $b] = [$this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $this->service()->execute($ca, $a, $this->command($b));
        $visit = $this->register($ca, $a);
        $family = PatientFamily::query()->sole();
        $this->assertSame(app(FamilyVisitGroups::class)->key($this->organisation->id, $family->id, $this->branch->id, '2026-11-27'), $visit->family_group_id);
        $this->travelBack();
    }

    public function test_linking_stamps_todays_open_visits_and_removal_never_rewrites_them(): void
    {
        $ca = $this->actor();
        [$a, $b] = [$this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $visitA = $this->register($ca, $a);
        $visitB = $this->register($ca, $b);
        $cancelled = $this->register($ca, $b, ['confirm_repeat' => true]);
        $cancelled->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $ca->id, 'cancellation_reason' => 'Synthetic cancelled visit'])->save();
        $this->assertNull($visitA->family_group_id);

        $this->service()->execute($ca, $a, $this->command($b));
        $key = $visitA->refresh()->family_group_id;
        $this->assertNotNull($key);
        $this->assertSame($key, $visitB->refresh()->family_group_id);
        $this->assertNull($cancelled->refresh()->family_group_id);
        $this->assertSame(2, AuditLog::query()->where('event', 'patient.family.changed')->sole()->metadata['visits_grouped']);

        $this->service()->execute($ca, $a, ['operation' => 'remove', 'relative_patient_number' => $b->patient_number, 'expected_family_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id]);
        $this->assertSame($key, $visitA->refresh()->family_group_id);
        $this->assertSame($key, $visitB->refresh()->family_group_id);
        $this->assertNull($this->register($ca, $a, ['confirm_repeat' => true])->family_group_id);
    }

    public function test_joining_stamps_only_visits_at_the_active_branch_with_a_new_version(): void
    {
        $ca = $this->actor();
        [$a, $b] = [$this->patient(), $this->patient()];
        $elsewhere = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $here = $this->register($ca, $a);
        $remote = $this->actor('ca', $elsewhere);
        $this->selectBranch($remote, $elsewhere);
        $there = app(VisitRegistrationService::class)->register($remote, [...$this->visitAttributes($a, ['confirm_repeat' => true]), 'expected_branch_id' => $elsewhere->id]);

        $this->selectBranch($ca);
        $this->service()->execute($ca, $a, $this->command($b));
        $here->refresh();
        $this->assertNotNull($here->family_group_id);
        $this->assertSame(2, $here->lock_version);
        $this->assertSame($ca->id, $here->updated_by_user_id);
        $this->assertNull($there->refresh()->family_group_id);
        $this->assertSame(1, $there->lock_version);
    }

    public function test_postgres_keeps_members_commands_and_one_active_family_per_patient(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL guards only.');
        }
        $ca = $this->actor();
        [$a, $b] = [$this->patient(), $this->patient()];
        $this->selectBranch($ca);
        $this->service()->execute($ca, $a, $this->command($b));
        $family = PatientFamily::query()->sole();

        foreach ([
            fn () => DB::table('patient_family_members')->where('patient_id', $a->id)->delete(),
            fn () => DB::table('patient_family_commands')->update(['action' => 'remove']),
            fn () => DB::table('patient_families')->delete(),
            fn () => DB::table('patient_family_members')->where('patient_id', $a->id)->update(['patient_id' => $b->id]),
            fn () => DB::table('patient_family_members')->insert(['organisation_id' => $this->organisation->id, 'patient_family_id' => $family->id, 'patient_id' => $a->id, 'role' => 'child', 'joined_at' => now(), 'added_by_user_id' => $ca->id, 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('patient_family_members')->where('patient_id', $a->id)->update(['role' => 'uncle']),
        ] as $attempt) {
            DB::beginTransaction();
            try {
                $attempt();
                $this->fail('The database guard should refuse this change.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }
    }
}

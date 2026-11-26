<?php

namespace Tests\Feature\Visit;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientFamilyLink;
use App\Domain\Patient\Services\PatientFamilyLinkService;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Database\Factories\PatientFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PatientFamilyLinkTest extends VisitTestCase
{
    private function command(Patient $other, array $overrides = []): array
    {
        return ['operation' => 'link', 'other_patient_number' => $other->patient_number, 'relationship_code' => 'parent_of', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id, ...$overrides];
    }

    private function changeMerge(User $actor, Patient $kept, Patient $other, string $kind): void
    {
        $this->selectBranch($actor);
        $service = app(PatientMergeService::class);
        $request = $service->propose($actor, $kept->refresh(), ['kind' => $kind, 'other_patient_number' => $other->patient_number, 'reason_code' => $kind === 'merge' ? 'same_person_registered_twice' : 'merged_in_error', 'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version, 'other_lock_version' => $other->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $approver = $this->actor('ca_supervisor');
        $this->selectBranch($approver);
        $service->approve($approver, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
        $other->refresh();
        $this->selectBranch($actor);
    }

    public function test_reciprocal_pair_correction_unlink_and_reactivation_keep_one_row(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientFamilyLinkService::class);
        $first = $this->command($b);
        $saved = $service->execute($actor, $a, $first);
        $this->assertSame('Parent of', $saved['links'][0]['relationshipLabel']);
        $this->assertSame('Child of', $service->directory($actor, $b)['links'][0]['relationshipLabel']);
        $service->execute($actor, $b, $this->command($a, ['operation' => 'correct', 'relationship_code' => 'sibling', 'lock_version' => 1]));
        $replayed = $service->execute($actor, $a, $first);
        $this->assertSame('Sibling of', $replayed['links'][0]['relationshipLabel']);
        $this->assertSame(2, $replayed['links'][0]['lockVersion']);
        $service->execute($actor, $a, $this->command($b, ['operation' => 'unlink', 'relationship_code' => 'sibling', 'lock_version' => 2]));
        $this->assertFalse($service->directory($actor, $a)['links'][0]['active']);
        $service->execute($actor, $a, $this->command($b, ['lock_version' => 3]));
        $this->assertDatabaseCount('patient_family_links', 1);
        $this->assertDatabaseCount('patient_family_link_commands', 4);
        $this->assertSame(4, PatientFamilyLink::first()->lock_version);
    }

    public function test_reversed_endpoint_retry_is_same_canonical_command(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        $command = $this->command($b);
        $service = app(PatientFamilyLinkService::class);
        $service->execute($actor, $a, $command);
        $service->execute($actor, $b, [...$command, 'other_patient_number' => $a->patient_number, 'relationship_code' => 'child_of']);
        $this->assertDatabaseCount('patient_family_link_commands', 1);
        $this->assertSame(1, PatientFamilyLink::first()->lock_version);
    }

    public function test_self_stale_duplicate_reciprocal_and_changed_retry_refused(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $c = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientFamilyLinkService::class);
        $first = $this->command($b);
        $service->execute($actor, $a, $first);
        foreach ([$this->command($a), $this->command($b), [...$first, 'other_patient_number' => $c->patient_number], [...$first, 'relationship_code' => 'spouse'], [...$first, 'operation' => 'unlink'], $this->command($b, ['operation' => 'correct'])] as $command) {
            try {
                $service->execute($actor, $a, $command);
                $this->fail('Expected conflict');
            } catch (ValidationException) {
                $this->assertDatabaseCount('patient_family_link_commands', 1);
            }
        }
        try {
            $service->execute($actor, $b, $this->command($a, ['relationship_code' => 'child_of', 'lock_version' => 1]));
            $this->fail('Expected duplicate refusal');
        } catch (ValidationException) {
            $this->assertDatabaseCount('patient_family_links', 1);
        }
    }

    public function test_merge_suppression_no_transfer_unmerge_and_original_unlink(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $kept = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientFamilyLinkService::class);
        $first = $this->command($b);
        $service->execute($actor, $a, $first);
        $this->changeMerge($actor, $kept, $b, 'merge');
        $row = $service->directory($actor, $a)['links'][0];
        $this->assertTrue($row['suppressed']);
        $this->assertNull($row['name']);
        $this->assertNull($row['patientNumber']);
        $this->assertSame([], $service->directory($actor, $kept)['links']);
        try {
            $service->execute($actor, $a, $first);
            $this->fail('Merged endpoint retry must refuse');
        } catch (ValidationException) {
            $this->assertDatabaseCount('patient_family_link_commands', 1);
        }
        $this->changeMerge($actor, $kept, $b, 'unmerge');
        $this->assertFalse($service->directory($actor, $a)['links'][0]['suppressed']);
        $this->changeMerge($actor, $kept, $b, 'merge');
        $unlink = $this->command($b, ['operation' => 'unlink', 'link_id' => $row['id'], 'lock_version' => 1]);
        $service->execute($actor, $a, $unlink);
        $service->execute($actor, $a, $unlink);
        $this->changeMerge($actor, $kept, $b, 'unmerge');
        $this->assertFalse($service->directory($actor, $a)['links'][0]['active']);
        $this->assertDatabaseCount('patient_family_link_commands', 2);
    }

    public function test_read_and_write_check_both_endpoint_policies(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientFamilyLinkService::class);
        $service->execute($actor, $a, $this->command($b));
        Gate::before(fn (User $user, string $ability, array $arguments) => ($arguments[0] ?? null) instanceof Patient && $arguments[0]->id === $b->id && $ability === 'view' ? false : null);
        $this->assertSame([], $service->directory($actor, $a)['links']);
        $this->expectException(AuthorizationException::class);
        $service->execute($actor, $a, $this->command($b, ['operation' => 'correct', 'lock_version' => 1]));
    }

    public function test_direct_update_permission_without_view_is_not_enough(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        $actor->syncRoles([]);
        $actor->givePermissionTo('patients.update.organisation');
        $this->expectException(AuthorizationException::class);
        app(PatientFamilyLinkService::class)->execute($actor, $a, $this->command($b));
    }

    public function test_audit_is_structural_and_failure_rolls_back_pair_and_command(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        app(PatientFamilyLinkService::class)->execute($actor, $a, $this->command($b));
        $audit = DB::table('audit_logs')->where('event', 'patient.family_link.changed')->first();
        $encoded = json_encode($audit);
        $this->assertStringNotContainsString($a->full_name, $encoded);
        $this->assertStringNotContainsString($b->patient_number, $encoded);
        $this->assertStringContainsString('patient_low_id', $encoded);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andReturnNull();
        try {
            app(PatientFamilyLinkService::class)->execute($actor, $a, $this->command($b, ['operation' => 'correct', 'lock_version' => 1, 'relationship_code' => 'sibling']));
            $this->fail('Expected rollback');
        } catch (HttpException) {
            $this->assertSame(1, PatientFamilyLink::first()->lock_version);
            $this->assertDatabaseCount('patient_family_link_commands', 1);
        }
    }

    public function test_http_payload_uses_private_response_and_no_phone_inference(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        $this->getJson(route('patients.family-links.show', $a))->assertOk()->assertJsonPath('links', []);
        $this->postJson(route('patients.family-links.store', $a), $this->command($b))->assertOk()->assertJsonPath('links.0.patientNumber', $b->patient_number);
        $response = $this->getJson(route('patients.family-links.show', $a))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('mobile_phone', $response->json('links.0'));
    }

    public function test_cross_organisation_and_unrelated_link_id_are_not_resolved(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $c = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientFamilyLinkService::class);
        $saved = $service->execute($actor, $b, $this->command($c));
        $this->postJson(route('patients.family-links.store', $a), $this->command($b, ['link_id' => $saved['links'][0]['id'], 'operation' => 'unlink', 'lock_version' => 1]))->assertNotFound();
        $foreignOrg = new Organisation;
        $foreignOrg->forceFill(['name' => 'Synthetic Other Clinic', 'code' => 'SYN_FAMILY_OTHER', 'is_active' => true])->save();
        $foreign = PatientFactory::new()->create(['organisation_id' => $foreignOrg->id, 'full_name' => 'Synthetic Other Patient']);
        $this->postJson(route('patients.family-links.store', $a), $this->command($foreign))->assertNotFound();
        $this->getJson(route('patients.family-links.show', $foreign))->assertNotFound();
        $this->assertDatabaseCount('patient_family_link_commands', 1);
    }

    public function test_inactive_actor_and_branch_change_refuse_replays(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientFamilyLinkService::class);
        $command = $this->command($b);
        $service->execute($actor, $a, $command);
        try {
            $service->execute($actor, $a, [...$command, 'expected_branch_id' => $this->branch->id + 9999]);
            $this->fail('Expected branch refusal');
        } catch (ValidationException|HttpException|AuthorizationException) {
            $this->assertDatabaseCount('patient_family_link_commands', 1);
        }
        $actor->forceFill(['is_active' => false])->save();
        try {
            $service->execute($actor, $a, $command);
            $this->fail('Expected inactive refusal');
        } catch (ValidationException|HttpException|AuthorizationException) {
            $this->assertDatabaseCount('patient_family_link_commands', 1);
        }
    }

    public function test_database_retains_immutable_commands_and_rejects_self_pair(): void
    {
        $actor = $this->actor();
        $a = $this->patient();
        $b = $this->patient();
        $this->selectBranch($actor);
        app(PatientFamilyLinkService::class)->execute($actor, $a, $this->command($b));
        foreach (['update', 'delete', 'self_pair'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $a): void {
                    if ($operation === 'update') {
                        DB::table('patient_family_link_commands')->update(['result_version' => 9]);
                    } elseif ($operation === 'delete') {
                        DB::table('patient_family_link_commands')->delete();
                    } else {
                        DB::table('patient_family_links')->update(['patient_high_id' => $a->id]);
                    }
                });
                $this->fail('Expected database refusal');
            } catch (QueryException) {
                $this->assertDatabaseCount('patient_family_link_commands', 1);
            }
        }
        $migration = require database_path('migrations/2026_11_26_020000_create_patient_family_links.php');
        $migration->down();
        $this->assertDatabaseCount('patient_family_links', 1);
        $this->assertDatabaseCount('patient_family_link_commands', 1);
    }
}

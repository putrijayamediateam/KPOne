<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class BusinessDetailsTest extends VisitTestCase
{
    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return [...[
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Clinic Group Ltd',
            'clinic_name' => 'Synthetic Central Clinic', 'address_line_1' => '1 Example Street', 'address_line_2' => '',
            'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State', 'country' => 'Malaysia',
            'phone' => '', 'email' => 'clinic@business-details.test', 'registration_type' => 'BRN',
            'registration_number' => 'SYN-BRN-001', 'remove_logo' => false,
        ], ...$overrides];
    }

    public function test_only_the_agreed_roles_view_and_only_director_and_administrator_edit(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['director', 'administrator', 'finance_officer', 'panel_officer'], true);
            $edit = in_array($role, ['director', 'administrator'], true);
            $response = $this->get('/business-details');
            if ($allowed) {
                $response->assertOk()->assertInertia(fn (Assert $p) => $p->component('BusinessDetails/Index')->where('canEdit', $edit)
                    ->where('letterhead.branchId', $this->branch->id)->where('letterhead.configured', false)
                    ->missing('patients')->missing('visits')->missing('records')->missing('letterhead.path'));
            } else {
                $response->assertForbidden();
            }
            $this->assertSame($edit, $actor->can('business_details.manage.organisation'));
            if (! $edit) {
                $this->post('/business-details', $this->payload())->assertForbidden();
            }
        }
        $this->assertDatabaseCount('organisation_business_profiles', 0);
        $this->assertDatabaseCount('branch_business_profiles', 0);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority('business_details.manage.organisation'));
    }

    public function test_a_save_is_atomic_normalized_and_audited_without_values_and_reads_do_not_write(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $publicAddress = $this->branch->getAttribute('public_address');
        $this->get('/business-details')->assertOk();
        $this->assertDatabaseCount('business_detail_commands', 0);
        $this->post('/business-details', $this->payload(['legal_name' => '  Synthetic   Clinic Group Ltd  ', 'phone' => '+1 202 555 0123']))
            ->assertRedirect(route('business-details.index'));
        $org = OrganisationBusinessProfile::query()->sole();
        $details = BranchBusinessProfile::query()->sole();
        $this->assertSame('Synthetic Clinic Group Ltd', $org->legal_name);
        $this->assertSame('+12025550123', $details->phone);
        $this->assertSame(1, $org->lock_version);
        $this->assertSame(1, $details->lock_version);
        $this->assertSame($publicAddress, $this->branch->fresh()->getAttribute('public_address'));
        $audit = DB::table('audit_logs')->where('event', 'business_details.saved')->sole();
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('organisation.legal_name', $metadata['changed_fields']);
        $this->assertContains('branch.phone', $metadata['changed_fields']);
        $this->assertEqualsCanonicalizing(['changed_fields', 'organisation_version', 'branch_version'], array_keys($metadata));
        foreach (['Synthetic Clinic', 'Example Street', 'clinic@', 'SYN-BRN', '12025550123'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $audit->metadata);
        }
        $letterhead = app(BranchLetterheadService::class)->forActiveBranch($actor);
        $this->assertSame($details->clinic_name, $letterhead->details['clinic_name']);
        $this->assertSame($org->legal_name, $letterhead->organisation['legal_name']);
        $this->get('/business-details')->assertInertia(fn (Assert $p) => $p->where('letterhead.configured', true));
        $this->assertDatabaseCount('business_detail_commands', 1);
    }

    public function test_identical_retries_replay_without_duplicate_assets_or_audit_and_conflicts_are_refused(): void
    {
        Storage::fake('local');
        $this->selectBranch($this->actor('director'));
        $payload = $this->payload(['logo' => UploadedFile::fake()->image('confidential-original-name.png', 160, 90)]);
        $this->post('/business-details', $payload)->assertRedirect();
        $this->post('/business-details', $payload)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('business_detail_assets', 1);
        $this->assertDatabaseCount('business_detail_commands', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'business_details.saved')->count());
        $this->post('/business-details', [...$payload, 'email' => 'changed@business-details.test'])->assertSessionHasErrors('idempotency_key');
        $this->assertSame('clinic@business-details.test', BranchBusinessProfile::query()->sole()->email);
        $this->post('/business-details', $this->payload(['organisation_lock_version' => 1, 'branch_lock_version' => 1, 'email' => 'new@business-details.test']))->assertSessionDoesntHaveErrors();
        $this->post('/business-details', $payload)->assertSessionDoesntHaveErrors();
        $this->assertSame('new@business-details.test', BranchBusinessProfile::query()->sole()->email);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'business_details.saved')->count());
        $this->assertDatabaseCount('business_detail_assets', 1);
    }

    public function test_stale_versions_and_changed_branch_refuse_the_entire_update_before_storage(): void
    {
        Storage::fake('local');
        $this->selectBranch($this->actor('director'));
        $this->post('/business-details', $this->payload())->assertSessionDoesntHaveErrors();
        $this->post('/business-details', $this->payload(['legal_name' => 'Synthetic Stale Legal Name', 'clinic_name' => 'Synthetic Stale Branch', 'logo' => UploadedFile::fake()->image('stale.png')]))
            ->assertRedirect(route('business-details.index'))->assertSessionHasErrors('version');
        $this->assertSame('Synthetic Clinic Group Ltd', OrganisationBusinessProfile::query()->sole()->legal_name);
        $this->assertSame('Synthetic Central Clinic', BranchBusinessProfile::query()->sole()->clinic_name);
        $this->assertDatabaseCount('business_detail_commands', 1);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->post('/business-details', $this->payload(['expected_branch_id' => $this->branch->id + 10000]))->assertSessionHasErrors('expected_branch_id');
        $this->post('/business-details', $this->payload(['organisation_id' => $this->organisation->id + 10000]))->assertSessionHasErrors('organisation_id');
    }

    public function test_logo_delivery_is_private_scoped_and_replacement_or_removal_retains_old_assets(): void
    {
        Storage::fake('local');
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post('/business-details', $this->payload(['logo' => UploadedFile::fake()->image('private-file.png', 80, 80)]))->assertSessionDoesntHaveErrors();
        $first = BusinessDetailAsset::query()->sole();
        $url = route('business-details.logo', ['branch' => $this->branch->id, 'asset' => $first->public_id]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->get($url)->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('private-file', $first->path);
        $this->get(route('business-details.logo', ['branch' => $this->branch->id + 10000, 'asset' => $first->public_id]))->assertNotFound();
        foreach (['finance_officer', 'panel_officer'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get($url)->assertOk();
        }
        foreach (['ca', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get($url)->assertForbidden();
        }
        $this->selectBranch($director);
        $this->post('/business-details', $this->payload(['organisation_lock_version' => 1, 'branch_lock_version' => 1, 'logo' => UploadedFile::fake()->image('new.jpg', 160, 90)]))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('business_detail_assets', 2);
        $this->assertNotNull($first->fresh()->getAttribute('retired_at'));
        Storage::disk('local')->assertExists($first->path);
        $this->get($url)->assertNotFound();
        $current = BusinessDetailAsset::query()->whereNull('retired_at')->sole();
        $newUrl = route('business-details.logo', ['branch' => $this->branch->id, 'asset' => $current->public_id]);
        $this->get($newUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->post('/business-details', $this->payload(['organisation_lock_version' => 2, 'branch_lock_version' => 1, 'remove_logo' => true]))->assertSessionDoesntHaveErrors();
        $this->assertNull(OrganisationBusinessProfile::query()->sole()->logo_asset_id);
        $this->get($newUrl)->assertNotFound();
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $metadata = DB::table('audit_logs')->where('event', 'business_details.saved')->pluck('metadata')->implode('');
        foreach (['private-file', 'new.jpg', $first->path, $first->sha256] as $private) {
            $this->assertStringNotContainsString($private, $metadata);
        }
    }

    public function test_logo_input_is_content_sniffed_bounded_and_decoded_before_any_write(): void
    {
        Storage::fake('local');
        $this->selectBranch($this->actor('administrator'));
        $valid = UploadedFile::fake()->image('valid.png', 100, 100);
        foreach ([
            UploadedFile::fake()->createWithContent('disguised.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->create('oversized.png', 2049, 'image/png'),
            UploadedFile::fake()->image('too-wide.png', 4097, 1),
            UploadedFile::fake()->createWithContent('truncated.png', substr($valid->getContent(), 0, 40)),
        ] as $file) {
            $this->post('/business-details', $this->payload(['logo' => $file]))->assertSessionHasErrors('logo');
            $this->assertDatabaseCount('business_detail_assets', 0);
            $this->assertDatabaseCount('organisation_business_profiles', 0);
            $this->assertDatabaseCount('branch_business_profiles', 0);
            $this->assertCount(0, Storage::disk('local')->allFiles());
        }
        $this->post('/business-details', $this->payload(['email' => 'invalid', 'phone' => 'not a phone', 'legal_name' => '   ']))
            ->assertSessionHasErrors(['email', 'legal_name']);
        $this->post('/business-details', $this->payload(['phone' => 'not a phone']))->assertSessionHasErrors('phone');
        $this->post('/business-details', $this->payload(['logo' => $valid, 'remove_logo' => true]))->assertSessionHasErrors('logo');
    }

    public function test_a_failed_transaction_cleans_only_the_new_candidate_file_and_rolls_back_every_record(): void
    {
        Storage::fake('local');
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $mock = Mockery::mock(AuditRecorder::class);
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditRecorder::class, $mock);
        try {
            app(BusinessDetailsService::class)->save($actor, $this->payload(), UploadedFile::fake()->image('rollback.png'));
            $this->fail('The synthetic audit failure must abort the save.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        foreach (['organisation_business_profiles', 'branch_business_profiles', 'business_detail_assets', 'business_detail_commands'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_branch_details_are_separate_while_legal_identity_and_the_logo_are_shared(): void
    {
        Storage::fake('local');
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $this->post('/business-details', $this->payload(['logo' => UploadedFile::fake()->image('shared.png')]))->assertSessionDoesntHaveErrors();
        $second = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($actor->staffProfile, $second);
        $this->selectBranch($actor, $second);
        $this->get('/business-details')->assertInertia(fn (Assert $p) => $p->where('letterhead.organisationVersion', 1)->where('letterhead.branchVersion', 0));
        $this->post('/business-details', $this->payload(['expected_branch_id' => $second->id, 'organisation_lock_version' => 1, 'clinic_name' => 'Synthetic Second Clinic']))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('organisation_business_profiles', 1);
        $this->assertDatabaseCount('branch_business_profiles', 2);
        $this->assertSame(1, OrganisationBusinessProfile::query()->sole()->lock_version);
        $this->assertSame('Synthetic Central Clinic', BranchBusinessProfile::query()->where('branch_id', $this->branch->id)->sole()->clinic_name);
        $letterhead = app(BranchLetterheadService::class)->forActiveBranch($actor);
        $this->assertSame('Synthetic Second Clinic', $letterhead->details['clinic_name']);
        $this->assertSame('Synthetic Clinic Group Ltd', $letterhead->organisation['legal_name']);
        $this->assertStringContainsString('/branches/'.$second->id.'/logo/', $letterhead->logo['url']);
    }

    public function test_foreign_organisation_assets_and_client_supplied_owner_ids_are_never_resolved(): void
    {
        Storage::fake('local');
        $this->selectBranch($this->actor('director'));
        $org = new Organisation;
        $org->forceFill(['code' => 'SYN-BUSINESS-FOREIGN', 'name' => 'Synthetic Foreign Organisation', 'is_active' => true])->save();
        $asset = new BusinessDetailAsset;
        $asset->forceFill(['organisation_id' => $org->id, 'public_id' => (string) Str::uuid(), 'path' => 'foreign-private.png', 'mime' => 'image/png', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'width' => 1, 'height' => 1])->save();
        $this->post('/business-details', $this->payload())->assertSessionDoesntHaveErrors();
        $this->get(route('business-details.logo', ['branch' => $this->branch->id, 'asset' => $asset->public_id]))->assertNotFound();
        $this->post('/business-details', $this->payload(['organisation_id' => $org->id]))->assertSessionHasErrors('organisation_id');
        $this->assertDatabaseCount('organisation_business_profiles', 1);
    }

    public function test_retained_profiles_and_assets_have_no_delete_path(): void
    {
        Storage::fake('local');
        $this->selectBranch($this->actor('director'));
        $this->post('/business-details', $this->payload(['logo' => UploadedFile::fake()->image('retained.png')]))->assertSessionDoesntHaveErrors();
        foreach ([OrganisationBusinessProfile::query()->sole(), BranchBusinessProfile::query()->sole(), BusinessDetailAsset::query()->sole()] as $model) {
            try {
                $model->delete();
                $this->fail('Retained business data must not be deleted.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }
        }
        $this->delete('/business-details')->assertStatus(405);
    }

    public function test_permission_migration_is_additive_idempotent_and_non_administrative(): void
    {
        $director = Role::findByName('director');
        $before = $director->permissions->pluck('name')->all();
        $migration = require database_path('migrations/2026_10_15_000400_grant_business_details_permission_additively.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertEqualsCanonicalizing($before, $director->fresh()->permissions->pluck('name')->all());
        foreach (['director', 'administrator'] as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('business_details.manage.organisation'));
        }
        foreach (['finance_officer', 'panel_officer', 'technical_admin', 'ca'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('business_details.manage.organisation'));
        }
    }

    public function test_transaction_retry_reuses_the_candidate_file_and_commits_one_asset_and_one_audit(): void
    {
        Storage::fake('local');
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        RefreshDatabaseState::$migrated = false;
        $real = app(AuditRecorder::class);
        $attempts = 0;
        $mock = Mockery::mock(AuditRecorder::class);
        $mock->shouldReceive('record')->twice()->andReturnUsing(function (...$arguments) use (&$attempts, $real) {
            if (++$attempts === 1) {
                throw new \PDOException('deadlock detected', 40001);
            }

            return $real->record(...$arguments);
        });
        $this->app->instance(AuditRecorder::class, $mock);
        $this->post('/business-details', $this->payload(['logo' => UploadedFile::fake()->image('retry.png')]))->assertSessionDoesntHaveErrors();
        $this->assertSame(2, $attempts);
        $this->assertDatabaseCount('business_detail_assets', 1);
        $this->assertDatabaseCount('business_detail_commands', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Storage::disk('local')->assertExists(BusinessDetailAsset::query()->sole()->path);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'business_details.saved')->count());
    }

    public function test_a_revoked_edit_grant_is_rechecked_inside_the_transaction(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->assertTrue($actor->can('business_details.manage.organisation'));
        $permission = Permission::findByName('business_details.manage.organisation');
        $role = Role::findByName('director');
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
        $this->post('/business-details', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('organisation_business_profiles', 0);
        $this->assertDatabaseCount('business_detail_commands', 0);
    }

    public function test_a_revoked_view_grant_is_rechecked_before_returning_letterhead_data(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $this->assertTrue($actor->can('catalogue.settings.manage.organisation'));
        $permission = Permission::findByName('catalogue.settings.manage.organisation');
        $role = Role::findByName('finance_officer');
        DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();
        $this->get('/business-details')->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use LogicException;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    private Branch $puchong;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $this->puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
    }

    public function test_active_user_with_existing_session_continues_normally(): void
    {
        $user = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_deactivated_user_existing_session_cannot_continue(): void
    {
        $administrator = $this->createStaff('director');
        $user = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $csrfToken = session()->token();

        app(StaffAdministrationService::class)->setActive($user, false, $administrator);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'auth.inactive_session.rejected',
        ]);
    }

    public function test_deactivated_session_cannot_access_fortify_authenticated_endpoint(): void
    {
        $administrator = $this->createStaff('director');
        $user = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($user)->get(route('password.confirm'))->assertOk();
        app(StaffAdministrationService::class)->setActive($user, false, $administrator);

        $this->get(route('password.confirm'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_session_cannot_access_settings_endpoint(): void
    {
        $administrator = $this->createStaff('director');
        $user = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        app(StaffAdministrationService::class)->setActive($user, false, $administrator);

        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_routes_still_function(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
        $this->get(route('password.request'))->assertOk();
        $this->assertGuest();
    }

    public function test_audit_metadata_recursively_redacts_sensitive_key_variants(): void
    {
        $log = app(AuditRecorder::class)->record('security.redaction.test', null, [
            'route' => 'dashboard',
            'branch_code' => 'CHERAS',
            'nested' => [
                'Password' => 'plain-text',
                'currentPassword' => 'current-value',
                'new_password_hash' => 'new-value',
                'user_passwd' => 'pass-value',
                'accessTokenExpiresAt' => 'token-value',
                'REFRESH_TOKEN' => 'refresh-value',
                'id_token' => 'identity-value',
                'apiToken' => 'api-value',
                'x-api-key' => 'key-value',
                'clientSecretValue' => 'secret-value',
                'AuthorizationHeader' => 'Bearer raw-value',
                'cookie_header' => 'session-cookie',
                'session_id' => 'session-value',
                'normal_count' => 7,
            ],
            'request_headers' => ['X-Normal' => 'must-not-survive'],
            'requestBody' => ['safe_looking' => 'must-not-survive'],
        ]);

        $metadata = $log?->fresh()->metadata;

        $this->assertSame('dashboard', $metadata['route']);
        $this->assertSame('CHERAS', $metadata['branch_code']);
        $this->assertSame(7, $metadata['nested']['normal_count']);
        $this->assertSame(AuditRecorder::REDACTED, $metadata['request_headers']);
        $this->assertSame(AuditRecorder::REDACTED, $metadata['requestBody']);

        foreach (array_diff(array_keys($metadata['nested']), ['normal_count']) as $key) {
            $this->assertSame(AuditRecorder::REDACTED, $metadata['nested'][$key], $key);
        }

        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        foreach (['plain-text', 'current-value', 'new-value', 'Bearer raw-value', 'session-cookie', 'must-not-survive'] as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_branch_assignment_creation_is_audited(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff();

        $assignment = $this->createAssignment($subject, $this->cheras, [], $actor);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.branch_assignment.created',
            'subject_id' => $assignment->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function test_branch_assignment_update_is_audited_with_before_and_after_values(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff();
        $assignment = $this->createAssignment($subject, $this->cheras);

        app(BranchAssignmentService::class)->update($assignment, [
            'assignment_type' => 'temporary',
            'valid_until' => now()->addWeek()->toDateString(),
        ], $actor);

        $log = AuditLog::query()->where('event', 'staff.branch_assignment.updated')->latest('id')->firstOrFail();
        $this->assertSame('permanent', $log->metadata['before']['assignment_type']);
        $this->assertSame('temporary', $log->metadata['after']['assignment_type']);
        $this->assertSame($actor->id, $log->actor_user_id);
    }

    public function test_branch_assignment_end_is_audited(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff();
        $assignment = $this->createAssignment($subject, $this->cheras, [
            'valid_from' => now()->subWeek()->toDateString(),
        ]);

        app(BranchAssignmentService::class)->end($assignment, $actor, now()->subDay()->toDateString());

        $log = AuditLog::query()->where('event', 'staff.branch_assignment.ended')->latest('id')->firstOrFail();
        $this->assertNull($log->metadata['before']['valid_until']);
        $this->assertSame(now()->subDay()->toDateString(), $log->metadata['after']['valid_until']);
    }

    public function test_primary_change_audits_demotion_and_promotion(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff();
        $previous = $this->createAssignment($subject, $this->cheras, ['is_primary' => true]);
        $next = $this->createAssignment($subject, $this->puchong);

        app(BranchAssignmentService::class)->changePrimary($subject->staffProfile, $next, $actor);

        $this->assertFalse($previous->fresh()->is_primary);
        $this->assertTrue($next->fresh()->is_primary);
        $this->assertSame(1, $subject->staffProfile->branchAssignments()->where('is_primary', true)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.branch_assignment.primary.demoted',
            'subject_id' => $previous->id,
            'actor_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.branch_assignment.primary.promoted',
            'subject_id' => $next->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function test_repeated_primary_requests_leave_one_primary_assignment(): void
    {
        $subject = $this->createStaff();
        $first = $this->createAssignment($subject, $this->cheras, ['is_primary' => true]);
        $second = $this->createAssignment($subject, $this->puchong, ['is_primary' => true]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, $subject->staffProfile->branchAssignments()->where('is_primary', true)->count());
    }

    public function test_expired_branch_assignment_does_not_grant_access(): void
    {
        $user = $this->createStaff('ca');
        $this->createAssignment($user, $this->cheras, [
            'valid_from' => now()->subWeek()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user)->get(route('branches.show', $this->cheras))->assertForbidden();
    }

    public function test_future_branch_assignment_does_not_grant_access(): void
    {
        $user = $this->createStaff('ca');
        $this->createAssignment($user, $this->cheras, [
            'valid_from' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($user)->get(route('branches.show', $this->cheras))->assertForbidden();
    }

    public function test_permission_detach_is_audited(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff();
        $subject->givePermissionTo('staff.view.branch');
        $this->actingAs($actor);

        $subject->revokePermissionTo('staff.view.branch');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'access.permission.detached',
            'subject_id' => $subject->id,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function test_audit_log_normal_model_update_fails(): void
    {
        $log = app(AuditRecorder::class)->record('audit.immutable.update');

        try {
            $log?->update(['event' => 'audit.changed']);
            $this->fail('AuditLog update unexpectedly succeeded.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit logs are append-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log?->id, 'event' => 'audit.immutable.update']);
    }

    public function test_audit_log_normal_model_delete_fails(): void
    {
        $log = app(AuditRecorder::class)->record('audit.immutable.delete');

        try {
            $log?->delete();
            $this->fail('AuditLog delete unexpectedly succeeded.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit logs are append-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log?->id, 'event' => 'audit.immutable.delete']);
    }

    public function test_no_application_route_edits_or_deletes_audit_logs(): void
    {
        $auditRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'audit-logs.'));

        $this->assertNotEmpty($auditRoutes);
        foreach ($auditRoutes as $route) {
            $this->assertEmpty(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']));
        }
    }

    public function test_known_user_with_verified_matching_google_email_is_linked(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'verified.google@kpone.test');
        $this->fakeGoogle('stable-google-subject', 'VERIFIED.GOOGLE@KPONE.TEST', true);

        $this->get(route('google.callback'))->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('stable-google-subject', $user->fresh()->google_subject);
    }

    public function test_unknown_verified_google_user_is_rejected_without_registration(): void
    {
        $this->fakeGoogle('unknown-subject', 'unknown.google@kpone.test', true);
        $before = User::query()->count();

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'unknown.google@kpone.test']);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'unverified.google@kpone.test');
        $this->fakeGoogle('unverified-subject', $user->email, false);

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_subject);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.google.denied']);
    }

    public function test_mismatched_google_email_is_rejected(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'expected.google@kpone.test');
        $this->fakeGoogle('mismatch-subject', 'different.google@kpone.test', true);

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_subject);
    }

    public function test_ambiguous_normalised_google_email_is_rejected(): void
    {
        $first = $this->createStaff('ca', [$this->cheras], 'ambiguous.google@kpone.test');
        $second = $this->createStaff('ca', [$this->cheras], 'AMBIGUOUS.GOOGLE@KPONE.TEST');
        $this->fakeGoogle('ambiguous-subject', 'Ambiguous.Google@kpone.test', true);

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($first->fresh()->google_subject);
        $this->assertNull($second->fresh()->google_subject);
    }

    public function test_malformed_google_identity_is_rejected(): void
    {
        $this->fakeGoogle('', 'not-an-email', true);

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.google.denied']);
    }

    public function test_existing_google_subject_is_the_stable_login_identity(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'linked.google@kpone.test');
        $user->forceFill(['google_subject' => 'existing-stable-subject'])->save();
        $this->fakeGoogle('existing-stable-subject', 'changed.provider.email@kpone.test', true);

        $this->get(route('google.callback'))->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('existing-stable-subject', $user->fresh()->google_subject);
    }

    public function test_conflicting_google_subject_is_rejected(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'conflict.google@kpone.test');
        $user->forceFill(['google_subject' => 'original-subject'])->save();
        $this->fakeGoogle('different-subject', $user->email, true);

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('original-subject', $user->fresh()->google_subject);
    }

    public function test_profile_update_cannot_mutate_protected_identity_or_access_attributes(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'protected.profile@kpone.test');
        $originalOrganisationId = $user->organisation_id;
        $originalAssignment = $user->staffProfile->branchAssignments()->firstOrFail();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Updated Dummy Name',
            'email' => $user->email,
            'organisation_id' => $originalOrganisationId + 1000,
            'google_subject' => 'attacker-subject',
            'is_active' => false,
            'last_login_at' => now()->subYear()->toDateTimeString(),
            'deactivated_at' => now()->toDateTimeString(),
            'staff_profile_id' => $user->staffProfile->id + 1000,
            'branch_id' => $this->puchong->id,
            'is_primary' => false,
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Updated Dummy Name', $user->name);
        $this->assertSame($originalOrganisationId, $user->organisation_id);
        $this->assertNull($user->google_subject);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->last_login_at);
        $this->assertNull($user->deactivated_at);
        $this->assertSame($this->cheras->id, $originalAssignment->fresh()->branch_id);
        $this->assertTrue($originalAssignment->fresh()->is_primary);
    }

    public function test_generic_mass_assignment_cannot_reassign_branch_assignment_ownership(): void
    {
        $user = $this->createStaff('ca', [$this->cheras]);
        $assignment = $user->staffProfile->branchAssignments()->firstOrFail();

        try {
            $assignment->fill([
                'staff_profile_id' => $assignment->staff_profile_id + 1000,
                'branch_id' => $this->puchong->id,
                'is_primary' => false,
                'assignment_type' => 'temporary',
            ]);
            $this->fail('Branch assignment mass assignment unexpectedly succeeded.');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($assignment->isDirty('staff_profile_id'));
        $this->assertFalse($assignment->isDirty('branch_id'));
        $this->assertFalse($assignment->isDirty('is_primary'));
        $this->assertFalse($assignment->isDirty('assignment_type'));
    }

    public function test_generic_user_mass_assignment_cannot_mutate_sensitive_identity_fields(): void
    {
        $user = $this->createStaff('ca', [$this->cheras]);

        $user->fill([
            'password' => 'untrusted-password',
            'organisation_id' => $user->organisation_id + 1000,
            'google_subject' => 'untrusted-subject',
            'is_active' => false,
            'last_login_at' => now(),
            'deactivated_at' => now(),
        ]);

        foreach (['password', 'organisation_id', 'google_subject', 'is_active', 'last_login_at', 'deactivated_at'] as $attribute) {
            $this->assertFalse($user->isDirty($attribute), $attribute);
        }
    }

    /**
     * @param  list<Branch>  $branches
     */
    private function createStaff(
        ?string $role = null,
        array $branches = [],
        ?string $email = null,
    ): User {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Dummy Staff '.Str::random(6),
            'email' => $email ?? 'dummy.'.Str::uuid().'@kpone.test',
        ]);
        $department = Department::query()->where('organisation_id', $this->organisation->id)->firstOrFail();
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'job_title' => 'Dummy Position',
        ]);

        foreach ($branches as $index => $branch) {
            $this->createAssignment($user->setRelation('staffProfile', $profile), $branch, [
                'is_primary' => $index === 0,
            ]);
        }

        if ($role) {
            $user->assignRole($role);
        }

        return $user->refresh()->load('staffProfile');
    }

    /** @param array{assignment_type?:string,is_primary?:bool,valid_from?:string,valid_until?:string|null} $attributes */
    private function createAssignment(
        User $user,
        Branch $branch,
        array $attributes = [],
        ?User $actor = null,
    ): StaffBranchAssignment {
        $values = [
            'assignment_type' => 'permanent',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            ...$attributes,
        ];

        return $actor
            ? app(BranchAssignmentService::class)->create($user->staffProfile, $branch, $values, $actor)
            : StaffBranchAssignmentBootstrapper::create($user->staffProfile, $branch, $values);
    }

    private function fakeGoogle(string $subject, string $email, bool $verified): void
    {
        Config::set('services.google', [
            'client_id' => 'dummy-client',
            'client_secret' => 'dummy-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => $subject,
            'email' => $email,
            'email_verified' => $verified,
        ]));
    }
}

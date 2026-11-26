<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\DispensarySettingsReader;
use App\Domain\Clinical\Dispensary\Services\DispensarySettingsService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\BranchDispensarySetting;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

class DispensarySettingsReadTest extends VisitTestCase
{
    private function change(User $actor): BranchDispensarySetting
    {
        $this->selectBranch($actor, $this->branch);
        $service = app(DispensarySettingsService::class);
        $current = $service->current($this->branch);

        return $service->setDoctorCheck($actor, ['expected_branch_id' => $this->branch->id, 'doctor_check_required' => ! $current['doctorCheckRequired'], 'lock_version' => $current['lockVersion'], 'retry_key' => (string) Str::uuid()]);
    }

    private function denied(callable $read): void
    {
        try {
            $read();
            $this->fail('Unauthorized settings metadata must be refused.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_attributed_change_is_localized_and_http_keeps_the_existing_settings_shape(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 23:30:00', 'UTC'));
        $actor = $this->actor('ca_supervisor');
        $actor->forceFill(['name' => 'Synthetic Settings Editor'])->save();
        $row = $this->change($actor);
        $auditCount = AuditLog::query()->count();
        $data = app(DispensarySettingsReader::class)->snapshot($actor, $this->branch);
        $this->assertSame(['doctorCheckRequired' => true, 'lockVersion' => $row->lock_version], $data['settings']);
        $this->assertSame(['changedAt' => '27 Nov 2026, 7:30 AM', 'changedBy' => 'Synthetic Settings Editor'], $data['settingsChange']);
        $this->assertSame($auditCount, AuditLog::query()->count(), 'Reading does not create or rewrite an audit event.');
        $this->actingAs($actor)->get(route('dispensary-settings.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Settings')->where('settings', $data['settings'])->where('settingsChange', $data['settingsChange']));
    }

    public function test_default_and_unattributable_rows_never_display_migration_timestamps(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $reader = app(DispensarySettingsReader::class);
        $this->assertNull($reader->snapshot($actor, $this->branch)['settingsChange']);
        $row = $this->change($actor);
        foreach ([['updated_by_user_id' => null], ['updated_at' => null], ['last_retry_key' => null], ['lock_version' => 1]] as $missing) {
            $original = $row->getAttributes();
            DB::table('branch_dispensary_settings')->where('id', $row->id)->update($missing);
            $this->assertNull($reader->snapshot($actor, $this->branch)['settingsChange']);
            DB::table('branch_dispensary_settings')->where('id', $row->id)->update($original);
        }
        DB::table('branch_dispensary_settings')->where('id', $row->id)->delete();
        $this->assertSame(['doctorCheckRequired' => false, 'lockVersion' => 1], $reader->snapshot($actor, $this->branch)['settings']);
        $this->assertNull($reader->snapshot($actor, $this->branch)['settingsChange']);
    }

    public function test_hidden_updater_uses_a_generic_label_without_expanding_staff_visibility(): void
    {
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $director = $this->actor('director', $other);
        $director->forceFill(['name' => 'Synthetic Hidden Editor'])->save();
        $this->change($director);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $data = app(DispensarySettingsReader::class)->snapshot($supervisor, $this->branch);
        $this->assertSame('Staff member', $data['settingsChange']['changedBy']);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($director->name, $encoded);
        $this->assertStringNotContainsString($director->email, $encoded);
        $this->assertSame(['changedAt', 'changedBy'], array_keys($data['settingsChange']));
        $this->selectBranch($director, $this->branch);
        $this->assertSame($director->name, app(DispensarySettingsReader::class)->snapshot($director, $this->branch)['settingsChange']['changedBy']);
    }

    public function test_fresh_inactive_permission_and_expired_assignment_checks_reject_stale_actor_objects(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $actor->load(['roles.permissions', 'permissions']);
        $reader = app(DispensarySettingsReader::class);
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        $this->denied(fn () => $reader->snapshot($actor, $this->branch));
        User::query()->whereKey($actor->id)->update(['is_active' => true]);
        $actor->staffProfile->branchAssignments()->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->denied(fn () => $reader->snapshot($actor, $this->branch));
        $other = $this->actor('ca_supervisor');
        $this->selectBranch($other);
        $stale = $other->fresh(['roles.permissions', 'permissions']);
        $other->removeRole('ca_supervisor');
        $this->denied(fn () => $reader->snapshot($stale, $this->branch));
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->actingAs($ca)->get(route('dispensary-settings.show'))->assertForbidden();
        $this->denied(fn () => $reader->snapshot($ca, $this->branch));
    }

    public function test_other_active_branch_organisation_and_disabled_branch_are_refused(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $reader = app(DispensarySettingsReader::class);
        $this->denied(fn () => $reader->snapshot($actor, $other));
        $org = Organisation::query()->create(['code' => 'SYN-'.Str::upper(Str::random(8)), 'name' => 'Synthetic Other Organisation']);
        $foreign = new Branch;
        $foreign->forceFill(['organisation_id' => $org->id, 'code' => 'SYN', 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $this->denied(fn () => $reader->snapshot($actor, $foreign));
        $this->branch->forceFill(['is_active' => false])->save();
        $this->denied(fn () => $reader->snapshot($actor, $this->branch));
    }

    public function test_noop_and_retry_preserve_attribution_and_reader_query_count_does_not_grow(): void
    {
        $actor = $this->actor('ca_supervisor');
        $row = $this->change($actor);
        $reader = app(DispensarySettingsReader::class);
        $before = $reader->snapshot($actor, $this->branch);
        $this->travel(1)->hours();
        app(DispensarySettingsService::class)->setDoctorCheck($actor, ['expected_branch_id' => $this->branch->id, 'doctor_check_required' => $row->doctor_check_required, 'lock_version' => $row->lock_version, 'retry_key' => (string) Str::uuid()]);
        app(DispensarySettingsService::class)->setDoctorCheck($actor, ['expected_branch_id' => $this->branch->id, 'doctor_check_required' => $row->doctor_check_required, 'lock_version' => $row->lock_version - 1, 'retry_key' => $row->last_retry_key]);
        $this->assertSame($before, $reader->snapshot($actor, $this->branch));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader->snapshot($actor, $this->branch);
        $baseline = DB::getQueryLog();
        DB::disableQueryLog();
        User::factory()->count(25)->create(['organisation_id' => $this->organisation->id, 'email' => fn () => Str::uuid().'@kpone.test']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader->snapshot($actor, $this->branch);
        $after = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(count($baseline), $after);
        $this->assertCount(1, array_filter($after, fn (array $query): bool => str_contains($query['query'], 'from "branch_dispensary_settings"')));
    }
}

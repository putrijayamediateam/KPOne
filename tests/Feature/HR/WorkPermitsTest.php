<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Credentials\Models\CredentialRecord;
use App\Domain\HR\Credentials\Models\CredentialType;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-21 foreign worker permits (owner, 18 Nov 2026): a clinic-added credential type marked as a work permit, with the
 * clinic's own warning days. Synthetic data only.
 */
class WorkPermitsTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_a_work_permit_type_expires_is_not_a_health_item_and_keeps_its_flag(): void
    {
        $this->selectBranch($this->hr);
        $this->saveType(['code' => 'PLKS', 'name' => 'PLKS (work permit)', 'work_permit' => true, 'health_item' => true, 'expires' => false])
            ->assertSessionHasErrors('work_permit'); // a work permit is never a health item
        $this->saveType(['code' => 'PLKS', 'name' => 'PLKS (work permit)', 'work_permit' => true, 'expires' => false])->assertSessionHasErrors('work_permit');
        $this->saveType(['code' => 'PLKS', 'name' => 'PLKS (work permit)', 'work_permit' => 'maybe'])->assertSessionHasErrors('work_permit');
        $this->saveType(['code' => 'PLKS', 'name' => 'PLKS (work permit)', 'work_permit' => true, 'warn_from_days' => 90])->assertSessionHasNoErrors();
        $type = CredentialType::query()->where('code', 'PLKS')->sole();
        $this->assertTrue($type->work_permit);
        // A new version changes the name and warning days, never the flag.
        $this->saveType(['type_id' => $type->public_id, 'name' => 'PLKS work permit', 'work_permit' => false, 'warn_from_days' => 60,
            'expected_lock_version' => $type->lock_version])->assertSessionHasNoErrors();
        $this->assertTrue($type->refresh()->work_permit);
        $this->get(route('hr.credentials.index', ['tab' => 'settings']))->assertInertia(fn (Assert $p) => $p
            ->where('credentials.settings.types.0.workPermit', true)->where('credentials.settings.types.0.warnFromDays', 60));

        // PostgreSQL keeps both rules.
        $this->refused('P0001', fn () => DB::table('hr_credential_types')->where('id', $type->id)->update(['work_permit' => false]));
        $this->refused('23514', fn () => DB::table('hr_credential_types')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $type->organisation_id,
            'code' => 'BAD', 'expires' => false, 'health_item' => false, 'work_permit' => true, 'current_version' => 1, 'created_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_an_expiring_or_expired_work_permit_shows_in_words_on_the_register_the_staff_file_and_the_dashboard(): void
    {
        $nurse = $this->actor('ca');
        $other = $this->actor('ca');
        $leaver = $this->actor('ca');
        $nurseFile = $this->workerFor($nurse);
        $otherFile = $this->workerFor($other);
        $leaverFile = $this->workerFor($leaver);
        $this->selectBranch($this->hr);
        $this->saveType(['code' => 'PLKS', 'name' => 'PLKS (work permit)', 'work_permit' => true, 'warn_from_days' => 60])->assertSessionHasNoErrors();
        $this->saveType(['code' => 'BLS', 'name' => 'Basic life support', 'warn_from_days' => 60])->assertSessionHasNoErrors();
        $plks = CredentialType::query()->where('code', 'PLKS')->sole();
        $bls = CredentialType::query()->where('code', 'BLS')->sole();

        // The CA's permit expires in 30 days; the other CA's is two years away; a BLS expiring soon is not a permit.
        $this->enter($nurseFile, $plks, '2026-04-07', '2027-04-07');
        $this->enter($otherFile, $plks, '2027-01-01', '2029-01-01');
        $this->enter($nurseFile, $bls, '2025-04-01', '2027-04-01');
        // Someone who has left is not counted anywhere, as on the register, even with a permit expiring soon.
        $this->enter($leaverFile, $plks, '2026-04-01', '2027-04-01');
        $this->selectBranch($this->hr);
        $this->post(route('hr.records.periods.store', $leaverFile->public_id), ['type' => 'contract', 'starts_on' => '2020-01-01', 'ends_on' => '2027-02-28',
            'end_reason' => 'resigned', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $line = 'Work permit expires on 7 Apr 2027 (in 30 days)';
        $this->selectBranch($this->hr);
        $this->get(route('hr.credentials.index', ['tab' => 'register']))->assertInertia(fn (Assert $p) => $p
            ->has('credentials.register.permits', 1)->where('credentials.register.permits.0.line', $line)
            ->where('credentials.register.permits.0.workerId', $nurseFile->public_id)->where('credentials.register.permits.0.expired', false)
            // The summary keys are unchanged; the BLS still counts as expiring there.
            ->where('credentials.register.summary.expiring', 2));
        $this->get(route('hr.records.show', $nurseFile->public_id))->assertInertia(fn (Assert $p) => $p->where('file.workPermits', [$line]));
        $this->get(route('hr.records.show', $otherFile->public_id))->assertInertia(fn (Assert $p) => $p->where('file.workPermits', []));
        $this->get(route('hr.dashboard.index'))->assertInertia(fn (Assert $p) => $p->where('dashboard.workPermits', 1));
        $this->get(route('hr.records.show', $leaverFile->public_id))->assertInertia(fn (Assert $p) => $p->where('file.workPermits', []));

        // Once expired it says so, in the same warning words.
        $this->travelTo(CarbonImmutable::parse('2027-04-10 09:00', $this->branch->timezone));
        $this->get(route('hr.records.show', $nurseFile->public_id))->assertInertia(fn (Assert $p) => $p
            ->where('file.workPermits', ['Work permit expired on 7 Apr 2027 (3 days ago)']));

        // Someone who cannot manage credentials gets no permit lines.
        $this->selectBranch($nurse);
        $this->get(route('hr.records.index'))->assertOk();
        $this->assertStringNotContainsString('Work permit', (string) json_encode($this->get(route('hr.records.index'))->viewData('page')));
    }

    // ---- fixture ----

    /** @param array<string, mixed> $input */
    private function saveType(array $input): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.credentials.types.save'), ['warn_from_days' => 60, 'expires' => true, 'health_item' => false, 'roles' => [], ...$input,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function workerFor(User $subject): Worker
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Worker::query()->where('user_id', $subject->id)->firstOrFail();
    }

    private function enter(Worker $worker, CredentialType $type, string $issued, string $expires): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.credentials.workers.store', $worker->public_id), ['type_id' => $type->public_id, 'issued_on' => $issued, 'expires_on' => $expires,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $record = CredentialRecord::query()->where('worker_id', $worker->id)->where('type_id', $type->id)->latest('id')->firstOrFail();
        $this->selectBranch($this->director);
        $this->post(route('hr.credentials.decide', [$record->public_id, 'accept']), ['expected_lock_version' => (int) $record->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function refused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}

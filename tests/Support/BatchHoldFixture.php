<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Database\Factories\PatientFactory;
use Database\Factories\VisitFactory;
use Illuminate\Support\Str;

/** Independent, synthetic bootstrap for task-owned PostgreSQL concurrency/Chrome evidence. */
final class BatchHoldFixture
{
    use OtcDispensaryFixtures;

    public function build(bool $saved): array
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $this->confirmAllergy($f, $case);
        if ($saved) {
            app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case));
        }

        return [...$f, 'case' => $case->refresh(), 'payload' => $this->addPayload($f, $case)];
    }

    public function actor(string $role = 'ca'): User
    {
        $branch = Branch::query()->firstOrFail();
        $user = User::factory()->create(['organisation_id' => $branch->organisation_id, 'name' => 'Synthetic batch '.$role, 'email' => 'batch-'.Str::uuid().'@kpone.test']);
        $user->assignRole($role);
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true]);

        return $user;
    }

    protected function patient(): Patient
    {
        return PatientFactory::new()->create(['organisation_id' => Branch::query()->firstOrFail()->organisation_id, 'full_name' => 'Synthetic batch fixture patient', 'date_of_birth' => '1990-01-01']);
    }

    protected function register(User $actor, Patient $patient, array $input): Visit
    {
        return VisitFactory::new()->create(['organisation_id' => $actor->organisation_id, 'patient_id' => $patient->id, 'registered_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id, ...$input]);
    }

    protected function selectBranch(User $actor, ?Branch $branch = null): void
    {
        app()->forgetInstance(BranchAccessService::class);
        session([BranchAccessService::SESSION_KEY => ($branch ?? Branch::query()->where('organisation_id', $actor->organisation_id)->firstOrFail())->id]);
    }
}

<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Database\Factories\PatientFactory;
use Database\Factories\VisitFactory;
use Illuminate\Support\Str;

/** Task/test bootstrap only, always fictional identities and a separate synthetic SKU. */
final class VerifiedReceivingFixture
{
    use OtcDispensaryFixtures {
        fixture as private dispensingFixture;
    }

    private Branch $branch;

    public function __construct(?Branch $branch = null)
    {
        $this->branch = $branch ?? Branch::query()->where('code', 'CHERAS')
            ->whereHas('organisation', fn ($query) => $query->where('code', 'KLINIK_PUTRIJAYA'))->sole();
    }

    public function build(string $ordered = '10.000'): array
    {
        $f = $this->dispensingFixture();
        $case = $this->open($f);
        $this->confirmAllergy($f, $case);
        $f['case'] = $case->refresh();
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiver = $this->actor('ca_supervisor');
        $this->selectBranch($receiver);
        $supplier = app(SupplierAdministrationService::class)->create($finance, ['code' => 'SYN-'.Str::upper(Str::random(8)), 'name' => 'Synthetic receiving supplier', 'business_email' => 'supplier@receiving.test', 'contact_name' => null, 'business_phone' => null]);
        $service = app(ProcurementService::class);
        $order = $service->create($finance, ['expected_branch_id' => $f['visit']->branch_id, 'supplier_public_id' => $supplier->public_id, 'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => $ordered, 'unit_cost_sen' => 125]]]);
        $order = $service->submit($finance, $order, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $order->lock_version]);
        $order = $service->approve($director, $order, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $order->lock_version]);
        $key = (string) Str::uuid();
        $payload = ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $order->lock_version, 'idempotency_key' => $key, 'retry_key' => (string) Str::uuid(), 'delivery_reference' => 'SYN-DELIVERY-'.$key, 'lines' => [['line_public_id' => $order->lines()->sole()->public_id, 'quantity' => '2.000', 'batch_number' => 'SYN-RECEIVING-'.Str::random(8), 'expiry_date' => '2028-12-31']]];

        return [...$f, 'receiver' => $receiver, 'finance' => $finance, 'director' => $director, 'supplier' => $supplier, 'order' => $order, 'payload' => $payload];
    }

    public function actor(string $role = 'ca'): User
    {
        $user = User::factory()->create(['organisation_id' => $this->branch->organisation_id, 'name' => 'Synthetic receiving '.$role, 'email' => 'receiving-'.Str::uuid().'@kpone.test']);
        $user->assignRole($role);
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $this->branch, ['is_primary' => true]);

        return $user;
    }

    protected function patient(): Patient
    {
        return PatientFactory::new()->create(['organisation_id' => $this->branch->organisation_id, 'full_name' => 'Synthetic receiving fixture patient', 'date_of_birth' => '1990-01-01']);
    }

    protected function register(User $actor, Patient $patient, array $input): Visit
    {
        return VisitFactory::new()->create([...$input, 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'patient_id' => $patient->id, 'registered_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id]);
    }

    protected function selectBranch(User $actor, ?Branch $branch = null): void
    {
        app(BranchAccessService::class)->select($actor, $branch ?? $this->branch);
    }
}

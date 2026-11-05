<?php

namespace Database\Seeders;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\UnifiedCatalogueSetupService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooterService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Services\ConsultationTariffAdministrationService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * DEMO-1: prepares a one-branch (CHERAS) synthetic demonstration on top of
 * KPOnePresentationSeeder. Local and testing environments only.
 *
 * Every state change goes through the existing domain services, acting as a
 * named demo account, so permissions, branch scope, locks and audit apply.
 * Each step first checks whether its result already exists, so a rerun adds
 * nothing.
 */
class KPOneDemoSetupSeeder extends Seeder
{
    public const BRANCH_CODE = 'CHERAS';

    public const SECOND_SUPERVISOR_EMAIL = 'demo.ca_supervisor2@kpone.test';

    public const FOOTER_TEXT = 'This is a computer-generated document. No signature is required.';

    public const LOCATION_CODE = 'DEMO-CHERAS-DISPENSARY';

    public const OPENING_QUANTITY = '200';

    /** Staff moved so their effective primary branch is CHERAS. */
    public const MOVED_EMAILS = [
        'demo.ca@kpone.test',
        'demo.ca_supervisor@kpone.test',
        'demo.finance_officer@kpone.test',
    ];

    /**
     * Fictional catalogue, generic names only. Prices are in sen per order unit.
     *
     * @var list<array{code: string, display_name: string, generic_name: string, strength_text: string, dosage_form: string, order_unit: string, self_pay_sen: int, panel_default_sen: int}>
     */
    public const MEDICINES = [
        ['code' => 'DEMO-PARA500', 'display_name' => 'Paracetamol 500 mg tablet', 'generic_name' => 'Paracetamol', 'strength_text' => '500 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'self_pay_sen' => 30, 'panel_default_sen' => 25],
        ['code' => 'DEMO-AMOX250', 'display_name' => 'Amoxicillin 250 mg capsule', 'generic_name' => 'Amoxicillin', 'strength_text' => '250 mg', 'dosage_form' => 'Capsule', 'order_unit' => 'capsule', 'self_pay_sen' => 80, 'panel_default_sen' => 70],
        ['code' => 'DEMO-CETI10', 'display_name' => 'Cetirizine 10 mg tablet', 'generic_name' => 'Cetirizine', 'strength_text' => '10 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'self_pay_sen' => 50, 'panel_default_sen' => 40],
        ['code' => 'DEMO-CHLOR4', 'display_name' => 'Chlorphenamine 4 mg tablet', 'generic_name' => 'Chlorphenamine', 'strength_text' => '4 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'self_pay_sen' => 20, 'panel_default_sen' => 15],
        ['code' => 'DEMO-OMEP20', 'display_name' => 'Omeprazole 20 mg capsule', 'generic_name' => 'Omeprazole', 'strength_text' => '20 mg', 'dosage_form' => 'Capsule', 'order_unit' => 'capsule', 'self_pay_sen' => 100, 'panel_default_sen' => 90],
        ['code' => 'DEMO-ORS', 'display_name' => 'Oral rehydration salts sachet', 'generic_name' => 'Oral rehydration salts', 'strength_text' => '1 sachet', 'dosage_form' => 'Powder', 'order_unit' => 'sachet', 'self_pay_sen' => 150, 'panel_default_sen' => 120],
    ];

    /** @var list<array{code: string, display_name: string, order_unit: string, category: string, self_pay_sen: int, panel_default_sen: int}> */
    public const SERVICES = [
        ['code' => 'DEMO-NEB', 'display_name' => 'Nebulisation', 'order_unit' => 'session', 'category' => 'Procedure', 'self_pay_sen' => 2500, 'panel_default_sen' => 2000],
        ['code' => 'DEMO-DRESS', 'display_name' => 'Simple wound dressing', 'order_unit' => 'session', 'category' => 'Procedure', 'self_pay_sen' => 2000, 'panel_default_sen' => 1500],
        ['code' => 'DEMO-GLUCOSE', 'display_name' => 'Capillary blood glucose test', 'order_unit' => 'test', 'category' => 'Test', 'self_pay_sen' => 1000, 'panel_default_sen' => 800],
    ];

    /** @var array{code: string, display_name: string, self_pay_sen: int, panel_default_sen: int} */
    public const CONSULTATION = ['code' => 'CONSULTATION', 'display_name' => 'General consultation', 'self_pay_sen' => 3000, 'panel_default_sen' => 2500];

    public function run(): void
    {
        $this->assertSafeEnvironment();

        $organisation = Organisation::query()
            ->where('code', 'KLINIK_PUTRIJAYA')
            ->where('is_active', true)
            ->firstOrFail();
        $branch = Branch::query()
            ->where('organisation_id', $organisation->id)
            ->where('code', self::BRANCH_CODE)
            ->where('is_active', true)
            ->firstOrFail();

        $director = $this->requireUser('demo.director@kpone.test');
        foreach (self::MOVED_EMAILS as $email) {
            $this->requireUser($email);
        }

        $this->actAs();
        foreach (self::MOVED_EMAILS as $email) {
            $this->moveToBranch($this->requireUser($email), $branch, $director);
        }
        $this->provisionSecondSupervisor($organisation, $branch, $director);

        $this->actAs();
        $this->saveBusinessDetails($organisation, $branch, $director);
        $this->saveFooter($organisation, $branch, $director);

        $recorder = $this->actAs('demo.ca_supervisor@kpone.test');
        $location = $this->ensureLocation($recorder, $branch);
        $this->ensureConsultationTariff($recorder, $branch);
        foreach (self::SERVICES as $service) {
            $this->ensureService($recorder, $branch, $service);
        }
        $openings = [];
        foreach (self::MEDICINES as $index => $medicine) {
            $sku = $this->ensureMedicine($recorder, $branch, $medicine);
            $this->ensureUnitVerified($recorder, $branch, $sku);
            $openings[] = $this->ensureOpeningBalance($recorder, $branch, $location, $sku, $index);
        }

        $verifier = $this->actAs(self::SECOND_SUPERVISOR_EMAIL);
        foreach ($openings as $opening) {
            $this->ensureOpeningSignedOff($verifier, $branch, $opening);
        }
    }

    private function assertSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('KPOneDemoSetupSeeder is restricted to local and testing environments.');
        }

        $nonTest = User::query()
            ->whereRaw("LOWER(email) NOT LIKE '%.test'")
            ->count();
        if ($nonTest > 0) {
            throw new LogicException("KPOneDemoSetupSeeder stopped: {$nonTest} user account(s) have an email that does not end in .test. Run it only on a synthetic database.");
        }
    }

    private function requireUser(string $email): User
    {
        return User::query()->where('email', $email)->first()
            ?? throw new LogicException("Demo account {$email} is missing. Run KPOnePresentationSeeder first.");
    }

    /**
     * Resets the cached branch context so the next service call resolves the
     * actor's own primary branch, as a fresh sign-in would.
     */
    private function actAs(?string $email = null): ?User
    {
        app()->forgetScopedInstances();
        session()->forget(BranchAccessService::SESSION_KEY);

        return $email === null ? null : $this->requireUser($email)->fresh();
    }

    private function moveToBranch(User $user, Branch $branch, User $director): void
    {
        $profile = StaffProfile::query()->where('user_id', $user->id)->firstOrFail();
        $assignments = app(BranchAssignmentService::class);
        $today = now()->toDateString();

        $current = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->where('branch_id', $branch->id)
            ->whereNull('valid_until')
            ->effectiveAt()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($current === null) {
            $assignments->create($profile, $branch, [
                'assignment_type' => 'permanent',
                'is_primary' => true,
                'valid_from' => $today,
            ], $director);
        } elseif (! $current->is_primary) {
            $assignments->changePrimary($profile, $current, $director);
        }

        // One-branch demo: end the remaining current assignments elsewhere.
        $yesterday = now()->subDay()->toDateString();
        $others = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->where('branch_id', '!=', $branch->id)
            ->where('is_primary', false)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>', $yesterday))
            ->get();
        foreach ($others as $other) {
            $from = $other->valid_from->toDateString();
            $assignments->end($other, $director, $from > $yesterday ? $from : $yesterday);
        }
    }

    private function provisionSecondSupervisor(Organisation $organisation, Branch $branch, User $director): void
    {
        if (User::query()->where('email', self::SECOND_SUPERVISOR_EMAIL)->exists()) {
            return;
        }

        $department = Department::query()
            ->where('organisation_id', $organisation->id)
            ->where('code', 'CLINIC_OPERATIONS')
            ->where('is_active', true)
            ->firstOrFail();

        app(StaffProvisioningService::class)->provision($director, [
            'name' => 'Demo CA Supervisor 2',
            'email' => self::SECOND_SUPERVISOR_EMAIL,
            'credential_strategy' => 'password',
            'password' => KPOnePresentationSeeder::PASSWORD,
            'password_confirmation' => KPOnePresentationSeeder::PASSWORD,
            'department_id' => $department->id,
            'staff_number' => 'DEMO-0012',
            'job_title' => 'Clinic Assistant Supervisor',
            'is_active' => true,
            'roles' => ['ca_supervisor'],
            'assignments' => [[
                'branch_id' => $branch->id,
                'assignment_type' => 'permanent',
                'is_primary' => true,
                'valid_from' => now()->toDateString(),
                'valid_until' => null,
            ]],
        ]);
    }

    private function saveBusinessDetails(Organisation $organisation, Branch $branch, User $director): void
    {
        $profile = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->first();
        $branchProfile = BranchBusinessProfile::query()
            ->where('organisation_id', $organisation->id)
            ->where('branch_id', $branch->id)
            ->first();
        if ($profile !== null && $branchProfile !== null && $branchProfile->clinic_name !== null) {
            return;
        }

        app(BusinessDetailsService::class)->save($director, [
            'expected_branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'organisation_lock_version' => $profile->lock_version ?? 0,
            'branch_lock_version' => $branchProfile->lock_version ?? 0,
            'legal_name' => 'Klinik Putrijaya Demo (synthetic data)',
            'clinic_name' => 'Klinik Putrijaya Cheras (Demo)',
            'address_line_1' => 'No. 19 G, Jalan Dwitasik 1',
            'address_line_2' => 'Dataran Dwitasik',
            'postcode' => '56000',
            'city' => 'Kuala Lumpur',
            'state' => 'Wilayah Persekutuan Kuala Lumpur',
            'country' => 'Malaysia',
            'phone' => null,
            'email' => 'demo.clinic@kpone.test',
        ]);
    }

    private function saveFooter(Organisation $organisation, Branch $branch, User $director): void
    {
        $footer = BranchDocumentFooter::query()
            ->where('organisation_id', $organisation->id)
            ->where('branch_id', $branch->id)
            ->first();
        if ($footer?->text === self::FOOTER_TEXT) {
            return;
        }

        app(BranchDocumentFooterService::class)->save($director, [
            'expected_branch_id' => $branch->id,
            'lock_version' => $footer->lock_version ?? 0,
            'idempotency_key' => (string) Str::uuid(),
            'footer_text' => self::FOOTER_TEXT,
        ]);
    }

    private function ensureLocation(User $actor, Branch $branch): InventoryLocation
    {
        $location = InventoryLocation::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('code', self::LOCATION_CODE)
            ->first();

        return $location ?? app(InventoryReferenceAdministrationService::class)->createLocation($actor, $branch, null, [
            'code' => self::LOCATION_CODE,
            'name' => 'Cheras Dispensary (Demo)',
            'type' => InventoryLocation::TYPE_DISPENSARY,
        ]);
    }

    private function ensureConsultationTariff(User $actor, Branch $branch): void
    {
        if (ChargeDefinition::query()->where('organisation_id', $actor->organisation_id)->where('source_key', 'consultation')->exists()) {
            return;
        }

        app(ConsultationTariffAdministrationService::class)->publish($actor, [
            'code' => self::CONSULTATION['code'],
            'display_name' => self::CONSULTATION['display_name'],
            'expected_branch_id' => $branch->id,
            'prices' => [
                'self_pay_sen' => self::CONSULTATION['self_pay_sen'],
                'panel_default_sen' => self::CONSULTATION['panel_default_sen'],
            ],
            'expected_versions' => ['self_pay' => 0, 'panel_default' => 0],
        ]);
    }

    /** @param array{code: string, display_name: string, order_unit: string, category: string, self_pay_sen: int, panel_default_sen: int} $service */
    private function ensureService(User $actor, Branch $branch, array $service): void
    {
        if (ClinicalServiceCatalogueItem::query()->where('organisation_id', $actor->organisation_id)->where('code', $service['code'])->exists()) {
            return;
        }

        app(UnifiedCatalogueSetupService::class)->createClinicalService($actor, [
            'code' => $service['code'],
            'display_name' => $service['display_name'],
            'order_unit' => $service['order_unit'],
            'category' => $service['category'],
            'expected_branch_id' => $branch->id,
            'prices' => [
                'self_pay_sen' => $service['self_pay_sen'],
                'panel_default_sen' => $service['panel_default_sen'],
            ],
        ]);
    }

    /** @param array{code: string, display_name: string, generic_name: string, strength_text: string, dosage_form: string, order_unit: string, self_pay_sen: int, panel_default_sen: int} $medicine */
    private function ensureMedicine(User $actor, Branch $branch, array $medicine): InventorySku
    {
        $existing = MedicineCatalogueItem::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('code', $medicine['code'])
            ->first();

        if ($existing === null) {
            // One SKU per medicine whose stock and dispensing units are the
            // medicine's order unit (STK-1: identity factor 1, no conversion).
            $existing = app(UnifiedCatalogueSetupService::class)->createMedicine($actor, [
                'code' => $medicine['code'],
                'display_name' => $medicine['display_name'],
                'generic_name' => $medicine['generic_name'],
                'strength_text' => $medicine['strength_text'],
                'dosage_form' => $medicine['dosage_form'],
                'order_unit' => $medicine['order_unit'],
                'category' => 'Demo',
                'route' => 'Oral',
                'expected_branch_id' => $branch->id,
                'sku' => [
                    'sku_code' => $medicine['code'],
                    'pack_size' => '1',
                    'purchase_unit' => $medicine['order_unit'],
                    'stock_unit' => $medicine['order_unit'],
                    'dispensing_unit' => $medicine['order_unit'],
                    'unit_conversion' => '1',
                ],
                'prices' => [
                    'self_pay_sen' => $medicine['self_pay_sen'],
                    'panel_default_sen' => $medicine['panel_default_sen'],
                ],
            ]);
        }

        $mapping = MedicineCatalogueInventorySku::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('medicine_catalogue_item_id', $existing->id)
            ->where('is_active', true)
            ->firstOrFail();

        return InventorySku::query()->whereKey($mapping->inventory_sku_id)->firstOrFail();
    }

    private function ensureUnitVerified(User $actor, Branch $branch, InventorySku $sku): void
    {
        if (DB::table('inventory_unit_contracts')->where('inventory_sku_id', $sku->id)->exists()) {
            return;
        }

        app(InventoryReadinessService::class)->verify($actor, 'unit', $sku->public_id, [
            'expected_branch_id' => $branch->id,
            'lock_version' => 0,
            'retry_key' => (string) Str::uuid(),
            'base_unit' => $sku->stock_unit,
        ]);
    }

    private function ensureOpeningBalance(User $actor, Branch $branch, InventoryLocation $location, InventorySku $sku, int $index): StockMovement
    {
        $batchNumber = 'DEMO-B'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        $batch = InventoryBatch::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('inventory_sku_id', $sku->id)
            ->where('batch_number', $batchNumber)
            ->first();
        if ($batch === null) {
            // A real, human-readable expiry well in the future (18 to 23 months ahead).
            $batch = app(InventoryReferenceAdministrationService::class)->createBatch($actor, $sku, [
                'batch_number' => $batchNumber,
                'expiry_date' => now()->addMonthsNoOverflow(18 + $index)->endOfMonth()->toDateString(),
                'received_at' => now()->toDateString(),
                'status' => InventoryBatch::STATUS_AVAILABLE,
            ]);
        }

        $opening = StockMovement::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('destination_location_id', $location->id)
            ->where('inventory_sku_id', $sku->id)
            ->where('inventory_batch_id', $batch->id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->first();

        return $opening ?? app(InventoryMovementService::class)->openingBalance($actor, [
            'expected_branch_id' => $branch->id,
            'location_public_id' => $location->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => self::OPENING_QUANTITY,
        ]);
    }

    private function ensureOpeningSignedOff(User $verifier, Branch $branch, StockMovement $opening): void
    {
        if (DB::table('inventory_opening_verifications')->where('stock_movement_id', $opening->id)->exists()) {
            return;
        }

        app(InventoryReadinessService::class)->verify($verifier, 'opening', $opening->public_id, [
            'expected_branch_id' => $branch->id,
            'lock_version' => 0,
            'retry_key' => (string) Str::uuid(),
        ]);
    }
}

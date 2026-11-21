<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\DiagnosisPresetSettingsService;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\UnifiedCatalogueSetupService;
use App\Domain\Identity\Services\ClinicianCredentialService;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooterService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\SupplierSettingsService;
use App\Domain\Organisation\Models\Department;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PanelClaimMessageTemplate;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingApprovalLimitAdministrationService;
use App\Domain\Visit\Billing\Services\ConsultationTariffAdministrationService;
use App\Domain\Visit\Billing\Services\PanelClaimMessageService;
use App\Domain\Visit\Billing\Services\PaymentMethodAdministrationService;
use App\Domain\Visit\Billing\Services\PaymentMethodClassificationService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\VisitReason;
use App\Domain\Visit\Services\PanelSettingsService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA Phase 1, foundation: fictional staff for every role, clinic set-up, catalogue and prices, stock with real
 * future expiry dates signed off by a second person, and fictional patients. Every write goes through an existing
 * domain service as the account whose job it is; every step first looks for its own result, so a rerun adds nothing.
 */
final class PhaseOneFoundation
{
    public const FOOTER_TEXT = 'This is a computer-generated document. No signature is required.';

    public const DISPENSARY_CODE = 'TRIAL-CHERAS-DISPENSARY';

    public const STORE_CODE = 'TRIAL-CHERAS-STORE';

    public const OPENING_QUANTITY = '300';

    /** @var list<array{code: string, name: string, cash: bool, reference: bool}> */
    public const PAYMENT_METHODS = [
        ['code' => 'CASH', 'name' => 'Cash', 'cash' => true, 'reference' => false],
        ['code' => 'CARD', 'name' => 'Debit or credit card', 'cash' => false, 'reference' => true],
        ['code' => 'EWALLET', 'name' => 'E-wallet', 'cash' => false, 'reference' => true],
    ];

    /**
     * Fictional catalogue, generic names only. Prices are in sen per order unit. The first batch expires in two months,
     * so the expiry views have something to show; one medicine is controlled, for the controlled register.
     *
     * @var list<array{code: string, display_name: string, generic_name: string, strength_text: string, dosage_form: string, order_unit: string, route: string, self_pay_sen: int, panel_default_sen: int, controlled?: bool}>
     */
    public const MEDICINES = [
        ['code' => 'TRIAL-PARA500', 'display_name' => 'Paracetamol 500 mg tablet', 'generic_name' => 'Paracetamol', 'strength_text' => '500 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 30, 'panel_default_sen' => 25],
        ['code' => 'TRIAL-AMOX250', 'display_name' => 'Amoxicillin 250 mg capsule', 'generic_name' => 'Amoxicillin', 'strength_text' => '250 mg', 'dosage_form' => 'Capsule', 'order_unit' => 'capsule', 'route' => 'Oral', 'self_pay_sen' => 80, 'panel_default_sen' => 70],
        ['code' => 'TRIAL-CETI10', 'display_name' => 'Cetirizine 10 mg tablet', 'generic_name' => 'Cetirizine', 'strength_text' => '10 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 50, 'panel_default_sen' => 40],
        ['code' => 'TRIAL-CHLOR4', 'display_name' => 'Chlorphenamine 4 mg tablet', 'generic_name' => 'Chlorphenamine', 'strength_text' => '4 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 20, 'panel_default_sen' => 15],
        ['code' => 'TRIAL-OMEP20', 'display_name' => 'Omeprazole 20 mg capsule', 'generic_name' => 'Omeprazole', 'strength_text' => '20 mg', 'dosage_form' => 'Capsule', 'order_unit' => 'capsule', 'route' => 'Oral', 'self_pay_sen' => 100, 'panel_default_sen' => 90],
        ['code' => 'TRIAL-ORS', 'display_name' => 'Oral rehydration salts sachet', 'generic_name' => 'Oral rehydration salts', 'strength_text' => '1 sachet', 'dosage_form' => 'Powder', 'order_unit' => 'sachet', 'route' => 'Oral', 'self_pay_sen' => 150, 'panel_default_sen' => 120],
        ['code' => 'TRIAL-IBU400', 'display_name' => 'Ibuprofen 400 mg tablet', 'generic_name' => 'Ibuprofen', 'strength_text' => '400 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 40, 'panel_default_sen' => 35],
        ['code' => 'TRIAL-LORA10', 'display_name' => 'Loratadine 10 mg tablet', 'generic_name' => 'Loratadine', 'strength_text' => '10 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 60, 'panel_default_sen' => 50],
        ['code' => 'TRIAL-METF500', 'display_name' => 'Metformin 500 mg tablet', 'generic_name' => 'Metformin', 'strength_text' => '500 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 30, 'panel_default_sen' => 25],
        ['code' => 'TRIAL-AMLO5', 'display_name' => 'Amlodipine 5 mg tablet', 'generic_name' => 'Amlodipine', 'strength_text' => '5 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 40, 'panel_default_sen' => 35],
        ['code' => 'TRIAL-DICLO-GEL', 'display_name' => 'Diclofenac 1% gel 20 g tube', 'generic_name' => 'Diclofenac', 'strength_text' => '1%', 'dosage_form' => 'Gel', 'order_unit' => 'tube', 'route' => 'Topical', 'self_pay_sen' => 900, 'panel_default_sen' => 800],
        ['code' => 'TRIAL-COD15', 'display_name' => 'Codeine phosphate 15 mg tablet', 'generic_name' => 'Codeine phosphate', 'strength_text' => '15 mg', 'dosage_form' => 'Tablet', 'order_unit' => 'tablet', 'route' => 'Oral', 'self_pay_sen' => 50, 'panel_default_sen' => 45, 'controlled' => true],
    ];

    /** @var list<array{code: string, display_name: string, order_unit: string, category: string, self_pay_sen: int, panel_default_sen: int}> */
    public const SERVICES = [
        ['code' => 'TRIAL-NEB', 'display_name' => 'Nebulisation', 'order_unit' => 'session', 'category' => 'Procedure(s)', 'self_pay_sen' => 2500, 'panel_default_sen' => 2000],
        ['code' => 'TRIAL-DRESS', 'display_name' => 'Simple wound dressing', 'order_unit' => 'session', 'category' => 'Procedure(s)', 'self_pay_sen' => 2000, 'panel_default_sen' => 1500],
        ['code' => 'TRIAL-GLUCOSE', 'display_name' => 'Capillary blood glucose test', 'order_unit' => 'test', 'category' => 'Investigation(s)', 'self_pay_sen' => 1000, 'panel_default_sen' => 800],
        ['code' => 'TRIAL-ECG', 'display_name' => 'Resting ECG', 'order_unit' => 'test', 'category' => 'Investigation(s)', 'self_pay_sen' => 4000, 'panel_default_sen' => 3500],
        ['code' => 'TRIAL-INJ', 'display_name' => 'Intramuscular injection', 'order_unit' => 'session', 'category' => 'Procedure(s)', 'self_pay_sen' => 1500, 'panel_default_sen' => 1200],
        ['code' => 'TRIAL-SUTURE-OFF', 'display_name' => 'Suture removal', 'order_unit' => 'session', 'category' => 'Procedure(s)', 'self_pay_sen' => 2500, 'panel_default_sen' => 2000],
    ];

    /** @var array{code: string, display_name: string, self_pay_sen: int, panel_default_sen: int} */
    public const CONSULTATION = ['code' => 'CONSULTATION', 'display_name' => 'General consultation', 'self_pay_sen' => 3000, 'panel_default_sen' => 2500];

    /** @var list<array{code: string, name: string, type: string, days: int}> */
    public const PANELS = [
        ['code' => 'TRIAL-PANEL-A', 'name' => 'Trial Care Network (fictional)', 'type' => 'tpa', 'days' => 30],
        ['code' => 'TRIAL-PANEL-B', 'name' => 'Trial Manufacturing Sdn Bhd (fictional)', 'type' => 'direct_company', 'days' => 14],
    ];

    public const CLAIM_MESSAGE = 'Claim {claim_number} for {patient_name} ({panel_name}), amount {claimed_amount}. Fictional trial data.';

    /** @var list<string> */
    public const SUPPLIERS = ['Trial Pharma Supplies (fictional)', 'Trial Medical Distributors (fictional)'];

    /** @var list<array{name: string, kind: string, sort: int}> */
    public const ROOMS = [
        ['name' => 'Consultation Room 1', 'kind' => BranchRoom::KIND_CONSULTATION, 'sort' => 1],
        ['name' => 'Consultation Room 2', 'kind' => BranchRoom::KIND_CONSULTATION, 'sort' => 2],
        ['name' => 'Dispensary Counter', 'kind' => BranchRoom::KIND_DISPENSARY, 'sort' => 3],
        ['name' => 'Treatment Room', 'kind' => BranchRoom::KIND_TREATMENT, 'sort' => 4],
    ];

    /** @var list<string> */
    public const VISIT_REASONS = [
        'Fever', 'Cough and cold', 'Sore throat', 'Diarrhoea and vomiting', 'Headache', 'Rash or skin problem',
        'Wound dressing', 'Follow-up visit', 'Medical check-up', 'Blood pressure review',
    ];

    /** @var list<string> */
    public const DIAGNOSES = [
        'Upper respiratory tract infection', 'Acute gastroenteritis', 'Viral fever', 'Allergic rhinitis',
        'Essential hypertension, follow-up', 'Type 2 diabetes, follow-up', 'Contact dermatitis', 'Tension headache',
    ];

    public const PATIENT_COUNT = 20;

    /** @var array<string, int> how many records of each kind this run created */
    private array $created = [];

    public function __construct(private readonly TrialDataContext $context) {}

    /**
     * @param  string|null  $password  the shared password for new accounts; only needed when some are missing
     * @return array<string, int>
     */
    public function run(?string $password): array
    {
        $this->created = [];
        $this->staff($password);
        $this->clinicSetUp();
        $this->catalogue();
        $this->panels();
        $this->stock();
        $this->patients();

        return $this->created;
    }

    private function staff(?string $password): void
    {
        $missing = $this->context->missingAccounts();
        if ($missing === []) {
            return;
        }
        if ($password === null) {
            throw new \LogicException('A shared password is needed to create the missing accounts.');
        }

        $director = $this->context->asDirector();
        foreach ($missing as $key) {
            $account = TrialDataContext::ACCOUNTS[$key];
            $department = Department::query()
                ->where('organisation_id', $this->context->organisation->id)
                ->where('code', $account['department'])
                ->where('is_active', true)
                ->firstOrFail();
            app(StaffProvisioningService::class)->provision($director, [
                'name' => $account['name'],
                'email' => TrialDataContext::email($key),
                'credential_strategy' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
                'department_id' => $department->id,
                'staff_number' => $account['staff_number'],
                'job_title' => $account['title'],
                'is_active' => true,
                'roles' => [$account['role']],
                'assignments' => [[
                    'branch_id' => $this->context->branch->id,
                    'assignment_type' => 'permanent',
                    'is_primary' => true,
                    'valid_from' => now()->toDateString(),
                    'valid_until' => null,
                ]],
            ]);
            $this->count('staff');
        }
    }

    private function clinicSetUp(): void
    {
        $branch = $this->context->branch;
        $organisation = $this->context->organisation;

        // Business details, the Klinik Putrijaya mark as the logo (when GD is available) and the agreed footer.
        $profile = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->first();
        $branchProfile = BranchBusinessProfile::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
        if ($profile === null || $branchProfile === null) {
            app(BusinessDetailsService::class)->save($this->context->asDirector(), [
                'expected_branch_id' => $branch->id,
                'idempotency_key' => (string) Str::uuid(),
                'organisation_lock_version' => $profile->lock_version ?? 0,
                'branch_lock_version' => $branchProfile->lock_version ?? 0,
                'legal_name' => 'Klinik Putrijaya Trial (fictional data)',
                'clinic_name' => 'Klinik Putrijaya Cheras (Trial)',
                'address_line_1' => 'No. 19 G, Jalan Dwitasik 1',
                'address_line_2' => 'Dataran Dwitasik',
                'postcode' => '56000',
                'city' => 'Kuala Lumpur',
                'state' => 'Wilayah Persekutuan Kuala Lumpur',
                'country' => 'Malaysia',
                'phone' => null,
                'email' => 'trial.clinic@'.TrialDataContext::EMAIL_DOMAIN,
            ]);
            $this->count('business_details');
        }
        $this->logo();

        $footer = BranchDocumentFooter::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
        if ($footer?->text !== self::FOOTER_TEXT) {
            app(BranchDocumentFooterService::class)->save($this->context->asDirector(), [
                'expected_branch_id' => $branch->id,
                'lock_version' => $footer->lock_version ?? 0,
                'idempotency_key' => (string) Str::uuid(),
                'footer_text' => self::FOOTER_TEXT,
            ]);
            $this->count('footer');
        }

        // Payment methods, published, with their Cash / Non-cash classification.
        foreach (self::PAYMENT_METHODS as $index => $definition) {
            $method = PaymentMethod::query()->where('organisation_id', $organisation->id)->where('code', $definition['code'])->first();
            if ($method === null) {
                $method = app(PaymentMethodAdministrationService::class)->create($this->context->as('finance'), [
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'requires_reference' => $definition['reference'],
                    'sort_order' => $index + 1,
                ]);
                $this->count('payment_methods');
            }
            if (! $method->is_active) {
                $method = app(PaymentMethodAdministrationService::class)->publish($this->context->as('finance'), $method);
            }
            $classification = $definition['cash'] ? 'cash' : 'non_cash';
            if ($method->classification !== $classification) {
                app(PaymentMethodClassificationService::class)->change($this->context->as('finance'), $method->fresh() ?? $method, [
                    'classification' => $classification,
                    'lock_version' => (int) ($method->fresh()->lock_version ?? 0),
                ]);
            }
        }

        // Panel and pay-later approval limits for the first CA Supervisor.
        $supervisor = $this->context->as('supervisor1');
        foreach (['panel' => 50000, 'deferment' => 20000] as $capability => $limitSen) {
            $current = DB::table('billing_approval_limits')
                ->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)
                ->where('user_id', $supervisor->id)->where('capability', $capability)->value('limit_sen');
            if ($current === null || (int) $current !== $limitSen) {
                app(BillingApprovalLimitAdministrationService::class)->setLimit($this->context->asDirector(), $branch, [
                    'user_id' => $supervisor->id,
                    'capability' => $capability,
                    'limit_sen' => $limitSen,
                ]);
                $this->count('approval_limits');
            }
        }

        // Rooms for the doctors' room choice and the waiting-room TV.
        foreach (self::ROOMS as $room) {
            if (! BranchRoom::query()->where('branch_id', $branch->id)->where('name', $room['name'])->exists()) {
                app(QueueDisplayAdministrationService::class)->createRoom($this->context->asDirector(), $branch, [
                    'kind' => $room['kind'],
                    'name' => $room['name'],
                    'sort_order' => $room['sort'],
                ]);
                $this->count('rooms');
            }
        }

        // Visit reasons: the service returns an existing reason unchanged.
        foreach (self::VISIT_REASONS as $reason) {
            if (! VisitReason::query()->where('organisation_id', $organisation->id)->where('normalized_name', Str::lower($reason))->exists()) {
                app(VisitReasonService::class)->create($this->context->as('ca1'), $reason);
                $this->count('visit_reasons');
            }
        }

        // Preset diagnoses (reference names only, no patient content).
        $existing = DB::table('diagnosis_presets')->where('organisation_id', $organisation->id)->pluck('normalized_name')->all();
        $missing = array_values(array_filter(self::DIAGNOSES, fn (string $name): bool => ! in_array(mb_strtolower($name), $existing, true)));
        if ($missing !== []) {
            app(DiagnosisPresetSettingsService::class)->create($this->context->as('administrator'), [
                'expected_branch_id' => $branch->id,
                'idempotency_key' => (string) Str::uuid(),
                'names' => $missing,
            ]);
            $this->count('diagnosis_presets', count($missing));
        }

        // Verified clinician credentials for both doctors, with obviously fictional registration numbers.
        foreach (['doctor1' => '000000000001', 'doctor2' => '000000000002'] as $key => $number) {
            $doctor = $this->context->as($key);
            $profile = DB::table('clinician_professional_profiles')->where('user_id', $doctor->id)->first();
            if ($profile === null || ! $profile->verified) {
                app(ClinicianCredentialService::class)->save($this->context->asDirector(), $doctor->id, [
                    'expected_branch_id' => $branch->id,
                    'lock_version' => (int) ($profile->lock_version ?? 0),
                    'idempotency_key' => (string) Str::uuid(),
                    'professional_name' => 'Dr '.TrialDataContext::ACCOUNTS[$key]['name'].' (fictional)',
                    'mmc_number' => $number,
                    'verified' => true,
                ]);
                $this->count('clinician_credentials');
            }
        }
    }

    private function logo(): void
    {
        $organisation = $this->context->organisation;
        $branch = $this->context->branch;
        $profile = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->first();
        $branchProfile = BranchBusinessProfile::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
        $mark = public_path('kp-mark.png');
        // Business details checks and re-encodes the image with GD; without it the trial simply has no logo.
        if ($profile === null || $branchProfile === null || $profile->logo_asset_id !== null || ! is_file($mark) || ! extension_loaded('gd')) {
            return;
        }

        $data = [
            'expected_branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'organisation_lock_version' => $profile->lock_version,
            'branch_lock_version' => $branchProfile->lock_version,
            'legal_name' => $profile->legal_name,
            'registration_type' => $profile->registration_type,
            'registration_number' => $profile->registration_number,
            'clinic_name' => $branchProfile->clinic_name,
            'address_line_1' => $branchProfile->address_line_1,
            'address_line_2' => $branchProfile->address_line_2,
            'postcode' => $branchProfile->postcode,
            'city' => $branchProfile->city,
            'state' => $branchProfile->state,
            'country' => $branchProfile->country,
            'phone' => $branchProfile->phone,
            'email' => $branchProfile->email,
        ];
        if ($branchProfile->phone_secondary !== null) {
            $data['phone_secondary'] = $branchProfile->phone_secondary;
        }

        app(BusinessDetailsService::class)->save($this->context->asDirector(), $data, new UploadedFile($mark, 'kp-mark.png', 'image/png', null, true));
        $this->count('logo');
    }

    private function catalogue(): void
    {
        $branch = $this->context->branch;
        $organisationId = $this->context->organisation->id;

        if (! ChargeDefinition::query()->where('organisation_id', $organisationId)->where('source_key', 'consultation')->exists()) {
            app(ConsultationTariffAdministrationService::class)->publish($this->context->as('supervisor1'), [
                'code' => self::CONSULTATION['code'],
                'display_name' => self::CONSULTATION['display_name'],
                'expected_branch_id' => $branch->id,
                'prices' => ['self_pay_sen' => self::CONSULTATION['self_pay_sen'], 'panel_default_sen' => self::CONSULTATION['panel_default_sen']],
                'expected_versions' => ['self_pay' => 0, 'panel_default' => 0],
            ]);
            $this->count('consultation_tariff');
        }

        foreach (self::SERVICES as $service) {
            if (ClinicalServiceCatalogueItem::query()->where('organisation_id', $organisationId)->where('code', $service['code'])->exists()) {
                continue;
            }
            app(UnifiedCatalogueSetupService::class)->createClinicalService($this->context->as('supervisor1'), [
                'code' => $service['code'],
                'display_name' => $service['display_name'],
                'order_unit' => $service['order_unit'],
                'category' => $service['category'],
                'expected_branch_id' => $branch->id,
                'prices' => ['self_pay_sen' => $service['self_pay_sen'], 'panel_default_sen' => $service['panel_default_sen']],
            ]);
            $this->count('services');
        }

        foreach (self::MEDICINES as $medicine) {
            $existing = MedicineCatalogueItem::query()->where('organisation_id', $organisationId)->where('code', $medicine['code'])->first();
            if ($existing === null) {
                // One SKU per medicine whose stock and dispensing units are the order unit (STK-1: no conversion).
                $existing = app(UnifiedCatalogueSetupService::class)->createMedicine($this->context->as('supervisor1'), [
                    'code' => $medicine['code'],
                    'display_name' => $medicine['display_name'],
                    'generic_name' => $medicine['generic_name'],
                    'strength_text' => $medicine['strength_text'],
                    'dosage_form' => $medicine['dosage_form'],
                    'order_unit' => $medicine['order_unit'],
                    'category' => 'Trial',
                    'route' => $medicine['route'],
                    'expected_branch_id' => $branch->id,
                    'sku' => [
                        'sku_code' => $medicine['code'],
                        'pack_size' => '1',
                        'purchase_unit' => $medicine['order_unit'],
                        'stock_unit' => $medicine['order_unit'],
                        'dispensing_unit' => $medicine['order_unit'],
                        'unit_conversion' => '1',
                    ],
                    'prices' => ['self_pay_sen' => $medicine['self_pay_sen'], 'panel_default_sen' => $medicine['panel_default_sen']],
                ]);
                $this->count('medicines');
            }
            if (($medicine['controlled'] ?? false) && ! $existing->is_controlled) {
                app(MedicineAdministrationService::class)->setControlled($this->context->as('supervisor1'), $existing, true, false);
            }
        }
    }

    private function panels(): void
    {
        $organisationId = $this->context->organisation->id;
        foreach (self::PANELS as $definition) {
            if (Panel::query()->where('organisation_id', $organisationId)->where('code', $definition['code'])->exists()) {
                continue;
            }
            app(PanelSettingsService::class)->create($this->context->as('panel'), [
                'code' => $definition['code'],
                'name' => $definition['name'],
                'panel_type' => $definition['type'],
                'company_name' => $definition['name'],
                'email' => 'claims.'.strtolower(str_replace('TRIAL-PANEL-', '', $definition['code'])).'@'.TrialDataContext::EMAIL_DOMAIN,
                'verification_mode' => 'remark',
                'verification_remark' => 'Fictional Panel for trial data. Check the member card.',
                'claim_due_rule' => 'days_after_visit',
                'claim_due_days' => $definition['days'],
            ]);
            $this->count('panels');
        }

        if (! PanelClaimMessageTemplate::query()->where('organisation_id', $organisationId)->exists()) {
            app(PanelClaimMessageService::class)->save($this->context->as('panel'), self::CLAIM_MESSAGE, 0);
            $this->count('claim_message_template');
        }
    }

    private function stock(): void
    {
        $branch = $this->context->branch;
        $organisationId = $this->context->organisation->id;

        $recorder = $this->context->as('supervisor1');
        $dispensary = $this->location($recorder, self::DISPENSARY_CODE, 'Cheras Dispensary (Trial)', InventoryLocation::TYPE_DISPENSARY);
        $this->location($recorder, self::STORE_CODE, 'Cheras Store Room (Trial)', InventoryLocation::TYPE_BRANCH_STORE);

        foreach (self::SUPPLIERS as $name) {
            if (! InventorySupplier::query()->where('organisation_id', $organisationId)->where('name', $name)->exists()) {
                app(SupplierSettingsService::class)->create($this->context->as('administrator'), [
                    'expected_branch_id' => $branch->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'name' => $name,
                    'contacts' => [['name' => 'Trial sales desk', 'phone' => null, 'country' => 'MY']],
                ]);
                $this->count('suppliers');
            }
        }

        $openings = [];
        foreach (self::MEDICINES as $index => $medicine) {
            $sku = $this->sku($medicine['code']);
            if (! DB::table('inventory_unit_contracts')->where('inventory_sku_id', $sku->id)->exists()) {
                app(InventoryReadinessService::class)->verify($this->context->as('supervisor1'), 'unit', $sku->public_id, [
                    'expected_branch_id' => $branch->id,
                    'lock_version' => 0,
                    'retry_key' => (string) Str::uuid(),
                    'base_unit' => $sku->stock_unit,
                ]);
                $this->count('verified_units');
            }
            $openings[] = $this->opening($dispensary, $sku, $index);
        }

        // A different CA Supervisor signs off every opening count (STK-1 independence).
        foreach ($openings as $opening) {
            if (! DB::table('inventory_opening_verifications')->where('stock_movement_id', $opening->id)->exists()) {
                app(InventoryReadinessService::class)->verify($this->context->as('supervisor2'), 'opening', $opening->public_id, [
                    'expected_branch_id' => $branch->id,
                    'lock_version' => 0,
                    'retry_key' => (string) Str::uuid(),
                ]);
                $this->count('opening_sign_offs');
            }
        }
    }

    private function location(User $actor, string $code, string $name, string $type): InventoryLocation
    {
        $location = InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('code', $code)->first();
        if ($location !== null) {
            return $location;
        }
        $this->count('locations');

        return app(InventoryReferenceAdministrationService::class)->createLocation($actor, $this->context->branch, null, [
            'code' => $code,
            'name' => $name,
            'type' => $type,
        ]);
    }

    private function sku(string $medicineCode): InventorySku
    {
        $medicine = MedicineCatalogueItem::query()->where('organisation_id', $this->context->organisation->id)->where('code', $medicineCode)->firstOrFail();
        $mapping = MedicineCatalogueInventorySku::query()
            ->where('organisation_id', $this->context->organisation->id)
            ->where('medicine_catalogue_item_id', $medicine->id)
            ->where('is_active', true)
            ->firstOrFail();

        return InventorySku::query()->whereKey($mapping->inventory_sku_id)->firstOrFail();
    }

    private function opening(InventoryLocation $location, InventorySku $sku, int $index): StockMovement
    {
        $batchNumber = 'TRIAL-B'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        $batch = InventoryBatch::query()
            ->where('organisation_id', $this->context->organisation->id)
            ->where('inventory_sku_id', $sku->id)
            ->where('batch_number', $batchNumber)
            ->first();
        if ($batch === null) {
            // The first batch expires in two months (for the expiry views); the rest 18 months or more ahead.
            $months = $index === 0 ? 2 : 18 + $index;
            $batch = app(InventoryReferenceAdministrationService::class)->createBatch($this->context->as('supervisor1'), $sku, [
                'batch_number' => $batchNumber,
                'expiry_date' => now()->addMonthsNoOverflow($months)->endOfMonth()->toDateString(),
                'received_at' => now()->toDateString(),
                'status' => InventoryBatch::STATUS_AVAILABLE,
            ]);
            $this->count('batches');
        }

        $opening = StockMovement::query()
            ->where('organisation_id', $this->context->organisation->id)
            ->where('destination_location_id', $location->id)
            ->where('inventory_sku_id', $sku->id)
            ->where('inventory_batch_id', $batch->id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->first();
        if ($opening !== null) {
            return $opening;
        }
        $this->count('openings');

        return app(InventoryMovementService::class)->openingBalance($this->context->as('supervisor1'), [
            'expected_branch_id' => $this->context->branch->id,
            'location_public_id' => $location->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => self::OPENING_QUANTITY,
        ]);
    }

    private function patients(): void
    {
        for ($index = 1; $index <= self::PATIENT_COUNT; $index++) {
            $name = sprintf('Trial Patient %02d', $index);
            if (Patient::query()->where('organisation_id', $this->context->organisation->id)->where('full_name', $name)->exists()) {
                continue;
            }

            $attributes = [
                'full_name' => $name,
                'date_of_birth' => sprintf('%04d-%02d-15', 1960 + $index * 2, ($index % 12) + 1),
                'sex' => $index % 2 === 0 ? 'female' : 'male',
                'nationality_code' => 'MY',
                'mobile_phone' => '+60123456789',
                'phone_country' => 'MY',
                'email' => sprintf('patient-%02d@%s', $index, TrialDataContext::EMAIL_DOMAIN),
                'country_code' => 'MY',
                'identifiers' => [[
                    'identifier_type' => 'passport',
                    'issuing_country_code' => 'MY',
                    'value' => sprintf('TRIALDEMO%04d', $index),
                ]],
                'duplicate_override' => true,
                'expected_branch_id' => $this->context->branch->id,
            ];
            if ($index === self::PATIENT_COUNT - 1) {
                // A minor, registered with a guardian contact.
                $attributes['date_of_birth'] = now('Asia/Kuala_Lumpur')->subYears(7)->toDateString();
                $attributes['guardian'] = [
                    'relationship' => 'mother', 'full_name' => 'Trial Guardian (fictional)', 'phone' => '+60123456780',
                    'phone_country' => 'MY', 'email' => 'guardian@'.TrialDataContext::EMAIL_DOMAIN, 'lock_version' => 0,
                    'retry_key' => (string) Str::uuid(),
                ];
            }
            if ($index === self::PATIENT_COUNT) {
                // An adult whose date of birth is unknown, confirmed as an adult by staff.
                $attributes['date_of_birth'] = null;
                $attributes['adult_confirmed'] = true;
                $attributes['adult_confirmation_key'] = (string) Str::uuid();
            }

            app(PatientAdministrationService::class)->create($this->context->as('ca1'), $attributes);
            $this->count('patients');
        }
    }

    private function count(string $kind, int $by = 1): void
    {
        $this->created[$kind] = ($this->created[$kind] ?? 0) + $by;
    }
}

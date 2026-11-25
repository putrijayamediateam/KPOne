<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\DiagnosisPreset;
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
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PanelClaimMessageTemplate;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingApprovalLimitAdministrationService;
use App\Domain\Visit\Billing\Services\ConsultationTariffAdministrationService;
use App\Domain\Visit\Billing\Services\PanelClaimMessageService;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PaymentMethodAdministrationService;
use App\Domain\Visit\Billing\Services\PaymentMethodClassificationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\VisitReason;
use App\Domain\Visit\Services\PanelSettingsService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA Phase 1, foundation, at all three branches: fictional staff for every role, clinic set-up, catalogue and prices, stock with real
 * future expiry dates signed off by a second person, and fictional patients. Every write goes through an existing
 * domain service as the account whose job it is; every step first looks for its own result, so a rerun adds nothing.
 */
final class PhaseOneFoundation
{
    public const FOOTER_TEXT = 'This is a computer-generated document. No signature is required.';

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

    /** Ten fictional patients registered at each branch (30 in all). */
    public const PATIENTS_PER_BRANCH = 10;

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
        $this->organisationSetUp();
        foreach ($this->context->branches as $code => $branch) {
            $this->branchSetUp($code, $branch);
        }
        $this->catalogue();
        $this->panelPrices();
        $this->panels();
        $this->stock();
        $this->patients();

        return $this->created;
    }

    public static function dispensaryCode(string $branchCode): string
    {
        return 'TRIAL-'.$branchCode.'-DISPENSARY';
    }

    public static function storeCode(string $branchCode): string
    {
        return 'TRIAL-'.$branchCode.'-STORE';
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

        $accounts = TrialDataContext::accounts();
        $director = $this->context->asDirector();
        foreach ($missing as $key) {
            $account = $accounts[$key];
            $department = Department::query()
                ->where('organisation_id', $this->context->organisation->id)
                ->where('code', $account['department'])
                ->where('is_active', true)
                ->firstOrFail();
            $assignments = [];
            foreach ($account['branches'] as $index => $code) {
                $assignments[] = [
                    'branch_id' => $this->context->branches->get($code)->id,
                    'assignment_type' => 'permanent',
                    'is_primary' => $index === 0,
                    'valid_from' => now()->toDateString(),
                    'valid_until' => null,
                ];
            }
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
                'assignments' => $assignments,
            ]);
            $this->count('staff');
        }
    }

    /** Organisation-wide set-up, done once from the main branch. */
    private function organisationSetUp(): void
    {
        $organisation = $this->context->organisation;
        $main = $this->context->mainBranch();

        // Payment methods, published, with their Cash / Non-cash classification.
        foreach (self::PAYMENT_METHODS as $index => $definition) {
            $method = PaymentMethod::query()->where('organisation_id', $organisation->id)->where('code', $definition['code'])->first();
            if ($method === null) {
                $method = app(PaymentMethodAdministrationService::class)->create($this->context->as('finance', $main), [
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'requires_reference' => $definition['reference'],
                    'sort_order' => $index + 1,
                ]);
                $this->count('payment_methods');
            }
            if (! $method->is_active) {
                $method = app(PaymentMethodAdministrationService::class)->publish($this->context->as('finance', $main), $method);
            }
            $classification = $definition['cash'] ? 'cash' : 'non_cash';
            $fresh = $method->fresh() ?? $method;
            if ($fresh->classification !== $classification) {
                app(PaymentMethodClassificationService::class)->change($this->context->as('finance', $main), $fresh, [
                    'classification' => $classification,
                    'lock_version' => (int) $fresh->lock_version,
                ]);
            }
        }

        // Visit reasons (organisation-wide).
        foreach (self::VISIT_REASONS as $reason) {
            if (! VisitReason::query()->where('organisation_id', $organisation->id)->where('normalized_name', Str::lower($reason))->exists()) {
                app(VisitReasonService::class)->create($this->context->as(TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'ca1')), $reason);
                $this->count('visit_reasons');
            }
        }

        // Preset diagnoses (reference names only, no patient content).
        $existing = DB::table('diagnosis_presets')->where('organisation_id', $organisation->id)->pluck('normalized_name')->all();
        $missing = array_values(array_filter(self::DIAGNOSES, fn (string $name): bool => ! in_array(mb_strtolower($name), $existing, true)));
        if ($missing !== []) {
            app(DiagnosisPresetSettingsService::class)->create($this->context->as('administrator', $main), [
                'expected_branch_id' => $main->id,
                'idempotency_key' => (string) Str::uuid(),
                'names' => $missing,
            ]);
            // SUG-1a: a new preset is a draft until a doctor reviews it, so the demo does it the real way.
            $doctor = $this->context->as(TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'doctor1'), $main);
            foreach (DiagnosisPreset::query()->where('organisation_id', $organisation->id)->where('status', 'draft')
                ->whereIn('normalized_name', array_map(mb_strtolower(...), $missing))->get() as $preset) {
                app(DiagnosisPresetSettingsService::class)->review($doctor, $preset->id, [
                    'expected_branch_id' => $main->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'lock_version' => $preset->lock_version,
                    'decision' => 'approve',
                ]);
            }
            $this->count('diagnosis_presets', count($missing));
        }
    }

    /** Each branch's own set-up: letterhead, footer, approval limits, rooms and its doctors' credentials. */
    private function branchSetUp(string $code, Branch $branch): void
    {
        $organisation = $this->context->organisation;
        $name = ucwords(str_replace('_', ' ', strtolower($code)));

        // Business details: the organisation profile is shared; each branch has its own letterhead.
        $profile = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->first();
        $branchProfile = BranchBusinessProfile::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
        if ($branchProfile === null) {
            app(BusinessDetailsService::class)->save($this->context->asDirector($branch), [
                'expected_branch_id' => $branch->id,
                'idempotency_key' => (string) Str::uuid(),
                'organisation_lock_version' => $profile->lock_version ?? 0,
                'branch_lock_version' => 0,
                'legal_name' => $profile->legal_name ?? 'Klinik Putrijaya Trial (fictional data)',
                'registration_type' => $profile?->registration_type,
                'registration_number' => $profile?->registration_number,
                'clinic_name' => 'Klinik Putrijaya '.$name.' (Trial)',
                'address_line_1' => 'Trial address, '.$name.' (fictional)',
                'address_line_2' => null,
                'postcode' => '50000',
                'city' => 'Kuala Lumpur',
                'state' => 'Wilayah Persekutuan Kuala Lumpur',
                'country' => 'Malaysia',
                'phone' => null,
                'email' => 'trial.'.TrialDataContext::BRANCHES[$code].'@'.TrialDataContext::EMAIL_DOMAIN,
            ]);
            $this->count('business_details');
        }
        if ($code === TrialDataContext::MAIN_BRANCH) {
            $this->logo($branch);
        }

        $footer = BranchDocumentFooter::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
        if ($footer?->text !== self::FOOTER_TEXT) {
            app(BranchDocumentFooterService::class)->save($this->context->asDirector($branch), [
                'expected_branch_id' => $branch->id,
                'lock_version' => $footer->lock_version ?? 0,
                'idempotency_key' => (string) Str::uuid(),
                'footer_text' => self::FOOTER_TEXT,
            ]);
            $this->count('footers');
        }

        // Panel and pay-later approval limits for the branch's first CA Supervisor.
        $supervisor = $this->context->as(TrialDataContext::key($code, 'supervisor1'));
        foreach (['panel' => 50000, 'deferment' => 20000] as $capability => $limitSen) {
            $current = DB::table('billing_approval_limits')
                ->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)
                ->where('user_id', $supervisor->id)->where('capability', $capability)->value('limit_sen');
            if ($current === null || (int) $current !== $limitSen) {
                app(BillingApprovalLimitAdministrationService::class)->setLimit($this->context->asDirector($branch), $branch, [
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
                app(QueueDisplayAdministrationService::class)->createRoom($this->context->asDirector($branch), $branch, [
                    'kind' => $room['kind'],
                    'name' => $room['name'],
                    'sort_order' => $room['sort'],
                ]);
                $this->count('rooms');
            }
        }

        // Verified clinician credentials for the branch's doctors, with obviously fictional registration numbers.
        $branchIndex = array_search($code, array_keys(TrialDataContext::BRANCHES), true);
        foreach (['doctor1' => 1, 'doctor2' => 2] as $role => $offset) {
            $key = TrialDataContext::key($code, $role);
            $doctor = $this->context->as($key);
            $credential = DB::table('clinician_professional_profiles')->where('user_id', $doctor->id)->first();
            if ($credential === null || ! $credential->verified) {
                app(ClinicianCredentialService::class)->save($this->context->asDirector($branch), $doctor->id, [
                    'expected_branch_id' => $branch->id,
                    'lock_version' => (int) ($credential->lock_version ?? 0),
                    'idempotency_key' => (string) Str::uuid(),
                    'professional_name' => 'Dr Trial '.$name.' '.TrialDataContext::BRANCH_STAFF[$role]['name'].' (fictional)',
                    'mmc_number' => sprintf('%012d', ((int) $branchIndex * 2) + $offset),
                    'verified' => true,
                ]);
                $this->count('clinician_credentials');
            }
        }
    }

    private function logo(Branch $branch): void
    {
        $organisation = $this->context->organisation;
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

        app(BusinessDetailsService::class)->save($this->context->asDirector($branch), $data, new UploadedFile($mark, 'kp-mark.png', 'image/png', null, true));
        $this->count('logo');
    }

    /** Catalogue and prices are organisation-wide, set up by the main branch's first CA Supervisor. */
    private function catalogue(): void
    {
        $main = $this->context->mainBranch();
        $organisationId = $this->context->organisation->id;
        $supervisor = TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'supervisor1');

        if (! ChargeDefinition::query()->where('organisation_id', $organisationId)->where('source_key', 'consultation')->exists()) {
            app(ConsultationTariffAdministrationService::class)->publish($this->context->as($supervisor), [
                'code' => self::CONSULTATION['code'],
                'display_name' => self::CONSULTATION['display_name'],
                'expected_branch_id' => $main->id,
                'prices' => ['self_pay_sen' => self::CONSULTATION['self_pay_sen']],
                'expected_versions' => ['self_pay' => 0],
            ]);
            $this->count('consultation_tariff');
        }

        foreach (self::SERVICES as $service) {
            if (ClinicalServiceCatalogueItem::query()->where('organisation_id', $organisationId)->where('code', $service['code'])->exists()) {
                continue;
            }
            app(UnifiedCatalogueSetupService::class)->createClinicalService($this->context->as($supervisor), [
                'code' => $service['code'],
                'display_name' => $service['display_name'],
                'order_unit' => $service['order_unit'],
                'category' => $service['category'],
                'expected_branch_id' => $main->id,
                'prices' => ['self_pay_sen' => $service['self_pay_sen']],
            ]);
            $this->count('services');
        }

        foreach (self::MEDICINES as $medicine) {
            $existing = MedicineCatalogueItem::query()->where('organisation_id', $organisationId)->where('code', $medicine['code'])->first();
            if ($existing === null) {
                // One SKU per medicine whose stock and dispensing units are the order unit (STK-1: no conversion).
                $existing = app(UnifiedCatalogueSetupService::class)->createMedicine($this->context->as($supervisor), [
                    'code' => $medicine['code'],
                    'display_name' => $medicine['display_name'],
                    'generic_name' => $medicine['generic_name'],
                    'strength_text' => $medicine['strength_text'],
                    'dosage_form' => $medicine['dosage_form'],
                    'order_unit' => $medicine['order_unit'],
                    'category' => 'Trial',
                    'route' => $medicine['route'],
                    'expected_branch_id' => $main->id,
                    'sku' => [
                        'sku_code' => $medicine['code'],
                        'pack_size' => '1',
                        'purchase_unit' => $medicine['order_unit'],
                        'stock_unit' => $medicine['order_unit'],
                        'dispensing_unit' => $medicine['order_unit'],
                        'unit_conversion' => '1',
                    ],
                    'prices' => ['self_pay_sen' => $medicine['self_pay_sen']],
                ]);
                $this->count('medicines');
            }
            if (($medicine['controlled'] ?? false) && ! $existing->is_controlled) {
                app(MedicineAdministrationService::class)->setControlled($this->context->as($supervisor), $existing, true, false);
            }
        }
    }

    /**
     * Panel prices change only by proposal and approval (owner decision 27): the Panel Officer proposes each default
     * Panel price and the Finance Officer approves it. The default Panel price book is created first.
     */
    private function panelPrices(): void
    {
        $main = $this->context->mainBranch();
        $organisationId = $this->context->organisation->id;
        $book = PriceBook::query()->where('organisation_id', $organisationId)->where('scope_key', 'panel:default')->first()
            ?? app(PricingReferenceAdministrationService::class)->createPriceBook($this->context->as(TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'supervisor1'), $main), null, [
                'name' => 'Panel', 'currency' => 'MYR', 'price_tier' => 'panel', 'panel_id' => null,
            ]);
        $prices = [self::CONSULTATION['code'] => self::CONSULTATION['panel_default_sen']];
        foreach ([...self::SERVICES, ...self::MEDICINES] as $item) {
            $prices[$item['code']] = $item['panel_default_sen'];
        }
        $service = app(PanelPriceProposalService::class);
        foreach ($prices as $code => $sen) {
            $charge = ChargeDefinition::query()->where('organisation_id', $organisationId)->where('code', $code)->firstOrFail();
            if (PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->exists()) {
                continue;
            }
            $proposal = $service->propose($this->context->as('panel', $main), $book, $charge, [
                'expected_branch_id' => $main->id,
                'expected_version' => 0,
                'amount_sen' => $sen,
                'agreement_reference' => 'TRIAL-PANEL-PRICE-LIST',
                'effective_date' => now($main->timezone)->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $service->approve($this->context->as('finance', $main), $proposal, ['expected_branch_id' => $main->id, 'lock_version' => $proposal->lock_version]);
            $this->count('panel_prices');
        }
    }

    private function panels(): void
    {
        $organisationId = $this->context->organisation->id;
        $main = $this->context->mainBranch();
        foreach (self::PANELS as $definition) {
            if (Panel::query()->where('organisation_id', $organisationId)->where('code', $definition['code'])->exists()) {
                continue;
            }
            app(PanelSettingsService::class)->create($this->context->as('panel', $main), [
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
            app(PanelClaimMessageService::class)->save($this->context->as('panel', $main), self::CLAIM_MESSAGE, 0);
            $this->count('claim_message_template');
        }
    }

    /** Suppliers once; at every branch a dispensary and a store, openings recorded by one supervisor and signed off by the other. */
    private function stock(): void
    {
        $organisationId = $this->context->organisation->id;
        $main = $this->context->mainBranch();

        foreach (self::SUPPLIERS as $name) {
            if (! InventorySupplier::query()->where('organisation_id', $organisationId)->where('name', $name)->exists()) {
                app(SupplierSettingsService::class)->create($this->context->as('administrator', $main), [
                    'expected_branch_id' => $main->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'name' => $name,
                    'contacts' => [['name' => 'Trial sales desk', 'phone' => null, 'country' => 'MY']],
                ]);
                $this->count('suppliers');
            }
        }

        $skus = [];
        foreach (self::MEDICINES as $index => $medicine) {
            $sku = $this->sku($medicine['code']);
            if (! DB::table('inventory_unit_contracts')->where('inventory_sku_id', $sku->id)->exists()) {
                app(InventoryReadinessService::class)->verify($this->context->as(TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'supervisor1')), 'unit', $sku->public_id, [
                    'expected_branch_id' => $main->id,
                    'lock_version' => 0,
                    'retry_key' => (string) Str::uuid(),
                    'base_unit' => $sku->stock_unit,
                ]);
                $this->count('verified_units');
            }
            $skus[$index] = $sku;
        }

        foreach ($this->context->branches as $code => $branch) {
            $recorder = TrialDataContext::key($code, 'supervisor1');
            $name = ucwords(str_replace('_', ' ', strtolower($code)));
            $dispensary = $this->location($this->context->as($recorder), $branch, self::dispensaryCode($code), $name.' Dispensary (Trial)', InventoryLocation::TYPE_DISPENSARY);
            $this->location($this->context->as($recorder), $branch, self::storeCode($code), $name.' Store Room (Trial)', InventoryLocation::TYPE_BRANCH_STORE);

            $openings = [];
            foreach ($skus as $index => $sku) {
                $openings[] = $this->opening($recorder, $branch, $dispensary, $sku, $index);
            }

            // A different CA Supervisor at the same branch signs off every opening count (STK-1 independence).
            foreach ($openings as $opening) {
                if (! DB::table('inventory_opening_verifications')->where('stock_movement_id', $opening->id)->exists()) {
                    app(InventoryReadinessService::class)->verify($this->context->as(TrialDataContext::key($code, 'supervisor2')), 'opening', $opening->public_id, [
                        'expected_branch_id' => $branch->id,
                        'lock_version' => 0,
                        'retry_key' => (string) Str::uuid(),
                    ]);
                    $this->count('opening_sign_offs');
                }
            }
        }
    }

    private function location(User $actor, Branch $branch, string $code, string $name, string $type): InventoryLocation
    {
        $location = InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('code', $code)->first();
        if ($location !== null) {
            return $location;
        }
        $this->count('locations');

        return app(InventoryReferenceAdministrationService::class)->createLocation($actor, $branch, null, [
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

    private function opening(string $recorder, Branch $branch, InventoryLocation $location, InventorySku $sku, int $index): StockMovement
    {
        // One batch per medicine, shared by the three branches: the first expires in two months (for the expiry
        // views), the rest 18 months or more ahead.
        $batchNumber = 'TRIAL-B'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        $batch = InventoryBatch::query()
            ->where('organisation_id', $this->context->organisation->id)
            ->where('inventory_sku_id', $sku->id)
            ->where('batch_number', $batchNumber)
            ->first();
        if ($batch === null) {
            $months = $index === 0 ? 2 : 18 + $index;
            $batch = app(InventoryReferenceAdministrationService::class)->createBatch($this->context->as($recorder), $sku, [
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

        return app(InventoryMovementService::class)->openingBalance($this->context->as($recorder), [
            'expected_branch_id' => $branch->id,
            'location_public_id' => $location->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => self::OPENING_QUANTITY,
        ]);
    }

    /** Patients are organisation-wide; each branch's first CA registers its share. */
    private function patients(): void
    {
        $number = 0;
        foreach ($this->context->branches as $code => $branch) {
            for ($i = 1; $i <= self::PATIENTS_PER_BRANCH; $i++) {
                $number++;
                $name = sprintf('Trial Patient %02d', $number);
                if (Patient::query()->where('organisation_id', $this->context->organisation->id)->where('full_name', $name)->exists()) {
                    continue;
                }

                $attributes = [
                    'full_name' => $name,
                    'date_of_birth' => sprintf('%04d-%02d-15', 1950 + $number, ($number % 12) + 1),
                    'sex' => $number % 2 === 0 ? 'female' : 'male',
                    'nationality_code' => 'MY',
                    'mobile_phone' => '+60123456789',
                    'phone_country' => 'MY',
                    'email' => sprintf('patient-%02d@%s', $number, TrialDataContext::EMAIL_DOMAIN),
                    'country_code' => 'MY',
                    'identifiers' => [[
                        'identifier_type' => 'passport',
                        'issuing_country_code' => 'MY',
                        'value' => sprintf('TRIALDEMO%04d', $number),
                    ]],
                    'duplicate_override' => true,
                    'expected_branch_id' => $branch->id,
                ];
                if ($i === self::PATIENTS_PER_BRANCH - 1) {
                    // A minor, registered with a guardian contact.
                    $attributes['date_of_birth'] = now('Asia/Kuala_Lumpur')->subYears(5 + $number % 10)->toDateString();
                    $attributes['guardian'] = [
                        'relationship' => 'mother', 'full_name' => 'Trial Guardian (fictional)', 'phone' => '+60123456780',
                        'phone_country' => 'MY', 'email' => 'guardian@'.TrialDataContext::EMAIL_DOMAIN, 'lock_version' => 0,
                        'retry_key' => (string) Str::uuid(),
                    ];
                }
                if ($i === self::PATIENTS_PER_BRANCH) {
                    // An adult whose date of birth is unknown, confirmed as an adult by staff.
                    $attributes['date_of_birth'] = null;
                    $attributes['adult_confirmed'] = true;
                    $attributes['adult_confirmation_key'] = (string) Str::uuid();
                }

                app(PatientAdministrationService::class)->create($this->context->as(TrialDataContext::key($code, 'ca1')), $attributes);
                $this->count('patients');
            }
        }
    }

    private function count(string $kind, int $by = 1): void
    {
        $this->created[$kind] = ($this->created[$kind] ?? 0) + $by;
    }
}

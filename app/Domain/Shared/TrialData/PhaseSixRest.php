<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Clinical\Triage\TriageGuidelineService;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\Organisation\Compliance\Services\AuditService;
use App\Domain\Organisation\Compliance\Services\ChecklistService;
use App\Domain\Organisation\Compliance\Services\FridgeService;
use App\Domain\Organisation\Compliance\Services\LicenceService;
use App\Domain\Organisation\Compliance\Services\TaskService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Organisation\Website\WebsiteDraftService;
use App\Domain\Organisation\Website\WebsiteEditorialFields;
use App\Domain\Organisation\Website\WebsiteEditorialService;
use App\Domain\Organisation\Website\WebsiteLittleShieldFields;
use App\Domain\Organisation\Website\WebsiteLittleShieldService;
use App\Domain\Organisation\Website\WebsitePeopleFields;
use App\Domain\Organisation\Website\WebsitePeopleService;
use App\Domain\Organisation\Website\WebsiteProfileFields;
use App\Domain\Organisation\Website\WebsiteReleaseService;
use App\Domain\Organisation\Website\WebsiteServiceFields;
use App\Domain\Organisation\Website\WebsiteServicesService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientMergeService;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA phase 6: the rest. Clinic compliance (licences, fridge readings, a monthly audit with tasks), text-only
 * website drafts in English and Bahasa Melayu and a private release freeze approved by the Director (nothing is
 * published), the triage guideline (entered by the HR Manager, approved by the Director), a QR check-in link at
 * every branch (and one QR intake only when public intake is switched on), and patient merges (one approved, one
 * waiting). Agents are not registered on a production server, so the agent screens stay empty. Each step is skipped
 * when its own result already exists, so a rerun adds nothing.
 */
final class PhaseSixRest
{
    use ClinicFlow;

    public const GUIDELINE_SIGNER = 'Dr Trial Signer (fictional)';

    private PlaceholderFiles $files;

    public function __construct(private readonly TrialDataContext $context)
    {
        $this->files = new PlaceholderFiles;
    }

    /** @return array<string, int> what was created, by kind */
    public function run(): array
    {
        $this->created = [];
        try {
            foreach (['licences', 'fridges', 'monthlyAudit', 'websiteDrafts', 'triageGuideline', 'checkInLinks', 'patientMerges'] as $step) {
                DB::transaction(fn () => $this->{$step}());
            }
            // The release freeze manages its own transaction and refuses to run inside another one.
            $this->websiteRelease();
        } finally {
            $this->files->cleanUp();
        }

        return $this->created;
    }

    // --- clinic compliance -----------------------------------------------------------------------------------------

    /** Licence types and a licence at each branch (one due within the warning window), each with a PDF scan. */
    private function licences(): void
    {
        if (DB::table('compliance_licences')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $licences = app(LicenceService::class);
        $admin = $this->context->as('administrator', $this->context->mainBranch());
        $operating = $licences->saveType($admin, ['name' => 'Clinic operating licence (fictional)', 'expires' => true, 'warn_from_days' => 60, 'idempotency_key' => $this->k()]);
        $insurance = $licences->saveType($this->context->as('administrator', $this->context->mainBranch()), ['name' => 'Public liability insurance (fictional)', 'expires' => false, 'idempotency_key' => $this->k()]);
        $today = $this->today($this->context->mainBranch());
        $index = 0;
        foreach ($this->context->branches as $code => $branch) {
            // The first branch's licence expires in 30 days, inside the 60-day warning.
            $licences->record($this->context->as('administrator', $this->context->mainBranch()), [
                'type_id' => $operating, 'branch_id' => $branch->id, 'issued_on' => $today->subYear()->toDateString(),
                'expires_on' => $today->addDays($index === 0 ? 30 : 300)->toDateString(), 'reference' => 'TRIAL-LIC-'.$code, 'note' => 'Fictional trial licence',
                'idempotency_key' => $this->k(),
            ], [LeaveEvidenceFiles::prepare($this->files->pdf('Clinic operating licence', 'Fictional licence for '.$code), 'files')]);
            $index++;
            $this->count('licences');
        }
        $licences->record($this->context->as('administrator', $this->context->mainBranch()), [
            'type_id' => $insurance, 'branch_id' => null, 'issued_on' => $today->subMonths(2)->toDateString(), 'expires_on' => null,
            'reference' => 'TRIAL-INS-1', 'note' => 'Fictional policy', 'idempotency_key' => $this->k(),
        ], []);
        $this->count('licences');
    }

    /** A vaccine fridge at each branch with an in-range reading and an out-of-range one reviewed by the supervisor. */
    private function fridges(): void
    {
        if (DB::table('compliance_fridges')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $fridges = app(FridgeService::class);
        foreach ($this->context->branches as $code => $branch) {
            $fridge = $fridges->saveFridge($this->context->as('administrator', $branch), ['branch_id' => $branch->id, 'name' => 'Vaccine fridge (trial)', 'min' => '2.0', 'max' => '8.0',
                'readings_per_day' => 2, 'expect_by' => '10:00', 'idempotency_key' => $this->k()]);
            $fridges->record($this->ca($code, $branch), ['fridge_id' => $fridge, 'temp' => '5.0', 'low' => '4.2', 'high' => '6.1', 'idempotency_key' => $this->k()]);
            $warm = $fridges->record($this->ca($code, $branch), ['fridge_id' => $fridge, 'temp' => '9.4', 'low' => '5.0', 'high' => '9.8', 'reason' => 'door_open', 'idempotency_key' => $this->k()]);
            $fridges->review($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), $warm, ['action' => 'rechecked_ok', 'idempotency_key' => $this->k()]);
            $this->count('fridge_readings', 2);
        }
    }

    /** A monthly audit at each branch: one item failed, so a task is raised; at CHERAS the task is done and verified. */
    private function monthlyAudit(): void
    {
        if (DB::table('compliance_audits')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        app(ChecklistService::class)->saveChecklist($this->context->as('administrator', $this->context->mainBranch()), ['items' => [
            ['section' => 'Front desk', 'title' => 'Fire extinguisher in date', 'help' => null, 'photo_required' => false],
            ['section' => 'Front desk', 'title' => 'First aid box stocked', 'help' => null, 'photo_required' => false],
            ['section' => 'Dispensary', 'title' => 'Sharps bin below the fill line', 'help' => null, 'photo_required' => false],
        ], 'idempotency_key' => $this->k()]);
        $audits = app(AuditService::class);
        foreach ($this->context->branches as $code => $branch) {
            $supervisor = fn (): User => $this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch);
            $audit = $audits->start($supervisor(), ['branch_id' => $branch->id, 'month' => 'current', 'idempotency_key' => $this->k()]);
            $row = DB::table('compliance_audits')->where('public_id', $audit)->first();
            $items = DB::table('compliance_checklist_items')->where('version_id', $row->checklist_version_id)->orderBy('position')->pluck('public_id')->all();
            $audits->saveAnswers($supervisor(), $audit, ['expected_lock_version' => (int) $row->lock_version, 'idempotency_key' => $this->k(), 'answers' => array_map(fn (string $item, int $i): array => [
                'item_id' => $item, 'result' => $i === 1 ? 'fail' : 'pass', 'note' => $i === 1 ? 'Fictional: plasters running low' : null,
            ], $items, array_keys($items))]);
            $audits->submit($supervisor(), $audit, ['expected_lock_version' => (int) DB::table('compliance_audits')->where('public_id', $audit)->value('lock_version'), 'idempotency_key' => $this->k()]);
            $this->count('audits');
            if ($code === TrialDataContext::MAIN_BRANCH) {
                $task = DB::table('compliance_tasks')->where('audit_id', $row->id)->first();
                if ($task !== null) {
                    $tasks = app(TaskService::class);
                    $tasks->markDone($supervisor(), $task->public_id, ['expected_lock_version' => (int) $task->lock_version, 'note' => 'Fictional: box restocked', 'idempotency_key' => $this->k()]);
                    $tasks->verify($this->context->as(TrialDataContext::key($code, 'supervisor2'), $branch), $task->public_id, [
                        'expected_lock_version' => (int) DB::table('compliance_tasks')->where('id', $task->id)->value('lock_version'), 'idempotency_key' => $this->k(),
                    ]);
                }
            }
        }
    }

    // --- website drafts --------------------------------------------------------------------------------------------

    /** Text-only English and Bahasa Melayu drafts: every branch profile, a doctor, a career, a service, a promotion, an activity, an FAQ and Little Shield. */
    private function websiteDrafts(): void
    {
        $main = $this->context->mainBranch();
        if (DB::table('website_content_items')->where('organisation_id', $this->org())->where('type', 'public_service')->exists()) {
            return;
        }
        foreach ($this->context->branches as $code => $branch) {
            $name = ucwords(str_replace('_', ' ', strtolower($code)));
            foreach (['en' => 'Klinik Putrijaya '.$name.' (fictional draft)', 'bm' => 'Klinik Putrijaya '.$name.' (draf rekaan)'] as $language => $display) {
                app(WebsiteDraftService::class)->save($this->context->as('marketing', $branch), [
                    'expected_branch_id' => $branch->id,
                    'lock_version' => (int) DB::table('website_content_items')->where('branch_id', $branch->id)->where('type', 'branch_public_profile')->value('lock_version'),
                    'idempotency_key' => $this->k(), 'language' => $language, 'hero_asset_public_id' => null,
                    'content' => [...WebsiteProfileFields::empty(), 'display_name' => $display, 'address_line_1' => 'Fictional address, '.$name, 'postcode' => '00000',
                        'city' => $name, 'state' => 'Fictional state', 'public_phone' => '+60300000000',
                        'opening_hours' => array_fill_keys(WebsiteProfileFields::DAYS, ['closed' => false, 'open' => '08:00', 'close' => '22:00'])],
                ]);
            }
            $this->count('website_branch_profiles');
        }
        $branchIds = $this->context->branches->map(fn (Branch $b): int => $b->id)->values()->all();

        $doctor = null;
        foreach (['en' => 'Medical officer', 'bm' => 'Pegawai perubatan'] as $language => $title) {
            $doctor = app(WebsitePeopleService::class)->mutate($this->context->as('hr', $main), 'doctor_profile', $doctor, 'save', [
                'expected_branch_id' => $main->id, 'lock_version' => $this->itemLock($doctor), 'idempotency_key' => $this->k(), 'language' => $language,
                'branch_ids' => $branchIds, 'portrait_public_id' => null,
                'content' => [...WebsitePeopleFields::empty('doctor_profile'), 'display_name' => 'Dr Trial Doctor (fictional)', 'professional_title' => $title,
                    'biography' => $language === 'en' ? 'A fictional doctor profile for the trial website.' : 'Profil doktor rekaan untuk laman web percubaan.', 'include_on_website' => true, 'sort_order' => 1,
                    'qualifications' => ['MBBS (fictional)'], 'areas_of_practice' => [$language === 'en' ? 'General practice' : 'Amalan am'],
                    'languages_spoken' => ['English', 'Bahasa Melayu'], 'profile_consent_reference' => 'TRIAL-CONSENT-1'],
            ]);
        }
        $career = null;
        foreach (['en' => 'Clinic Assistant (fictional)', 'bm' => 'Pembantu Klinik (rekaan)'] as $language => $title) {
            $career = app(WebsitePeopleService::class)->mutate($this->context->as('hr', $main), 'career_posting', $career, 'save', [
                'expected_branch_id' => $main->id, 'lock_version' => $this->itemLock($career), 'idempotency_key' => $this->k(), 'language' => $language,
                'branch_ids' => [$main->id], 'portrait_public_id' => null,
                'content' => [...WebsitePeopleFields::empty('career_posting'), 'title' => $title, 'summary' => $language === 'en' ? 'A fictional opening.' : 'Jawatan rekaan.',
                    'apply_email' => 'careers@'.TrialDataContext::EMAIL_DOMAIN, 'employment_type' => 'full_time', 'open' => true, 'sort_order' => 1,
                    'location_note' => 'Cheras (fictional)', 'responsibilities' => [$language === 'en' ? 'Register patients' : 'Mendaftar pesakit'],
                    'requirements' => [$language === 'en' ? 'SPM or equivalent' : 'SPM atau setaraf']],
            ]);
        }
        $this->count('website_people', 2);

        $category = null;
        foreach (['en' => 'General care', 'bm' => 'Rawatan am'] as $language => $name) {
            $category = app(WebsiteServicesService::class)->mutate($this->context->as('marketing', $main), 'service_category', $category, 'save', [
                'expected_branch_id' => $main->id, 'lock_version' => $this->itemLock($category), 'idempotency_key' => $this->k(), 'language' => $language, 'branch_ids' => [],
                'content' => [...WebsiteServiceFields::empty('service_category'), 'name' => $name, 'short_description' => $language === 'en' ? 'Everyday care (fictional).' : 'Rawatan harian (rekaan).', 'include_on_website' => true, 'sort_order' => 1],
            ]);
        }
        $service = null;
        foreach (['en' => 'Wound dressing', 'bm' => 'Balutan luka'] as $language => $name) {
            $service = app(WebsiteServicesService::class)->mutate($this->context->as('marketing', $main), 'public_service', $service, 'save', [
                'expected_branch_id' => $main->id, 'lock_version' => $this->itemLock($service), 'idempotency_key' => $this->k(), 'language' => $language, 'branch_ids' => $branchIds,
                'content' => [...WebsiteServiceFields::empty('public_service'), 'name' => $name, 'short_summary' => $language === 'en' ? 'Clean and dress a wound (fictional).' : 'Membersih dan membalut luka (rekaan).',
                    'seo_title' => $name, 'seo_description' => $name.' (fictional trial)', 'parent_public_id' => $category, 'include_on_website' => true,
                    'description_sections' => [['heading' => $language === 'en' ? 'About' : 'Tentang', 'body' => $language === 'en' ? 'Fictional trial description.' : 'Penerangan percubaan rekaan.']]],
            ]);
        }
        $this->count('website_services', 2);

        $today = $this->today($main);
        foreach ([
            'promotion' => ['en' => ['title' => 'Fictional health screening offer', 'short_summary' => 'A fictional promotion.'], 'bm' => ['title' => 'Tawaran saringan kesihatan rekaan', 'short_summary' => 'Promosi rekaan.'], 'branches' => $branchIds,
                'extra' => ['valid_from' => $today->toDateString(), 'valid_until' => $today->addMonth()->toDateString()]],
            'activity' => ['en' => ['title' => 'Fictional community health talk', 'short_summary' => 'A fictional activity.'], 'bm' => ['title' => 'Ceramah kesihatan komuniti rekaan', 'short_summary' => 'Aktiviti rekaan.'], 'branches' => [$main->id],
                'extra' => ['starts_on' => $today->addWeek()->toDateString(), 'ends_on' => $today->addWeek()->toDateString()]],
            'faq' => ['en' => ['question' => 'Do I need an appointment? (fictional)', 'answer' => 'No. Walk-ins are welcome (fictional answer).'], 'bm' => ['question' => 'Perlukah saya buat temujanji? (rekaan)', 'answer' => 'Tidak. Pesakit tanpa temujanji dialu-alukan (jawapan rekaan).'], 'branches' => [],
                'extra' => ['linked_service_public_id' => $service]],
        ] as $type => $definition) {
            $item = null;
            foreach (['en', 'bm'] as $language) {
                $item = app(WebsiteEditorialService::class)->mutate($this->context->as('marketing', $main), $type, $item, 'save', [
                    'expected_branch_id' => $main->id, 'lock_version' => $this->itemLock($item), 'idempotency_key' => $this->k(), 'language' => $language, 'branch_ids' => $definition['branches'],
                    'content' => [...WebsiteEditorialFields::empty($type), ...$definition[$language], ...$definition['extra']],
                ]);
            }
            $this->count('website_editorial');
        }

        app(WebsiteLittleShieldService::class)->save($this->context->as('marketing', $main), [
            'expected_branch_id' => $main->id, 'item_public_id' => null, 'lock_version' => 0, 'idempotency_key' => $this->k(), 'language' => 'en', 'branch_ids' => [],
            'content' => [...WebsiteLittleShieldFields::empty(), 'title' => 'Little Shield (fictional draft)', 'tagline' => 'A fictional programme page', 'introduction' => 'Fictional trial text.', 'include_on_website' => false],
        ]);
        $this->count('website_little_shield');
    }

    /** A private release freeze prepared by Marketing and approved by the Director. Nothing is published. */
    private function websiteRelease(): void
    {
        if (DB::table('website_releases')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $main = $this->context->mainBranch();
        $releases = app(WebsiteReleaseService::class);
        $id = $releases->mutate($this->context->as('marketing', $main), 'prepare', null, ['expected_branch_id' => $main->id, 'lock_version' => 0, 'idempotency_key' => $this->k()]);
        $release = DB::table('website_releases')->where('id', $id)->first();
        $releases->mutate($this->context->asDirector($main), 'approve', $id, [
            'expected_branch_id' => $main->id, 'lock_version' => (int) DB::table('website_release_controls')->where('release_id', $id)->value('lock_version'),
            'manifest_hash' => $release->manifest_hash, 'confirmation' => $release->number, 'idempotency_key' => $this->k(),
        ]);
        $this->count('website_releases');
    }

    // --- triage, QR links, merges ----------------------------------------------------------------------------------

    /** A fictional triage guideline entered by the HR Manager and approved by the Director (approver differs). */
    private function triageGuideline(): void
    {
        $service = app(TriageGuidelineService::class);
        if ($service->inForce($this->org()) !== null) {
            return;
        }
        $main = $this->context->mainBranch();
        $version = $service->enter($this->context->as('hr', $main), [
            'child_age_limit_years' => 12,
            'approving_doctor_name' => self::GUIDELINE_SIGNER,
            'doctor_signed_on' => $this->today($main)->subDay()->toDateString(),
            'red_flags' => ['Fictional red flag: severe breathing difficulty', 'Fictional red flag: chest pain with sweating'],
            'yellow_signs' => ['Fictional yellow sign: vomiting many times today'],
            'thresholds' => [
                ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '38.00', 'zone' => 'yellow'],
                ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '39.50', 'zone' => 'red'],
                ['reading' => 'temperature_celsius', 'age_band' => 'child', 'direction' => 'very_high', 'limit_value' => '38.50', 'zone' => 'red'],
                ['reading' => 'spo2_percent', 'age_band' => 'adult', 'direction' => 'very_low', 'limit_value' => '92', 'zone' => 'red'],
                ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'yellow'],
            ],
            'idempotency_key' => $this->k(),
        ]);
        $service->approve($this->context->asDirector($main), $version, ['lock_version' => (int) $version->refresh()->lock_version, 'matches_signed_copy' => true, 'idempotency_key' => $this->k()]);
        $this->count('triage_guidelines');
    }

    /** A QR check-in link at every branch, issued by its first CA Supervisor; one QR intake only when intake is on. */
    private function checkInLinks(): void
    {
        foreach ($this->context->branches as $code => $branch) {
            if (PublicCheckInLink::query()->where('branch_id', $branch->id)->where('is_active', true)->exists()) {
                continue;
            }
            $issued = app(PublicCheckInLinkService::class)->issue($this->context->as(TrialDataContext::key($code, 'supervisor1'), $branch), $branch, 'Front desk QR (trial)');
            $this->count('check_in_links');
            if ($code === TrialDataContext::MAIN_BRANCH && config('public-intake.enabled') === true) {
                $intake = app(PublicPatientIntakeService::class);
                $session = $intake->openSession($issued['link'])['session'];
                $intake->submit($session, [
                    'submission_type' => 'patient', 'full_name' => 'Trial QR Patient (fictional)', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown',
                    'nationality_code' => 'MY', 'mobile_phone' => '+60123456789', 'phone_country' => 'MY', 'identifier_type' => 'passport',
                    'identifier_value' => 'TRIALQR'.strtoupper(Str::random(8)), 'identifier_issuing_country_code' => 'MY', 'visit_purpose' => 'doctor_illness',
                    'chief_complaint' => 'Fictional fever since yesterday', 'complaint_duration' => 'One day', 'coverage_type' => 'self_pay', 'panel_id' => null,
                    'coverage_member_reference' => null, 'guardian_name' => null, 'guardian_relationship' => null, 'guardian_contact_number' => null,
                    'guardian_attestation' => false, 'consent_confirmed' => true, 'privacy_notice_version' => config('public-intake.privacy_notice_version'),
                ], true);
                $this->count('qr_intakes');
            }
        }
    }

    /** Two fictional duplicate registrations at CHERAS: one merge approved by a supervisor, one left waiting. */
    private function patientMerges(): void
    {
        if (DB::table('patient_merge_requests')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $main = $this->context->mainBranch();
        $merges = app(PatientMergeService::class);
        // Patients whose phase 2 visit is completed: a merge is refused while either record has an unfinished visit.
        foreach ([1 => true, 2 => false] as $position => $approve) {
            $kept = Patient::query()->where('organisation_id', $this->org())->where('full_name', sprintf('Trial Patient %02d', $position))->firstOrFail();
            $duplicate = app(PatientAdministrationService::class)->create($this->ca(TrialDataContext::MAIN_BRANCH, $main), [
                'full_name' => $kept->full_name.' (registered twice)', 'date_of_birth' => $kept->date_of_birth?->toDateString(), 'sex' => $kept->sex, 'nationality_code' => 'MY',
                'mobile_phone' => '+60123456789', 'phone_country' => 'MY', 'country_code' => 'MY',
                'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => sprintf('TRIALDUP%04d', $position)]],
                'duplicate_override' => true, 'expected_branch_id' => $main->id,
            ]);
            $request = $merges->propose($this->ca(TrialDataContext::MAIN_BRANCH, $main), $kept->refresh(), [
                'kind' => 'merge', 'other_patient_number' => $duplicate->patient_number, 'reason_code' => 'same_person_registered_twice', 'expected_branch_id' => $main->id,
                'kept_lock_version' => (int) $kept->refresh()->lock_version, 'other_lock_version' => (int) $duplicate->refresh()->lock_version, 'idempotency_key' => $this->k(),
            ]);
            if ($approve) {
                $merges->approve($this->context->as(TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'supervisor1'), $main), $request, [
                    'expected_branch_id' => $main->id, 'lock_version' => (int) $request->refresh()->lock_version,
                ]);
            }
            $this->count('patient_merges');
        }
    }

    // --- helpers ---------------------------------------------------------------------------------------------------

    private function itemLock(?string $publicId): int
    {
        return $publicId === null ? 0 : (int) DB::table('website_content_items')->where('public_id', $publicId)->value('lock_version');
    }

    private function org(): int
    {
        return $this->context->organisation->id;
    }

    private function k(): string
    {
        return (string) Str::uuid();
    }
}

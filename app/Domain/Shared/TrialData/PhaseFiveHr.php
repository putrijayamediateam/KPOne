<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Domain\HR\Appraisals\Services\AppraisalService;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Attendance\Services\ClockService;
use App\Domain\HR\Attendance\Services\CorrectionService;
use App\Domain\HR\Attendance\Services\OvertimeService;
use App\Domain\HR\Attendance\Services\OvertimeSettingsService;
use App\Domain\HR\Claims\Services\ClaimService;
use App\Domain\HR\Credentials\Services\CredentialService;
use App\Domain\HR\Discipline\Services\DisciplineService;
use App\Domain\HR\Incidents\Services\IncidentService;
use App\Domain\HR\Leave\Services\LeaveDefaultsService;
use App\Domain\HR\Leave\Services\LeaveRequestService;
use App\Domain\HR\Leave\Services\LeaveSettingsService;
use App\Domain\HR\Letters\Services\LetterService;
use App\Domain\HR\LocumPay\Services\LocumPayService;
use App\Domain\HR\Memos\Services\MemoService;
use App\Domain\HR\OpenShifts\Services\OpenShiftService;
use App\Domain\HR\Payroll\Services\PayrollExportService;
use App\Domain\HR\Payroll\Services\PayrollRunService;
use App\Domain\HR\Payroll\Services\PayrollSetupService;
use App\Domain\HR\Records\Services\BankDetailService;
use App\Domain\HR\Records\Services\ChecklistService;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\Services\MinimumStaffingService;
use App\Domain\HR\Roster\Services\RosterService;
use App\Domain\HR\Sops\Services\SopService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL-DATA phase 5: KPOne HR, through the HR services. With one Director and one HR Manager the HR Manager enters,
 * prepares and sends, and the Director approves, checks, confirms and locks; nobody acts on the Director's own file,
 * and the Director's and HR Manager's own pay stays outside KPOne. Payroll uses FICTIONAL statutory tables with
 * obviously round numbers (owner decision, 21 Nov 2026). Each step runs in one transaction and is skipped when its
 * own result already exists, so a rerun adds nothing.
 */
final class PhaseFiveHr
{
    use ClinicFlow;

    /** Every statutory table carries this in its name and notes (owner decision). */
    public const FICTIONAL = 'FICTIONAL — NOT OFFICIAL RATES — TRIAL ONLY';

    /** Branch roles paid through KPOne payroll in the trial (plus the Finance Officer). */
    public const PAYROLL_ROLES = ['doctor1', 'doctor2', 'ca1', 'ca2', 'supervisor1', 'supervisor2'];

    /** Fictional, obviously round statutory tables: scheme => [category, employee %, employer %]. */
    public const TABLES = [
        'epf' => ['FICTIONAL EPF - trial staff', '10', '12'],
        'socso' => ['FICTIONAL SOCSO - trial staff', '1', '2'],
        'eis' => ['FICTIONAL EIS - trial staff', '0.5', '0.5'],
        'hrd' => ['FICTIONAL HRD - trial staff', '0', '2'],
    ];

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
            foreach (['staffFiles', 'bankAndDocuments', 'leave', 'roster', 'attendance', 'credentials', 'sopsAndMemos', 'letters',
                'appraisal', 'incidentAndDiscipline', 'shiftsAndLocums', 'claims', 'jobPositions', 'payroll'] as $step) {
                DB::transaction(fn () => $this->{$step}());
            }
        } finally {
            $this->files->cleanUp();
        }

        return $this->created;
    }

    // --- staff files -----------------------------------------------------------------------------------------------

    /** A staff file, employment period and position for every fictional account except the TV screens. */
    private function staffFiles(): void
    {
        $files = app(StaffFileService::class);
        $start = $this->day()->subYears(2)->startOfYear()->toDateString();
        foreach (TrialDataContext::accounts() as $key => $account) {
            if ($account['role'] === 'queue_display' || $this->worker($key) !== null) {
                continue;
            }
            // The HR Manager's own file is opened by the Director (nobody manages their own file).
            $actor = $key === 'hr' ? $this->director() : $this->hr();
            $worker = $files->openFile($actor, ['user_id' => $this->user($key)->id, 'idempotency_key' => $this->k()]);
            $files->addPeriod($key === 'hr' ? $this->director() : $this->hr(), $worker->public_id, ['type' => 'permanent', 'starts_on' => $start, 'idempotency_key' => $this->k()]);
            $files->addPosition($key === 'hr' ? $this->director() : $this->hr(), $worker->public_id, ['title' => $account['title'], 'from_date' => $start, 'idempotency_key' => $this->k()]);
            $this->count('staff_files');
        }
    }

    /** Bank details confirmed for two people and one waiting for a second check; a contract PDF; a checklist. */
    private function bankAndDocuments(): void
    {
        if (DB::table('hr_bank_details')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $banks = app(BankDetailService::class);
        foreach (['ca1', 'ca2', 'supervisor1'] as $index => $role) {
            $key = TrialDataContext::key(TrialDataContext::MAIN_BRANCH, $role);
            $worker = $this->workerId($key);
            $bank = $banks->enter($this->hr(), $worker, ['bank_name' => 'Trial Bank Berhad (fictional)', 'holder' => $this->user($key)->name, 'account' => sprintf('90000000%02d', $index + 1), 'idempotency_key' => $this->k()]);
            $this->count('bank_details');
            if ($role === 'supervisor1') {
                continue; // left waiting for a second check
            }
            app(StaffFileService::class)->show($this->director(), $worker, ['field' => 'bank_account', 'bank_id' => $bank->public_id]);
            $banks->decide($this->director(), $worker, $bank->public_id, ['decision' => 'confirm', 'expected_lock_version' => (int) DB::table('hr_bank_details')->where('public_id', $bank->public_id)->value('lock_version'), 'idempotency_key' => $this->k()]);
        }

        $ca = TrialDataContext::key(TrialDataContext::MAIN_BRANCH, 'ca1');
        app(DocumentService::class)->upload($this->hr(), $this->workerId($ca), DocumentService::prepare($this->files->pdf('Employment contract', 'Fictional staff member '.$this->user($ca)->name)), [
            'category' => 'contract', 'issued_on' => $this->day()->subYear()->toDateString(), 'idempotency_key' => $this->k(),
        ]);
        $this->count('documents');

        $checklist = app(ChecklistService::class);
        $first = $checklist->addItem($this->hr(), ['list' => 'onboarding', 'label' => 'Sign the employment contract', 'about_account' => false, 'idempotency_key' => $this->k()]);
        $checklist->addItem($this->hr(), ['list' => 'onboarding', 'label' => 'Read the hand hygiene SOP', 'about_account' => false, 'idempotency_key' => $this->k()]);
        $period = DB::table('hr_employment_periods')->where('worker_id', DB::table('hr_workers')->where('public_id', $this->workerId($ca))->value('id'))->value('public_id');
        $checklist->tick($this->hr(), $this->workerId($ca), (string) $period, $first->public_id, ['idempotency_key' => $this->k()]);
        $this->count('checklist_items', 2);
    }

    // --- leave and roster ------------------------------------------------------------------------------------------

    /** KPOne's default leave types and entitlements, working days and two holidays, a grant, and four requests. */
    private function leave(): void
    {
        if (DB::table('hr_leave_requests')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $settings = app(LeaveSettingsService::class);
        $start = $this->day()->addDays(21)->startOfWeek(); // a Monday, so every request has working days
        foreach (array_unique([(int) $this->day()->year, (int) $start->addDays(3)->year]) as $year) {
            app(LeaveDefaultsService::class)->apply($this->hr(), ['year' => $year, 'idempotency_key' => $this->k()]);
        }
        foreach ($this->context->branches as $branch) {
            if (! DB::table('hr_branch_working_days')->where('branch_id', $branch->id)->exists()) {
                $settings->saveWorkingDays($this->hr(), ['branch_id' => $branch->id, 'weekdays' => [1, 2, 3, 4, 5, 6], 'expected_lock_version' => null]);
            }
            $settings->addHoliday($this->hr(), ['branch_id' => $branch->id, 'holiday_date' => $this->day()->year.'-12-25', 'name' => 'Christmas Day']);
        }
        foreach (array_unique([(int) $this->day()->year, (int) $start->addDays(3)->year]) as $year) {
            $settings->grant($this->hr(), ['year' => $year, 'idempotency_key' => $this->k()]);
        }
        $annual = (int) DB::table('hr_leave_types')->where('organisation_id', $this->org())->where('code', 'ANNUAL')->value('id');

        $requests = app(LeaveRequestService::class);
        $leaveLock = fn (string $id): int => (int) DB::table('hr_leave_requests')->where('public_id', $id)->value('lock_version');
        $apply = fn (string $key, int $offset, int $days) => $requests->apply($this->as($key), [
            'leave_type_id' => $annual, 'start_date' => $start->addDays($offset)->toDateString(), 'end_date' => $start->addDays($offset + $days - 1)->toDateString(),
            'reason' => 'Fictional family trip', 'idempotency_key' => $this->k(),
        ]);
        $apply(TrialDataContext::key('CHERAS', 'ca2'), 0, 2); // left pending
        $approved = $apply(TrialDataContext::key('CHERAS', 'doctor1'), 0, 1);
        $requests->approve($this->hr(), $approved->public_id, ['expected_lock_version' => $leaveLock($approved->public_id), 'idempotency_key' => $this->k()]);
        $rejected = $apply(TrialDataContext::key('SUNGAI_BESI', 'ca1'), 1, 1);
        $requests->reject($this->hr(), $rejected->public_id, ['reason' => 'not_enough_cover', 'expected_lock_version' => $leaveLock($rejected->public_id), 'idempotency_key' => $this->k()]);
        $cancelled = $apply(TrialDataContext::key('PUCHONG', 'ca1'), 2, 1);
        $cancelled = $requests->approve($this->hr(), $cancelled->public_id, ['expected_lock_version' => $leaveLock($cancelled->public_id), 'idempotency_key' => $this->k()]);
        $requests->cancel($this->as(TrialDataContext::key('PUCHONG', 'ca1')), $cancelled->public_id, ['expected_lock_version' => $leaveLock($cancelled->public_id), 'idempotency_key' => $this->k()]);
        $this->count('leave_requests', 4);
    }

    /** At each branch: minimum staffing, next week's roster approved by the Director, and the week after in draft. */
    private function roster(): void
    {
        $roster = app(RosterService::class);
        foreach ($this->context->branches as $code => $branch) {
            if (DB::table('hr_roster_weeks')->where('branch_id', $branch->id)->exists()) {
                continue;
            }
            app(MinimumStaffingService::class)->save($this->hr(), ['branch_id' => $branch->id, 'minimums' => ['morning' => ['ca' => 1, 'resident_doctor' => 1]],
                'expected_lock_version' => app(MinimumStaffingService::class)->lockVersion($branch)]);
            foreach ([7 => true, 14 => false] as $offset => $approve) {
                $monday = $this->day()->addDays($offset)->startOfWeek();
                $version = $roster->startDraft($this->hr(), ['branch_id' => $branch->id, 'week' => $monday->toDateString(), 'mode' => 'blank', 'idempotency_key' => $this->k()])['version'];
                $shifts = $approve
                    ? [['doctor1', 0, '08:00', '14:00', 'morning'], ['ca1', 0, '08:00', '14:00', 'morning'], ['doctor2', 0, '14:00', '20:00', 'afternoon'], ['ca2', 0, '14:00', '20:00', 'afternoon'], ['supervisor1', 1, '09:00', '17:00', 'full_day']]
                    : [['doctor1', 0, '08:00', '14:00', 'morning']];
                foreach ($shifts as [$role, $day, $from, $to, $session]) {
                    $roster->addShift($this->hr(), $version->public_id, [
                        'staff_user_id' => $this->user(TrialDataContext::key($code, $role))->id, 'date' => $monday->addDays($day)->toDateString(),
                        'start_time' => $from, 'end_time' => $to, 'break_minutes' => 30, 'session_type' => $session, 'branch_room_id' => null,
                        'expected_version_lock' => (int) $version->refresh()->lock_version, 'idempotency_key' => $this->k(),
                    ]);
                }
                if ($approve) {
                    $roster->submit($this->hr(), $version->public_id, ['expected_version_lock' => (int) $version->refresh()->lock_version, 'idempotency_key' => $this->k()]);
                    $roster->approve($this->director($branch), $version->public_id, ['expected_version_lock' => (int) $version->refresh()->lock_version, 'idempotency_key' => $this->k()]);
                }
                $this->count('roster_weeks');
            }
        }
    }

    // --- attendance ------------------------------------------------------------------------------------------------

    /**
     * Attendance and overtime settings from today; the first CA at each branch clocked in now (with a placeholder
     * photo); a past session added for the second CHERAS CA by an approved correction, with overtime approved; and a
     * correction the second SUNGAI_BESI CA asked for, left waiting.
     */
    private function attendance(): void
    {
        if (DB::table('hr_attendance_settings')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        app(AttendanceSettingsService::class)->save($this->hr(), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7, 'effective_from' => null, 'idempotency_key' => $this->k()]);
        app(OvertimeSettingsService::class)->save($this->hr(), ['normal_multiplier' => '1.5', 'rest_day_multiplier' => '2', 'public_holiday_multiplier' => '3',
            'nearing_hours' => 40, 'limit_hours' => 45, 'roles' => ['ca', 'resident_doctor', 'ca_supervisor'], 'effective_from' => null, 'idempotency_key' => $this->k()]);

        $photos = app(ClockPhotoService::class);
        foreach ($this->context->branches as $code => $branch) {
            $key = TrialDataContext::key($code, 'ca1');
            $photos->markNoticeSeen($this->as($key, $branch), ClockPhotoService::NOTICE_VERSION);
            $token = $photos->issueToken($this->as($key, $branch), 'in')['token'];
            app(ClockService::class)->clockIn($this->as($key, $branch), ['idempotency_key' => $this->k(), 'photo_prepared' => $this->clockPhoto($key), 'capture_token' => $token]);
            $this->count('clock_ins');
        }

        // Earlier today, ending an hour ago: a whole session the CA forgot to press, added by HR and approved by the Director.
        $cheras = $this->context->mainBranch();
        [$in, $out] = $this->earlierToday($cheras, 4, 1);
        $subject = TrialDataContext::key('CHERAS', 'ca2');
        $corrections = app(CorrectionService::class);
        $added = $corrections->propose($this->hr($cheras), ['kind' => 'add_day', 'user_id' => $this->user($subject)->id, 'on_behalf' => true, 'branch_id' => $cheras->id,
            'in_time' => $in, 'out_time' => $out, 'reason_code' => 'forgot', 'note' => 'Fictional trial correction', 'idempotency_key' => $this->k()]);
        $added = $corrections->decide($this->director($cheras), $added->public_id, 'approve', ['expected_lock_version' => (int) DB::table('hr_attendance_corrections')->where('public_id', $added->public_id)->value('lock_version'), 'idempotency_key' => $this->k()]);
        $session = DB::table('hr_clock_sessions')->where('id', $added->created_session_id)->value('public_id');
        $overtime = app(OvertimeService::class)->ask($this->as($subject, $cheras), ['session_id' => $session, 'minutes' => 60, 'reason_code' => 'patient_load', 'note' => null, 'idempotency_key' => $this->k()]);
        app(OvertimeService::class)->decide($this->hr($cheras), $overtime->public_id, 'approve', ['expected_lock_version' => (int) DB::table('hr_overtime_requests')->where('public_id', $overtime->public_id)->value('lock_version'), 'idempotency_key' => $this->k()]);
        $this->count('attendance_corrections');
        $this->count('overtime_requests');

        $sungaiBesi = $this->context->branches->get('SUNGAI_BESI');
        if ($sungaiBesi !== null) {
            [$in, $out] = $this->earlierToday($sungaiBesi, 3, 1);
            $corrections->propose($this->as(TrialDataContext::key('SUNGAI_BESI', 'ca2'), $sungaiBesi), ['kind' => 'add_day', 'on_behalf' => false, 'branch_id' => $sungaiBesi->id,
                'in_time' => $in, 'out_time' => $out, 'reason_code' => 'forgot', 'note' => null, 'idempotency_key' => $this->k()]);
            $this->count('attendance_corrections');
        }
    }

    // --- credentials, SOPs, memos, letters, appraisal --------------------------------------------------------------

    private function credentials(): void
    {
        // Only the credentials module reads its own tables (HR decision 4), so the audit trail tells whether this ran.
        if (DB::table('audit_logs')->where('organisation_id', $this->org())->where('event', 'hr.credentials.record_sent')->exists()) {
            return;
        }
        $service = app(CredentialService::class);
        $type = $service->saveType($this->hr(), ['code' => 'TRIAL-APC', 'name' => 'Annual practising certificate (fictional)', 'expires' => true, 'health_item' => false,
            'work_permit' => false, 'warn_from_days' => 60, 'roles' => ['resident_doctor'], 'idempotency_key' => $this->k()]);
        foreach (['doctor1' => true, 'doctor2' => false] as $role => $accept) {
            $record = $service->enterFor($this->hr(), $this->workerId(TrialDataContext::key('CHERAS', $role)), DocumentService::prepare($this->files->pdf('Practising certificate', 'Fictional doctor')), [
                'type_id' => $type->public_id, 'issued_on' => $this->day()->startOfYear()->toDateString(), 'expires_on' => $this->day()->endOfYear()->toDateString(),
                'issuing_body' => 'Trial Medical Council (fictional)', 'reference' => 'TRIAL-APC-'.strtoupper($role), 'idempotency_key' => $this->k(),
            ]);
            if ($accept) {
                $service->decide($this->director(), $record->public_id, ['decision' => 'accept', 'expected_lock_version' => (int) $record->lock_version, 'idempotency_key' => $this->k()]);
            }
            $this->count('credentials');
        }
    }

    private function sopsAndMemos(): void
    {
        if (DB::table('hr_sops')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $reader = TrialDataContext::key('CHERAS', 'ca1');
        $sops = app(SopService::class);
        $sops->publish($this->hr(), SopService::prepare($this->files->pdf('Hand hygiene SOP', 'Fictional procedure for the trial')), [
            'title' => 'Hand hygiene (fictional SOP)', 'roles' => ['ca', 'ca_supervisor', 'resident_doctor'], 'idempotency_key' => $this->k(),
        ]);
        $version = (string) DB::table('hr_sop_versions')->where('organisation_id', $this->org())->latest('id')->value('public_id');
        $sops->open($this->as($reader), $version);
        $sops->acknowledge($this->as($reader), $version, ['idempotency_key' => $this->k()]);
        $this->count('sops');

        $memos = app(MemoService::class);
        $memo = $memos->publish($this->hr(), null, [
            'title' => 'Fictional memo: new uniform from next month', 'body' => 'Fictional trial memo. New uniforms are ready for collection at each branch.',
            'roles' => ['ca', 'ca_supervisor'], 'branches' => $this->context->branches->map(fn (Branch $b): string => (string) $b->id)->values()->all(),
            'open_until' => null, 'replaces' => null, 'idempotency_key' => $this->k(),
        ]);
        $memos->openBody($this->as($reader), $memo);
        $memos->acknowledge($this->as($reader), $memo, ['idempotency_key' => $this->k()]);
        $this->count('memos');
    }

    private function letters(): void
    {
        if (DB::table('hr_letters')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $letters = app(LetterService::class);
        $template = $letters->createTemplate($this->hr(), [
            'type' => 'confirmation', 'title' => 'Confirmation of appointment', 'title_bm' => 'Pengesahan pelantikan',
            'body_en' => "Dear {name},\n\nWe confirm your appointment as {position} at {branch} from {start_date}.\n\nFictional trial letter. Reference {reference}, dated {date}.",
            'body_bm' => "{name} yang dihormati,\n\nKami mengesahkan pelantikan anda sebagai {position} di {branch} mulai {start_date}.\n\nSurat percubaan rekaan. Rujukan {reference}, bertarikh {date}.",
            'idempotency_key' => $this->k(),
        ]);
        $letter = $letters->issue($this->hr(), [
            'worker_id' => $this->workerId(TrialDataContext::key('CHERAS', 'ca1')), 'template_id' => $template, 'language' => 'en', 'details' => null, 'replaces' => null,
            'expected_template_version' => (int) DB::table('hr_letter_templates')->where('public_id', $template)->value('current_version'), 'idempotency_key' => $this->k(),
        ]);
        $letters->recordGiven($this->hr(), $letter, ['given_on' => $this->day()->toDateString(), 'expected_lock_version' => (int) DB::table('hr_letters')->where('public_id', $letter)->value('lock_version'), 'idempotency_key' => $this->k()]);
        $this->count('letters');
    }

    private function appraisal(): void
    {
        if (DB::table('hr_appraisal_cycles')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $service = app(AppraisalService::class);
        $service->saveCriteria($this->hr(), ['criteria' => [['title' => 'Teamwork', 'description' => 'Works well with others'], ['title' => 'Punctuality', 'description' => '']], 'idempotency_key' => $this->k()]);
        $staff = [TrialDataContext::key('CHERAS', 'ca1'), TrialDataContext::key('CHERAS', 'ca2')];
        $cycle = $service->openCycle($this->hr(), ['name' => 'Fictional year '.$this->day()->year, 'period_start' => $this->day()->startOfYear()->toDateString(),
            'period_end' => $this->day()->endOfYear()->toDateString(), 'worker_ids' => array_map(fn (string $key): string => $this->workerId($key), $staff), 'idempotency_key' => $this->k()]);
        $cycleId = (int) DB::table('hr_appraisal_cycles')->where('public_id', $cycle)->value('id');
        $appraisal = DB::table('hr_appraisals')->where('cycle_id', $cycleId)->where('worker_id', DB::table('hr_workers')->where('public_id', $this->workerId($staff[0]))->value('id'))->first();
        $lock = fn (): int => (int) DB::table('hr_appraisals')->where('id', $appraisal->id)->value('lock_version');
        $service->saveRatings($this->hr(), $appraisal->public_id, ['ratings' => [['rating' => 4, 'comment' => 'Fictional: helpful to colleagues'], ['rating' => 5, 'comment' => '']], 'expected_lock_version' => $lock(), 'idempotency_key' => $this->k()], true);
        $service->comment($this->as($staff[0]), $appraisal->public_id, ['comment' => 'Thank you (fictional).', 'expected_lock_version' => $lock(), 'idempotency_key' => $this->k()]);
        $service->signOff($this->director(), $appraisal->public_id, ['expected_lock_version' => $lock(), 'idempotency_key' => $this->k()]);
        $this->count('appraisals', 2);
    }

    // --- incidents, discipline, shifts, claims, positions ----------------------------------------------------------

    private function incidentAndDiscipline(): void
    {
        if (DB::table('hr_incidents')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $incidents = app(IncidentService::class);
        $incident = $incidents->report($this->as(TrialDataContext::key('CHERAS', 'ca2')), [
            'branch_id' => 'CHERAS', 'occurred_on' => $this->day()->toDateString(), 'occurred_time' => '08:30', 'category' => 'workplace_safety', 'director_only' => false,
            'description' => 'Fictional trial incident: a loose shelf in the store room.', 'idempotency_key' => $this->k(),
        ]);
        $incidents->acknowledge($this->hr(), $incident, ['expected_lock_version' => 1, 'idempotency_key' => $this->k()]);
        $incidents->addAction($this->hr(), $incident, ['description' => 'Fix the shelf (fictional)', 'owner_id' => $this->user(TrialDataContext::key('CHERAS', 'supervisor1'))->id,
            'due_on' => $this->day()->addDays(7)->toDateString(), 'idempotency_key' => $this->k()]);
        $this->count('incidents');

        $discipline = app(DisciplineService::class);
        $case = $discipline->open($this->hr(), ['worker_id' => $this->workerId(TrialDataContext::key('SUNGAI_BESI', 'ca2')), 'incident_on' => $this->day()->subDays(3)->toDateString(),
            'allegation' => 'Fictional trial case: late for three morning shifts.', 'idempotency_key' => $this->k()]);
        $discipline->addNote($this->hr(), $case, ['note' => 'Fictional: spoke with the staff member.', 'idempotency_key' => $this->k()]);
        $discipline->close($this->hr(), $case, ['outcome' => 'verbal_warning', 'letter_id' => null, 'note' => 'Fictional outcome', 'expected_lock_version' => 1, 'idempotency_key' => $this->k()]);
        $this->count('discipline_cases');
    }

    private function shiftsAndLocums(): void
    {
        if (DB::table('hr_open_shifts')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $puchong = $this->context->branches->get('PUCHONG') ?? $this->context->mainBranch();
        app(OpenShiftService::class)->post($this->hr(), ['branch_id' => $puchong->id, 'date' => $this->day()->addDays(10)->toDateString(), 'start_time' => '08:00', 'end_time' => '14:00',
            'break_minutes' => '30', 'session_type' => 'morning', 'role_needed' => 'resident_doctor', 'idempotency_key' => $this->k()]);
        $this->count('open_shifts');

        $locum = app(StaffFileService::class)->addLocum($this->hr(), ['display_name' => 'Dr Trial Locum (fictional)', 'phone' => '012-345 6789', 'idempotency_key' => $this->k()]);
        app(LocumPayService::class)->enterRate($this->hr(), $locum->public_id, ['kind' => 'session', 'amount' => '300', 'effective_from' => $this->day()->startOfMonth()->toDateString(), 'idempotency_key' => $this->k()]);
        $rate = (string) DB::table('hr_locum_pay_rates')->where('worker_id', $locum->id)->value('public_id');
        app(LocumPayService::class)->confirmRate($this->director(), $rate, ['expected_lock_version' => 1, 'idempotency_key' => $this->k()]);
        $this->count('locums');
    }

    /** Staff claims in a category needing no receipt: one paid by Finance, one approved (paid by payroll), one waiting. */
    private function claims(): void
    {
        if (DB::table('hr_staff_claims')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $claims = app(ClaimService::class);
        $category = $claims->saveCategory($this->hr(), ['name' => 'Meals (fictional)', 'needs_receipt' => false, 'per_claim_limit' => '100.00', 'monthly_limit' => '300.00', 'is_active' => true, 'idempotency_key' => $this->k()]);
        $lock = fn (string $id): int => (int) DB::table('hr_staff_claims')->where('public_id', $id)->value('lock_version');
        foreach ([['CHERAS', 'ca1', 'paid'], ['CHERAS', 'ca2', 'approved'], ['SUNGAI_BESI', 'ca1', 'waiting']] as [$code, $role, $end]) {
            $branch = $this->context->branches->get($code) ?? $this->context->mainBranch();
            $id = $claims->send($this->as(TrialDataContext::key($code, $role), $branch), $branch, [], ['category_id' => $category, 'spent_on' => $this->day()->subDays(2)->toDateString(),
                'amount' => '45.60', 'purpose' => 'Fictional team lunch after a long clinic day', 'idempotency_key' => $this->k()]);
            if ($end !== 'waiting') {
                $claims->decide($this->hr(), $id, 'approve', ['note' => null, 'expected_lock_version' => $lock($id), 'idempotency_key' => $this->k()]);
            }
            if ($end === 'paid') {
                $claims->pay($this->context->as('finance', $branch), $id, ['method' => 'bank_transfer', 'paid_on' => $this->day()->toDateString(), 'reference' => 'TRIAL-CLAIM-PAY-1',
                    'expected_lock_version' => $lock($id), 'idempotency_key' => $this->k()]);
            }
            $this->count('staff_claims');
        }
    }

    private function jobPositions(): void
    {
        if (DB::table('hr_job_positions')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        foreach ([['Clinic Assistant (fictional opening)', 'CHERAS', 'ca'], ['Resident Doctor (fictional opening)', 'PUCHONG', 'resident_doctor']] as [$title, $code, $type]) {
            $branch = $this->context->branches->get($code) ?? $this->context->mainBranch();
            app(JobApplicationService::class)->addPosition($this->hr(), ['title' => $title, 'branch_id' => $branch->id, 'position_type' => $type, 'idempotency_key' => $this->k()]);
            $this->count('job_positions');
        }
    }

    // --- payroll ---------------------------------------------------------------------------------------------------

    /**
     * Payroll for last month: fictional statutory tables entered by HR and checked by the Director, pay profiles entered
     * by HR and confirmed by the Director, then a run prepared and sent by HR, approved and locked by the Director (which
     * issues payslips), and the bank and accountant files.
     */
    private function payroll(): void
    {
        if (DB::table('hr_payroll_runs')->where('organisation_id', $this->org())->exists()) {
            return;
        }
        $setup = app(PayrollSetupService::class);
        $group = $setup->saveGroup($this->hr(), ['name' => 'Trial pay group (fictional)', 'pay_day' => '28', 'is_active' => true, 'idempotency_key' => $this->k()]);
        /** @var array<string, string> $types */
        $types = [];
        foreach ([
            'Fixed allowance' => ['earnings', true, true, true],
            'Overtime' => ['earnings', false, true, true],
            'Unpaid leave' => ['deduction', true, false, false],
            'Staff claim refund' => ['refund', false, false, false],
        ] as $name => [$kind, $epf, $socso, $eis]) {
            $types[$name] = $setup->saveItemType($this->hr(), ['name' => $name, 'kind' => $kind, 'counts_epf' => $epf, 'counts_socso' => $socso, 'counts_eis' => $eis,
                'counts_mtd' => false, 'counts_hrd' => false, 'is_active' => true, 'idempotency_key' => $this->k()]);
        }
        $setup->saveRates($this->hr(), ['daily_divisor' => '26', 'hourly_divisor' => '8', 'expected_version' => 0, 'idempotency_key' => $this->k()]);
        $from = $this->day()->subYear()->startOfYear()->toDateString();
        foreach (self::TABLES as $scheme => [$category, $employee, $employer]) {
            $table = $setup->enterTable($this->hr(), ['scheme' => $scheme, 'category' => $category, 'effective_from' => $from, 'rounding' => 'nearest_sen', 'source_note' => self::FICTIONAL,
                'rows' => [['from' => '0', 'to' => '', 'employee_kind' => 'rate', 'employee' => $employee, 'employer_kind' => 'rate', 'employer' => $employer]], 'idempotency_key' => $this->k()]);
            $setup->decideTable($this->director(), $table, 'check', ['expected_lock_version' => 1, 'idempotency_key' => $this->k()]);
            $this->count('statutory_tables');
        }

        $keys = [];
        foreach (array_keys(TrialDataContext::BRANCHES) as $code) {
            foreach (self::PAYROLL_ROLES as $role) {
                $keys[] = TrialDataContext::key($code, $role);
            }
        }
        $keys[] = 'finance';
        foreach ($keys as $index => $key) {
            $doctor = str_contains($key, 'doctor');
            $profile = $setup->enterProfile($this->hr(), ['worker_id' => $this->workerId($key), 'pay_group_id' => $group, 'pay_type' => 'monthly', 'rate' => $doctor ? '8,000.00' : '3,000.00',
                'epf' => true, 'epf_category' => self::TABLES['epf'][0], 'socso' => true, 'socso_category' => self::TABLES['socso'][0], 'eis' => true, 'mtd' => false, 'hrd' => false,
                'effective_from' => $from, 'items' => [['item_type_id' => $types['Fixed allowance'], 'amount' => '100.00']], 'idempotency_key' => $this->k()]);
            $setup->decideProfile($this->director(), $profile, 'confirm', ['expected_lock_version' => 1, 'idempotency_key' => $this->k()]);
            $this->count('pay_profiles');
        }

        $runs = app(PayrollRunService::class);
        $runs->saveLineTypes($this->hr(), ['overtime_type_id' => $types['Overtime'], 'unpaid_leave_type_id' => $types['Unpaid leave'], 'claim_type_id' => $types['Staff claim refund'],
            'expected_version' => 0, 'idempotency_key' => $this->k()]);
        $run = $runs->prepare($this->hr(), ['pay_group_id' => $group, 'month' => $this->day()->subMonthNoOverflow()->format('Y-m'), 'kind' => 'regular', 'idempotency_key' => $this->k()]);
        $lock = fn (): int => (int) DB::table('hr_payroll_runs')->where('public_id', $run)->value('lock_version');
        $runs->send($this->hr(), $run, ['expected_lock_version' => $lock(), 'idempotency_key' => $this->k()]);
        $runs->decide($this->director(), $run, 'approve', ['expected_lock_version' => $lock(), 'idempotency_key' => $this->k()]);
        $runs->decide($this->director(), $run, 'lock', ['expected_lock_version' => $lock(), 'idempotency_key' => $this->k()]);
        $this->count('payroll_runs');

        $exports = app(PayrollExportService::class);
        $exports->bank($this->hr(), $run);
        $exports->accountant($this->hr(), $run);
        $this->count('payroll_exports', 2);
    }

    // --- helpers ---------------------------------------------------------------------------------------------------

    /** @return array{content: string, thumb: string, width: int, height: int, bytes: int, thumb_bytes: int, sha256: string} */
    private function clockPhoto(string $key): array
    {
        // A placeholder PNG marked FICTIONAL TRIAL DATA, unique per person, handed over already prepared (the server's
        // own photo preparation needs an image extension; the stored photo is a real, viewable PNG).
        $file = $this->files->image('Clock-in photo '.$key, 480, 360);
        $bytes = (string) file_get_contents($file->getPathname());

        return ['content' => $bytes, 'thumb' => $bytes, 'width' => 480, 'height' => 360, 'bytes' => strlen($bytes), 'thumb_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    /** @return array{string, string} branch-local Y-m-d\TH:i times today, $from hours and $to hours ago (never before midnight) */
    private function earlierToday(Branch $branch, int $from, int $to): array
    {
        $now = CarbonImmutable::now($branch->timezone)->startOfMinute();
        $midnight = $now->startOfDay();
        $in = $now->subHours($from)->max($midnight);
        $out = $now->subHours($to)->max($in->addMinutes(30))->min($now->subMinute());

        return [$in->format('Y-m-d\TH:i'), $out->format('Y-m-d\TH:i')];
    }

    /** Today in the main branch's time zone (the organisation-wide HR dates). */
    private function day(): CarbonImmutable
    {
        return $this->today($this->context->mainBranch());
    }

    private function hr(?Branch $branch = null): User
    {
        return $this->context->as('hr', $branch ?? $this->context->mainBranch());
    }

    private function director(?Branch $branch = null): User
    {
        return $this->context->asDirector($branch);
    }

    private function as(string $key, ?Branch $branch = null): User
    {
        $account = TrialDataContext::accounts()[$key];

        return $this->context->as($key, $branch ?? $this->context->branches->get($account['branches'][0]) ?? $this->context->mainBranch());
    }

    private function worker(string $key): ?string
    {
        $id = DB::table('hr_workers')->where('organisation_id', $this->org())->where('user_id', $this->user($key)->id)->value('public_id');

        return $id === null ? null : (string) $id;
    }

    private function workerId(string $key): string
    {
        return $this->worker($key) ?? throw new \LogicException("No staff file for {$key}.");
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

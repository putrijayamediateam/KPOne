<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\AgentAssistantController;
use App\Http\Controllers\AgentDailySummaryController;
use App\Http\Controllers\AgentInboxController;
use App\Http\Controllers\AgentOfficeController;
use App\Http\Controllers\AgentRegistryController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BillingApprovalLimitController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingWorkController;
use App\Http\Controllers\BranchContextController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchReportController;
use App\Http\Controllers\BusinessDetailsController;
use App\Http\Controllers\CashClosingController;
use App\Http\Controllers\CatalogueOptionController;
use App\Http\Controllers\CataloguePanelController;
use App\Http\Controllers\CatalogueSetupReferenceController;
use App\Http\Controllers\ClinicalAllergyController;
use App\Http\Controllers\ClinicalEncounterController;
use App\Http\Controllers\ClinicalProblemController;
use App\Http\Controllers\ClinicalServiceCatalogueController;
use App\Http\Controllers\ClinicComplianceController;
use App\Http\Controllers\ClinicianCredentialController;
use App\Http\Controllers\ClinicInsightsController;
use App\Http\Controllers\ClinicManagementController;
use App\Http\Controllers\ClinicPlaceholderController;
use App\Http\Controllers\ConsultationCheckoutController;
use App\Http\Controllers\ConsultationHoldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentSpaceController;
use App\Http\Controllers\DiagnosisPresetSettingsController;
use App\Http\Controllers\DispensaryController;
use App\Http\Controllers\GovernedStockController;
use App\Http\Controllers\HR\ApplicationsController as HrApplicationsController;
use App\Http\Controllers\HR\AppraisalsController as HrAppraisalsController;
use App\Http\Controllers\HR\AttendanceController as HrAttendanceController;
use App\Http\Controllers\HR\ClaimsController as HrClaimsController;
use App\Http\Controllers\HR\CredentialsController as HrCredentialsController;
use App\Http\Controllers\HR\DashboardController as HrDashboardController;
use App\Http\Controllers\HR\DisciplineController as HrDisciplineController;
use App\Http\Controllers\HR\IncidentsController as HrIncidentsController;
use App\Http\Controllers\HR\LeaveController;
use App\Http\Controllers\HR\LettersController as HrLettersController;
use App\Http\Controllers\HR\MemosController as HrMemosController;
use App\Http\Controllers\HR\MessagesController as HrMessagesController;
use App\Http\Controllers\HR\OpenShiftsController as HrOpenShiftsController;
use App\Http\Controllers\HR\OutsideExportController as HrOutsideExportController;
use App\Http\Controllers\HR\PayrollController as HrPayrollController;
use App\Http\Controllers\HR\RecordsController as HrRecordsController;
use App\Http\Controllers\HR\RosterController;
use App\Http\Controllers\HR\SopsController as HrSopsController;
use App\Http\Controllers\InventoryBatchHoldController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InventoryOperationsController;
use App\Http\Controllers\InventoryReadinessController;
use App\Http\Controllers\InventoryReferenceController;
use App\Http\Controllers\InventoryScanController;
use App\Http\Controllers\InventoryStockReviewController;
use App\Http\Controllers\InvoiceActivityController;
use App\Http\Controllers\InvoiceBillToController;
use App\Http\Controllers\InvoiceNoteController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\MedicalCertificateController;
use App\Http\Controllers\MedicineCatalogueController;
use App\Http\Controllers\MedicineOptionSettingsController;
use App\Http\Controllers\PanelClaimsController;
use App\Http\Controllers\PanelInvoicesController;
use App\Http\Controllers\PanelPaymentCorrectionsController;
use App\Http\Controllers\PanelRemittancesController;
use App\Http\Controllers\PanelSettingsController;
use App\Http\Controllers\PatientBillingReportController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientIdentifierController;
use App\Http\Controllers\PatientMergeController;
use App\Http\Controllers\PatientSearchController;
use App\Http\Controllers\PatientWhatsAppController;
use App\Http\Controllers\PayLaterCollectionController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentReconciliationController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\PublicCheckInController;
use App\Http\Controllers\PublicCheckInLinkController;
use App\Http\Controllers\PublicIntakeReviewController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\QueueDisplayController;
use App\Http\Controllers\QueueDisplaySettingsController;
use App\Http\Controllers\QueueReadinessController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationSettingsController;
use App\Http\Controllers\RoomCallController;
use App\Http\Controllers\StaffBranchAssignmentController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\StaffStatusController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\SupplierSettingsController;
use App\Http\Controllers\SupplierTermsController;
use App\Http\Controllers\TreatmentPlanCatalogueController;
use App\Http\Controllers\TreatmentPlanController;
use App\Http\Controllers\TreatmentProgressController;
use App\Http\Controllers\TreatmentUsageController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\VisitHistoryController;
use App\Http\Controllers\VisitReasonController;
use App\Http\Controllers\WebsiteArtifactBuildController;
use App\Http\Controllers\WebsiteDraftController;
use App\Http\Controllers\WebsiteEditorialController;
use App\Http\Controllers\WebsiteLittleShieldController;
use App\Http\Controllers\WebsiteOutputPlanController;
use App\Http\Controllers\WebsitePeopleController;
use App\Http\Controllers\WebsiteReleaseController;
use App\Http\Controllers\WebsiteServicesController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\PrivateWebsiteRelease;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('workspace')
    : redirect()->route('login'))->name('home');

// HR-13 public job-application form: off unless HR_APPLICATIONS_ENABLED is true (404 otherwise).
Route::get('careers/apply', [JobApplicationController::class, 'show'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-view', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.show');
Route::post('careers/apply', [JobApplicationController::class, 'submit'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-submit', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.submit');
Route::get('careers/apply/thanks', [JobApplicationController::class, 'thanks'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-view', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.thanks');

// HR-17b: the candidate's private link (HR-PLAN 19.5). Off with the form.
Route::get('careers/status', [JobApplicationController::class, 'status'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-status', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.status');
Route::post('careers/status/exchange', [JobApplicationController::class, 'exchange'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-link', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.status.exchange');
Route::post('careers/status/picks', [JobApplicationController::class, 'picks'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-link', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.status.picks');
Route::get('careers/status/documents/{document}', [JobApplicationController::class, 'document'])
    ->whereUuid('document')
    ->middleware(['same-origin.open', 'public-intake.proxy', 'throttle:hr-applications-status', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.status.document');
Route::post('careers/status/leave-pool', [JobApplicationController::class, 'leavePool'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-link', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.status.leave-pool');
Route::post('careers/status/withdraw', [JobApplicationController::class, 'withdraw'])
    ->middleware(['public-intake.proxy', 'throttle:hr-applications-link', 'sensitive.no-store:no-referrer'])
    ->name('hr-applications.status.withdraw');

Route::get('check-in', PublicCheckInController::class)
    ->middleware(['public-intake.proxy', 'throttle:public-checkin-view', 'sensitive.no-store:no-referrer'])
    ->name('public-checkin.show');
Route::post('check-in/exchange', [PublicCheckInController::class, 'exchange'])
    ->middleware(['public-intake.proxy', 'throttle:public-intake-exchange', 'sensitive.no-store:no-referrer'])
    ->name('public-intake.exchange');
Route::post('check-in/intakes', [PublicCheckInController::class, 'submit'])
    ->middleware(['public-intake.proxy', 'throttle:public-intake-submit', 'sensitive.no-store:no-referrer'])
    ->name('public-intake.submit');
Route::get('check-in/status', [PublicCheckInController::class, 'status'])
    ->middleware(['public-intake.proxy', 'throttle:public-intake-status', 'sensitive.no-store:no-referrer'])
    ->name('public-intake.status');

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('patient-contact/{context}/{key}/whatsapp', PatientWhatsAppController::class)->whereIn('context', ['patient', 'claim', 'visit'])->middleware(['sensitive.no-store:no-referrer', 'throttle:patient-whatsapp'])->name('patients.whatsapp');

    Route::get('workspace', WorkspaceController::class)->name('workspace');

    Route::get('clinician-credentials/me', [ClinicianCredentialController::class, 'own'])->middleware(['permission:staff.view.own', 'sensitive.no-store'])->name('clinician-credentials.own');
    Route::get('clinician-credentials', [ClinicianCredentialController::class, 'index'])->middleware(['permission:clinician_credentials.manage.organisation', 'sensitive.no-store'])->name('clinician-credentials.index');
    Route::post('clinician-credentials/{subject}', [ClinicianCredentialController::class, 'save'])->whereNumber('subject')->middleware(['permission:clinician_credentials.manage.organisation', 'sensitive.no-store', 'throttle:20,1'])->name('clinician-credentials.save');
    Route::get('medical-certificate-templates', [ClinicianCredentialController::class, 'templates'])->middleware(['permission:catalogue.settings.manage.organisation', 'sensitive.no-store'])->name('medical-certificate-templates.index');

    // DS-1 department spaces (the new front door; no menu link until the DS-2 switch).
    Route::get('spaces', [DepartmentSpaceController::class, 'index'])->middleware('sensitive.no-store')->name('spaces.index');
    Route::get('spaces/{space}', [DepartmentSpaceController::class, 'show'])->where('space', '[a-z_]+')->middleware('sensitive.no-store')->name('spaces.show');

    Route::get('clinic-management', ClinicManagementController::class)
        ->middleware('sensitive.no-store')->name('clinic-management.index');

    // HR-1 roster planner (B.E.R.N.I.S.S for the owner, 2026-11-05). Rules live in app/Domain/HR/Roster.
    Route::prefix('hr/roster')->controller(RosterController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.roster.view.own,hr.roster.manage.organisation,hr.roster.approve.organisation')->name('hr.roster.index');
        Route::middleware(['permission:hr.roster.manage.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('drafts', 'start')->name('hr.roster.drafts.store');
            Route::post('versions/{version}/shifts', 'addShift')->whereUuid('version')->name('hr.roster.shifts.store');
            Route::put('versions/{version}/shifts/{shift}', 'updateShift')->whereUuid(['version', 'shift'])->name('hr.roster.shifts.update');
            Route::delete('versions/{version}/shifts/{shift}', 'removeShift')->whereUuid(['version', 'shift'])->name('hr.roster.shifts.destroy');
            Route::post('versions/{version}/submit', 'submit')->whereUuid('version')->name('hr.roster.submit');
            Route::post('versions/{version}/discard', 'discard')->whereUuid('version')->name('hr.roster.discard');
            Route::put('minimum-staffing', 'minimums')->name('hr.roster.minimums');
        });
        // HR-5b compliance rules (Director, HR Manager): feed warnings on the planner, never a refusal.
        Route::middleware(['permission:hr.credentials.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::put('compliance/coverage', 'coverage')->name('hr.roster.compliance.coverage');
            Route::put('compliance/work-hours', 'workHours')->name('hr.roster.compliance.work-hours');
        });
        Route::middleware(['permission:hr.roster.approve.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('versions/{version}/approve', 'approve')->whereUuid('version')->name('hr.roster.approve');
            Route::post('versions/{version}/return', 'returnToDraft')->whereUuid('version')->name('hr.roster.return');
        });
    });

    // HR-9 HR dashboard (docs/teams/hr/HR-9-DESIGN.md). Read-only aggregates; each section needs its own existing HR permission.
    Route::get('hr/dashboard', [HrDashboardController::class, 'index'])
        ->middleware(['permission:hr.roster.manage.organisation,hr.attendance.review.organisation,hr.leave.manage.organisation', 'sensitive.no-store', 'throttle:60,1'])->name('hr.dashboard.index');

    // HR-3a attendance (owner, 2026-11-06). Rules live in app/Domain/HR/Attendance. Server time; HR-16a may keep a location result, never a position.
    Route::prefix('hr/attendance')->controller(HrAttendanceController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.attendance.clock.own,hr.attendance.review.organisation')->name('hr.attendance.index');
        Route::middleware(['permission:hr.attendance.clock.own', 'throttle:10,1'])->group(function (): void {
            Route::post('clock-in', 'clockIn')->name('hr.attendance.clock-in');
            Route::post('clock-out', 'clockOut')->name('hr.attendance.clock-out');
        });
        Route::middleware(['permission:hr.attendance.clock.own,hr.attendance.review.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('corrections', 'propose')->name('hr.attendance.corrections.store');
            Route::post('corrections/{correction}/cancel', 'cancel')->whereUuid('correction')->name('hr.attendance.corrections.cancel');
        });
        Route::middleware(['permission:hr.attendance.review.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('corrections/{correction}/{decision}', 'decide')->whereUuid('correction')->whereIn('decision', ['approve', 'reject'])->name('hr.attendance.corrections.decide');
            Route::put('settings', 'saveSettings')->name('hr.attendance.settings');
            Route::put('overtime-settings', 'saveOvertimeSettings')->name('hr.attendance.overtime-settings');
        });
        // HR-16a: the Director turns the location check on or off for one branch (HR-PLAN 22).
        Route::middleware(['permission:hr.attendance.location.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::put('location-check', 'saveLocationCheck')->name('hr.attendance.location-check');
        });
        // HR-3b overtime (owner, 2026-11-06). Minutes and day type only; no money.
        Route::middleware(['permission:hr.attendance.clock.own,hr.attendance.review.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('overtime', 'askOvertime')->name('hr.attendance.overtime.store');
            Route::post('overtime/{overtimeRequest}/withdraw', 'withdrawOvertime')->whereUuid('overtimeRequest')->name('hr.attendance.overtime.withdraw');
        });
        Route::middleware(['permission:hr.overtime.approve.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('overtime/{overtimeRequest}/reverse', 'reverseOvertime')->whereUuid('overtimeRequest')->name('hr.attendance.overtime.reverse');
            Route::post('overtime/{overtimeRequest}/{decision}', 'decideOvertime')->whereUuid('overtimeRequest')->whereIn('decision', ['approve', 'reject'])->name('hr.attendance.overtime.decide');
        });
    });

    // HR-4a staff files (owner, 2026-11-06). Rules live in app/Domain/HR/Records. Synthetic data only until the PDPA review.
    Route::prefix('hr/records')->controller(HrRecordsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.records.view.own,hr.records.view.organisation')->name('hr.records.index');
        // HR-12: a generic CSV for an outside HR product (staff list, approved leave, approved overtime), audited first, never stored.
        Route::post('outside-export/{kind}', [HrOutsideExportController::class, 'download'])->whereIn('kind', ['staff', 'leave', 'overtime'])
            ->middleware(['permission:hr.records.manage.organisation', 'throttle:reports-downloads'])->name('hr.records.outside-export');
        Route::middleware(['permission:hr.records.view.own', 'throttle:30,1'])->group(function (): void {
            Route::put('my/contact', 'saveOwnContact')->name('hr.records.my.contact');
            Route::post('my/corrections', 'askCorrection')->name('hr.records.my.corrections.store');
            Route::post('my/corrections/{correction}/withdraw', 'withdrawCorrection')->whereUuid('correction')->name('hr.records.my.corrections.withdraw');
        });
        // HR-4b: your own documents with view.own, anyone's with documents.view; each open is recorded first.
        Route::get('{worker}/documents/{document}', 'openDocument')->whereUuid(['worker', 'document'])
            ->middleware(['same-origin.open', 'permission:hr.records.view.own,hr.documents.view.organisation', 'throttle:60,1'])->name('hr.records.documents.show');
        Route::get('{worker}', 'show')->whereUuid('worker')->middleware('permission:hr.records.view.organisation')->name('hr.records.show');
        Route::post('{worker}/show-value', 'showValue')->whereUuid('worker')->middleware(['permission:hr.records.view.organisation', 'throttle:30,1'])->name('hr.records.show-value');
        Route::middleware(['permission:hr.records.manage.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('files', 'openFile')->name('hr.records.files.store');
            Route::post('locums', 'addLocum')->name('hr.records.locums.store');
            // HR-15a: HR asks for a locum's sign-in; the account administrator creates and links it from the Staff page.
            Route::post('{worker}/sign-in', 'askSignIn')->whereUuid('worker')->name('hr.records.sign-in.store');
            Route::post('{worker}/sign-in/{signIn}/cancel', 'cancelSignIn')->whereUuid(['worker', 'signIn'])->name('hr.records.sign-in.cancel');
            Route::post('{worker}/periods', 'addPeriod')->whereUuid('worker')->name('hr.records.periods.store');
            Route::put('{worker}/periods/{period}', 'updatePeriod')->whereUuid(['worker', 'period'])->name('hr.records.periods.update');
            Route::post('{worker}/positions', 'addPosition')->whereUuid('worker')->name('hr.records.positions.store');
            Route::put('{worker}/personal', 'savePersonal')->whereUuid('worker')->name('hr.records.personal');
            Route::post('{worker}/bank', 'enterBank')->whereUuid('worker')->name('hr.records.bank.store');
            Route::post('{worker}/bank/{bank}/withdraw', 'withdrawBank')->whereUuid(['worker', 'bank'])->name('hr.records.bank.withdraw');
            Route::post('{worker}/bank/{bank}/{decision}', 'decideBank')->whereUuid(['worker', 'bank'])->whereIn('decision', ['confirm', 'reject'])->name('hr.records.bank.decide');
            Route::post('{worker}/documents', 'uploadDocument')->whereUuid('worker')->name('hr.records.documents.store');
            Route::post('{worker}/documents/{document}/remove', 'removeDocument')->whereUuid(['worker', 'document'])->name('hr.records.documents.remove');
            Route::post('{worker}/periods/{period}/checklist/{checklistItem}', 'tick')->whereUuid(['worker', 'period', 'checklistItem'])->name('hr.records.checklist.tick');
            Route::put('settings/retention', 'saveRetention')->name('hr.records.retention');
            Route::post('settings/checklist-items', 'addChecklistItem')->name('hr.records.checklist-items.store');
            Route::post('settings/checklist-items/{checklistItem}/archive', 'archiveChecklistItem')->whereUuid('checklistItem')->name('hr.records.checklist-items.archive');
            Route::post('{worker}/corrections/{correction}/{decision}', 'decideCorrection')->whereUuid(['worker', 'correction'])->whereIn('decision', ['apply', 'reject'])->name('hr.records.corrections.decide');
        });
    });

    // HR-2a leave (B.E.R.N.I.S.S for the owner, 2026-11-05). Rules live in app/Domain/HR/Leave.
    // HR-5a credential register (owner, 2026-11-06: "approve the HR-5 note with the defaults").
    Route::prefix('hr/credentials')->controller(HrCredentialsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.credentials.view.own,hr.credentials.manage.organisation')->name('hr.credentials.index');
        Route::post('my', 'sendOwn')->middleware(['permission:hr.credentials.view.own', 'throttle:30,1'])->name('hr.credentials.my.store');
        Route::post('records/{record}/show-reference', 'showReference')->whereUuid('record')
            ->middleware(['permission:hr.credentials.view.own,hr.credentials.manage.organisation', 'throttle:30,1'])->name('hr.credentials.show-reference');
        Route::middleware(['permission:hr.credentials.manage.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('workers/{worker}', 'enterFor')->whereUuid('worker')->name('hr.credentials.workers.store');
            Route::post('records/{record}/{decision}', 'decide')->whereUuid('record')->whereIn('decision', ['accept', 'reject'])->name('hr.credentials.decide');
            Route::post('types', 'saveType')->name('hr.credentials.types.save');
            Route::post('types/{type}/archive', 'archiveType')->whereUuid('type')->name('hr.credentials.types.archive');
        });
    });

    // HR-11a clinic licences (owner, 2026-11-10: "yes, order is fine, go ahead"). Clinic data, not HR data; see HR-11-DESIGN.md.
    Route::prefix('clinic-compliance')->controller(ClinicComplianceController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:compliance.licences.manage.organisation,compliance.fridge.manage.organisation,compliance.fridge.record.branch,compliance.fridge.review.branch')->name('clinic-compliance.index');
        Route::middleware(['permission:compliance.licences.manage.organisation'])->group(function (): void {
            Route::get('files/{file}', 'openFile')->whereUuid('file')->middleware(['same-origin.open', 'throttle:30,1'])->name('clinic-compliance.files.open');
            Route::middleware('throttle:60,1')->group(function (): void {
                Route::post('types', 'saveType')->name('clinic-compliance.types.save');
                Route::post('types/{type}/archive', 'archiveType')->whereUuid('type')->name('clinic-compliance.types.archive');
                Route::post('licences', 'record')->name('clinic-compliance.licences.store');
                Route::post('versions/{version}/error', 'markInError')->whereUuid('version')->name('clinic-compliance.versions.error');
            });
        });
        // HR-11b vaccine fridge log: managing fridges is organisation-wide; readings and actions are checked at the fridge's branch in the service.
        Route::middleware(['permission:compliance.fridge.manage.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('fridges', 'saveFridge')->name('clinic-compliance.fridges.save');
            Route::post('fridges/{fridge}/archive', 'archiveFridge')->whereUuid('fridge')->name('clinic-compliance.fridges.archive');
            Route::post('readings/{reading}/error', 'readingError')->whereUuid('reading')->name('clinic-compliance.readings.error');
        });
        // HR-11c monthly audits and supervisor tasks: the checklist and settings are organisation-wide; audits and tasks are checked at their branch in the service.
        Route::middleware(['permission:compliance.checklists.manage.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('checklist', 'saveChecklist')->name('clinic-compliance.checklist.save');
            Route::post('audit-settings', 'saveAuditSettings')->name('clinic-compliance.audit-settings.save');
        });
        Route::middleware(['permission:compliance.audits.perform.branch', 'throttle:60,1'])->group(function (): void {
            Route::post('audits', 'startAudit')->name('clinic-compliance.audits.start');
            Route::post('audits/{audit}/answers', 'saveAnswers')->whereUuid('audit')->name('clinic-compliance.audits.answers');
            Route::post('audits/{audit}/submit', 'submitAudit')->whereUuid('audit')->name('clinic-compliance.audits.submit');
            Route::post('audits/{audit}/items/{item}/photos', 'addAuditPhoto')->whereUuid(['audit', 'item'])->name('clinic-compliance.audits.photos.store');
            Route::post('audits/{audit}/photos/{photo}/remove', 'removeAuditPhoto')->whereUuid(['audit', 'photo'])->name('clinic-compliance.audits.photos.remove');
            Route::post('tasks/{task}/done', 'taskDone')->whereUuid('task')->name('clinic-compliance.tasks.done');
            Route::post('tasks/{task}/verify', 'taskVerify')->whereUuid('task')->name('clinic-compliance.tasks.verify');
            Route::post('tasks/{task}/cancel', 'taskCancel')->whereUuid('task')->name('clinic-compliance.tasks.cancel');
            Route::post('tasks/{task}/photos', 'addTaskPhoto')->whereUuid('task')->name('clinic-compliance.tasks.photos.store');
        });
        Route::middleware(['permission:compliance.audits.perform.branch,compliance.checklists.manage.organisation', 'throttle:60,1'])->group(function (): void {
            Route::get('audit-photos/{photo}', 'openAuditPhoto')->whereUuid('photo')->name('clinic-compliance.audit-photos.open');
            Route::get('task-photos/{photo}', 'openTaskPhoto')->whereUuid('photo')->name('clinic-compliance.task-photos.open');
        });
        Route::post('readings', 'recordReading')->middleware(['permission:compliance.fridge.record.branch', 'throttle:60,1'])->name('clinic-compliance.readings.store');
        Route::post('readings/{reading}/review', 'reviewReading')->whereUuid('reading')->middleware(['permission:compliance.fridge.review.branch', 'throttle:60,1'])->name('clinic-compliance.readings.review');
    });

    // HR-6 staff claims (B.E.R.N.I.S.S for the owner, 2026-11-06). Not Panel Claims; KPOne pays nothing.
    Route::prefix('hr/claims')->controller(HrClaimsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.claims.submit.own,hr.claims.approve.organisation,hr.claims.pay.organisation,hr.claims.manage.organisation')->name('hr.claims.index');
        Route::get('receipts/{receipt}', 'openReceipt')->whereUuid('receipt')
            ->middleware(['same-origin.open', 'permission:hr.claims.submit.own,hr.claims.approve.organisation,hr.claims.pay.organisation', 'throttle:120,1'])->name('hr.claims.receipts.show');
        Route::middleware(['permission:hr.claims.submit.own', 'throttle:30,1'])->group(function (): void {
            Route::post('/', 'send')->name('hr.claims.send');
            Route::post('{claim}/withdraw', 'withdraw')->whereUuid('claim')->name('hr.claims.withdraw');
        });
        Route::post('{claim}/{decision}', 'decide')->whereUuid('claim')->whereIn('decision', ['approve', 'reject'])
            ->middleware(['permission:hr.claims.approve.organisation', 'throttle:60,1'])->name('hr.claims.decide');
        Route::middleware(['permission:hr.claims.pay.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('{claim}/pay', 'pay')->whereUuid('claim')->name('hr.claims.pay');
            Route::post('{claim}/reverse', 'reverse')->whereUuid('claim')->name('hr.claims.reverse');
        });
        Route::middleware(['permission:hr.claims.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('settings', 'saveSettings')->name('hr.claims.settings');
            Route::post('categories', 'saveCategory')->name('hr.claims.categories.save');
        });
    });

    // HR-7a payroll setup (HR-PLAN 14; TEAM.md decision 43). No calculation, payment or filing; synthetic data only.
    Route::prefix('hr/payroll')->controller(HrPayrollController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.payroll.prepare.organisation,hr.payroll.export.organisation')->name('hr.payroll.index');
        // HR-7c exports from locked runs (the Director only in v1); each download is audited first and never stored.
        Route::post('runs/{run}/export/{kind}', 'export')->whereUuid('run')->whereIn('kind', ['bank', 'accountant'])
            ->middleware(['permission:hr.payroll.export.organisation', 'throttle:reports-downloads'])->name('hr.payroll.runs.export');
        Route::get('runs/{run}', 'showRun')->whereUuid('run')->middleware('permission:hr.payroll.prepare.organisation')->name('hr.payroll.runs.show');
        Route::middleware(['permission:hr.payroll.prepare.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('profiles', 'enterProfile')->name('hr.payroll.profiles.enter');
            Route::post('profiles/{profile}/withdraw', 'withdrawProfile')->whereUuid('profile')->name('hr.payroll.profiles.withdraw');
            Route::post('profiles/{profile}/{decision}', 'decideProfile')->whereUuid('profile')->whereIn('decision', ['confirm', 'reject'])->name('hr.payroll.profiles.decide');
            Route::post('tables', 'enterTable')->name('hr.payroll.tables.enter');
            Route::post('tables/{table}/withdraw', 'withdrawTable')->whereUuid('table')->name('hr.payroll.tables.withdraw');
            Route::post('tables/{table}/{decision}', 'decideTable')->whereUuid('table')->whereIn('decision', ['check', 'reject'])->name('hr.payroll.tables.decide');
            Route::post('mtd-schedules', 'enterMtdSchedule')->name('hr.payroll.mtd-schedules.enter');
            Route::post('mtd-schedules/{schedule}/withdraw', 'withdrawMtdSchedule')->whereUuid('schedule')->name('hr.payroll.mtd-schedules.withdraw');
            Route::post('mtd-schedules/{schedule}/{decision}', 'decideMtdSchedule')->whereUuid('schedule')->whereIn('decision', ['check', 'reject'])->name('hr.payroll.mtd-schedules.decide');
            Route::post('groups', 'saveGroup')->name('hr.payroll.groups.save');
            Route::post('item-types', 'saveItemType')->name('hr.payroll.item-types.save');
            Route::post('rates', 'saveRates')->name('hr.payroll.rates.save');
            Route::post('line-types', 'saveLineTypes')->name('hr.payroll.line-types.save');
            Route::post('runs', 'prepareRun')->name('hr.payroll.runs.prepare');
            Route::post('runs/{run}/manual/{line}/remove', 'removeManual')->whereUuid('run')->whereUuid('line')->name('hr.payroll.runs.manual.remove');
            Route::post('runs/{run}/{step}', 'runStep')->whereUuid('run')->whereIn('step', ['again', 'mtd', 'manual', 'send', 'approve', 'return', 'lock'])->name('hr.payroll.runs.step');
        });
    });

    // HR-7c payslips: your own, or any for a payroll preparer or approver; each open is recorded.
    Route::get('hr/payslips/{payslip}', [HrPayrollController::class, 'payslip'])->whereUuid('payslip')
        ->middleware(['same-origin.open', 'sensitive.no-store', 'permission:hr.payslips.view.own,hr.payroll.prepare.organisation,hr.payroll.approve.organisation', 'throttle:60,1'])
        ->name('hr.payslips.show');

    // HR-14 staff messages (owner, 2026-11-10: "start"; email now, SMS and WhatsApp later).
    Route::prefix('hr/messages')->controller(HrMessagesController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.messages.receive.own,hr.messages.manage.organisation')->name('hr.messages.index');
        Route::post('my/{channel}', 'choose')->whereIn('channel', ['email', 'sms', 'whatsapp'])
            ->middleware(['permission:hr.messages.receive.own', 'throttle:30,1'])->name('hr.messages.choose');
        Route::post('channels/{channel}', 'configure')->whereIn('channel', ['email', 'sms', 'whatsapp'])
            ->middleware(['permission:hr.messages.manage.organisation', 'throttle:30,1'])->name('hr.messages.configure');
    });

    // HR-13 job applications (owner, 2026-11-07: "start the HR-13 build").
    Route::prefix('hr/applications')->controller(HrApplicationsController::class)
        ->middleware(['sensitive.no-store', 'permission:hr.applications.manage.organisation'])->group(function (): void {
            Route::get('/', 'index')->name('hr.applications.index');
            Route::get('{application}/cv', 'cv')->whereUuid('application')->middleware(['same-origin.open', 'throttle:60,1'])->name('hr.applications.cv');
            Route::post('{application}/show-contact', 'showContact')->whereUuid('application')->middleware('throttle:30,1')->name('hr.applications.show-contact');
            Route::get('interview-documents/{document}', 'document')->whereUuid('document')->middleware(['same-origin.open', 'throttle:60,1'])->name('hr.applications.documents.show');
            Route::middleware('throttle:30,1')->group(function (): void {
                Route::post('positions', 'addPosition')->name('hr.applications.positions.store');
                Route::post('positions/{position}', 'updatePosition')->whereUuid('position')->name('hr.applications.positions.update');
                Route::post('{application}/move', 'move')->whereUuid('application')->name('hr.applications.move');
                // HR-17a hiring stages (owner, 2026-11-10).
                Route::post('{application}/screening', 'screen')->whereUuid('application')->name('hr.applications.screen');
                Route::post('{application}/interview/confirm', 'confirmInterview')->whereUuid('application')->name('hr.applications.interview.confirm');
                Route::post('{application}/interview/ask-again', 'askAgain')->whereUuid('application')->name('hr.applications.interview.ask-again');
                Route::post('{application}/interview/cancel', 'cancelInterview')->whereUuid('application')->name('hr.applications.interview.cancel');
                Route::post('interview-documents', 'addDocument')->name('hr.applications.documents.store');
                Route::post('interview-documents/{document}/remove', 'removeDocument')->whereUuid('document')->name('hr.applications.documents.remove');
                // HR-17e talent pool and staff-account handoff.
                Route::post('pool-days', 'poolDays')->name('hr.applications.pool-days');
                Route::post('{application}/invite', 'invite')->whereUuid('application')->name('hr.applications.invite');
                Route::post('{application}/account-request', 'requestAccount')->whereUuid('application')->name('hr.applications.account-request');
                Route::post('account-requests/{accountRequest}/cancel', 'cancelAccountRequest')->whereUuid('accountRequest')->name('hr.applications.account-request.cancel');
                Route::post('checklist', 'checklist')->name('hr.applications.checklist');
                Route::post('retention', 'retention')->name('hr.applications.retention');
            });
        });

    // HR-5c SOPs (owner, 2026-11-06: "approve the HR-5 note with the defaults").
    Route::prefix('hr/sops')->controller(HrSopsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.sops.acknowledge.own,hr.sops.manage.organisation')->name('hr.sops.index');
        Route::get('versions/{version}/open', 'open')->whereUuid('version')
            ->middleware(['same-origin.open', 'permission:hr.sops.acknowledge.own,hr.sops.manage.organisation', 'throttle:60,1'])->name('hr.sops.open');
        Route::post('versions/{version}/acknowledge', 'acknowledge')->whereUuid('version')
            ->middleware(['permission:hr.sops.acknowledge.own', 'throttle:30,1'])->name('hr.sops.acknowledge');
        Route::middleware(['permission:hr.sops.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('/', 'publish')->name('hr.sops.publish');
            Route::post('{sop}/versions', 'replace')->whereUuid('sop')->name('hr.sops.replace');
            Route::post('{sop}/withdraw', 'withdraw')->whereUuid('sop')->name('hr.sops.withdraw');
        });
    });

    // HR-8 announcements and memos (owner, via B.E.R.N.I.S.S, 2026-11-10). In KPOne only; nothing is sent.
    Route::prefix('hr/memos')->controller(HrMemosController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.memos.read.own,hr.memos.publish.organisation')->name('hr.memos.index');
        Route::post('{memo}/open', 'open')->whereUuid('memo')
            ->middleware(['permission:hr.memos.read.own,hr.memos.publish.organisation', 'throttle:60,1'])->name('hr.memos.open');
        Route::get('{memo}/pdf', 'pdf')->whereUuid('memo')
            ->middleware(['same-origin.open', 'permission:hr.memos.read.own,hr.memos.publish.organisation', 'throttle:60,1'])->name('hr.memos.pdf');
        Route::post('{memo}/acknowledge', 'acknowledge')->whereUuid('memo')
            ->middleware(['permission:hr.memos.read.own', 'throttle:30,1'])->name('hr.memos.acknowledge');
        Route::middleware(['permission:hr.memos.publish.organisation', 'throttle:30,1'])->group(function (): void {
            Route::get('audience', 'audience')->name('hr.memos.audience');
            Route::post('/', 'publish')->name('hr.memos.publish');
            Route::post('{memo}/withdraw', 'withdraw')->whereUuid('memo')->name('hr.memos.withdraw');
        });
    });

    // HR-10a letters (HR-PLAN 20.3; owner, 2026-11-10). Printed and signed by hand; nothing is sent.
    Route::prefix('hr/letters')->controller(HrLettersController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.records.view.own,hr.letters.issue.organisation')->name('hr.letters.index');
        Route::get('{letter}', 'show')->whereUuid('letter')
            ->middleware(['same-origin.open', 'permission:hr.records.view.own,hr.letters.issue.organisation', 'throttle:60,1'])->name('hr.letters.show');
        Route::middleware(['permission:hr.letters.issue.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('preview', 'preview')->name('hr.letters.preview');
            Route::post('/', 'issue')->name('hr.letters.issue');
            Route::post('{letter}/void', 'void')->whereUuid('letter')->name('hr.letters.void');
            Route::post('{letter}/given', 'given')->whereUuid('letter')->name('hr.letters.given');
            Route::post('templates', 'storeTemplate')->name('hr.letters.templates.store');
            Route::post('templates/{template}/revise', 'reviseTemplate')->whereUuid('template')->name('hr.letters.templates.revise');
            Route::post('templates/{template}/retire', 'retireTemplate')->whereUuid('template')->name('hr.letters.templates.retire');
        });
    });

    // HR-10b appraisal (HR-PLAN 20.4; owner, 2026-11-10). No ranking and no total score.
    Route::prefix('hr/appraisals')->controller(HrAppraisalsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.appraisal.view.own,hr.appraisal.manage.organisation')->name('hr.appraisals.index');
        Route::get('{appraisal}', 'show')->whereUuid('appraisal')
            ->middleware(['same-origin.open', 'permission:hr.appraisal.view.own,hr.appraisal.manage.organisation', 'throttle:60,1'])->name('hr.appraisals.show');
        Route::post('{appraisal}/comment', 'comment')->whereUuid('appraisal')->middleware(['permission:hr.appraisal.view.own', 'throttle:30,1'])->name('hr.appraisals.comment');
        Route::middleware(['permission:hr.appraisal.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('criteria', 'saveCriteria')->name('hr.appraisals.criteria.store');
            Route::post('cycles', 'openCycle')->name('hr.appraisals.cycles.store');
            Route::post('cycles/{cycle}/close', 'closeCycle')->whereUuid('cycle')->name('hr.appraisals.cycles.close');
            Route::post('{appraisal}', 'save')->whereUuid('appraisal')->name('hr.appraisals.save');
            Route::post('{appraisal}/sign-off', 'signOff')->whereUuid('appraisal')->name('hr.appraisals.sign-off');
        });
    });

    // HR-10d incident reports (HR-PLAN 20.6; owner, 2026-11-10). Everyone reports; the Director and HR Manager handle.
    // HR-15b open shifts (HR-PLAN 21.4): managers post and confirm; a linked locum asks.
    Route::prefix('hr/open-shifts')->controller(HrOpenShiftsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.open_shifts.request.own,hr.open_shifts.manage.organisation')->name('hr.open-shifts.index');
        Route::middleware(['permission:hr.open_shifts.request.own', 'throttle:30,1'])->group(function (): void {
            Route::post('{shift}/ask', 'ask')->whereUuid('shift')->name('hr.open-shifts.ask');
            Route::post('asks/{ask}/withdraw', 'withdrawAsk')->whereUuid('ask')->name('hr.open-shifts.ask-withdraw');
        });
        Route::middleware(['permission:hr.open_shifts.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('/', 'store')->name('hr.open-shifts.store');
            Route::post('{shift}/withdraw', 'withdraw')->whereUuid('shift')->name('hr.open-shifts.withdraw');
            Route::post('asks/{ask}/confirm', 'confirm')->whereUuid('ask')->name('hr.open-shifts.confirm');
            Route::post('asks/{ask}/decline', 'decline')->whereUuid('ask')->name('hr.open-shifts.decline');
        });
    });

    Route::prefix('hr/incidents')->controller(HrIncidentsController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.incidents.report.own,hr.incidents.manage.organisation')->name('hr.incidents.index');
        Route::get('{incident}', 'show')->whereUuid('incident')
            ->middleware(['same-origin.open', 'permission:hr.incidents.report.own,hr.incidents.manage.organisation', 'throttle:60,1'])->name('hr.incidents.show');
        Route::get('{incident}/photos/{photo}', 'photo')->whereUuid('incident')->whereUuid('photo')
            ->middleware(['same-origin.open', 'permission:hr.incidents.report.own,hr.incidents.manage.organisation', 'throttle:60,1'])->name('hr.incidents.photos.show');
        Route::post('/', 'store')->middleware(['permission:hr.incidents.report.own', 'throttle:10,1'])->name('hr.incidents.store');
        Route::middleware(['permission:hr.incidents.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::post('{incident}/acknowledge', 'acknowledge')->whereUuid('incident')->name('hr.incidents.acknowledge');
            Route::post('{incident}/notes', 'note')->whereUuid('incident')->name('hr.incidents.note');
            Route::post('{incident}/actions', 'action')->whereUuid('incident')->name('hr.incidents.action');
            Route::post('{incident}/actions/{action}/done', 'actionDone')->whereUuid('incident')->whereUuid('action')->name('hr.incidents.action-done');
            Route::post('{incident}/link', 'link')->whereUuid('incident')->name('hr.incidents.link');
            Route::post('{incident}/close', 'close')->whereUuid('incident')->name('hr.incidents.close');
        });
    });

    // HR-10c discipline (HR-PLAN 20.5; owner, 2026-11-10). Restricted: never shown on the staff file, the dashboard or exports.
    Route::prefix('hr/discipline')->controller(HrDisciplineController::class)->middleware(['sensitive.no-store', 'permission:hr.discipline.manage.organisation'])->group(function (): void {
        Route::get('/', 'index')->name('hr.discipline.index');
        Route::get('{case}', 'show')->whereUuid('case')->middleware(['same-origin.open', 'throttle:60,1'])->name('hr.discipline.show');
        Route::get('{case}/files/{file}', 'openFile')->whereUuid('case')->whereUuid('file')->middleware(['same-origin.open', 'throttle:60,1'])->name('hr.discipline.files.show');
        Route::middleware('throttle:30,1')->group(function (): void {
            Route::post('/', 'store')->name('hr.discipline.store');
            Route::post('{case}/notes', 'note')->whereUuid('case')->name('hr.discipline.note');
            Route::post('{case}/replies', 'reply')->whereUuid('case')->name('hr.discipline.reply');
            Route::post('{case}/show-cause', 'showCause')->whereUuid('case')->name('hr.discipline.show-cause');
            Route::post('{case}/files', 'file')->whereUuid('case')->name('hr.discipline.files.store');
            Route::post('{case}/close', 'close')->whereUuid('case')->name('hr.discipline.close');
        });
    });

    Route::prefix('hr/leave')->controller(LeaveController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:hr.leave.request.own,hr.leave.approve.organisation,hr.leave.manage.organisation')->name('hr.leave.index');
        Route::middleware(['permission:hr.leave.request.own', 'throttle:60,1'])->group(function (): void {
            Route::post('preview', 'preview')->name('hr.leave.preview');
            Route::post('requests', 'apply')->name('hr.leave.requests.store');
        });
        // HR-2b evidence files: own files with request.own, anyone's with manage (add, remove) or evidence.view (open).
        Route::post('requests/{leaveRequest}/evidence', 'addEvidence')->whereUuid('leaveRequest')
            ->middleware(['permission:hr.leave.request.own,hr.leave.manage.organisation', 'throttle:30,1'])->name('hr.leave.evidence.store');
        Route::post('evidence/{file}/remove', 'removeEvidence')->whereUuid('file')
            ->middleware(['permission:hr.leave.request.own,hr.leave.manage.organisation', 'throttle:30,1'])->name('hr.leave.evidence.remove');
        Route::get('evidence/{file}', 'openEvidence')->whereUuid('file')
            ->middleware(['same-origin.open', 'permission:hr.leave.request.own,hr.leave.evidence.view.organisation', 'throttle:120,1'])->name('hr.leave.evidence.show');
        Route::post('requests/{leaveRequest}/cancel', 'cancel')->whereUuid('leaveRequest')
            ->middleware(['permission:hr.leave.request.own,hr.leave.approve.organisation', 'throttle:30,1'])->name('hr.leave.requests.cancel');
        Route::middleware(['permission:hr.leave.approve.organisation', 'throttle:60,1'])->group(function (): void {
            Route::post('requests/{leaveRequest}/approve', 'approve')->whereUuid('leaveRequest')->name('hr.leave.requests.approve');
            Route::post('requests/{leaveRequest}/reject', 'reject')->whereUuid('leaveRequest')->name('hr.leave.requests.reject');
        });
        Route::middleware(['permission:hr.leave.manage.organisation', 'throttle:30,1'])->group(function (): void {
            Route::put('types', 'saveType')->name('hr.leave.types.save');
            Route::put('entitlements', 'saveEntitlements')->name('hr.leave.entitlements.save');
            Route::post('grant', 'grant')->name('hr.leave.grant');
            Route::post('carry-forward', 'carryForward')->name('hr.leave.carry-forward');
            Route::post('expire', 'expire')->name('hr.leave.expire');
            Route::put('working-days', 'workingDays')->name('hr.leave.working-days');
            Route::post('holidays', 'addHoliday')->name('hr.leave.holidays.store');
            Route::delete('holidays/{holiday}', 'removeHoliday')->whereNumber('holiday')->name('hr.leave.holidays.destroy');
            Route::post('{kind}', 'enter')->whereIn('kind', ['openings', 'adjustments'])->name('hr.leave.entries.store');
            Route::post('{kind}/{entry}/{decision}', 'decide')->whereIn('kind', ['openings', 'adjustments'])->whereUuid('entry')
                ->whereIn('decision', ['confirm', 'reject'])->name('hr.leave.entries.decide');
        });
    });

    foreach (['doctor-profiles' => ['doctor_profile', 'doctors', 'website.doctor_profiles.manage.organisation'], 'careers' => ['career_posting', 'careers', 'website.careers.manage.organisation']] as $path => [$type, $name, $permission]) {
        Route::prefix('website/'.$path)->controller(WebsitePeopleController::class)->middleware('sensitive.no-store')->group(function () use ($type, $name, $permission): void {
            Route::get('/', 'index')->defaults('contentType', $type)->middleware('permission:website.content.view.organisation')->name('website.'.$name.'.index');
            Route::get('/create', 'index')->defaults('contentType', $type)->defaults('websiteItem', 'create')->middleware('permission:'.$permission)->name('website.'.$name.'.create');
            Route::get('/{websiteItem}', 'index')->whereUuid('websiteItem')->defaults('contentType', $type)->middleware('permission:website.content.view.organisation')->name('website.'.$name.'.edit');
            Route::post('/', 'save')->defaults('contentType', $type)->middleware('permission:'.$permission)->name('website.'.$name.'.store');
            Route::post('/{websiteItem}', 'save')->whereUuid('websiteItem')->defaults('contentType', $type)->middleware('permission:'.$permission)->name('website.'.$name.'.save');
            foreach (['archive', 'unarchive'] as $action) {
                Route::post('/{websiteItem}/'.$action, 'archive')->whereUuid('websiteItem')->defaults('contentType', $type)->defaults('archiveAction', $action)->middleware('permission:'.$permission)->name('website.'.$name.'.'.$action);
            }
        });
    }
    foreach (['service-categories' => ['service_category', 'categories', 'website.content.manage.organisation'], 'services' => ['public_service', 'services', 'website.content.manage.organisation']] as $path => [$type, $name, $permission]) {
        Route::prefix('website/'.$path)->controller(WebsiteServicesController::class)->middleware('sensitive.no-store')->group(function () use ($type, $name, $permission): void {
            Route::get('/', 'index')->defaults('contentType', $type)->middleware('permission:website.content.view.organisation')->name('website.'.$name.'.index');
            Route::get('/create', 'index')->defaults('contentType', $type)->defaults('websiteItem', 'create')->middleware('permission:'.$permission)->name('website.'.$name.'.create');
            Route::get('/{websiteItem}', 'index')->whereUuid('websiteItem')->defaults('contentType', $type)->middleware('permission:website.content.view.organisation')->name('website.'.$name.'.edit');
            Route::post('/', 'save')->defaults('contentType', $type)->middleware('permission:'.$permission)->name('website.'.$name.'.store');
            Route::post('/{websiteItem}', 'save')->whereUuid('websiteItem')->defaults('contentType', $type)->middleware('permission:'.$permission)->name('website.'.$name.'.save');
            foreach (['archive', 'unarchive'] as $action) {
                Route::post('/{websiteItem}/'.$action, 'archive')->whereUuid('websiteItem')->defaults('contentType', $type)->defaults('archiveAction', $action)->middleware('permission:'.$permission)->name('website.'.$name.'.'.$action);
            }
        });
    }
    foreach (['promotions' => 'promotion', 'activities' => 'activity', 'faqs' => 'faq'] as $path => $type) {
        Route::prefix('website/'.$path)->controller(WebsiteEditorialController::class)->middleware('sensitive.no-store')->group(function () use ($type, $path): void {
            Route::get('/', 'index')->defaults('contentType', $type)->middleware('permission:website.content.view.organisation')->name('website.'.$path.'.index');
            Route::get('/create', 'index')->defaults('contentType', $type)->defaults('websiteItem', 'create')->middleware('permission:website.content.manage.organisation')->name('website.'.$path.'.create');
            Route::get('/{websiteItem}', 'index')->whereUuid('websiteItem')->defaults('contentType', $type)->middleware('permission:website.content.view.organisation')->name('website.'.$path.'.edit');
            Route::post('/', 'save')->defaults('contentType', $type)->middleware('permission:website.content.manage.organisation')->name('website.'.$path.'.store');
            Route::post('/{websiteItem}', 'save')->whereUuid('websiteItem')->defaults('contentType', $type)->middleware('permission:website.content.manage.organisation')->name('website.'.$path.'.save');
            foreach (['archive', 'unarchive'] as $action) {
                Route::post('/{websiteItem}/'.$action, 'archive')->whereUuid('websiteItem')->defaults('contentType', $type)->defaults('archiveAction', $action)->middleware('permission:website.content.manage.organisation')->name('website.'.$path.'.'.$action);
            }
        });
    }
    Route::prefix('website/releases')->controller(WebsiteOutputPlanController::class)->middleware(['sensitive.no-store:no-referrer', PrivateWebsiteRelease::class, 'permission:website.releases.prepare.organisation'])->group(function (): void {
        Route::get('output-plans', 'index')->name('website.output-plans.index');
        Route::get('output-plans/{plan}', 'index')->whereNumber('plan')->name('website.output-plans.show');
        Route::get('{release}/output-plans', 'index')->whereNumber('release')->name('website.output-plans.create');
        Route::post('{release}/output-plans', 'prepare')->whereNumber('release')->middleware('throttle:10,1')->name('website.output-plans.prepare');
        Route::post('output-plans/{plan}/{operation}', 'decide')->whereNumber('plan')->whereIn('operation', ['approve', 'refuse', 'withdraw'])->middleware(['permission:website.releases.approve.organisation', 'throttle:10,1'])->name('website.output-plans.decide');
    });

    Route::post('website/releases/{release}/builds', [WebsiteArtifactBuildController::class, 'store'])->whereNumber('release')->middleware(['sensitive.no-store:no-referrer', PrivateWebsiteRelease::class, 'permission:website.releases.prepare.organisation', 'throttle:10,1'])->name('website.releases.build');

    Route::prefix('website/releases')->controller(WebsiteReleaseController::class)->middleware(['sensitive.no-store:no-referrer', PrivateWebsiteRelease::class, 'permission:website.releases.prepare.organisation'])->group(function (): void {
        Route::get('/', 'index')->name('website.releases.index');
        Route::post('/', 'prepare')->middleware('throttle:10,1')->name('website.releases.prepare');
        Route::get('{release}', 'index')->whereNumber('release')->name('website.releases.show');
        Route::get('{release}/media/{filename}', 'media')->whereNumber('release')->where('filename', '[a-f0-9]{64}\\.(jpg|png|webp)')->name('website.releases.media');
        Route::post('{release}/{operation}', 'decide')->whereNumber('release')->whereIn('operation', ['approve', 'refuse', 'withdraw'])->middleware(['permission:website.releases.approve.organisation', 'throttle:10,1'])->name('website.releases.decide');
    });

    Route::get('website/little-shield', [WebsiteLittleShieldController::class, 'index'])->middleware(['sensitive.no-store', 'permission:website.content.view.organisation'])->name('website.little-shield.index');
    Route::post('website/little-shield', [WebsiteLittleShieldController::class, 'save'])->middleware(['sensitive.no-store', 'permission:website.content.manage.organisation'])->name('website.little-shield.save');

    Route::prefix('website')->controller(WebsiteDraftController::class)->middleware('sensitive.no-store')->group(function (): void {
        Route::get('branch-profiles', 'profiles')->middleware('permission:website.content.view.organisation')->name('website.profiles');
        Route::post('branch-profiles', 'save')->middleware('permission:website.content.manage.organisation')->name('website.profiles.save');
        Route::get('media', 'media')->middleware('permission:website.assets.manage.organisation')->name('website.media');
        Route::post('media', 'upload')->middleware(['permission:website.assets.manage.organisation', 'throttle:20,1'])->name('website.media.upload');
        Route::post('media/{asset}/retire', 'retire')->whereUuid('asset')->middleware('permission:website.assets.manage.organisation')->name('website.media.retire');
        Route::get('media/{asset}/image', 'image')->whereUuid('asset')->middleware('permission:website.content.view.organisation')->name('website.media.image');
    });

    Route::middleware(['permission:catalogue.settings.manage.organisation', 'sensitive.no-store'])->group(function () {
        Route::get('business-details', [BusinessDetailsController::class, 'index'])->name('business-details.index');
        Route::post('business-details/footer', [BusinessDetailsController::class, 'footer'])->middleware('throttle:20,1')->name('business-details.footer');
        Route::post('business-details', [BusinessDetailsController::class, 'store'])->middleware(['permission:business_details.manage.organisation', 'throttle:20,1'])->name('business-details.store');
        Route::get('business-details/branches/{branch}/logo/{asset}', [BusinessDetailsController::class, 'logo'])->whereNumber('branch')->whereUuid('asset')->name('business-details.logo');
    });

    Route::get('dashboard', DashboardController::class)
        ->middleware('permission:dashboard.view.own')
        ->name('dashboard');

    Route::middleware('permission:visits.view.branch,queue.view.own,queue.view.branch')->group(function () {
        Route::get('reviews', ClinicPlaceholderController::class)->name('clinic.reviews');
        Route::get('purchase', ClinicPlaceholderController::class)->name('clinic.purchase');
    });
    Route::get('reports/transactions', [BranchReportController::class, 'index'])->defaults('dataset', 'transactions')->middleware(['permission:reports.transactions.view.branch', 'sensitive.no-store'])->name('reports.transactions.index');
    Route::post('reports/transactions/run', [BranchReportController::class, 'run'])->defaults('dataset', 'transactions')->middleware(['permission:reports.transactions.view.branch', 'sensitive.no-store', 'throttle:patient-report-views'])->name('reports.transactions.run');
    Route::post('reports/transactions/download', [BranchReportController::class, 'download'])->defaults('dataset', 'transactions')->middleware(['permission:reports.transactions.view.branch', 'permission:reports.transactions.export.branch', 'sensitive.no-store', 'throttle:reports-downloads'])->name('reports.transactions.download');
    Route::get('reports/stock-movement', [BranchReportController::class, 'index'])->defaults('dataset', 'stock_movement')->middleware(['permission:reports.stock_movement.view.branch', 'sensitive.no-store'])->name('reports.stock-movement.index');
    Route::post('reports/stock-movement/run', [BranchReportController::class, 'run'])->defaults('dataset', 'stock_movement')->middleware(['permission:reports.stock_movement.view.branch', 'sensitive.no-store', 'throttle:patient-report-views'])->name('reports.stock-movement.run');
    Route::post('reports/stock-movement/download', [BranchReportController::class, 'download'])->defaults('dataset', 'stock_movement')->middleware(['permission:reports.stock_movement.view.branch', 'permission:reports.stock_movement.export.branch', 'sensitive.no-store', 'throttle:reports-downloads'])->name('reports.stock-movement.download');
    Route::get('reports/panel-summary', [BranchReportController::class, 'index'])->defaults('dataset', 'panel_summary')->middleware(['permission:reports.panel_summary.view.branch', 'sensitive.no-store'])->name('reports.panel-summary.index');
    Route::post('reports/panel-summary/run', [BranchReportController::class, 'run'])->defaults('dataset', 'panel_summary')->middleware(['permission:reports.panel_summary.view.branch', 'sensitive.no-store', 'throttle:patient-report-views'])->name('reports.panel-summary.run');
    Route::post('reports/panel-summary/download', [BranchReportController::class, 'download'])->defaults('dataset', 'panel_summary')->middleware(['permission:reports.panel_summary.view.branch', 'permission:reports.panel_summary.export.branch', 'sensitive.no-store', 'throttle:reports-downloads'])->name('reports.panel-summary.download');
    Route::get('reports/daily-summary', [BranchReportController::class, 'index'])->defaults('dataset', 'daily_summary')->middleware(['permission:reports.daily_summary.view.branch', 'sensitive.no-store'])->name('reports.daily-summary.index');
    Route::post('reports/daily-summary/run', [BranchReportController::class, 'run'])->defaults('dataset', 'daily_summary')->middleware(['permission:reports.daily_summary.view.branch', 'sensitive.no-store', 'throttle:patient-report-views'])->name('reports.daily-summary.run');
    Route::post('reports/daily-summary/download', [BranchReportController::class, 'download'])->defaults('dataset', 'daily_summary')->middleware(['permission:reports.daily_summary.view.branch', 'permission:reports.daily_summary.export.branch', 'sensitive.no-store', 'throttle:reports-downloads'])->name('reports.daily-summary.download');
    Route::get('reports/billing-detail', [PatientBillingReportController::class, 'index'])
        ->middleware(['permission:reports.patient_billing.view.branch', 'sensitive.no-store'])
        ->name('patient-billing-report.index');
    Route::post('reports/billing-detail/run', [PatientBillingReportController::class, 'run'])
        ->middleware(['permission:reports.patient_billing.view.branch', 'sensitive.no-store', 'throttle:patient-report-views'])
        ->name('patient-billing-report.run');
    Route::post('reports/billing-detail/download', [PatientBillingReportController::class, 'download'])
        ->middleware(['permission:reports.patient_billing.view.branch', 'permission:reports.patient_billing.export.branch', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('patient-billing-report.download');
    Route::get('insight', fn () => to_route('insights.today'))
        ->middleware('permission:insights.view.organisation')
        ->name('clinic.insight');
    Route::get('insights/today', [ClinicInsightsController::class, 'today'])
        ->middleware(['permission:insights.view.organisation', 'sensitive.no-store'])
        ->name('insights.today');
    Route::get('insights/daily-sales/download', [ClinicInsightsController::class, 'downloadDailyReport'])
        ->defaults('dataset', 'daily_sales')
        ->middleware(['permission:reports.sales.export.organisation', 'permission:insights.view.organisation', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('insights.daily-sales.download');
    Route::get('insights/daily-receipts/download', [ClinicInsightsController::class, 'downloadDailyReport'])
        ->defaults('dataset', 'daily_receipts')
        ->middleware(['permission:reports.payments.export.organisation', 'permission:insights.view.organisation', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('insights.daily-receipts.download');
    Route::get('insights/sales-summary/download', [ClinicInsightsController::class, 'downloadSalesSummary'])
        ->middleware(['permission:reports.sales.export.organisation', 'permission:insights.view.organisation', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('insights.sales-summary.download');
    Route::get('insights/{section}', [ClinicInsightsController::class, 'report'])
        ->whereIn('section', ['sales', 'in-clinic', 'payments', 'inventory', 'patients'])
        ->middleware(['permission:insights.view.organisation', 'sensitive.no-store'])
        ->name('insights.report');

    Route::middleware(['permission:panel.remittances.record.branch', 'sensitive.no-store'])->group(function () {
        Route::get('panel-remittances', [PanelRemittancesController::class, 'index'])->name('panel-remittances.index');
        Route::post('panel-remittances', [PanelRemittancesController::class, 'store'])->middleware('throttle:20,1')->name('panel-remittances.store');
        Route::post('panel-remittances/statements', [PanelRemittancesController::class, 'storeStatement'])->middleware('throttle:10,1')->name('panel-remittances.statements.store');
        Route::delete('panel-remittances/statements/{statement}', [PanelRemittancesController::class, 'destroyStatement'])->whereUuid('statement')->middleware('throttle:20,1')->name('panel-remittances.statements.destroy');
    });
    Route::middleware(['permission:panel.claim_payments.reverse.branch', 'sensitive.no-store'])->group(function () {
        Route::get('panel-payment-corrections', [PanelPaymentCorrectionsController::class, 'index'])->name('panel-payment-corrections.index');
        Route::post('panel-payment-corrections', [PanelPaymentCorrectionsController::class, 'store'])->middleware('throttle:10,1')->name('panel-payment-corrections.store');
    });

    Route::middleware(['permission:panel.invoices.export.branch', 'sensitive.no-store'])->group(function () {
        Route::get('panel-invoices', [PanelInvoicesController::class, 'index'])->name('panel-invoices.index');
        Route::get('panel-invoices/download', [PanelInvoicesController::class, 'download'])->middleware('throttle:10,1')->name('panel-invoices.download');
    });
    Route::middleware(['permission:catalogue.settings.manage.organisation', 'sensitive.no-store'])->group(function () {
        Route::get('registration-settings', [RegistrationSettingsController::class, 'index'])->name('registration-settings.index');
        Route::post('registration-settings', [RegistrationSettingsController::class, 'store'])->middleware('throttle:20,1')->name('registration-settings.store');
        Route::get('consultation-settings', [DiagnosisPresetSettingsController::class, 'index'])->name('diagnosis-presets.index');
        Route::post('consultation-settings/diagnoses', [DiagnosisPresetSettingsController::class, 'store'])->middleware('throttle:30,1')->name('diagnosis-presets.store');
        Route::patch('consultation-settings/diagnoses/{preset}/rename', [DiagnosisPresetSettingsController::class, 'rename'])->whereNumber('preset')->middleware('throttle:30,1')->name('diagnosis-presets.rename');
        Route::patch('consultation-settings/diagnoses/{preset}/active', [DiagnosisPresetSettingsController::class, 'active'])->whereNumber('preset')->middleware('throttle:30,1')->name('diagnosis-presets.active');
        Route::get('inventory-services-settings/medicine-details', [MedicineOptionSettingsController::class, 'index'])->name('medicine-details.index');
        Route::post('inventory-services-settings/medicine-details/{type}', [MedicineOptionSettingsController::class, 'store'])->middleware('throttle:30,1')->name('medicine-details.store');
        Route::patch('inventory-services-settings/medicine-details/{type}/{option}/rename', [MedicineOptionSettingsController::class, 'rename'])->whereNumber('option')->middleware('throttle:30,1')->name('medicine-details.rename');
        Route::patch('inventory-services-settings/medicine-details/{type}/{option}/active', [MedicineOptionSettingsController::class, 'active'])->whereNumber('option')->middleware('throttle:30,1')->name('medicine-details.active');
        Route::get('inventory-services-settings/suppliers', [SupplierSettingsController::class, 'index'])->name('supplier-settings.index');
        Route::post('inventory-services-settings/suppliers', [SupplierSettingsController::class, 'store'])->middleware('throttle:30,1')->name('supplier-settings.store');
        Route::patch('inventory-services-settings/suppliers/{supplier}/active', [SupplierSettingsController::class, 'active'])->middleware('throttle:20,1')->name('supplier-settings.active');
    });

    Route::get('panel-responsibility', [BillingWorkController::class, 'panel'])->middleware('sensitive.no-store')->name('clinic.panel-responsibility');
    Route::middleware(['permission:panel.claims.manage.branch', 'sensitive.no-store'])->group(function () {
        Route::get('panel-claims', [PanelClaimsController::class, 'index'])->name('panel-claims.index');
        Route::get('panel-claims/follow-up', [PanelClaimsController::class, 'followUp'])->name('panel-claims.follow-up');
        Route::get('panel-claims/pack', [PanelClaimsController::class, 'pack'])->middleware('throttle:30,1')->name('panel-claims.pack');
        Route::get('panel-claims/pack/logo/{asset}', [PanelClaimsController::class, 'packLogo'])->whereUuid('asset')->name('panel-claims.letterhead-logo');
        Route::get('panel-claims/allocations/{allocation}/create', [PanelClaimsController::class, 'create'])->name('panel-claims.create');
        Route::post('panel-claims/allocations/{allocation}', [PanelClaimsController::class, 'store'])->middleware('throttle:20,1')->name('panel-claims.store');
        Route::post('panel-claims/bulk', [PanelClaimsController::class, 'bulkStore'])->middleware('throttle:10,1')->name('panel-claims.bulk-store');
        Route::post('panel-claims/allocations/{allocation}/prepare', [PanelClaimsController::class, 'prepare'])->name('panel-claims.prepare');
        Route::get('panel-claims/{claim}', [PanelClaimsController::class, 'show'])->name('panel-claims.show');
        Route::patch('panel-claims/{claim}/amount', [PanelClaimsController::class, 'adjustAmount'])->name('panel-claims.amount');
        Route::post('panel-claims/{claim}/submit', [PanelClaimsController::class, 'submit'])->name('panel-claims.submit');
        Route::post('panel-claims/{claim}/query', [PanelClaimsController::class, 'query'])->name('panel-claims.query');
        Route::post('panel-claims/{claim}/decision', [PanelClaimsController::class, 'decision'])->name('panel-claims.decision');
        Route::post('panel-claims/{claim}/payments', [PanelClaimsController::class, 'payment'])->name('panel-claims.payment');
        Route::post('panel-claims/{claim}/notes', [PanelClaimsController::class, 'note'])->name('panel-claims.note');
        Route::patch('panel-claims/{claim}/remarks', [PanelClaimsController::class, 'remarks'])->name('panel-claims.remarks');
        Route::post('panel-claims/{claim}/documents', [PanelClaimsController::class, 'storeDocument'])->middleware('throttle:20,1')->name('panel-claims.documents.store');
        Route::get('panel-claims/{claim}/documents/{document}', [PanelClaimsController::class, 'document'])->name('panel-claims.documents.show');
    });
    Route::get('financial-work', [BillingWorkController::class, 'finance'])->middleware('sensitive.no-store')->name('billing.work');
    Route::get('pay-later-collections', [PayLaterCollectionController::class, 'index'])
        ->middleware(['permission:outstanding.view.branch', 'sensitive.no-store'])->name('billing.pay-later-collections');

    Route::get('staff', [StaffController::class, 'index'])
        ->middleware('permission:staff.view.own,staff.view.branch,staff.view.organisation')
        ->name('staff.index');
    // HR-17e: whoever may create staff accounts marks HR's request done once the account exists.
    Route::post('staff/account-requests/{accountRequest}/done', [StaffController::class, 'accountRequestDone'])
        ->whereUuid('accountRequest')
        ->middleware(['permission:staff.manage.organisation', 'permission:access.manage.organisation', 'throttle:30,1'])
        ->name('staff.account-requests.done');
    // HR-15a: the same people link the locum account they created to the locum's file.
    Route::post('staff/locum-sign-ins/{signIn}/link', [StaffController::class, 'linkLocumSignIn'])
        ->whereUuid('signIn')
        ->middleware(['permission:staff.manage.organisation', 'permission:access.manage.organisation', 'throttle:30,1'])
        ->name('staff.locum-sign-ins.link');
    Route::get('staff/create', [StaffController::class, 'create'])
        ->middleware(['permission:staff.manage.organisation', 'permission:access.manage.organisation'])
        ->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])
        ->middleware(['permission:staff.manage.organisation', 'permission:access.manage.organisation'])
        ->name('staff.store');
    Route::get('staff/{staff}', [StaffController::class, 'show'])
        ->middleware('permission:staff.view.own,staff.view.branch,staff.view.organisation')
        ->name('staff.show');
    Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])
        ->middleware('permission:staff.manage.organisation')
        ->name('staff.edit');
    Route::patch('staff/{staff}', [StaffController::class, 'update'])
        ->middleware('permission:staff.manage.organisation')
        ->name('staff.update');
    Route::post('staff/{staff}/branch-assignments', [StaffBranchAssignmentController::class, 'store'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.store');
    Route::patch('staff/{staff}/branch-assignments/{assignment}', [StaffBranchAssignmentController::class, 'update'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.update');
    Route::patch('staff/{staff}/branch-assignments/{assignment}/end', [StaffBranchAssignmentController::class, 'end'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.end');
    Route::post('staff/{staff}/branch-assignments/{assignment}/primary', [StaffBranchAssignmentController::class, 'setPrimary'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.primary');
    Route::put('staff/{staff}/roles', [StaffRoleController::class, 'update'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.roles.update');
    Route::patch('staff/{staff}/status', [StaffStatusController::class, 'update'])
        ->middleware('permission:staff.manage.organisation')
        ->name('staff.status.update');

    Route::get('branches', [BranchController::class, 'index'])
        ->middleware('permission:branches.view.branch,branches.view.organisation')
        ->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])
        ->middleware('permission:branches.view.branch,branches.view.organisation')
        ->name('branches.show');
    Route::post('branch-context', [BranchContextController::class, 'store'])
        ->middleware('permission:branch_context.switch.branch,branch_context.switch.organisation')
        ->name('branch-context.store');

    Route::get('access-control', AccessControlController::class)
        ->middleware('permission:access.view.organisation')
        ->name('access-control.index');

    Route::get('audit-logs', AuditLogController::class)
        ->middleware('permission:audit.view.organisation')
        ->name('audit-logs.index');

    Route::middleware(['permission:public_checkin_links.manage.organisation,public_checkin_links.manage.branch', 'sensitive.no-store'])->group(function () {
        Route::get('public-checkin-links', [PublicCheckInLinkController::class, 'index'])->name('public-checkin-links.index');
        Route::post('public-checkin-links', [PublicCheckInLinkController::class, 'store'])->name('public-checkin-links.store');
        Route::post('public-checkin-links/{publicCheckInLink}/rotate', [PublicCheckInLinkController::class, 'rotate'])->name('public-checkin-links.rotate');
        Route::delete('public-checkin-links/{publicCheckInLink}', [PublicCheckInLinkController::class, 'revoke'])->name('public-checkin-links.revoke');
    });

    Route::middleware('permission:queue.display.branch,queue_display.manage.organisation,queue_display.manage.branch')->group(function () {
        Route::get('queue-display', [QueueDisplayController::class, 'screen'])->name('queue-display.screen');
        Route::get('queue-display/feed', [QueueDisplayController::class, 'feed'])
            ->middleware('throttle:120,1')->name('queue-display.feed');
        Route::get('queue-display/branches/{branch}/posters/{poster}', [QueueDisplayController::class, 'poster'])
            ->whereNumber('branch')->whereUuid('poster')->middleware('throttle:240,1')->name('queue-display.posters.show');
    });

    Route::middleware('permission:queue_display.manage.organisation,queue_display.manage.branch')->prefix('queue-display-settings')->group(function () {
        Route::get('/', [QueueDisplaySettingsController::class, 'index'])->name('queue-display.settings');
        Route::post('branches/{branch}/rooms', [QueueDisplaySettingsController::class, 'storeRoom'])
            ->whereNumber('branch')->middleware('throttle:30,1')->name('queue-display.rooms.store');
        Route::patch('rooms/{room}', [QueueDisplaySettingsController::class, 'updateRoom'])
            ->whereNumber('room')->middleware('throttle:30,1')->name('queue-display.rooms.update');
        Route::post('rooms/{room}/activate', [QueueDisplaySettingsController::class, 'activateRoom'])
            ->whereNumber('room')->middleware('throttle:30,1')->name('queue-display.rooms.activate');
        Route::post('rooms/{room}/deactivate', [QueueDisplaySettingsController::class, 'deactivateRoom'])
            ->whereNumber('room')->middleware('throttle:30,1')->name('queue-display.rooms.deactivate');
        Route::patch('branches/{branch}', [QueueDisplaySettingsController::class, 'updateSettings'])
            ->whereNumber('branch')->middleware('throttle:30,1')->name('queue-display.settings.update');
        Route::post('branches/{branch}/posters', [QueueDisplaySettingsController::class, 'storePoster'])
            ->whereNumber('branch')->middleware('throttle:20,1')->name('queue-display.posters.store');
        Route::post('branches/{branch}/posters/{poster}/remove', [QueueDisplaySettingsController::class, 'destroyPoster'])
            ->whereNumber('branch')->whereUuid('poster')->middleware('throttle:20,1')->name('queue-display.posters.destroy');
    });

    Route::middleware(['sensitive.no-store', 'inertia.encrypt'])->group(function () {
        Route::prefix('registration-review')->controller(PublicIntakeReviewController::class)
            ->middleware('permission:public_intakes.review.branch')->group(function () {
                Route::get('/', 'index')->name('registration-review.index');
                Route::get('{publicIntake}', 'show')->whereUuid('publicIntake')->name('registration-review.show');
                Route::get('{publicIntake}/guardian-contact', 'guardianContact')->whereUuid('publicIntake')->middleware(['permission:patients.view.organisation', 'throttle:60,1'])->name('registration-review.guardian-contact');
                Route::post('{publicIntake}/start', 'start')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.start');
                Route::patch('{publicIntake}/correct', 'correct')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.correct');
                Route::post('{publicIntake}/correction-required', 'correctionRequired')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.correction-required');
                Route::post('{publicIntake}/reject', 'reject')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.reject');
                Route::post('{publicIntake}/accept', 'accept')->whereUuid('publicIntake')->middleware('throttle:20,1')->name('registration-review.accept');
            });

        Route::get('inventory/counts', [GovernedStockController::class, 'counts'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.governed-counts');
        Route::get('inventory/writeoffs', [GovernedStockController::class, 'writeoffs'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.governed-writeoffs');
        Route::post('inventory/governed/{kind}', [GovernedStockController::class, 'store'])->whereIn('kind', ['count', 'writeoff', 'reversal'])->middleware(['sensitive.no-store', 'throttle:30,1'])->name('inventory.governed.store');
        Route::post('inventory/governed/{governedCase}/{action}', [GovernedStockController::class, 'act'])->whereNumber('governedCase')->whereIn('action', ['counted', 'recounted', 'approved'])->middleware(['sensitive.no-store', 'throttle:30,1'])->name('inventory.governed.act');
        Route::get('inventory/supplier-returns', [SupplierReturnController::class, 'index'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.supplier-returns');
        Route::post('inventory/supplier-returns', [SupplierReturnController::class, 'store'])->middleware(['permission:inventory.supplier_returns.request.branch', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.supplier-returns.store');
        Route::post('inventory/supplier-returns/{supplierReturn}/{action}', [SupplierReturnController::class, 'act'])->whereNumber('supplierReturn')->whereIn('action', ['approved', 'rejected', 'cancelled', 'dispatched', 'credit_recorded'])->middleware(['sensitive.no-store', 'throttle:30,1'])->name('inventory.supplier-returns.act');
        Route::get('inventory/supplier-terms', [SupplierTermsController::class, 'index'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.supplier-terms');
        Route::post('inventory/supplier-terms', [SupplierTermsController::class, 'store'])->middleware(['permission:inventory.suppliers.manage.organisation', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.supplier-terms.store');
        Route::put('inventory/supplier-terms/{term}', [SupplierTermsController::class, 'update'])->whereUuid('term')->middleware(['permission:inventory.suppliers.manage.organisation', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.supplier-terms.update');
        Route::get('inventory/treatment-usage', [TreatmentUsageController::class, 'index'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.treatment-usage');
        Route::post('inventory/treatment-usage', [TreatmentUsageController::class, 'store'])->middleware(['permission:inventory.usage.record.branch', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.treatment-usage.store');
        Route::post('inventory/treatment-usage/{treatmentUsage}/reverse', [TreatmentUsageController::class, 'reverse'])->whereNumber('treatmentUsage')->middleware(['permission:inventory.variances.approve.branch', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.treatment-usage.reverse');
        Route::get('inventory/scan', [InventoryScanController::class, 'lookup'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store', 'throttle:120,1'])->name('inventory.scan');
        Route::get('inventory/stock-review', [InventoryStockReviewController::class, 'index'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.stock-review');
        Route::post('inventory/stock-review/{kind}/{reviewItem}', [InventoryStockReviewController::class, 'store'])->whereIn('kind', ['low_stock', 'expiring', 'expired'])->whereNumber('reviewItem')->middleware(['permission:inventory.stock_reviews.manage.branch', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.stock-review.store');
        Route::get('inventory/batch-holds', [InventoryBatchHoldController::class, 'index'])->middleware(['permission:inventory.view.branch', 'sensitive.no-store'])->name('inventory.batch-holds');
        Route::post('inventory/batch-holds/{batch}/{action}', [InventoryBatchHoldController::class, 'store'])->whereUuid('batch')->whereIn('action', ['place', 'release'])->middleware(['sensitive.no-store', 'throttle:30,1'])->name('inventory.batch-holds.store');
        Route::get('inventory/readiness', [InventoryReadinessController::class, 'index'])->middleware('permission:inventory.view.branch')->name('inventory.readiness');
        Route::post('inventory/readiness/{kind}/{key}', [InventoryReadinessController::class, 'verify'])->whereIn('kind', ['unit', 'opening'])->whereUuid('key')->middleware('throttle:30,1')->name('inventory.readiness.verify');
        Route::post('inventory/readiness/barcode/{sku}', [InventoryReadinessController::class, 'barcode'])->whereUuid('sku')->middleware(['permission:inventory.references.manage.organisation', 'sensitive.no-store', 'throttle:30,1'])->name('inventory.readiness.barcode');

        Route::get('inventory/picker', [InventoryController::class, 'picker'])
            ->middleware(['permission:inventory.view.branch', 'throttle:120,1'])->name('inventory.picker');
        Route::get('inventory', InventoryController::class)
            ->middleware('permission:inventory.view.branch')->name('inventory.index');
        Route::get('visits/{visit}/referrals', [ReferralController::class, 'index'])->middleware(['permission:referrals.view.own', 'sensitive.no-store'])->name('referrals.index');
        Route::get('visits/{visit}/referrals/source', [ReferralController::class, 'source'])->middleware(['permission:referrals.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('referrals.source');
        Route::post('visits/{visit}/referrals/preview', [ReferralController::class, 'preview'])->middleware(['permission:referrals.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('referrals.preview');
        Route::post('visits/{visit}/referrals', [ReferralController::class, 'store'])->middleware(['permission:referrals.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('referrals.store');
        Route::get('visits/{visit}/referrals/{document}', [ReferralController::class, 'show'])->whereNumber('document')->middleware(['permission:referrals.view.own', 'sensitive.no-store:no-referrer'])->name('referrals.show');
        Route::get('visits/{visit}/attendance', [AttendanceController::class, 'index'])->middleware(['permission:attendance_documents.view.own', 'sensitive.no-store'])->name('attendance.index');
        Route::get('visits/{visit}/attendance/source', [AttendanceController::class, 'source'])->middleware(['permission:attendance_documents.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('attendance.source');
        Route::post('visits/{visit}/attendance/preview', [AttendanceController::class, 'preview'])->middleware(['permission:attendance_documents.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('attendance.preview');
        Route::post('visits/{visit}/attendance', [AttendanceController::class, 'store'])->middleware(['permission:attendance_documents.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('attendance.store');
        Route::get('visits/{visit}/attendance/{document}', [AttendanceController::class, 'show'])->whereNumber('document')->middleware(['permission:attendance_documents.view.own,attendance_documents.view.branch', 'sensitive.no-store:no-referrer'])->name('attendance.show');
        Route::get('visits/{visit}/medical-certificates', [MedicalCertificateController::class, 'index'])
            ->middleware(['permission:medical_certificates.view.own,medical_certificates.view.branch', 'sensitive.no-store'])->name('medical-certificates.index');
        Route::post('visits/{visit}/medical-certificates', [MedicalCertificateController::class, 'store'])
            ->middleware(['permission:medical_certificates.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('medical-certificates.store');
        Route::get('visits/{visit}/medical-certificates/{document}', [MedicalCertificateController::class, 'show'])
            ->whereNumber('document')->middleware(['permission:medical_certificates.view.own,medical_certificates.view.branch', 'sensitive.no-store:no-referrer'])->name('medical-certificates.show');
        Route::post('visits/{visit}/encounter', [ClinicalEncounterController::class, 'store'])
            ->middleware(['permission:encounters.start.own', 'throttle:20,1'])
            ->name('encounters.store');
        Route::get('visits/{visit}/encounter', [ClinicalEncounterController::class, 'show'])
            ->middleware('permission:encounters.view.own')
            ->name('encounters.show');
        Route::get('visits/{historicalVisit}/encounter/history', [ClinicalEncounterController::class, 'history'])
            ->middleware('permission:encounters.history.view.organisation')
            ->name('encounters.history.show');
        Route::get('visits/{visit}/encounter/diagnosis-presets', [ClinicalEncounterController::class, 'diagnosisPresets'])
            ->middleware(['permission:encounters.view.own', 'permission:encounters.update.own', 'throttle:60,1'])
            ->name('encounters.diagnosis-presets');
        Route::patch('visits/{visit}/encounter', [ClinicalEncounterController::class, 'update'])
            ->middleware(['permission:encounters.update.own', 'throttle:60,1'])
            ->name('encounters.update');
        Route::post('visits/{visit}/encounter/allergies/no-known', [ClinicalAllergyController::class, 'declareNoKnown'])
            ->middleware(['permission:allergies.update.own', 'throttle:30,1'])
            ->name('encounters.allergies.no-known');
        Route::post('visits/{visit}/encounter/allergies', [ClinicalAllergyController::class, 'store'])
            ->middleware(['permission:allergies.update.own', 'throttle:60,1'])
            ->name('encounters.allergies.store');
        Route::patch('visits/{visit}/encounter/allergies/{allergy}', [ClinicalAllergyController::class, 'update'])
            ->middleware(['permission:allergies.update.own', 'throttle:60,1'])
            ->name('encounters.allergies.update');
        Route::patch('visits/{visit}/encounter/allergies/{allergy}/entered-in-error', [ClinicalAllergyController::class, 'enterInError'])
            ->middleware(['permission:allergies.update.own', 'throttle:30,1'])
            ->name('encounters.allergies.entered-in-error');
        Route::post('visits/{visit}/encounter/allergies/review', [ClinicalAllergyController::class, 'review'])
            ->middleware(['permission:allergies.review.own', 'throttle:30,1'])
            ->name('encounters.allergies.review');
        Route::post('visits/{visit}/encounter/problems', [ClinicalProblemController::class, 'store'])
            ->middleware(['permission:problems.update.own', 'throttle:60,1'])
            ->name('encounters.problems.store');
        Route::patch('visits/{visit}/encounter/problems/{problem}', [ClinicalProblemController::class, 'update'])
            ->middleware(['permission:problems.update.own', 'throttle:60,1'])
            ->name('encounters.problems.update');
        Route::patch('visits/{visit}/encounter/problems/{problem}/resolve', [ClinicalProblemController::class, 'resolve'])
            ->middleware(['permission:problems.update.own', 'throttle:30,1'])
            ->name('encounters.problems.resolve');
        Route::patch('visits/{visit}/encounter/problems/{problem}/entered-in-error', [ClinicalProblemController::class, 'enterInError'])
            ->middleware(['permission:problems.update.own', 'throttle:30,1'])
            ->name('encounters.problems.entered-in-error');
        Route::put('visits/{visit}/encounter/treatment-plan', [TreatmentPlanController::class, 'save'])
            ->middleware(['permission:treatment_plans.create.own,treatment_plans.update.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.save');
        Route::post('visits/{visit}/encounter/treatment-plan/send-to-dispensary', [TreatmentPlanController::class, 'send'])
            ->middleware(['permission:treatment_plans.send_to_dispensary.own', 'throttle:20,1'])
            ->name('encounters.treatment-plan.send-to-dispensary');

        Route::post('visits/{visit}/encounter/complete-consultation', [ConsultationCheckoutController::class, 'complete'])
            ->middleware(['permission:consultations.complete.own', 'throttle:20,1'])->name('encounters.checkout');
        Route::post('visits/{visit}/encounter/hold', [ConsultationHoldController::class, 'hold'])
            ->middleware(['permission:consultations.hold.own', 'throttle:20,1'])->name('encounters.hold');
        Route::post('visits/{visit}/encounter/resume', [ConsultationHoldController::class, 'resume'])
            ->middleware(['permission:consultations.hold.own', 'throttle:20,1'])->name('encounters.resume');
        Route::post('visits/{visit}/encounter/reopen-checkout', [ConsultationCheckoutController::class, 'reopen'])
            ->middleware(['permission:consultations.reopen.own', 'throttle:20,1'])->name('encounters.checkout.reopen');

        Route::post('visits/{visit}/dispense', [DispensaryController::class, 'openOtc'])
            ->middleware(['permission:dispensary.otc.create.branch', 'throttle:30,1'])->name('visits.dispense');
        Route::post('dispensary/{dispensaryCase}/otc-allergy', [DispensaryController::class, 'confirmOtcAllergy'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:30,1'])->name('dispensary.otc-allergy');
        Route::post('dispensary/{dispensaryCase}/otc-allergy-records', [DispensaryController::class, 'addOtcAllergy'])
            ->whereUuid('dispensaryCase')->middleware(['permission:allergies.record_otc.branch', 'throttle:30,1'])->name('dispensary.otc-allergy-records');
        Route::post('dispensary/{dispensaryCase}/items/{itemPublicId}/returns', [DispensaryController::class, 'recordReturn'])
            ->whereUuid(['dispensaryCase', 'itemPublicId'])->middleware(['permission:dispensary.update.branch', 'throttle:30,1'])->name('dispensary.returns.record');
        Route::post('dispensary/{dispensaryCase}/returns/{returnPublicId}/check', [DispensaryController::class, 'checkReturn'])
            ->whereUuid(['dispensaryCase', 'returnPublicId'])->middleware(['permission:inventory.variances.approve.branch', 'throttle:30,1'])->name('dispensary.returns.check');
        // DSP-7b: the branch list of returns waiting for a check (read only; the check stays on the case page).
        Route::get('dispensary/returns-to-check', [DispensaryController::class, 'returnsToCheck'])
            ->middleware(['permission:dispensary.view.branch', 'sensitive.no-store', 'throttle:60,1'])->name('dispensary.returns-to-check');
        Route::get('dispensary/{dispensaryCase}', [DispensaryController::class, 'show'])
            ->whereUuid('dispensaryCase')->middleware('permission:dispensary.view.branch')->name('dispensary.show');

        Route::get('visits/{visit}/invoice/activity', [InvoiceActivityController::class, 'completed'])
            ->middleware(['permission:visits.history.view.branch', 'sensitive.no-store'])->name('visits.invoice.activity');
        Route::get('visits/{visit}/billing/activity', [InvoiceActivityController::class, 'visit'])
            ->middleware(['permission:billing.view.branch,billing.summary.branch', 'sensitive.no-store'])->name('billing.visit-activity');
        Route::get('visits/{visit}/billing/{invoice}/activity', [InvoiceActivityController::class, 'billing'])
            ->whereUuid('invoice')->middleware(['permission:billing.view.branch,billing.summary.branch', 'sensitive.no-store'])->name('billing.activity');

        Route::get('visits/{visit}/billing/{invoice}/bill-to', [InvoiceBillToController::class, 'index'])
            ->whereUuid('invoice')->middleware(['permission:billing.bill_to.view.branch', 'sensitive.no-store'])->name('billing.bill-to');
        Route::post('visits/{visit}/billing/{invoice}/bill-to', [InvoiceBillToController::class, 'store'])
            ->whereUuid('invoice')->middleware(['permission:billing.bill_to.manage.branch', 'throttle:30,1', 'sensitive.no-store'])->name('billing.bill-to.store');

        Route::get('visits/{visit}/billing/{invoice}/notes', [InvoiceNoteController::class, 'index'])
            ->whereUuid('invoice')->middleware(['permission:billing.notes.view.branch', 'sensitive.no-store'])->name('billing.notes');
        Route::post('visits/{visit}/billing/{invoice}/notes', [InvoiceNoteController::class, 'store'])
            ->whereUuid('invoice')->middleware(['permission:billing.notes.view.branch', 'throttle:30,1', 'sensitive.no-store'])->name('billing.notes.store');

        Route::prefix('visits/{visit}/billing')->controller(BillingController::class)->group(function () {
            Route::get('/', 'show')->middleware('permission:billing.view.branch,billing.summary.branch')->name('billing.show');
            Route::get('outstanding/{group}', 'outstanding')->whereIn('group', ['patient', 'panel'])->middleware(['permission:billing.view.branch,billing.summary.branch', 'sensitive.no-store'])->name('billing.outstanding');
            Route::get('payments', 'payments')->middleware('permission:billing.view.branch,billing.summary.branch')->name('billing.payments');
            Route::post('build', 'build')->middleware(['permission:billing.build.branch', 'throttle:20,1'])->name('billing.build');
            Route::post('complete', 'complete')->middleware(['permission:visits.complete.branch', 'throttle:20,1'])->name('billing.complete');
            Route::post('{invoice}/finalize', 'finalize')->middleware(['permission:billing.finalize.branch', 'throttle:20,1'])->name('billing.finalize');
            Route::post('{invoice}/payments', 'payment')->middleware(['permission:payments.add.branch', 'throttle:20,1'])->name('billing.payment');
            Route::post('{invoice}/responsibility/{kind}', 'propose')->whereIn('kind', ['panel', 'deferment'])->middleware(['permission:coverage.propose.branch,outstanding.request.branch', 'throttle:20,1'])->name('billing.propose');
            Route::post('{invoice}/responsibility/{kind}/{proposal}/approve', 'approve')->whereIn('kind', ['panel', 'deferment'])->whereUuid('proposal')->middleware(['permission:coverage.approve.branch,outstanding.approve.branch', 'throttle:20,1'])->name('billing.approve');
            Route::post('{invoice}/payments/{payment}/reverse', 'reverse')->middleware(['permission:payments.reverse.branch', 'throttle:10,1'])->name('billing.reverse');
            Route::post('{invoice}/payments/{payment}/correct', 'correctReceipt')->middleware(['permission:payments.reverse.branch', 'throttle:10,1'])->name('billing.receipt.correct');
            Route::post('{invoice}/adjustments', 'adjustment')->middleware(['permission:billing.adjustments.add.branch', 'throttle:20,1'])->name('billing.adjustment');
            Route::post('{invoice}/adjustments/{adjustment}/reverse', 'reverseAdjustment')->whereUuid('adjustment')->middleware(['permission:billing.adjustments.reverse.branch', 'throttle:10,1'])->name('billing.adjustment.reverse');
            Route::post('{invoice}/void', 'void')->middleware(['permission:invoices.void.branch', 'throttle:10,1'])->name('billing.void');
            Route::post('refunds/{payment}', 'refund')->whereUuid('payment')->middleware(['permission:payments.refunds.record.branch', 'throttle:10,1'])->name('billing.refund');
            Route::post('refunds/{refund}/{decision}', 'decideRefund')->whereUuid('refund')->whereIn('decision', ['approved', 'rejected'])->middleware(['permission:payments.refunds.approve.branch', 'throttle:10,1'])->name('billing.refund.decide');
            Route::get('{invoice}/letterhead-logo/{asset}', 'letterheadLogo')->whereUuid('asset')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.letterhead-logo');
            Route::get('{invoice}/print', 'printInvoice')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.print');
            Route::get('{invoice}/receipts/{payment}/print', 'printReceipt')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.receipt');
            // BIL-5: printing is recorded; the frozen copy is shown from its own page.
            Route::post('{invoice}/issue', 'issueInvoice')->middleware(['permission:billing.print.branch', 'throttle:30,1'])->name('billing.issue');
            Route::post('{invoice}/receipts/{payment}/issue', 'issueReceipt')->middleware(['permission:billing.print.branch', 'throttle:30,1'])->name('billing.receipt.issue');
            Route::post('issued/{document}/reprint', 'reprintIssued')->whereUuid('document')->middleware(['permission:billing.print.branch', 'throttle:30,1'])->name('billing.issued.reprint');
            Route::get('issued/prints/{print}', 'showIssued')->whereUuid('print')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.issued.show');
        });
        Route::get('dispensary/{dispensaryCase}/labels', [DispensaryController::class, 'labels'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.labels.print.branch', 'throttle:30,1'])->name('dispensary.labels');
        Route::get('dispensary/{dispensaryCase}/items/{itemPublicId}/label', [DispensaryController::class, 'labels'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.labels.print.branch', 'throttle:30,1'])->whereUuid('itemPublicId')->name('dispensary.items.label');
        Route::post('dispensary/{dispensaryCase}/labels/print', [DispensaryController::class, 'printLabels'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.labels.print.branch', 'throttle:30,1'])->name('dispensary.labels.print');
        Route::post('dispensary/{dispensaryCase}/items/{itemPublicId}/label/print', [DispensaryController::class, 'printLabels'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.labels.print.branch', 'throttle:30,1'])->whereUuid('itemPublicId')->name('dispensary.items.label.print');
        Route::post('dispensary/{dispensaryCase}/call', [RoomCallController::class, 'dispensary'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('dispensary.call');
        Route::post('dispensary/{dispensaryCase}/start', [DispensaryController::class, 'start'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('dispensary.start');
        Route::patch('dispensary/{dispensaryCase}/items/{item}', [DispensaryController::class, 'updateItem'])
            ->whereUuid('dispensaryCase')->whereUuid('item')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.update');
        Route::post('dispensary/{dispensaryCase}/items', [DispensaryController::class, 'addItem'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.add');
        Route::put('dispensary/{dispensaryCase}/items/{item}/final', [DispensaryController::class, 'editItem'])
            ->whereUuid('dispensaryCase')->whereUuid('item')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.edit');
        Route::post('dispensary/{dispensaryCase}/items/{item}/remove', [DispensaryController::class, 'removeItem'])
            ->whereUuid('dispensaryCase')->whereUuid('item')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.remove');
        Route::post('dispensary/{dispensaryCase}/medicines/search', [DispensaryController::class, 'searchMedicines'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.medicines.search');
        // DSP-12: find the medicine mapped to a scanned SKU for the Add a medicine picker (read only).
        Route::post('dispensary/{dispensaryCase}/medicines/by-sku', [DispensaryController::class, 'medicinesForSku'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.medicines.by-sku');
        Route::post('dispensary/{dispensaryCase}/services', [DispensaryController::class, 'addService'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.add');
        Route::put('dispensary/{dispensaryCase}/services/{line}/final', [DispensaryController::class, 'editService'])
            ->whereUuid('dispensaryCase')->whereUuid('line')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.edit');
        Route::post('dispensary/{dispensaryCase}/services/{line}/remove', [DispensaryController::class, 'removeService'])
            ->whereUuid('dispensaryCase')->whereUuid('line')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.remove');
        Route::post('dispensary/{dispensaryCase}/services/search', [DispensaryController::class, 'searchServices'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.search');
        Route::post('dispensary/{dispensaryCase}/return-to-doctor', [DispensaryController::class, 'returnToDoctor'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.return_to_doctor.branch', 'throttle:20,1'])->name('dispensary.return-to-doctor');
        Route::post('dispensary/{dispensaryCase}/complete', [DispensaryController::class, 'complete'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.complete.branch', 'throttle:20,1'])->name('dispensary.complete');
        Route::post('dispensary-exceptions/{exception}/acknowledge', [DispensaryController::class, 'acknowledge'])
            ->whereUuid('exception')->middleware(['permission:dispensary.acknowledge_partial.own', 'throttle:20,1'])->name('dispensary.exceptions.acknowledge');
        Route::post('inventory/opening-balances', [InventoryMovementController::class, 'openingBalance'])
            ->middleware(['permission:inventory.opening_balance.branch', 'throttle:20,1'])->name('inventory.opening-balances.store');
        Route::post('inventory/transfers', [InventoryMovementController::class, 'transfer'])
            ->middleware(['permission:inventory.transfer.branch,inventory.transfer.organisation', 'throttle:30,1'])->name('inventory.transfers.store');
        Route::post('inventory/suppliers', [InventoryOperationsController::class, 'createSupplier'])->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:30,1'])->name('inventory.suppliers.store');
        Route::patch('inventory/suppliers/{supplier}', [InventoryOperationsController::class, 'updateSupplier'])->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:30,1'])->name('inventory.suppliers.update');
        Route::patch('inventory/suppliers/{supplier}/status', [InventoryOperationsController::class, 'supplierStatus'])->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:20,1'])->name('inventory.suppliers.status');
        Route::post('inventory/purchase-orders', [InventoryOperationsController::class, 'createPurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation', 'throttle:30,1'])->name('inventory.purchase-orders.store');
        Route::patch('inventory/purchase-orders/{purchaseOrder}', [InventoryOperationsController::class, 'updatePurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation', 'throttle:30,1'])->name('inventory.purchase-orders.update');
        Route::post('inventory/purchase-orders/{purchaseOrder}/submit', [InventoryOperationsController::class, 'submitPurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.submit');
        Route::post('inventory/purchase-orders/{purchaseOrder}/approve', [InventoryOperationsController::class, 'approvePurchaseOrder'])->middleware(['permission:inventory.purchase_orders.approve.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.approve');
        Route::post('inventory/purchase-orders/{purchaseOrder}/cancel', [InventoryOperationsController::class, 'cancelPurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation,inventory.purchase_orders.approve.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.cancel');
        Route::post('inventory/purchase-orders/{purchaseOrder}/close', [InventoryOperationsController::class, 'closePurchaseOrder'])->middleware(['permission:inventory.purchase_orders.approve.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.close');
        Route::post('inventory/purchase-orders/{purchaseOrder}/receipts', [InventoryOperationsController::class, 'receivePurchaseOrder'])->middleware(['permission:inventory.receiving.branch', 'throttle:30,1'])->name('inventory.purchase-orders.receipts.store');
        Route::post('inventory/purchase-orders/{purchaseOrder}/receiving-reviews', [InventoryOperationsController::class, 'requestReceivingReview'])->middleware(['permission:inventory.receiving.branch', 'throttle:30,1'])->name('inventory.receiving.reviews.store');
        Route::post('inventory/receiving-reviews/{review}/approve', [InventoryOperationsController::class, 'approveReceivingReview'])->whereUuid('review')->middleware(['permission:inventory.receiving.exceptions.approve.branch', 'throttle:20,1'])->name('inventory.receiving.reviews.approve');
        Route::post('inventory/receiving-reviews/{review}/reverse', [InventoryOperationsController::class, 'reverseReceivingReceipt'])->whereUuid('review')->middleware(['permission:inventory.receiving.branch', 'throttle:20,1'])->name('inventory.receiving.reviews.reverse');
        Route::post('inventory/stock-requests', [InventoryOperationsController::class, 'createStockRequest'])->middleware(['permission:inventory.stock_requests.create.branch', 'throttle:30,1'])->name('inventory.stock-requests.store');
        Route::post('inventory/stock-requests/{stockRequest}/approve', [InventoryOperationsController::class, 'approveStockRequest'])->middleware(['permission:inventory.stock_requests.approve.branch', 'throttle:20,1'])->name('inventory.stock-requests.approve');
        Route::post('inventory/stock-requests/{stockRequest}/reject', [InventoryOperationsController::class, 'rejectStockRequest'])->middleware(['permission:inventory.stock_requests.approve.branch', 'throttle:20,1'])->name('inventory.stock-requests.reject');
        Route::post('inventory/stock-requests/{stockRequest}/dispatch', [InventoryOperationsController::class, 'dispatchStockRequest'])->middleware(['permission:inventory.transfers.dispatch.branch', 'throttle:20,1'])->name('inventory.stock-requests.dispatch');
        Route::post('inventory/stock-requests/{stockRequest}/receive', [InventoryOperationsController::class, 'receiveStockRequest'])->middleware(['permission:inventory.transfers.receive.branch', 'throttle:20,1'])->name('inventory.stock-requests.receive');
        Route::post('inventory/stock-requests/{stockRequest}/differences', [InventoryOperationsController::class, 'resolveTransferDifference'])->middleware(['permission:inventory.variances.approve.branch', 'throttle:20,1'])->name('inventory.stock-requests.differences.resolve');
        Route::post('inventory/stocktakes', [InventoryOperationsController::class, 'createStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.store');
        Route::post('inventory/stocktakes/{stocktake}/start', [InventoryOperationsController::class, 'startStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.start');
        Route::post('inventory/stocktakes/{stocktake}/count', [InventoryOperationsController::class, 'countStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.count');
        Route::post('inventory/stocktakes/{stocktake}/post', [InventoryOperationsController::class, 'postStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.post');
        Route::post('inventory/stocktakes/{stocktake}/cancel', [InventoryOperationsController::class, 'cancelStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.cancel');
        Route::post('inventory/adjustments', [InventoryOperationsController::class, 'adjustment'])->middleware(['permission:inventory.adjust.branch', 'throttle:20,1'])->name('inventory.adjustments.store');
        Route::put('inventory/reorder-levels', [InventoryOperationsController::class, 'reorderLevel'])->middleware(['permission:inventory.reorder.manage.branch', 'throttle:30,1'])->name('inventory.reorder-levels.update');

        Route::middleware(['permission:medicines.manage.organisation'])->prefix('medicines')->group(function () {
            Route::get('/', [MedicineCatalogueController::class, 'index'])->name('medicines.index');
            Route::get('{medicine}/setup', [MedicineCatalogueController::class, 'editSetup'])
                ->whereUuid('medicine')->name('medicines.setup');
            Route::get('inventory-skus', [MedicineCatalogueController::class, 'searchInventorySkus'])
                ->middleware('throttle:60,1')->name('medicines.inventory-skus');
            Route::post('/', [MedicineCatalogueController::class, 'store'])->middleware('throttle:30,1')->name('medicines.store');
            Route::patch('{medicine}', [MedicineCatalogueController::class, 'update'])->whereUuid('medicine')->middleware('throttle:30,1')->name('medicines.update');
            Route::post('{medicine}/activate', [MedicineCatalogueController::class, 'activate'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.activate');
            Route::post('{medicine}/deactivate', [MedicineCatalogueController::class, 'deactivate'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.deactivate');
            Route::post('{medicine}/controlled', [MedicineCatalogueController::class, 'controlled'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.controlled');
            Route::post('{medicine}/allergy-groups', [MedicineCatalogueController::class, 'allergyGroups'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.allergy-groups');
            Route::post('{medicine}/allergy-aliases', [MedicineCatalogueController::class, 'allergyAliases'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.allergy-aliases');
        });

        Route::middleware(['permission:clinical_services.manage.organisation'])->prefix('clinical-services')->group(function () {
            Route::get('/', [ClinicalServiceCatalogueController::class, 'index'])->name('clinical-services.index');
            Route::post('consultation-tariffs', [ClinicalServiceCatalogueController::class, 'storeConsultationTariff'])
                ->middleware('throttle:20,1')->name('clinical-services.consultation-tariffs.store');
            Route::post('/', [ClinicalServiceCatalogueController::class, 'store'])->middleware('throttle:30,1')->name('clinical-services.store');
            Route::get('{clinicalService}/setup', [ClinicalServiceCatalogueController::class, 'editSetup'])->whereUuid('clinicalService')->middleware('throttle:60,1')->name('clinical-services.edit-setup');
            Route::patch('{clinicalService}', [ClinicalServiceCatalogueController::class, 'update'])->whereUuid('clinicalService')->middleware('throttle:30,1')->name('clinical-services.update');
            Route::post('{clinicalService}/activate', [ClinicalServiceCatalogueController::class, 'activate'])->whereUuid('clinicalService')->middleware('throttle:20,1')->name('clinical-services.activate');
            Route::post('{clinicalService}/deactivate', [ClinicalServiceCatalogueController::class, 'deactivate'])->whereUuid('clinicalService')->middleware('throttle:20,1')->name('clinical-services.deactivate');
        });

        Route::get('catalogue-options/{type}', [CatalogueOptionController::class, 'index'])
            ->where('type', '[a-z_]+')->middleware('throttle:60,1')->name('catalogue-options.index');
        Route::post('catalogue-options/{type}', [CatalogueOptionController::class, 'store'])
            ->where('type', '[a-z_]+')->middleware('throttle:30,1')->name('catalogue-options.store');
        Route::post('catalogue-panels', [CataloguePanelController::class, 'store'])
            ->middleware(['permission:pricing.references.manage.organisation', 'throttle:30,1'])->name('catalogue-panels.store');
        Route::post('catalogue-setup/suppliers', [CatalogueSetupReferenceController::class, 'storeSupplier'])
            ->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:30,1'])->name('catalogue-setup.suppliers.store');
        Route::post('catalogue-setup/locations', [CatalogueSetupReferenceController::class, 'storeLocation'])
            ->middleware(['permission:inventory.references.manage.organisation', 'throttle:30,1'])->name('catalogue-setup.locations.store');

        Route::prefix('pricing')->group(function () {
            Route::middleware(['permission:pricing.references.manage.organisation'])->group(function () {
                Route::get('/', [PricingController::class, 'index'])->name('pricing.index');
                Route::get('price-check', [PricingController::class, 'priceCheck'])->middleware('throttle:60,1')->name('pricing.price-check');
                Route::get('charges/{charge}/history', [PricingController::class, 'history'])->whereUuid('charge')->middleware('throttle:60,1')->name('pricing.charges.history');
                Route::post('charges', [PricingController::class, 'storeCharge'])->middleware('throttle:30,1')->name('pricing.charges.store');
                Route::post('charges/{charge}/activate', [PricingController::class, 'activateCharge'])->whereUuid('charge')->middleware('throttle:20,1')->name('pricing.charges.activate');
                Route::post('charges/{charge}/deactivate', [PricingController::class, 'deactivateCharge'])->whereUuid('charge')->middleware('throttle:20,1')->name('pricing.charges.deactivate');
                Route::post('price-books', [PricingController::class, 'storePriceBook'])->middleware('throttle:30,1')->name('pricing.price-books.store');
                Route::post('price-books/{priceBook}/activate', [PricingController::class, 'activatePriceBook'])->whereUuid('priceBook')->middleware('throttle:20,1')->name('pricing.price-books.activate');
                Route::post('price-books/{priceBook}/deactivate', [PricingController::class, 'deactivatePriceBook'])->whereUuid('priceBook')->middleware('throttle:20,1')->name('pricing.price-books.deactivate');
            });
            Route::post('charges/{charge}/publish', [PricingController::class, 'publish'])
                ->whereUuid('charge')->middleware(['permission:prices.publish.organisation', 'throttle:20,1'])->name('pricing.charges.publish');
        });

        Route::middleware(['permission:panels.manage.organisation', 'sensitive.no-store'])->prefix('checkout-settings')->group(function () {
            Route::get('/', [PanelSettingsController::class, 'index'])->name('panel-settings.index');
            Route::patch('claim-message', [PanelSettingsController::class, 'claimMessage'])->middleware('throttle:30,1')->name('panel-settings.claim-message');
            Route::get('panels/create', [PanelSettingsController::class, 'create'])->name('panel-settings.create');
            Route::post('panels', [PanelSettingsController::class, 'store'])->middleware('throttle:30,1')->name('panel-settings.store');
            Route::get('panels/{panel}/edit', [PanelSettingsController::class, 'edit'])->whereNumber('panel')->name('panel-settings.edit');
            Route::patch('panels/{panel}', [PanelSettingsController::class, 'update'])->whereNumber('panel')->middleware('throttle:30,1')->name('panel-settings.update');
            Route::post('panels/{panel}/active', [PanelSettingsController::class, 'active'])->whereNumber('panel')->middleware('throttle:30,1')->name('panel-settings.active');
        });

        Route::middleware(['permission:ai_proposals.view.branch', 'sensitive.no-store'])->prefix('agents/inbox')->group(function () {
            Route::get('/', [AgentInboxController::class, 'index'])->name('agents.inbox');
            Route::post('{proposal}/approve', [AgentInboxController::class, 'approve'])->whereNumber('proposal')->middleware('throttle:30,1')->name('agents.inbox.approve');
            Route::post('{proposal}/reject', [AgentInboxController::class, 'reject'])->whereNumber('proposal')->middleware('throttle:30,1')->name('agents.inbox.reject');
        });

        // AG-5: each answer is one agent run (a GET that records the run, as an explicit Answer press), so it is throttled.
        Route::middleware(['permission:ai_assistant.use.organisation', 'sensitive.no-store'])
            ->get('agents/assistant', [AgentAssistantController::class, 'index'])->middleware('throttle:30,1')->name('agents.assistant');

        Route::get('agents/daily-summary', [AgentDailySummaryController::class, 'index'])
            ->middleware(['permission:ai_daily_summary.view.branch', 'sensitive.no-store', 'throttle:60,1'])->name('agents.daily-summary');

        Route::middleware(['permission:ai_agents.view.organisation', 'sensitive.no-store'])->prefix('agents')->group(function () {
            Route::get('/', [AgentRegistryController::class, 'index'])->name('agents.index');
            Route::get('office-feed', [AgentRegistryController::class, 'feed'])->middleware('throttle:30,1')->name('agents.office-feed');
            Route::get('office', [AgentOfficeController::class, 'index'])->name('agents.office');
            Route::get('office/agents/{code}', [AgentOfficeController::class, 'agent'])->where('code', '[a-z][a-z0-9_]{1,59}')
                ->middleware('throttle:60,1')->name('agents.office.agent');
            Route::post('{agent}/enabled', [AgentRegistryController::class, 'enabled'])->whereNumber('agent')
                ->middleware(['permission:ai_agents.manage.organisation', 'throttle:20,1'])->name('agents.enabled');
        });

        Route::middleware(['permission:payment_methods.manage.organisation'])->prefix('payment-methods')->group(function () {
            Route::get('/', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
            Route::post('/', [PaymentMethodController::class, 'store'])->middleware('throttle:30,1')->name('payment-methods.store');
            Route::patch('{method}', [PaymentMethodController::class, 'update'])->whereNumber('method')->middleware('throttle:30,1')->name('payment-methods.update');
            Route::patch('{method}/classification', [PaymentMethodController::class, 'classification'])->whereNumber('method')->middleware('throttle:30,1')->name('payment-methods.classification');
            Route::post('{method}/publish', [PaymentMethodController::class, 'publish'])->whereNumber('method')->middleware('throttle:20,1')->name('payment-methods.publish');
            Route::post('{method}/deactivate', [PaymentMethodController::class, 'deactivate'])->whereNumber('method')->middleware('throttle:20,1')->name('payment-methods.deactivate');
        });
        Route::middleware(['permission:billing.approval_limits.manage.organisation'])->prefix('billing-approval-limits')->group(function () {
            Route::get('/', [BillingApprovalLimitController::class, 'index'])->name('billing-approval-limits.index');
            Route::post('/', [BillingApprovalLimitController::class, 'store'])->middleware('throttle:30,1')->name('billing-approval-limits.store');
            Route::post('adjustment-threshold', [BillingApprovalLimitController::class, 'adjustmentThreshold'])->middleware('throttle:30,1')->name('billing-approval-limits.adjustment-threshold');
            Route::post('clear', [BillingApprovalLimitController::class, 'clear'])->middleware('throttle:30,1')->name('billing-approval-limits.clear');
        });
        Route::middleware(['permission:payments.reconcile.branch', 'sensitive.no-store'])->prefix('payment-reconciliations')->group(function () {
            Route::get('/', [PaymentReconciliationController::class, 'index'])->name('payment-reconciliations.index');
            Route::post('/', [PaymentReconciliationController::class, 'store'])->middleware('throttle:20,1')->name('payment-reconciliations.store');
        });
        Route::middleware(['permission:payments.reconcile.branch', 'sensitive.no-store'])->prefix('cash-closings')->group(function () {
            Route::get('/', [CashClosingController::class, 'index'])->name('cash-closings.index');
            Route::get('month', [CashClosingController::class, 'month'])->name('cash-closings.month');
            Route::post('start-date', [CashClosingController::class, 'startDate'])->middleware(['permission:payments.cash_closing.start_date.manage.branch', 'throttle:20,1'])->name('cash-closings.start-date');
            Route::post('/', [CashClosingController::class, 'store'])->middleware('throttle:20,1')->name('cash-closings.store');
            Route::post('{closing}/{decision}', [CashClosingController::class, 'decide'])->whereUuid('closing')->whereIn('decision', ['approved', 'returned'])
                ->middleware(['permission:payments.cash_closing.approve.branch', 'throttle:20,1'])->name('cash-closings.decide');
        });

        Route::middleware(['permission:inventory.references.manage.organisation'])->prefix('inventory-references')->group(function () {
            Route::post('items', [InventoryReferenceController::class, 'storeItem'])->middleware('throttle:30,1')->name('inventory-references.items.store');
            Route::post('skus', [InventoryReferenceController::class, 'storeSku'])->middleware('throttle:30,1')->name('inventory-references.skus.store');
            Route::post('locations', [InventoryReferenceController::class, 'storeLocation'])->middleware('throttle:30,1')->name('inventory-references.locations.store');
            Route::get('skus/{sku}/batches', [InventoryReferenceController::class, 'skuBatches'])->whereUuid('sku')->middleware('throttle:60,1')->name('inventory-references.skus.batches');
            Route::post('batches', [InventoryReferenceController::class, 'storeBatch'])->middleware('throttle:30,1')->name('inventory-references.batches.store');
            Route::post('mappings', [InventoryReferenceController::class, 'storeMapping'])->middleware('throttle:30,1')->name('inventory-references.mappings.store');
        });
        Route::post('visits/{visit}/encounter/treatment-plan/catalogue/medicines/search', [TreatmentPlanCatalogueController::class, 'medicines'])
            ->middleware(['permission:treatment_plans.view.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.catalogue.medicines');
        Route::post('visits/{visit}/encounter/treatment-plan/catalogue/services/search', [TreatmentPlanCatalogueController::class, 'services'])
            ->middleware(['permission:treatment_plans.view.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.catalogue.services');

        Route::get('queue', [QueueController::class, 'index'])
            ->middleware('permission:queue.view.own,queue.view.branch')
            ->name('queue.index');
        Route::post('visits/{visit}/queue/readiness', [QueueReadinessController::class, 'store'])->middleware(['permission:queue.call_hold.set.branch', 'sensitive.no-store', 'throttle:60,1'])->name('queue.readiness.set');
        Route::patch('visits/{visit}/queue/readiness', [QueueReadinessController::class, 'clear'])->middleware(['permission:queue.call_hold.set.branch,queue.call_hold.clear.branch', 'sensitive.no-store', 'throttle:60,1'])->name('queue.readiness.clear');
        Route::get('queue/readiness-updates', [QueueReadinessController::class, 'updates'])->middleware(['permission:queue.view.own,queue.view.branch', 'sensitive.no-store', 'throttle:60,1'])->name('queue.readiness.updates');
        Route::post('visits/{visit}/queue/treatment-steps', [TreatmentProgressController::class, 'store'])->middleware(['permission:queue.treatment_progress.set.branch,queue.treatment_progress.set.own', 'sensitive.no-store', 'throttle:60,1'])->name('queue.treatment.start');
        Route::patch('visits/{visit}/queue/treatment-steps/done', [TreatmentProgressController::class, 'done'])->middleware(['permission:queue.treatment_progress.set.branch,queue.treatment_progress.set.own', 'sensitive.no-store', 'throttle:60,1'])->name('queue.treatment.done');
        Route::patch('visits/{visit}/queue/treatment-steps/cancel', [TreatmentProgressController::class, 'cancel'])->middleware(['permission:queue.treatment_progress.set.branch,queue.treatment_progress.set.own', 'sensitive.no-store', 'throttle:60,1'])->name('queue.treatment.cancel');
        Route::get('queue/treatment-updates', [TreatmentProgressController::class, 'updates'])->middleware(['permission:queue.view.own', 'sensitive.no-store', 'throttle:60,1'])->name('queue.treatment.updates');
        Route::get('queue/waiting-counts', [QueueController::class, 'waitingCounts'])
            ->middleware(['permission:queue.view.own,queue.view.branch', 'sensitive.no-store', 'throttle:60,1'])
            ->name('queue.waiting-counts');
        Route::post('queue/search', [QueueController::class, 'search'])
            ->middleware(['permission:queue.view.own,queue.view.branch', 'throttle:120,1'])
            ->name('queue.search');
        Route::post('visits/{visit}/queue', [QueueController::class, 'store'])
            ->middleware(['permission:queue.enter.branch', 'throttle:30,1'])
            ->name('queue.store');
        Route::patch('visits/{visit}/queue/call', [QueueController::class, 'call'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.call');
        Route::get('queue/call-next', [QueueController::class, 'callNextBlock'])
            ->middleware(['permission:queue.call.own', 'sensitive.no-store', 'throttle:60,1'])
            ->name('queue.call-next.block');
        Route::post('queue/call-next', [QueueController::class, 'callNext'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.call-next');
        Route::post('visits/{visit}/otc-queue/call', [RoomCallController::class, 'otcDispensary'])
            ->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('otc-queue.call');
        Route::patch('visits/{visit}/queue/treatment-call', [RoomCallController::class, 'treatment'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.treatment-call');
        Route::patch('visits/{visit}/queue/recall', [QueueController::class, 'recall'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.recall');
        Route::put('queue/room', [QueueController::class, 'room'])
            ->middleware(['permission:queue.room.select.own', 'throttle:30,1'])
            ->name('queue.room');

        Route::get('registration', [RegistrationController::class, 'index'])
            ->middleware('permission:visits.view.branch')
            ->name('registration.index');
        Route::get('visits/{visit}/invoice', [VisitHistoryController::class, 'invoice'])
            ->middleware(['permission:visits.history.view.branch', 'sensitive.no-store'])
            ->name('visits.invoice');

        Route::get('visits/{visit}/history', VisitHistoryController::class)
            ->middleware(['permission:visits.history.view.branch', 'sensitive.no-store'])
            ->name('visits.history');
        Route::post('registration/search', [RegistrationController::class, 'search'])
            ->middleware(['permission:visits.view.branch', 'throttle:60,1'])
            ->name('registration.search');
        Route::get('registration/create', [RegistrationController::class, 'create'])
            ->middleware('permission:visits.create.branch')
            ->name('registration.create');
        Route::post('registration', [RegistrationController::class, 'store'])
            ->middleware(['permission:visits.create.branch', 'throttle:30,1'])
            ->name('registration.store');
        Route::get('visit-reasons', [VisitReasonController::class, 'index'])
            ->middleware(['permission:visits.create.branch', 'throttle:120,1'])
            ->name('visit-reasons.index');
        Route::post('visit-reasons', [VisitReasonController::class, 'store'])
            ->middleware(['permission:visits.create.branch', 'throttle:30,1'])
            ->name('visit-reasons.store');
        Route::get('visits/{visit}', [VisitController::class, 'show'])
            ->middleware('permission:visits.view.branch')
            ->name('visits.show');
        Route::get('visits/{visit}/edit', [VisitController::class, 'edit'])
            ->middleware('permission:visits.update.branch')
            ->name('visits.edit');
        Route::patch('visits/{visit}', [VisitController::class, 'update'])
            ->middleware('permission:visits.update.branch')
            ->name('visits.update');
        Route::patch('visits/{visit}/cancel', [VisitController::class, 'cancel'])
            ->middleware('permission:visits.cancel.branch')
            ->name('visits.cancel');

        Route::get('patients', [PatientController::class, 'index'])
            ->middleware('permission:patients.search.organisation')
            ->name('patients.index');
        Route::post('patients/search', [PatientSearchController::class, 'search'])
            ->middleware(['permission:patients.search.organisation', 'throttle:60,1'])
            ->name('patients.search');
        Route::post('patients/duplicate-check', [PatientSearchController::class, 'duplicateCheck'])
            ->middleware(['permission:patients.create.organisation', 'throttle:30,1'])
            ->name('patients.duplicate-check');
        Route::get('patients/create', [PatientController::class, 'create'])
            ->middleware('permission:patients.create.organisation')
            ->name('patients.create');
        Route::post('patients', [PatientController::class, 'store'])
            ->middleware(['permission:patients.create.organisation', 'throttle:20,1'])
            ->name('patients.store');
        Route::get('patients/{patient}', [PatientController::class, 'show'])
            ->middleware('permission:patients.view.organisation')
            ->name('patients.show');
        // REG-4: patient change history (field names only).
        Route::get('patients/{patient}/history', [PatientController::class, 'history'])
            ->middleware(['permission:patients.history.view.organisation', 'sensitive.no-store', 'throttle:60,1'])
            ->name('patients.history');
        // REG-3: two-person patient merge (link only).
        Route::get('patients/{patient}/merge-compare', [PatientMergeController::class, 'compare'])
            ->middleware(['permission:patients.merge.propose.organisation', 'sensitive.no-store', 'throttle:30,1'])
            ->name('patients.merge-compare');
        Route::post('patients/{patient}/merge-requests', [PatientMergeController::class, 'store'])
            ->middleware(['permission:patients.merge.propose.organisation', 'throttle:20,1'])
            ->name('patients.merge-requests.store');
        Route::get('patient-merges', [PatientMergeController::class, 'index'])
            ->middleware('permission:patients.merge.propose.organisation,patients.merge.approve.organisation')
            ->name('patient-merges.index');
        Route::post('patient-merges/{mergeRequest}/approve', [PatientMergeController::class, 'approve'])
            ->whereUuid('mergeRequest')->middleware(['permission:patients.merge.approve.organisation', 'throttle:20,1'])
            ->name('patient-merges.approve');
        Route::post('patient-merges/{mergeRequest}/reject', [PatientMergeController::class, 'reject'])
            ->whereUuid('mergeRequest')->middleware(['permission:patients.merge.approve.organisation', 'throttle:20,1'])
            ->name('patient-merges.reject');
        Route::post('patient-merges/{mergeRequest}/withdraw', [PatientMergeController::class, 'withdraw'])
            ->whereUuid('mergeRequest')->middleware(['permission:patients.merge.propose.organisation', 'throttle:20,1'])
            ->name('patient-merges.withdraw');
        Route::get('patients/{patient}/edit', [PatientController::class, 'edit'])
            ->middleware('permission:patients.update.organisation')
            ->name('patients.edit');
        Route::patch('patients/{patient}', [PatientController::class, 'update'])
            ->middleware('permission:patients.update.organisation')
            ->name('patients.update');
        Route::post('patients/{patient}/identifiers', [PatientIdentifierController::class, 'store'])
            ->middleware('permission:patients.update.organisation')
            ->name('patients.identifiers.store');
        Route::patch('patients/{patient}/identifiers/{identifier}', [PatientIdentifierController::class, 'replace'])
            ->scopeBindings()
            ->middleware('permission:patients.identifiers.manage.organisation')
            ->name('patients.identifiers.replace');
        Route::patch('patients/{patient}/identifiers/{identifier}/retire', [PatientIdentifierController::class, 'retire'])
            ->scopeBindings()
            ->middleware('permission:patients.identifiers.manage.organisation')
            ->name('patients.identifiers.retire');
    });
});

require __DIR__.'/settings.php';

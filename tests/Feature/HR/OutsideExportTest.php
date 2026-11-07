<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-12 export for an outside HR product (HR-PLAN 15.1; the owner's "start the HR-12 build" and answers, 2026-11-07):
 * a generic CSV of the staff list, approved leave and approved overtime, for the Director and the HR Manager only,
 * audited before the bytes are returned, never stored, formula-safe, and never holding an identity number, bank detail,
 * document, salary or reason. All data is synthetic.
 */
class OutsideExportTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    private User $ca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-02 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->ca = $this->actor('ca');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', $this->branch->timezone));
    }

    public function test_the_three_files_hold_exactly_the_named_columns_and_rows(): void
    {
        $worker = $this->staffFile($this->ca);
        DB::table('hr_employment_periods')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'type' => 'permanent', 'starts_on' => '2026-08-06', 'probation_ends_on' => '2026-11-30', 'created_by_user_id' => $this->director->id,
            'created_at' => now(), 'updated_at' => now()]);
        // A position title that looks like a formula is neutralised in the file.
        DB::table('hr_positions')->insert(['organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, 'title' => '=Clinic Assistant',
            'from_date' => '2026-08-06', 'created_by_user_id' => $this->director->id, 'created_at' => now()]);
        $this->selectBranch($this->director);
        $this->post(route('hr.records.locums.store'), ['display_name' => 'Synthetic Locum', 'phone' => '+60123456780', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->leave($this->ca, '2026-10-30', '2026-11-02', 'full', 'am', 5, 'Annual leave', true, 'approved');
        $this->leave($this->ca, '2026-11-10', '2026-11-10', 'full', 'full', 2, 'Unpaid leave', false, 'pending');
        $this->overtime($this->ca, '2026-11-12', 'rest_day', 95, 'approved');
        $this->overtime($this->ca, '2026-11-13', 'normal', 30, 'pending');
        $this->overtime($this->ca, '2026-12-01', 'normal', 60, 'approved');

        $this->selectBranch($this->hr);
        $staff = $this->download('staff');
        $staff->assertOk();
        $this->assertStringContainsString('no-store', (string) $staff->headers->get('Cache-Control'));
        $this->assertStringContainsString('kpone-hr-staff-2026-11-20.csv', (string) $staff->headers->get('Content-Disposition'));
        $rows = $this->rows($staff->streamedContent());
        $this->assertSame(['KPOne ID', 'Name', 'Kind', 'Position', 'Employment type', 'Starts on', 'Probation ends on', 'Confirmed on', 'Ends on', 'Primary branch', 'Status'],
            $rows[3]);
        $this->assertContains([(string) $worker->public_id, $this->ca->name, 'Staff', "'=Clinic Assistant", 'Permanent', '2026-08-06', '2026-11-30', '', '', $this->branch->name, 'Active'],
            $rows);
        $this->assertTrue(collect($rows)->contains(fn (array $r): bool => ($r[1] ?? null) === 'Synthetic Locum' && $r[2] === 'Locum' && $r[10] === 'No employment period'));
        $this->assertSame('KPOne HR export', $rows[0][0]);

        // Leave touching November, approved only, with all of its days; never the reason.
        $leave = $this->rows($this->download('leave', '2026-11')->assertOk()->streamedContent());
        $this->assertSame(['KPOne ID', 'Name', 'Leave type', 'From', 'To', 'Days', 'Paid'], $leave[3]);
        $this->assertSame([[(string) $worker->public_id, $this->ca->name, 'Annual leave', '2026-10-30', '2026-11-02 (am)', '2.5', 'Yes']], array_slice($leave, 4));

        // Overtime approved in November only, minutes and day type, never pay.
        $overtime = $this->rows($this->download('overtime', '2026-11')->assertOk()->streamedContent());
        $this->assertSame(['KPOne ID', 'Name', 'Date', 'Day type', 'Approved minutes'], $overtime[3]);
        $this->assertSame([[(string) $worker->public_id, $this->ca->name, '2026-11-12', 'Rest day', '95']], array_slice($overtime, 4));
        $all = $staff->streamedContent();
        foreach (['multiplier', '1.50', 'IC', 'Passport', 'Bank', 'Account', 'Salary', 'Reason'] as $absent) {
            $this->assertStringNotContainsString($absent, (string) $all.implode(',', array_merge(...$leave)).implode(',', array_merge(...$overtime)), $absent);
        }

        // Every download is audited first, with the kind, month and row count only.
        $audits = DB::table('audit_logs')->where('event', 'hr.outside_export.downloaded')->orderBy('id')->pluck('metadata')->map(fn ($m) => json_decode((string) $m, true))->all();
        $this->assertCount(3, $audits);
        $this->assertSame(['kind' => 'leave', 'month' => '2026-11', 'rows' => 1], array_intersect_key($audits[1], ['kind' => 1, 'month' => 1, 'rows' => 1]));
        $this->assertStringNotContainsString($this->ca->name, json_encode($audits, JSON_THROW_ON_ERROR));
    }

    public function test_only_the_director_and_the_hr_manager_download_and_a_month_is_required(): void
    {
        $this->selectBranch($this->director);
        $this->download('staff')->assertOk();
        $this->selectBranch($this->hr);
        $this->download('leave')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->download('overtime', '2026-13')->assertUnprocessable();
        $this->post('/hr/records/outside-export/payroll', [], ['Accept' => 'application/json'])->assertNotFound();
        foreach (['ca', 'finance_officer', 'ca_supervisor', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->download('staff')->assertForbidden();
        }
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.outside_export.downloaded')->count());
    }

    public function test_the_staff_files_tab_offers_the_export_to_managers_only(): void
    {
        $this->selectBranch($this->hr);
        $this->get(route('hr.records.index', ['tab' => 'files']))->assertOk()->assertInertia(fn ($page) => $page->where('records.canManage', true));
        $this->selectBranch($this->ca);
        $this->get(route('hr.records.index'))->assertOk()->assertInertia(fn ($page) => $page->where('records.canManage', false));
    }

    // ---- fixture ----

    private function download(string $kind, ?string $month = null): TestResponse
    {
        return $this->post(route('hr.records.outside-export', $kind), $month === null ? [] : ['month' => $month], ['Accept' => 'application/json']);
    }

    /** @return list<list<string>> */
    private function rows(string|false $csv): array
    {
        $csv = ltrim((string) $csv, "\u{FEFF}");
        $out = [];
        foreach (preg_split('/\r\n|\n/', trim($csv)) ?: [] as $line) {
            $out[] = $line === '' ? [] : str_getcsv($line, ',', '"', '');
        }

        return $out;
    }

    private function staffFile(User $subject): Worker
    {
        $this->selectBranch($this->director);
        $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Worker::query()->with('user')->where('user_id', $subject->id)->firstOrFail();
    }

    private function leave(User $person, string $from, string $to, string $startPart, string $endPart, int $halves, string $type, bool $paid, string $status): void
    {
        $typeId = DB::table('hr_leave_types')->where('organisation_id', $person->organisation_id)->where('name', $type)->value('id')
            ?? DB::table('hr_leave_types')->insertGetId(['organisation_id' => $person->organisation_id, 'code' => strtoupper(substr(str_replace(' ', '', $type), 0, 8)),
                'name' => $type, 'is_paid' => $paid, 'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id,
                'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id,
            'leave_type_id' => $typeId, 'branch_id' => $this->branch->id, 'start_date' => $from, 'end_date' => $to, 'start_part' => $startPart, 'end_part' => $endPart,
            'slot_start' => "{$from} 00:00:00", 'slot_end' => "{$to} ".($endPart === 'am' ? '12:00:00' : '23:59:59'), 'year' => 2026, 'halves' => $halves,
            'status' => 'pending', 'reason_encrypted' => encrypt('Synthetic reason'), 'created_at' => now(), 'updated_at' => now()]);
        if ($status === 'approved') {
            DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->hr->id, 'decided_at' => now()]);
        }
    }

    private function overtime(User $person, string $date, string $dayType, int $minutes, string $status): void
    {
        $zone = $this->branch->timezone;
        $session = new ClockSession;
        $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id, 'branch_id' => $this->branch->id,
            'local_date' => $date, 'timezone' => $zone, 'clock_in_at' => CarbonImmutable::parse("{$date} 18:00", $zone)->utc(),
            'clock_out_at' => CarbonImmutable::parse("{$date} 21:00", $zone)->utc(), 'status' => 'closed'])->save();
        $setting = DB::table('hr_overtime_settings')->where('organisation_id', $person->organisation_id)->value('id') ?? DB::table('hr_overtime_settings')->insertGetId([
            'organisation_id' => $person->organisation_id, 'effective_from' => '2026-01-01', 'normal_multiplier' => '1.50', 'rest_day_multiplier' => '2.00',
            'public_holiday_multiplier' => '3.00', 'nearing_hours' => 40, 'limit_hours' => 45, 'created_by_user_id' => $this->hr->id, 'created_at' => now()]);
        $id = DB::table('hr_overtime_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id,
            'requested_by_user_id' => $person->id, 'clock_session_id' => $session->id, 'branch_id' => $this->branch->id, 'local_date' => $date,
            'minutes_requested' => $minutes, 'reason_code' => 'patient_load', 'day_type' => $dayType, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if ($status === 'approved') {
            DB::table('hr_overtime_requests')->where('id', $id)->update(['status' => 'approved', 'minutes_approved' => $minutes, 'overtime_setting_id' => $setting,
                'multiplier' => '1.50', 'worked_minutes_at_decision' => $minutes, 'decided_by_user_id' => $this->hr->id, 'decided_at' => now()]);
        }
    }
}

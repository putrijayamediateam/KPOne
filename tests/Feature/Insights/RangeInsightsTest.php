<?php

namespace Tests\Feature\Insights;

use App\Domain\Queue\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestCase;

class RangeInsightsTest extends BillingTestCase
{
    public function test_authorized_range_reports_are_read_only_and_return_the_selected_aggregate_sections(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur'));
        $this->finalizedFixture(12345);
        $this->actingAs($this->actor('marketing'));

        foreach (['sales', 'in-clinic', 'payments', 'inventory', 'patients'] as $section) {
            $this->get(route('insights.report', [
                'section' => $section,
                'from' => '2026-10-01',
                'to' => '2026-10-01',
            ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Insights/Report')
                    ->where('filters.section', $section)
                    ->where('data.report.kind', $section));
        }
    }

    public function test_range_report_uses_an_organisation_scoped_branch_and_rejects_invalid_filters(): void
    {
        $this->actingAs($this->actor('ca'));

        $this->get(route('insights.report', [
            'section' => 'sales',
            'branch' => 'PUCHONG',
            'from' => '2026-10-01',
            'to' => '2026-10-01',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.branch', 'PUCHONG')
                ->where('data.report.summary.totalSen', 0));

        $this->get(route('insights.report', [
            'section' => 'sales',
            'branch' => 'UNKNOWN',
            'from' => '2026-10-01',
            'to' => '2026-10-01',
        ]))->assertNotFound();

        $this->get(route('insights.report', [
            'section' => 'sales',
            'from' => '2026-10-02',
            'to' => '2026-10-01',
        ]))->assertSessionHasErrors('to');
    }

    public function test_sales_and_patient_reports_project_period_totals_without_patient_identity(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur'));
        [$doctor, , , $invoice] = $this->finalizedFixture(12345);
        $this->actingAs($this->actor('marketing'));

        $this->get(route('insights.report', [
            'section' => 'sales',
            'from' => '2026-10-01',
            'to' => '2026-10-01',
            'doctor' => $doctor->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.report.summary.totalSen', $invoice->total_sen)
                ->where('data.report.summary.patients', 1)
                ->where('data.report.providers.0.name', $doctor->name)
                ->where('data.report.rankingTruncated.services', false)
                ->where('data.report.rankingTruncated.medicines', false));

        $this->get(route('insights.report', [
            'section' => 'patients',
            'from' => '2026-10-01',
            'to' => '2026-10-01',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.report.summary.visits', 1)
                ->missing('data.report.patientNames')
                ->missing('data.report.identifiers'));
    }

    public function test_patient_report_contains_only_aggregates_and_marks_appointments_unavailable(): void
    {
        $this->actingAs($this->actor('marketing'));

        $this->get(route('insights.report', [
            'section' => 'patients',
            'from' => '2026-10-01',
            'to' => '2026-10-01',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.report.summary.appointmentsAvailable', false)
                ->where('data.report.walkInVsAppointment', null)
                ->missing('data.report.patients'));
    }

    public function test_in_clinic_report_includes_today_active_visits_but_not_stale_registered_visits(): void
    {
        $now = CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur');
        $this->travelTo($now);
        [$doctor, $ca, $activeVisit, $activeQueue] = $this->servingFixture();
        $activeVisit->forceFill([
            'registered_at' => $now->subHour()->utc(),
        ])->save();
        $activeQueue->forceFill([
            'queued_at' => $now->subMinutes(50)->utc(),
            'called_at' => $now->subMinutes(40)->utc(),
        ])->save();

        $waitingVisit = $this->consultationVisit($ca, $doctor);
        $waitingVisit->forceFill([
            'registered_at' => $now->subHour()->utc(),
        ])->save();
        $waitingQueue = $this->send($ca, $waitingVisit);
        $waitingQueue->forceFill([
            'queued_at' => $now->subMinutes(50)->utc(),
            'called_at' => null,
        ])->save();

        $staleVisit = $this->consultationVisit($ca, $doctor);
        $staleVisit->forceFill([
            'registered_at' => $now->subDay()->subHour()->utc(),
        ])->save();
        $staleQueue = $this->send($ca, $staleVisit);
        $staleQueue->forceFill([
            'status' => QueueEntry::STATUS_WAITING,
            'queued_at' => $now->subDay()->subMinutes(50)->utc(),
            'called_at' => null,
        ])->save();

        $this->actingAs($this->actor('marketing'));

        $this->get(route('insights.report', [
            'section' => 'in-clinic',
            'from' => '2026-09-30',
            'to' => '2026-10-01',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.report.time.inClinic.average', 60)
                ->where('data.report.time.waiting.average', 30)
                ->where('data.report.time.serving.average', 40)
                ->where('data.report.time.inClinic.trend.0.date', '2026-10-01'));
    }

    public function test_appointment_insights_are_not_registered(): void
    {
        $this->actingAs($this->actor('marketing'));

        $this->get('/insights/appointments')->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Owner decision 2026-11-18: pressing Register visit always sends the Visit to waiting ("always send, later can edit
 * or cancel visit"), in one transaction, and returns to the Registration board.
 */
class RegisterAndSendToWaitingTest extends QueueTestCase
{
    /** @return array<string, mixed> */
    private function consultationPayload(User $ca, User $doctor, array $overrides = []): array
    {
        $reason = app(VisitReasonService::class)->create($ca, 'Synthetic waiting reason');

        return $this->visitAttributes($this->patient($ca), [
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => null,
            'visit_reason_public_ids' => [$reason->public_id],
            ...$overrides,
        ]);
    }

    public function test_consultation_is_registered_and_sent_to_waiting_in_one_step_and_returns_to_the_board(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $payload = $this->consultationPayload($ca, $doctor);

        $this->post(route('registration.store'), $payload)
            ->assertRedirect(route('registration.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Visit KPV-00000001 registered · A-001 is waiting']);

        $visit = Visit::query()->sole();
        $entry = QueueEntry::query()->sole();
        $this->assertSame(Visit::STATUS_REGISTERED, $visit->status);
        $this->assertSame($visit->id, $entry->visit_id);
        $this->assertSame(QueueEntry::STATUS_WAITING, $entry->status);
        $this->assertSame(1, $entry->queue_number);
        $this->assertSame(0, OtcQueueEntry::query()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'visit.created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'queue.entered')->count());

        // A retry with the same key returns the same Visit and entry: nothing new is written.
        $audits = DB::table('audit_logs')->count();
        $this->post(route('registration.store'), $payload)->assertRedirect(route('registration.index'));
        $this->assertSame(1, Visit::query()->count());
        $this->assertSame(1, QueueEntry::query()->count());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_otc_is_registered_and_joins_the_b_list(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $payload = $this->visitAttributes($this->patient($ca));

        $this->post(route('registration.store'), $payload)
            ->assertRedirect(route('registration.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Visit KPV-00000001 registered · B-001 is waiting']);

        $this->assertSame(0, QueueEntry::query()->count(), 'the consultation queue is untouched');
        $entry = OtcQueueEntry::query()->sole();
        $this->assertSame(OtcQueueEntry::STATUS_WAITING, $entry->status);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'otc_queue.entered')->count());

        $this->post(route('registration.store'), $payload)->assertRedirect(route('registration.index'));
        $this->assertSame(1, Visit::query()->count());
        $this->assertSame(1, OtcQueueEntry::query()->count());
    }

    public function test_a_refused_send_rolls_the_registration_back_and_shows_the_reason(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $payload = $this->consultationPayload($ca, $doctor);
        $this->app->bind(QueueEntryService::class, fn () => new class extends QueueEntryService
        {
            public function __construct() {}

            public function enter(User $actor, Visit $visit, array $attributes): QueueEntry
            {
                throw ValidationException::withMessages(['assigned_doctor_user_id' => 'Synthetic refusal: this doctor cannot take the queue today.']);
            }
        });

        $this->from(route('registration.create'))->post(route('registration.store'), $payload)
            ->assertRedirect(route('registration.create'))
            ->assertSessionHasErrors(['assigned_doctor_user_id' => 'Synthetic refusal: this doctor cannot take the queue today.']);

        $this->assertSame(0, Visit::query()->count(), 'nothing is saved');
        $this->assertSame(0, QueueEntry::query()->count());
        $this->assertSame(0, DB::table('visit_number_counters')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'visit.created')->count());
    }

    public function test_an_account_that_may_register_but_not_queue_registers_only_as_before(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        Role::findByName('ca')->revokePermissionTo('queue.enter.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($ca);

        $this->post(route('registration.store'), $this->consultationPayload($ca, $doctor))
            ->assertRedirect(route('visits.show', Visit::query()->firstOrFail()->visit_number ?? 'x'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Visit registered.']);

        $this->assertSame(1, Visit::query()->count());
        $this->assertSame(0, QueueEntry::query()->count());
    }

    public function test_a_new_visit_is_already_waiting_so_send_to_waiting_is_not_offered(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $this->consultationPayload($ca, $doctor))->assertRedirect(route('registration.index'));
        $visit = Visit::query()->sole();

        $this->get(route('visits.show', $visit))->assertOk()
            ->assertInertia(fn ($page) => $page->where('visit.can.sendToWaiting', false)->where('visit.queue.status', QueueEntry::STATUS_WAITING));
    }

    public function test_a_waiting_visit_can_be_cancelled_through_waiting_consultation_and_otc(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $this->consultationPayload($ca, $doctor))->assertRedirect(route('registration.index'));
        $this->post(route('registration.store'), $this->visitAttributes($this->patient($ca)))->assertRedirect(route('registration.index'));

        foreach (Visit::query()->orderBy('id')->get() as $visit) {
            $entry = $visit->visit_type === 'otc' ? OtcQueueEntry::query()->where('visit_id', $visit->id)->first() : QueueEntry::query()->where('visit_id', $visit->id)->first();
            $this->patch(route('visits.cancel', $visit), [
                'expected_branch_id' => $visit->branch_id, 'lock_version' => $visit->lock_version,
                'queue_lock_version' => $visit->visit_type === 'otc' ? null : $entry->lock_version,
                'cancellation_reason' => 'Synthetic: patient left',
            ])->assertRedirect(route('visits.show', $visit))->assertSessionHasNoErrors();
            $this->assertSame(Visit::STATUS_CANCELLED, $visit->refresh()->status);
            $this->assertSame('removed', $entry->refresh()->status);
        }
    }

    public function test_changing_the_doctor_while_waiting_keeps_the_place_the_number_and_the_not_ready_flag(): void
    {
        $ca = $this->actor('ca');
        $first = $this->doctor();
        $second = $this->doctor();
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $this->consultationPayload($ca, $first))->assertRedirect(route('registration.index'));
        $visit = Visit::query()->sole();
        $entry = QueueEntry::query()->sole();
        $this->post(route('queue.readiness.set', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id, 'queue_lock_version' => $entry->refresh()->lock_version,
            'reason_code' => 'other_administrative', 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $entry->refresh();
        $before = $entry->only(['id', 'queue_number', 'queued_at', 'operational_date', 'status', 'lock_version']);

        $this->patch(route('visits.update', $visit), $this->waitingEdit($visit, $entry, ['assigned_doctor_user_id' => $second->id]))
            ->assertRedirect(route('visits.show', $visit))->assertSessionHasNoErrors();

        $this->assertSame($second->id, $visit->refresh()->assigned_doctor_user_id);
        $after = $entry->refresh()->only(['id', 'queue_number', 'queued_at', 'operational_date', 'status', 'lock_version']);
        $this->assertEquals($before, $after, 'same entry, number, arrival time and date');
        $this->assertSame(1, DB::table('queue_call_holds')->where('queue_entry_id', $entry->id)->whereNull('closed_at')->count(), 'the not-ready flag stays');
    }

    public function test_urgency_can_be_changed_while_waiting_and_the_entry_is_not_rewritten(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $this->consultationPayload($ca, $doctor))->assertRedirect(route('registration.index'));
        $visit = Visit::query()->sole();
        $entry = QueueEntry::query()->sole();

        $this->patch(route('visits.update', $visit), $this->waitingEdit($visit, $entry, ['priority' => 'urgent']))
            ->assertRedirect(route('visits.show', $visit))->assertSessionHasNoErrors();

        $this->assertSame('urgent', $visit->refresh()->priority);
        $this->assertSame($entry->lock_version, $entry->refresh()->lock_version);
    }

    public function test_an_otc_visit_cannot_become_a_consultation_while_waiting_and_a_consultation_cannot_become_otc(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $this->visitAttributes($this->patient($ca)))->assertRedirect(route('registration.index'));
        $otc = Visit::query()->sole();
        $reason = app(VisitReasonService::class)->create($ca, 'Synthetic switch reason');

        $this->patch(route('visits.update', $otc), [
            'expected_branch_id' => $otc->branch_id, 'lock_version' => $otc->lock_version, 'queue_lock_version' => null,
            'visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason_public_ids' => [$reason->public_id],
            'priority' => 'normal', 'coverage_type' => 'self_pay',
        ])->assertSessionHasErrors(['visit_type' => 'This visit is already in the OTC waiting list, so it cannot be changed to a consultation. Cancel it and register it again.']);
        $this->assertSame('otc', $otc->refresh()->visit_type);
        $this->assertSame(1, OtcQueueEntry::query()->count());
        $this->assertSame(0, QueueEntry::query()->count());

        $this->post(route('registration.store'), $this->consultationPayload($ca, $doctor))->assertRedirect(route('registration.index'));
        $consultation = Visit::query()->where('visit_type', 'consultation')->sole();
        $entry = QueueEntry::query()->sole();
        $this->patch(route('visits.update', $consultation), $this->waitingEdit($consultation, $entry, ['visit_type' => 'otc', 'assigned_doctor_user_id' => null, 'visit_reason_public_ids' => []]))
            ->assertSessionHasErrors(['visit_type' => 'A Visit already in the Consultation Queue cannot be changed to OTC.']);
        $this->assertSame('consultation', $consultation->refresh()->visit_type);
    }

    public function test_a_called_in_visit_still_cannot_be_edited(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $this->consultationPayload($ca, $doctor))->assertRedirect(route('registration.index'));
        $visit = Visit::query()->sole();
        $entry = QueueEntry::query()->sole();
        DB::table('queue_entries')->where('id', $entry->id)->update(['status' => QueueEntry::STATUS_SERVING, 'called_at' => now()->utc(), 'called_by_user_id' => $doctor->id]);
        $entry->refresh();

        // The policy already refuses an edit once the Patient is Called In (the service refuses too).
        $this->patch(route('visits.update', $visit), $this->waitingEdit($visit, $entry, ['priority' => 'urgent']))
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function waitingEdit(Visit $visit, QueueEntry $entry, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $entry->refresh()->lock_version,
            'visit_type' => $visit->visit_type, 'assigned_doctor_user_id' => $visit->assigned_doctor_user_id,
            'visit_reason_public_ids' => $this->visitReasonIds($visit), 'priority' => $visit->priority,
            'coverage_type' => $visit->coverage_type, 'panel_id' => $visit->panel_id,
            ...$overrides,
        ];
    }
}

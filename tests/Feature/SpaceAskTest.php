<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Queue\Services\OtcQueueService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-3a: the Ask panel offers a question only to someone whose own page shows the answer, and re-checks on every call. */
class SpaceAskTest extends VisitTestCase
{
    public function test_a_ca_is_offered_the_dispensary_and_stock_questions_and_gets_403_for_returns(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p
            ->where('questions', [
                ['key' => 'pharmacy.longest_waiting', 'label' => 'Who has waited longest?'],
                ['key' => 'pharmacy.stock_attention', 'label' => 'What stock needs attention?'],
            ]));
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.longest_waiting'])
            ->assertOk()->assertJsonPath('sentence', 'Nobody is waiting at Dispensary.')->assertJsonPath('href', '/registration');
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.returns_waiting'])->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('event', 'space_ask.answered')->count());
    }

    public function test_a_ca_supervisor_is_offered_all_three(): void
    {
        $sup = $this->actor('ca_supervisor');
        $this->selectBranch($sup);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p->has('questions', 3));
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.returns_waiting'])
            ->assertOk()->assertJsonPath('sentence', 'Nothing is waiting for a check.');
    }

    public function test_roles_without_the_permission_see_no_question_and_are_refused_directly(): void
    {
        foreach (['director', 'resident_doctor', 'panel_officer', 'finance_officer', 'marketing'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.longest_waiting'])->assertForbidden();
        }
        foreach (['resident_doctor', 'panel_officer', 'marketing'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.stock_attention'])->assertForbidden();
        }
    }

    public function test_an_unknown_or_foreign_question_is_refused_and_a_guest_cannot_ask(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'nonsense'])->assertNotFound();
        $this->postJson('/spaces/front_desk/ask', ['question' => 'pharmacy.longest_waiting'])->assertNotFound();
        $this->postJson('/spaces/pharmacy/ask', [])->assertStatus(422);
        auth()->logout();
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.longest_waiting'])->assertUnauthorized();
    }

    public function test_the_patient_level_answer_carries_only_what_the_page_row_carries_with_the_waiting_words(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $visit = $this->register($ca, $this->patient($ca, ['full_name' => 'Ahmad Ismail bin Ali']));
        app(OtcQueueService::class)->enter($ca, $visit, ['expected_branch_id' => $this->branch->id]);
        $case = app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);
        DB::table('dispensary_cases')->where('id', $case->id)->update(['received_at' => now()->utc()->subMinutes(70)]);

        $json = $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.longest_waiting'])->assertOk()->json();
        $this->assertSame('Ahmad I. (B-001) has waited longest: Waiting 70 min, Very long wait.', $json['sentence']);
        $this->assertSame(['Number', 'Patient', 'Waiting'], $json['columns']);
        $this->assertSame(['B-001', 'Ahmad I.', 'Waiting 70 min, Very long wait'], $json['rows'][0]);
        $this->assertSame(['key', 'label', 'sentence', 'columns', 'rows', 'asOf', 'href'], array_keys($json));
        $this->assertMatchesRegularExpression('/^\d{1,2}:\d{2} (AM|PM)$/', $json['asOf']);
        $this->assertStringNotContainsString('Ismail', json_encode($json));
    }

    public function test_the_director_is_offered_only_the_stock_question_from_the_stock_review_counts(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p
            ->where('questions', [['key' => 'pharmacy.stock_attention', 'label' => 'What stock needs attention?']]));
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.stock_attention'])
            ->assertOk()->assertJsonPath('sentence', 'No stock needs attention.')->assertJsonPath('rows', []);
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.longest_waiting'])->assertForbidden();
    }

    public function test_front_desk_questions_are_offered_to_a_ca_and_answered_from_the_registration_services(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p->has('questions', 8)
            ->where('questions.3', ['key' => 'front_desk.qr_pending', 'label' => 'QR check-ins to review?']));
        $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.waited_over_30'])
            ->assertOk()->assertJsonPath('sentence', 'Nobody has waited over 30 minutes.')->assertJsonPath('href', '/registration');
        $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.not_ready'])
            ->assertOk()->assertJsonPath('sentence', 'No patient is marked not ready to call.');
        $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.awaiting_billing'])
            ->assertOk()->assertJsonPath('sentence', 'No visit is awaiting billing.');
        $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.qr_pending'])
            ->assertOk()->assertJsonPath('sentence', 'No QR check-ins are waiting for review.')->assertJsonPath('href', '/registration?tab=qr-intake');
    }

    public function test_the_billing_question_is_only_for_billing_holders_and_the_director_does_not_get_it(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p->has('questions', 7));
        $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.awaiting_billing'])->assertForbidden();
    }

    public function test_a_wait_over_30_minutes_is_listed_with_the_waiting_words(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $visit = $this->register($ca, $this->patient($ca, ['full_name' => 'Ahmad Ismail bin Ali']));
        $entry = app(OtcQueueService::class)->enter($ca, $visit, ['expected_branch_id' => $this->branch->id]);
        DB::table('otc_queue_entries')->where('id', $entry->id)->update(['queued_at' => now()->utc()->subMinutes(70)]);

        $json = $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.waited_over_30'])->assertOk()->json();
        $this->assertSame('1 patient has waited over 30 minutes.', $json['sentence']);
        $this->assertSame(['B-001', 'Ahmad I.', 'Waiting 70 min, Very long wait'], $json['rows'][0]);
        $this->assertStringNotContainsString('Ismail', json_encode($json));
    }

    public function test_a_doctor_cannot_ask_front_desk_questions(): void
    {
        $this->selectBranch($this->actor('resident_doctor'));
        $this->postJson('/spaces/front_desk/ask', ['question' => 'front_desk.waiting_counts'])->assertForbidden();
    }

    public function test_billing_questions_follow_each_pages_permission(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get('/spaces/billing')->assertInertia(fn (Assert $p) => $p->has('questions', 3));
        $this->postJson('/spaces/billing/ask', ['question' => 'billing.over_60'])
            ->assertOk()->assertJsonPath('sentence', 'No bill to collect is over 60 days.')->assertJsonPath('href', '/pay-later-collections');
        $this->postJson('/spaces/billing/ask', ['question' => 'billing.owes_most'])
            ->assertOk()->assertJsonPath('sentence', 'No bill to collect is open.');
        $this->postJson('/spaces/billing/ask', ['question' => 'billing.closings_missing'])->assertOk();
        $this->postJson('/spaces/billing/ask', ['question' => 'billing.closings_waiting'])->assertForbidden();

        $this->selectBranch($this->actor('ca_supervisor'));
        $this->postJson('/spaces/billing/ask', ['question' => 'billing.closings_waiting'])
            ->assertOk()->assertJsonPath('href', '/cash-closings/month');
    }
}

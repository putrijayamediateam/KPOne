<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Queue\Services\OtcQueueService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-3a: the Ask panel offers a question only to someone whose own page shows the answer, and re-checks on every call. */
class SpaceAskTest extends VisitTestCase
{
    public function test_a_ca_is_offered_only_the_dispensary_question_and_gets_403_for_returns(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p
            ->where('questions', [['key' => 'pharmacy.left_to_dispense', 'label' => 'What is left to dispense?']]));
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.left_to_dispense'])
            ->assertOk()->assertJsonPath('sentence', 'Nothing is waiting at Dispensary.');
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.returns_waiting'])->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('event', 'space_ask.answered')->count());
    }

    public function test_a_ca_supervisor_is_offered_both(): void
    {
        $sup = $this->actor('ca_supervisor');
        $this->selectBranch($sup);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p->has('questions', 2));
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.returns_waiting'])
            ->assertOk()->assertJsonPath('sentence', 'Nothing is waiting for a check.');
    }

    public function test_roles_without_the_permission_see_no_question_and_are_refused_directly(): void
    {
        foreach (['director', 'resident_doctor', 'panel_officer', 'finance_officer', 'marketing'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.left_to_dispense'])->assertForbidden();
        }
        $this->selectBranch($this->actor('director'));
        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p->where('questions', []));
    }

    public function test_an_unknown_or_foreign_question_is_refused_and_a_guest_cannot_ask(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'nonsense'])->assertNotFound();
        $this->postJson('/spaces/front_desk/ask', ['question' => 'pharmacy.left_to_dispense'])->assertNotFound();
        $this->postJson('/spaces/pharmacy/ask', [])->assertStatus(422);
        auth()->logout();
        $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.left_to_dispense'])->assertUnauthorized();
    }

    public function test_the_patient_level_answer_carries_only_what_the_page_row_carries(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $visit = $this->register($ca, $this->patient($ca, ['full_name' => 'Ahmad Ismail bin Ali']));
        app(OtcQueueService::class)->enter($ca, $visit, ['expected_branch_id' => $this->branch->id]);
        app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);

        $json = $this->postJson('/spaces/pharmacy/ask', ['question' => 'pharmacy.left_to_dispense'])->assertOk()->json();
        $this->assertSame('1 patient is waiting at Dispensary.', $json['sentence']);
        $this->assertSame(['Number', 'Patient', 'Waiting'], $json['columns']);
        $this->assertSame('B-001', $json['rows'][0][0]);
        $this->assertSame('Ahmad I.', $json['rows'][0][1]);
        $this->assertSame(['key', 'label', 'sentence', 'columns', 'rows'], array_keys($json));
        $this->assertStringNotContainsString('Ismail', json_encode($json));
    }
}

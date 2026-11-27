<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Clinical\Models\DiagnosisPreset;
use App\Domain\Clinical\Suggestions\SuggestionPermissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/** SUG-1a: ICD-10 code on a diagnosis preset and the doctor's review before it is offered. */
class DiagnosisReviewTest extends ClinicalTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function draft(string $name = 'Synthetic fever', ?string $code = 'R50.9'): DiagnosisPreset
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => [$name], 'codes' => [$code]]))->assertRedirect();

        return DiagnosisPreset::query()->where('name', $name)->firstOrFail();
    }

    public function test_the_permission_is_held_only_by_the_resident_doctor_and_the_director(): void
    {
        $holders = [];
        foreach (['director', 'resident_doctor', 'ca', 'medical_assistant', 'ca_supervisor', 'administrator', 'finance_officer', 'panel_officer', 'hr_manager', 'technical_admin'] as $role) {
            $holders[$role] = $this->actor($role)->can(SuggestionPermissions::APPROVE);
        }
        $this->assertSame(['director', 'resident_doctor'], array_keys(array_filter($holders)));
    }

    public function test_a_new_diagnosis_is_a_draft_with_a_code_and_is_not_offered_to_doctors_until_approved(): void
    {
        $preset = $this->draft();
        $this->assertSame('draft', $preset->status);
        $this->assertSame('R50.9', $preset->icd10_code);
        $this->assertNull($preset->approved_at);
        $this->get(route('diagnosis-presets.index'))->assertInertia(fn (Assert $p) => $p->where('presets.0.status', 'draft')->where('presets.0.icd10Code', 'R50.9'));
        $this->get(route('diagnosis-presets.index', ['q' => 'r50']))->assertInertia(fn (Assert $p) => $p->has('presets', 1));
        $this->assertDatabaseHas('audit_logs', ['event' => 'diagnosis_preset.created']);
    }

    public function test_codes_are_checked_normalised_and_retry_safe(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic bad'], 'codes' => ['not a code']]))->assertSessionHasErrors('codes.0');
        $this->assertDatabaseCount('diagnosis_presets', 0);
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic lower'], 'codes' => [' j06.9 ']]))->assertRedirect();
        $this->assertSame('J06.9', DiagnosisPreset::query()->sole()->icd10_code);
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic blank']]))->assertRedirect();
        $this->assertNull(DiagnosisPreset::query()->where('name', 'Synthetic blank')->sole()->icd10_code);
    }

    public function test_the_doctor_approves_a_draft_and_the_picker_then_offers_it(): void
    {
        $preset = $this->draft();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);
        $this->get(route('suggestion-review.index'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $p) => $p->component('SuggestionReview/Index')->has('drafts', 1)->where('draftTotal', 1)->where('drafts.0.ownEntry', false)
                ->where('drafts.0.enteredLabel', fn (string $label): bool => preg_match('/^Entered by .+, \d{1,2} [A-Z][a-z]{2} \d{4}$/', $label) === 1));
        $key = (string) Str::uuid();
        $data = $this->payload(['decision' => 'approve', 'lock_version' => $preset->lock_version, 'idempotency_key' => $key]);
        $this->patch(route('suggestion-review.decide', $preset->id), $data)->assertRedirect(route('suggestion-review.index'));
        $this->patch(route('suggestion-review.decide', $preset->id), $data)->assertRedirect();
        $preset->refresh();
        $this->assertSame('approved', $preset->status);
        $this->assertSame($doctor->id, $preset->approved_by_user_id);
        $this->assertNotNull($preset->approved_at);
        $this->assertSame(2, $preset->lock_version);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'diagnosis_preset.approved')->count());
    }

    public function test_nobody_reviews_their_own_entry_and_a_stale_page_is_refused(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic own']]))->assertRedirect();
        $preset = DiagnosisPreset::query()->sole();
        $this->get(route('suggestion-review.index'))->assertInertia(fn (Assert $p) => $p->where('drafts.0.ownEntry', true));
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertSessionHasErrors('preset');
        $this->assertSame('draft', $preset->refresh()->status);
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => 9]))->assertSessionHasErrors('preset');
        $this->assertSame('draft', $preset->refresh()->status);
    }

    public function test_changing_an_approved_item_returns_it_to_draft_and_the_editor_cannot_approve_it(): void
    {
        $preset = $this->draft();
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertRedirect();
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->patch(route('diagnosis-presets.rename', $preset->id), $this->payload(['name' => 'Synthetic fever, unspecified', 'icd10_code' => 'R50.9', 'lock_version' => 2]))->assertRedirect();
        $preset->refresh();
        $this->assertSame('draft', $preset->status);
        $this->assertNull($preset->approved_by_user_id);
        $this->assertSame($director->id, $preset->edited_by_user_id);
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => $preset->lock_version]))->assertSessionHasErrors('preset');
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => $preset->lock_version]))->assertRedirect();
        $this->assertSame('approved', $preset->refresh()->status);
    }

    public function test_a_doctor_can_return_an_approved_item_to_draft_and_an_archive_keeps_the_review(): void
    {
        $preset = $this->draft();
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertRedirect();
        $this->selectBranch($this->actor('administrator'));
        $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => false, 'lock_version' => 2]))->assertRedirect();
        $this->assertSame('approved', $preset->refresh()->status);
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'return', 'lock_version' => 3]))->assertRedirect();
        $this->assertSame('draft', $preset->refresh()->status);
        $this->assertNull($preset->approved_at);
    }

    public function test_other_roles_cannot_reach_or_decide_the_review(): void
    {
        $preset = $this->draft();
        foreach (['administrator', 'finance_officer', 'panel_officer', 'ca', 'medical_assistant', 'ca_supervisor', 'technical_admin', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('suggestion-review.index'))->assertForbidden();
            $this->patch(route('suggestion-review.decide', $preset->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertForbidden();
        }
        $this->assertSame('draft', $preset->refresh()->status);
    }

    public function test_a_row_written_without_a_status_is_a_draft_and_the_database_refuses_a_self_review(): void
    {
        $doctor = $this->actor('resident_doctor');
        $other = $this->actor('director');
        $id = DB::table('diagnosis_presets')->insertGetId(['organisation_id' => $doctor->organisation_id, 'name' => 'Synthetic raw', 'normalized_name' => 'synthetic raw',
            'is_active' => true, 'lock_version' => 1, 'created_by_user_id' => $doctor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('draft', DB::table('diagnosis_presets')->where('id', $id)->value('status'));
        foreach ([
            ['status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $doctor->id],
            ['status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $other->id, 'edited_by_user_id' => $other->id],
            ['status' => 'draft', 'approved_at' => now()],
        ] as $bad) {
            try {
                DB::transaction(fn () => DB::table('diagnosis_presets')->where('id', $id)->update($bad));
                $this->fail('The database accepted an invalid review.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('diagnosis_presets')->where('id', $id)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $other->id]);
        $this->assertSame('approved', DB::table('diagnosis_presets')->where('id', $id)->value('status'));
    }

    public function test_an_archived_draft_cannot_be_approved_and_a_replayed_key_is_tied_to_its_actor(): void
    {
        $preset = $this->draft();
        $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => false, 'lock_version' => 1]))->assertRedirect();
        $preset->refresh();
        $this->selectBranch($this->actor('resident_doctor'));
        $key = (string) Str::uuid();
        $data = $this->payload(['decision' => 'approve', 'lock_version' => $preset->lock_version, 'idempotency_key' => $key]);
        $this->patch(route('suggestion-review.decide', $preset->id), $data)->assertSessionHasErrors('preset');
        $this->assertSame('draft', $preset->refresh()->status);
        $this->selectBranch($this->actor('administrator'));
        $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => true, 'lock_version' => $preset->lock_version]))->assertRedirect();
        $preset->refresh();
        $this->selectBranch($this->actor('resident_doctor'));
        $good = $this->payload(['decision' => 'approve', 'lock_version' => $preset->lock_version, 'idempotency_key' => $key]);
        $this->patch(route('suggestion-review.decide', $preset->id), $good)->assertRedirect();
        $this->assertSame('approved', $preset->refresh()->status);
        // The same key and payload from the entering person is a different request, not a replay.
        $this->selectBranch($this->actor('administrator'));
        $this->patch(route('suggestion-review.decide', $preset->id), $good)->assertForbidden();
    }
}

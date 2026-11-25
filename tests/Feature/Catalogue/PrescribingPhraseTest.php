<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Clinical\Models\PrescribingPhrase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Clinical\ClinicalTestCase;

/** SUG-1b: bilingual prescribing phrases, their settings page and the doctor's review. */
class PrescribingPhraseTest extends ClinicalTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function draft(string $en = '1 tablet three times daily after food', string $ms = '1 biji 3 kali sehari selepas makan', string $kind = 'frequency'): PrescribingPhrase
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => $kind, 'label_en' => $en, 'label_ms' => $ms]))->assertRedirect();

        return PrescribingPhrase::query()->where('label_en', $en)->firstOrFail();
    }

    public function test_only_settings_roles_reach_the_page_and_a_new_phrase_is_a_draft(): void
    {
        foreach (['technical_admin', 'ca', 'ca_supervisor', 'resident_doctor', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('prescribing-phrases.index'))->assertForbidden();
            $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'frequency', 'label_en' => 'Denied', 'label_ms' => 'Ditolak']))->assertForbidden();
        }
        $this->assertDatabaseCount('prescribing_phrases', 0);
        $phrase = $this->draft();
        $this->assertSame('draft', $phrase->status);
        $this->assertSame('1 tablet three times daily after food', $phrase->label_en);
        $this->assertDatabaseHas('audit_logs', ['event' => 'prescribing_phrase.created']);
        $this->get(route('prescribing-phrases.index'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $p) => $p->component('ConsultationSettings/Phrases')->has('phrases', 1)->where('phrases.0.status', 'draft')->where('phrases.0.kindLabel', 'Frequency'));
    }

    public function test_wording_is_checked_trimmed_unique_per_kind_and_retry_safe(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $long = str_repeat('a', 61);
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'frequency', 'label_en' => $long, 'label_ms' => 'ok']))->assertSessionHasErrors('label_en');
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'frequency', 'label_en' => 'ok', 'label_ms' => '   ']))->assertSessionHasErrors('label_ms');
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'bogus', 'label_en' => 'ok', 'label_ms' => 'ok']))->assertSessionHasErrors('kind');
        $this->assertDatabaseCount('prescribing_phrases', 0);
        $data = $this->payload(['kind' => 'instruction', 'label_en' => '  After   food ', 'label_ms' => 'Selepas makan']);
        $this->post(route('prescribing-phrases.store'), $data)->assertRedirect();
        $this->post(route('prescribing-phrases.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('prescribing_phrases', 1);
        $this->assertSame('After food', PrescribingPhrase::query()->sole()->label_en);
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'instruction', 'label_en' => 'after food', 'label_ms' => 'x']))->assertSessionHasErrors('label_en');
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'precaution', 'label_en' => 'after food', 'label_ms' => 'x']))->assertRedirect();
        $this->assertDatabaseCount('prescribing_phrases', 2);
        $this->post(route('prescribing-phrases.store'), [...$data, 'label_ms' => 'Lain'])->assertSessionHasErrors('phrase');
    }

    public function test_the_doctor_approves_a_draft_once_and_never_the_person_who_entered_it(): void
    {
        $phrase = $this->draft();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);
        $this->get(route('suggestion-review.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('phraseDrafts', 1)->where('phraseDrafts.0.ownEntry', false)
            ->where('phraseDrafts.0.enteredLabel', fn (string $label): bool => preg_match('/^Entered by .+, \d{1,2} [A-Z][a-z]{2} \d{4}$/', $label) === 1));
        $key = (string) Str::uuid();
        $data = $this->payload(['decision' => 'approve', 'lock_version' => 1, 'idempotency_key' => $key]);
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $data)->assertRedirect(route('suggestion-review.index'));
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $data)->assertRedirect();
        $phrase->refresh();
        $this->assertSame('approved', $phrase->status);
        $this->assertSame($doctor->id, $phrase->approved_by_user_id);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'prescribing_phrase.approved')->count());
        // the entering person (a Director here) cannot approve their own entry
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('prescribing-phrases.store'), $this->payload(['kind' => 'duration', 'label_en' => 'For 5 days', 'label_ms' => 'Selama 5 hari']))->assertRedirect();
        $own = PrescribingPhrase::query()->where('label_en', 'For 5 days')->sole();
        $this->get(route('suggestion-review.index'))->assertInertia(fn (Assert $p) => $p->where('phraseDrafts.0.ownEntry', true));
        $this->patch(route('suggestion-review.decide-phrase', $own->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertSessionHasErrors('phrase');
        $this->assertSame('draft', $own->refresh()->status);
    }

    public function test_an_edit_returns_an_approved_phrase_to_draft_and_the_editor_cannot_approve_it(): void
    {
        $phrase = $this->draft();
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertRedirect();
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->patch(route('prescribing-phrases.edit', $phrase->id), $this->payload(['label_en' => '1 tablet 3 times a day after food', 'label_ms' => '1 biji 3 kali sehari selepas makan', 'lock_version' => 2]))->assertRedirect();
        $phrase->refresh();
        $this->assertSame('draft', $phrase->status);
        $this->assertNull($phrase->approved_by_user_id);
        $this->assertSame($director->id, $phrase->edited_by_user_id);
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => $phrase->lock_version]))->assertSessionHasErrors('phrase');
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => 9]))->assertSessionHasErrors('phrase');
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => $phrase->lock_version]))->assertRedirect();
        $this->assertSame('approved', $phrase->refresh()->status);
    }

    public function test_archive_keeps_the_review_an_archived_draft_cannot_be_approved_and_nothing_is_deleted(): void
    {
        $phrase = $this->draft();
        $this->patch(route('prescribing-phrases.active', $phrase->id), $this->payload(['active' => false, 'lock_version' => 1]))->assertRedirect();
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => 2]))->assertSessionHasErrors('phrase');
        $this->selectBranch($this->actor('administrator'));
        $this->patch(route('prescribing-phrases.active', $phrase->id), $this->payload(['active' => true, 'lock_version' => 2]))->assertRedirect();
        $this->selectBranch($this->actor('resident_doctor'));
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => 3]))->assertRedirect();
        $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'return', 'lock_version' => 4]))->assertRedirect();
        $this->assertSame('draft', $phrase->refresh()->status);
        $this->expectException(LogicException::class);
        $phrase->delete();
    }

    public function test_other_roles_cannot_decide_and_filters_and_search_work(): void
    {
        $phrase = $this->draft();
        $this->draft('After food', 'Selepas makan', 'instruction');
        foreach (['administrator', 'finance_officer', 'panel_officer', 'ca', 'medical_assistant', 'ca_supervisor', 'technical_admin', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->patch(route('suggestion-review.decide-phrase', $phrase->id), $this->payload(['decision' => 'approve', 'lock_version' => 1]))->assertForbidden();
        }
        $this->selectBranch($this->actor('administrator'));
        $this->get(route('prescribing-phrases.index', ['kind' => 'instruction']))->assertInertia(fn (Assert $p) => $p->has('phrases', 1)->where('phrases.0.labelEn', 'After food'));
        $this->get(route('prescribing-phrases.index', ['q' => 'selepas makan']))->assertInertia(fn (Assert $p) => $p->has('phrases', 2));
        $this->get(route('prescribing-phrases.index', ['kind' => 'bogus']))->assertRedirect(route('prescribing-phrases.index'));
        $this->assertSame('draft', $phrase->refresh()->status);
    }

    public function test_the_database_refuses_a_self_review_an_invalid_kind_and_a_review_on_a_draft(): void
    {
        $phrase = $this->draft();
        $doctor = $this->actor('resident_doctor');
        foreach ([
            ['status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $phrase->created_by_user_id],
            ['status' => 'draft', 'approved_at' => now()],
            ['kind' => 'bogus'],
        ] as $bad) {
            try {
                DB::transaction(fn () => DB::table('prescribing_phrases')->where('id', $phrase->id)->update($bad));
                $this->fail('The database accepted an invalid change.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('prescribing_phrases')->where('id', $phrase->id)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $doctor->id]);
        $this->assertSame('approved', $phrase->refresh()->status);
    }
}

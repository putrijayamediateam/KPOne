<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoctorCheckFixtures;

/**
 * DSP-29 (coordinator OK, 27 Nov 2026; found by QA on the DSP-CARD-1 run): the doctor's "Medicine ready for your
 * check" panel names the allergens a line may match, by the same rule as the CA's warning. Read only: the server's
 * allergy checks at save and complete are unchanged. Synthetic data only.
 */
class DispensaryDoctorAllergyMarkTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>|null
     */
    private function sendAndRead(array $f): ?array
    {
        $this->switchOn($f['visit']->branch);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
        ]);
        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);

        return $this->read($f);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>|null
     */
    private function read(array $f): ?array
    {
        $this->selectBranch($f['doctor'], $f['visit']->branch);
        $encounter = $f['visit']->clinicalEncounter()->sole();
        $encounter->setRelation('visit', $f['visit']->refresh()->load('queueEntry'));

        return app(DoctorDispensaryAttentionService::class)->checkForEncounter($f['doctor'], $encounter);
    }

    /**
     * A synthetic allergy recorded while the case waits (fixture data, written directly: once the medicine has gone
     * to Dispensary the consultation's allergy service no longer accepts a write from this encounter).
     *
     * @param  array<string, mixed>  $f
     */
    private function recordAllergy(array $f, string $allergen, ?string $reaction = null): void
    {
        $profile = PatientAllergyProfile::query()->where('patient_id', $f['visit']->patient_id)->sole();
        $profile->forceFill(['status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'lock_version' => $profile->lock_version + 1])->save();
        (new PatientAllergyRecord)->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $profile->organisation_id, 'patient_allergy_profile_id' => $profile->id,
            'allergen_text' => $allergen, 'category' => 'medication', 'reaction_text' => $reaction, 'severity' => $reaction === null ? null : 'severe',
            'status' => PatientAllergyRecord::STATUS_ACTIVE, 'recorded_at' => now()->utc(), 'recorded_by_user_id' => $f['doctor']->id,
            'updated_by_user_id' => $f['doctor']->id, 'entered_in_error_at' => null, 'entered_in_error_by_user_id' => null,
        ])->save();
    }

    public function test_a_line_without_a_matching_allergy_has_no_mark(): void
    {
        $f = $this->prepared();
        $check = $this->sendAndRead($f);

        $this->assertNotNull($check);
        $this->assertSame([], $check['lines'][0]['allergyMatches']);
    }

    public function test_a_line_that_may_match_a_recorded_allergy_names_the_allergen_only(): void
    {
        $f = $this->prepared();
        $this->sendAndRead($f);
        $this->recordAllergy($f, 'Synthetic medicine', 'Synthetic reaction sentinel');
        $this->recordAllergy($f, 'Synthetic unrelated allergen');
        $check = $this->read($f);

        $this->assertNotNull($check);
        $this->assertSame(['Synthetic medicine'], $check['lines'][0]['allergyMatches'], 'Only the matching allergen, by name.');
        $this->assertSame(['Synthetic medicine', 'Synthetic unrelated allergen'], $check['allergies']['records'], 'The profile line still lists every active allergy.');
        // Hakim: names only, never the reaction or severity.
        $this->assertStringNotContainsString('Synthetic reaction sentinel', (string) json_encode($check));
    }

    public function test_a_catalogue_alias_matches_like_the_cas_warning(): void
    {
        $f = $this->prepared();
        DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['allergy_aliases' => json_encode(['Synthetic alias name'])]);
        $this->sendAndRead($f);
        $this->recordAllergy($f, 'Synthetic alias name');

        $this->assertSame(['Synthetic alias name'], $this->read($f)['lines'][0]['allergyMatches']);
    }

    public function test_a_removed_line_shows_no_mark(): void
    {
        $f = $this->prepared();
        $service = app(DispensaryService::class);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        // The CA adds a second synthetic medicine (on the same stock item) and removes the doctor's line, through the
        // real service, so the waiting case holds one removed line and one given line.
        $other = $f['medicine']->replicate()->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'SYN-MED-'.Str::upper(Str::random(8)), 'display_name' => 'Trialzolam plain', 'generic_name' => null]);
        $other->save();
        (new MedicineCatalogueInventorySku)->forceFill(['organisation_id' => $other->organisation_id, 'medicine_catalogue_item_id' => $other->id, 'inventory_sku_id' => $f['sku']->id, 'is_active' => true, 'approved_by_user_id' => $f['doctor']->id, 'approved_at' => now()->utc()])->save();
        $service->addItem($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'medicine_public_id' => $other->public_id,
            'quantity_dispensed' => '1.000', 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null,
            'administration_instruction' => null, 'precaution' => null,
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000']],
        ]);
        $doctorLine = DispensaryItem::query()->where('dispensary_handoff_id', $f['case']->handoffs()->sole()->id)->where('source', DispensaryItem::SOURCE_DOCTOR)->sole();
        $service->removeItem($f['ca'], $f['case']->refresh(), $doctorLine, [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'item_lock_version' => $doctorLine->refresh()->lock_version,
        ]);
        $this->sendAndRead($f);
        $this->recordAllergy($f, 'Synthetic medicine');

        $lines = collect($this->read($f)['lines'])->keyBy('name');
        $this->assertSame(DispensaryItem::CHANGE_REMOVED, $lines['Synthetic medicine']['changeState']);
        $this->assertSame([], $lines['Synthetic medicine']['allergyMatches'], 'A removed line is not given, so it shows no mark.');
        $this->assertSame([], $lines['Trialzolam plain']['allergyMatches']);
    }
}

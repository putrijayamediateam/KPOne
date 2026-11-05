<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\MergeFixture;

/**
 * REG-5: the REG-3 follow-ups on the Patient side: search rows say "Merged from KP-…", and the kept page shows a
 * merged record's guardian contact, read only, when the kept record has none.
 */
class PatientMergeFollowUpsTest extends VisitTestCase
{
    public function test_search_rows_and_registration_summaries_list_the_merged_records(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient(null, ['full_name' => 'Synthetic Followup Kept']);
        $first = $this->patient(null, ['full_name' => 'Synthetic Followup First']);
        $second = $this->patient(null, ['full_name' => 'Synthetic Followup Second']);
        MergeFixture::merge($kept, $first, $ca, $this->actor('ca_supervisor'));
        MergeFixture::merge($kept, $second, $ca, $this->actor('ca_supervisor'));
        $other = $this->patient(null, ['full_name' => 'Synthetic Followup Unrelated']);

        $this->selectBranch($ca);
        $directory = app(PatientDirectoryService::class);
        $rows = collect($directory->search($ca, ['search_type' => 'name', 'query' => 'Synthetic Followup'])['data'])->keyBy('patientNumber');
        $this->assertSame([$first->patient_number, $second->patient_number], $rows[$kept->patient_number]['mergedFrom']);
        $this->assertSame([], $rows[$other->patient_number]['mergedFrom']);

        $byOldNumber = $directory->search($ca, ['search_type' => 'patient_number', 'query' => $second->patient_number])['data'];
        $this->assertSame($kept->patient_number, $byOldNumber[0]['patientNumber']);
        $this->assertSame([$first->patient_number, $second->patient_number], $byOldNumber[0]['mergedFrom']);

        $this->assertSame([$first->patient_number, $second->patient_number], $directory->summaries($ca, [$kept->id])[0]['mergedFrom']);
    }

    public function test_the_kept_page_shows_a_merged_guardian_read_only_only_while_it_has_none_of_its_own(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->guardian($duplicate, 'Synthetic Merged Guardian');
        MergeFixture::merge($kept, $duplicate, $ca, $this->actor('ca_supervisor'));

        $this->selectBranch($ca);
        $this->actingAs($ca)->get('/patients/'.$kept->patient_number)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Patient/Show')
                ->where('patient.guardian', null)
                ->where('mergedGuardian.full_name', 'Synthetic Merged Guardian')
                ->where('mergedGuardian.relationship', 'mother')
                ->where('mergedGuardian.fromPatientNumber', $duplicate->patient_number)
                ->missing('mergedGuardian.lock_version'));

        $this->guardian($kept, 'Synthetic Own Guardian');
        $this->actingAs($ca)->get('/patients/'.$kept->patient_number)->assertOk()
            ->assertInertia(fn ($page) => $page->where('patient.guardian.full_name', 'Synthetic Own Guardian')->where('mergedGuardian', null));
    }

    public function test_an_unmerged_records_guardian_is_not_shown(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $other = $this->patient();
        $this->guardian($other, 'Synthetic Unlinked Guardian');

        $this->selectBranch($ca);
        $this->actingAs($ca)->get('/patients/'.$kept->patient_number)->assertOk()
            ->assertInertia(fn ($page) => $page->where('mergedGuardian', null));
    }

    private function guardian(Patient $patient, string $name): void
    {
        DB::table('patient_guardian_contacts')->insert([
            'organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id, 'relationship' => 'mother',
            'encrypted_contact' => Crypt::encryptString((string) json_encode(['full_name' => $name, 'phone' => '+12025550120', 'phone_country' => 'US', 'email' => 'guardian@kpone.test'])),
            'lock_version' => 1, 'updated_by_user_id' => User::query()->value('id'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

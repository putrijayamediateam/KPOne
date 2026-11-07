<?php

namespace Tests\Feature;

use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Visit\VisitTestCase;

/**
 * CAT-9 (owner decision 2026-11-06, "go with CAT-9"): an optional second clinic phone per branch in Business details,
 * carried on the letterhead as details.phone_secondary.
 */
class BusinessDetailsSecondPhoneTest extends VisitTestCase
{
    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = $this->actor('director');
        $this->selectBranch($this->director);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'expected_branch_id' => $this->branch->id,
            'organisation_lock_version' => (int) OrganisationBusinessProfile::query()->where('organisation_id', $this->organisation->id)->value('lock_version'),
            'branch_lock_version' => (int) DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Clinic Group Ltd',
            'clinic_name' => 'Synthetic Central Clinic', 'address_line_1' => '1 Example Street', 'address_line_2' => '',
            'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State', 'country' => 'Malaysia',
            'phone' => '03-2000 0001', 'email' => 'clinic@second-phone.test', 'registration_type' => 'BRN',
            'registration_number' => 'SYN-BRN-009', 'remove_logo' => false,
            ...$overrides,
        ];
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->director)->post('/business-details', $this->payload($overrides));
    }

    private function profile(): ?BranchBusinessProfile
    {
        return BranchBusinessProfile::query()->where('branch_id', $this->branch->id)->first();
    }

    public function test_the_column_is_nullable_and_existing_rows_keep_no_second_phone(): void
    {
        $this->assertTrue(Schema::hasColumn('branch_business_profiles', 'phone_secondary'));
        $this->save()->assertRedirect(route('business-details.index'))->assertSessionHasNoErrors();
        $this->assertNull($this->profile()->phone_secondary);
    }

    public function test_a_second_phone_is_saved_normalised_audited_and_on_the_letterhead(): void
    {
        $this->save(['phone_secondary' => '012-345 6789'])->assertSessionHasNoErrors();

        $this->assertSame('+60123456789', $this->profile()->phone_secondary);
        $details = app(BranchLetterheadService::class)->forActiveBranch($this->director)->details;
        $this->assertSame('+60320000001', $details['phone']);
        $this->assertSame('+60123456789', $details['phone_secondary']);
        $audit = DB::table('audit_logs')->where('event', 'business_details.saved')->latest('id')->first();
        $this->assertContains('branch.phone_secondary', json_decode((string) $audit->metadata, true)['changed_fields']);
        $this->assertStringNotContainsString('6789', (string) $audit->metadata);
    }

    public function test_an_invalid_second_phone_is_refused_under_its_own_field(): void
    {
        $this->save(['phone_secondary' => 'not a phone'])
            ->assertSessionHasErrors(['phone_secondary' => 'Enter a valid second phone number, including the country code.'])
            ->assertSessionDoesntHaveErrors('phone');
        $this->assertNull($this->profile());
    }

    public function test_a_second_phone_needs_a_first_one_and_must_differ_from_it(): void
    {
        $this->save(['phone' => '', 'phone_secondary' => '012-345 6789'])
            ->assertSessionHasErrors(['phone_secondary' => 'Enter the first phone before a second one.']);
        $this->save(['phone_secondary' => '+60 3-2000 0001'])
            ->assertSessionHasErrors(['phone_secondary' => 'The second phone is the same as the first.']);
        $this->assertNull($this->profile());
    }

    public function test_leaving_the_field_out_keeps_the_saved_second_phone_and_an_empty_box_clears_it(): void
    {
        $this->save(['phone_secondary' => '012-345 6789'])->assertSessionHasNoErrors();

        // An older client that does not send the field does not wipe it.
        $this->save(['email' => 'front-desk@second-phone.test'])->assertSessionHasNoErrors();
        $this->assertSame('+60123456789', $this->profile()->phone_secondary);

        $this->save(['phone_secondary' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->profile()->phone_secondary);
    }

    public function test_an_older_client_cannot_leave_a_second_phone_alone_or_equal_to_the_first(): void
    {
        $this->save(['phone_secondary' => '012-345 6789'])->assertSessionHasNoErrors();

        // The field is left out, but the saved second phone still counts.
        $this->save(['phone' => ''])->assertSessionHasErrors(['phone_secondary' => 'Enter the first phone before a second one.']);
        $this->save(['phone' => '012-345 6789'])->assertSessionHasErrors(['phone_secondary' => 'The second phone is the same as the first.']);
        $this->assertSame(['+60320000001', '+60123456789'], [$this->profile()->phone, $this->profile()->phone_secondary]);
    }

    public function test_an_invalid_first_phone_reports_only_on_the_first_field(): void
    {
        $this->save(['phone' => 'not a phone', 'phone_secondary' => '012-345 6789'])
            ->assertSessionHasErrors('phone')->assertSessionDoesntHaveErrors('phone_secondary');
    }

    public function test_saving_the_same_second_phone_again_changes_nothing_and_a_retry_replays(): void
    {
        $this->save(['phone_secondary' => '012-345 6789'])->assertSessionHasNoErrors();
        $version = $this->profile()->lock_version;

        $this->save(['phone_secondary' => '+60 12-345 6789'])->assertSessionHasNoErrors();
        $this->assertSame($version, $this->profile()->lock_version);
        $audit = DB::table('audit_logs')->where('event', 'business_details.saved')->latest('id')->first();
        $this->assertNotContains('branch.phone_secondary', json_decode((string) $audit->metadata, true)['changed_fields']);

        // The same request sent twice with the field left out is one save.
        $payload = $this->payload(['email' => 'retry@second-phone.test']);
        $before = DB::table('audit_logs')->where('event', 'business_details.saved')->count();
        $this->actingAs($this->director)->post('/business-details', $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->director)->post('/business-details', $payload)->assertSessionHasNoErrors();
        $this->assertSame($before + 1, DB::table('audit_logs')->where('event', 'business_details.saved')->count());
        $this->assertSame('+60123456789', $this->profile()->phone_secondary);
    }
}

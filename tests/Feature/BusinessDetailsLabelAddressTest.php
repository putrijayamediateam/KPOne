<?php

namespace Tests\Feature;

use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Visit\VisitTestCase;

/**
 * Drug label short address (owner decision 2026-11-12, option A): an optional single-line address per branch of at
 * most 96 characters, carried on the letterhead as details.label_address and read only by the drug label.
 */
class BusinessDetailsLabelAddressTest extends VisitTestCase
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
            'phone' => '03-2000 0001', 'email' => 'clinic@label-address.test', 'registration_type' => 'BRN',
            'registration_number' => 'SYN-BRN-010', 'remove_logo' => false,
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

    public function test_the_column_is_nullable_and_existing_rows_have_no_label_address(): void
    {
        $this->assertTrue(Schema::hasColumn('branch_business_profiles', 'label_address'));
        $this->save()->assertRedirect(route('business-details.index'))->assertSessionHasNoErrors();
        $this->assertNull($this->profile()->label_address);
        $this->assertNull(app(BranchLetterheadService::class)->forActiveBranch($this->director)->details['label_address']);
    }

    public function test_a_label_address_is_saved_audited_by_field_name_only_and_on_the_letterhead(): void
    {
        $text = 'No. 19 G, Jalan Dwitasik 1, Cheras';
        $this->save(['label_address' => $text])->assertSessionHasNoErrors();

        $this->assertSame($text, $this->profile()->label_address);
        $details = app(BranchLetterheadService::class)->forActiveBranch($this->director)->details;
        $this->assertSame($text, $details['label_address']);
        $this->assertSame('1 Example Street', $details['address_line_1']);
        $audit = DB::table('audit_logs')->where('event', 'business_details.saved')->latest('id')->first();
        $this->assertContains('branch.label_address', json_decode((string) $audit->metadata, true)['changed_fields']);
        $this->assertStringNotContainsString('Dwitasik', (string) $audit->metadata);
    }

    public function test_ninety_six_characters_are_accepted_and_ninety_seven_are_refused_counting_characters(): void
    {
        $ok = str_repeat('é', 96);
        $this->save(['label_address' => $ok])->assertSessionHasNoErrors();
        $this->assertSame($ok, $this->profile()->label_address);

        $this->save(['label_address' => str_repeat('é', 97)])->assertSessionHasErrors('label_address');
        $this->assertSame($ok, $this->profile()->label_address);
    }

    public function test_line_breaks_become_spaces_and_blank_clears_the_value(): void
    {
        $this->save(['label_address' => "No. 19 G\nJalan Dwitasik 1"])->assertSessionHasNoErrors();
        $this->assertSame('No. 19 G Jalan Dwitasik 1', $this->profile()->label_address);

        $this->save(['label_address' => '   '])->assertSessionHasNoErrors();
        $this->assertNull($this->profile()->label_address);
        $audit = DB::table('audit_logs')->where('event', 'business_details.saved')->latest('id')->first();
        $this->assertContains('branch.label_address', json_decode((string) $audit->metadata, true)['changed_fields']);
    }

    public function test_a_tab_only_value_is_stored_as_empty_not_as_a_blank_looking_address(): void
    {
        $this->save(['label_address' => 'No. 19 G'])->assertSessionHasNoErrors();
        $this->save(['label_address' => "\t\t"])->assertSessionHasNoErrors();
        $this->assertNull($this->profile()->label_address);
    }

    public function test_an_older_client_that_leaves_the_field_out_does_not_wipe_it(): void
    {
        $this->save(['label_address' => 'Short label address'])->assertSessionHasNoErrors();
        $this->save(['email' => 'front-desk@label-address.test'])->assertSessionHasNoErrors();
        $this->assertSame('Short label address', $this->profile()->label_address);
    }

    public function test_a_stale_version_a_wrong_branch_and_an_unauthorised_role_change_nothing(): void
    {
        $this->save(['label_address' => 'First'])->assertSessionHasNoErrors();
        $stale = $this->payload(['label_address' => 'Second', 'branch_lock_version' => 0]);
        $this->actingAs($this->director)->post('/business-details', $stale)->assertSessionHasErrors('version');
        $this->assertSame('First', $this->profile()->label_address);

        $this->actingAs($this->actor('ca'))->post('/business-details', $this->payload(['label_address' => 'Intruder']))->assertForbidden();
        $this->assertSame('First', $this->profile()->label_address);
    }

    public function test_a_retried_save_is_one_save(): void
    {
        $payload = $this->payload(['label_address' => 'Retry label']);
        $before = DB::table('audit_logs')->where('event', 'business_details.saved')->count();
        $this->actingAs($this->director)->post('/business-details', $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->director)->post('/business-details', $payload)->assertSessionHasNoErrors();
        $this->assertSame($before + 1, DB::table('audit_logs')->where('event', 'business_details.saved')->count());
        $this->assertSame('Retry label', $this->profile()->label_address);
    }

    public function test_the_same_key_with_a_different_label_address_is_refused_and_a_replay_changes_nothing(): void
    {
        $key = (string) Str::uuid();
        $first = $this->payload(['label_address' => 'A', 'idempotency_key' => $key]);
        $this->actingAs($this->director)->post('/business-details', $first)->assertSessionHasNoErrors();
        $version = $this->profile()->lock_version;

        $this->actingAs($this->director)->post('/business-details', $first)->assertSessionHasNoErrors();
        $this->assertSame($version, $this->profile()->lock_version);

        $this->actingAs($this->director)->post('/business-details', [...$first, 'label_address' => 'B'])->assertSessionHasErrors('idempotency_key');
        $this->assertSame('A', $this->profile()->label_address);
        $this->assertSame($version, $this->profile()->lock_version);
    }

    public function test_postgresql_refuses_a_long_blank_or_multi_line_label_address(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The check constraint is PostgreSQL only.');
        }
        $this->save()->assertSessionHasNoErrors();
        $id = $this->profile()->id;
        foreach ([str_repeat('x', 97), '   ', "two\nlines", "two\rlines"] as $bad) {
            DB::beginTransaction();
            try {
                DB::table('branch_business_profiles')->where('id', $id)->update(['label_address' => $bad]);
                $this->fail('PostgreSQL should refuse this label address');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/branch_business_profiles_label_address_shape|too long for type character varying\\(96\\)/', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }

        // A literal backslash, the letter n and the letter r are ordinary characters and stay allowed.
        DB::table('branch_business_profiles')->where('id', $id)->update(['label_address' => 'a\\nr']);
        $this->assertSame('a\\nr', DB::table('branch_business_profiles')->where('id', $id)->value('label_address'));
    }
}

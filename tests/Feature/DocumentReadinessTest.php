<?php

namespace Tests\Feature;

use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\DocumentReadinessService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * CAT-4 (owner decision 2026-11-05): a read-only check of every active branch for invoices, receipts and the
 * doctor's letters, following the checks the document code makes at issue time.
 */
class DocumentReadinessTest extends VisitTestCase
{
    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->director = $this->actor('director');
        $this->selectBranch($this->director);
    }

    /** @param array<string, string> $overrides */
    private function saveDetails(array $overrides = []): void
    {
        app(BusinessDetailsService::class)->save($this->director, [
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => (int) OrganisationBusinessProfile::query()->value('lock_version'),
            'branch_lock_version' => (int) DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Ready Clinic',
            'address_line_1' => '1 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State',
            'country' => 'MY', 'phone' => '', 'email' => 'clinic@readiness.test', 'registration_type' => 'BRN', 'registration_number' => 'SYN-1',
            ...$overrides,
        ], null);
    }

    private function footer(string $text = 'This is a computer-generated document. No signature is required.'): void
    {
        BranchDocumentFooter::query()->create(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'text' => $text, 'lock_version' => 1]);
    }

    /** @return array<string, mixed> */
    private function row(?User $actor = null): array
    {
        return collect(app(DocumentReadinessService::class)->branches($actor ?? $this->director))->firstWhere('branchId', $this->branch->id);
    }

    private function logo(bool $onDisk = true, bool $intact = true): void
    {
        $bytes = 'synthetic-logo-bytes';
        if ($onDisk) {
            Storage::disk('local')->put('business-details/synthetic.png', $intact ? $bytes : 'changed-bytes');
        }
        $asset = new BusinessDetailAsset;
        $asset->forceFill(['organisation_id' => $this->organisation->id, 'public_id' => (string) Str::uuid(), 'path' => 'business-details/synthetic.png',
            'mime' => 'image/png', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'width' => 1, 'height' => 1])->save();
        DB::table('organisation_business_profiles')->where('organisation_id', $this->organisation->id)->update(['logo_asset_id' => $asset->id]);
    }

    public function test_a_complete_branch_is_ready_and_a_missing_footer_is_only_a_warning(): void
    {
        $this->saveDetails();

        $row = $this->row();
        $this->assertSame('needs_footer', $row['status']);
        $this->assertSame(['print footer'], $row['missing']);
        $this->assertSame([], $row['blockedLetters']);
        $this->assertTrue($row['isActive']);
        $this->assertSame('none', $row['logoState']);

        $this->footer();
        $row = $this->row();
        $this->assertSame('ready', $row['status']);
        $this->assertSame([], $row['missing']);
        $this->assertTrue($row['hasFooter']);
    }

    public function test_an_incomplete_address_or_contact_blocks_referral_and_attendance_only(): void
    {
        // The form requires these, so an incomplete record comes from older data: set it up directly.
        $this->saveDetails();
        DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->update(['postcode' => '', 'email' => null, 'phone' => null]);
        $this->footer();

        $row = $this->row();
        $this->assertSame('letters_blocked', $row['status']);
        $this->assertSame(['postcode', 'phone or email'], $row['missing']);
        // The MC code checks only the logo; BASELINE records this, and CAT-4 does not change it.
        $this->assertSame(['referral', 'attendance'], $row['blockedLetters']);
    }

    public function test_a_branch_with_no_details_lists_everything_missing(): void
    {
        $row = $this->row();

        $this->assertSame('letters_blocked', $row['status']);
        $this->assertSame(
            ['clinic name', 'address line 1', 'postcode', 'city', 'state', 'country', 'phone or email', 'print footer'],
            $row['missing'],
        );
    }

    public function test_a_configured_logo_that_is_missing_or_changed_blocks_every_letter_and_a_good_one_is_fine(): void
    {
        $this->saveDetails();
        $this->footer();

        $this->logo();
        $this->assertSame(['ready', 'ok'], [$this->row()['status'], $this->row()['logoState']]);

        $this->logo(intact: false);
        $row = $this->row();
        $this->assertSame('letters_blocked', $row['status']);
        $this->assertSame(['MC', 'referral', 'attendance'], $row['blockedLetters']);
        $this->assertSame(['logo (configured but unavailable)'], $row['missing']);

        $this->logo(onDisk: false);
        Storage::disk('local')->delete('business-details/synthetic.png');
        $this->assertSame(['MC', 'referral', 'attendance'], $this->row()['blockedLetters']);
        $this->assertSame('unavailable', $this->row()['logoState']);
    }

    public function test_a_retired_logo_blocks_referral_and_attendance_but_not_the_mc(): void
    {
        $this->saveDetails();
        $this->footer();
        $this->logo();
        // The assembler drops a retired logo, so the MC issues without one; the letters refuse a configured logo that
        // is gone (DocumentLetterheadCapture).
        DB::table('business_detail_assets')->where('organisation_id', $this->organisation->id)->update(['retired_at' => now()]);

        $row = $this->row();
        $this->assertSame('letters_blocked', $row['status']);
        $this->assertSame(['referral', 'attendance'], $row['blockedLetters']);
        $this->assertSame(['logo (configured but retired)'], $row['missing']);
        $this->assertSame('retired', $row['logoState']);
        $this->assertTrue($row['detailsComplete']);
    }

    public function test_every_active_branch_is_listed_with_the_active_one_first_and_inactive_ones_left_out(): void
    {
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        $other->forceFill(['is_active' => false])->save();

        $rows = app(DocumentReadinessService::class)->branches($this->director);
        $ids = array_column($rows, 'branchId');

        $this->assertSame($this->branch->id, $ids[0]);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(
            Branch::query()->where('organisation_id', $this->organisation->id)->where('is_active', true)->count(),
            count($rows),
        );
    }

    public function test_the_page_carries_the_readiness_writes_nothing_and_stays_with_the_existing_viewers(): void
    {
        $this->saveDetails();
        $before = [DB::table('audit_logs')->count(), DB::table('branch_business_profiles')->count(), DB::table('branch_document_footers')->count()];

        $this->actingAs($this->director)->get('/business-details')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('BusinessDetails/Index')
                ->where('readiness.0.branchId', $this->branch->id)
                ->where('readiness.0.status', 'needs_footer'));
        $this->assertSame($before, [DB::table('audit_logs')->count(), DB::table('branch_business_profiles')->count(), DB::table('branch_document_footers')->count()]);

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->actingAs($ca)->get('/business-details')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(DocumentReadinessService::class)->branches($ca);
    }
}

<?php

namespace Tests\Feature\Approvals;

use App\Domain\Approvals\ApprovalInbox;
use App\Domain\Approvals\ApprovalSources;
use App\Domain\Approvals\Sources\PanelPriceChangeSource;
use App\Domain\Approvals\Sources\PatientMergeSource;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Patient\Services\PatientMergeService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

/** APPR-1a: the inbox counts what the owning pages list, per person, and never grants access by itself. */
final class ApprovalInboxTest extends ClinicalTestCase
{
    private PriceBook $book;

    private ChargeDefinition $charge;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $references = app(PricingReferenceAdministrationService::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-PNL', 'name' => 'Synthetic Panel']);
        $this->book = $references->createPriceBook($supervisor, null, ['name' => 'Synthetic Panel book', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'APR-MED', 'display_name' => 'Synthetic APR medicine', 'order_unit' => 'tablet', 'created_by_user_id' => $supervisor->id, 'updated_by_user_id' => $supervisor->id]);
        $this->charge = $references->createMedicineCharge($supervisor, $medicine, ['code' => 'APR-MED', 'display_name' => 'Synthetic APR medicine']);
    }

    private function proposePrice(?User $officer = null): void
    {
        $officer ??= $this->actor('panel_officer');
        $this->selectBranch($officer);
        app(PanelPriceProposalService::class)->propose($officer, $this->book, $this->charge, [
            'expected_branch_id' => $this->branch->id,
            'expected_version' => (int) PriceEntry::query()->where('price_book_id', $this->book->id)->where('charge_definition_id', $this->charge->id)->max('version'),
            'amount_sen' => 1450, 'agreement_reference' => 'AGR-1',
            'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDays(3)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_a_panel_price_proposal_counts_for_finance_and_never_for_its_maker(): void
    {
        $officer = $this->actor('panel_officer');
        $this->proposePrice($officer);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);

        $this->assertSame(1, app(PanelPriceChangeSource::class)->count($finance, $this->branch));
        $this->assertSame(0, app(PanelPriceChangeSource::class)->count($officer, $this->branch));
        $this->assertSame(1, app(ApprovalInbox::class)->summary($finance)['total']);
        $this->assertTrue(app(ApprovalInbox::class)->summary($finance)['showNumber']);
        // The maker holds no decide permission, so sees neither a number nor a section.
        $this->selectBranch($officer);
        $summary = app(ApprovalInbox::class)->summary($officer);
        $this->assertFalse($summary['showNumber']);
        $this->assertSame(0, $summary['total']);
    }

    public function test_a_patient_merge_proposal_counts_for_another_supervisor_not_the_proposer(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $kept = $this->patient();
        $other = $this->patient();
        app(PatientMergeService::class)->propose($ca, $kept->refresh(), [
            'kind' => 'merge', 'other_patient_number' => $other->patient_number, 'reason_code' => 'same_person_registered_twice',
            'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version, 'other_lock_version' => $other->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        $this->assertSame(1, app(PatientMergeSource::class)->count($supervisor, $this->branch));
        $this->assertSame(1, PatientMergeSource::waitingFor($supervisor)->count());
    }

    public function test_counts_are_cached_per_person_and_cleared_when_that_person_decides(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $inbox = app(ApprovalInbox::class);
        $this->assertSame(0, $inbox->summary($finance)['total']);
        $this->proposePrice();
        $this->assertSame(0, $inbox->summary($finance)['total'], 'a cached count may lag up to 60 seconds');
        ApprovalInbox::forget($finance);
        $this->assertSame(1, $inbox->summary($finance)['total']);
    }

    public function test_access_is_never_cached_a_role_without_the_permission_sees_nothing(): void
    {
        $this->proposePrice();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);
        $this->assertSame([], app(ApprovalSources::class)->visibleTo($doctor, $this->branch));
        $this->actingAs($doctor)->getJson(route('approvals.count'))->assertOk()->assertJson(['showNumber' => false, 'total' => 0, 'areas' => []]);
    }

    public function test_the_count_endpoint_is_refused_to_the_tv_account(): void
    {
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->actingAs($tv)->getJson(route('approvals.count'))->assertForbidden();
    }

    public function test_the_response_carries_no_names_amounts_or_reasons(): void
    {
        $this->proposePrice();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $body = $this->actingAs($finance)->getJson(route('approvals.count'))->assertOk()->getContent();
        foreach (['AGR-1', '1450', 'Synthetic'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body);
        }
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPackDocument;
use App\Domain\Visit\Billing\Models\PanelClaimPackPrint;
use App\Domain\Visit\Billing\Services\PanelClaimPackIssueService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PNL-25 (Panel pilot work): the claim pack is frozen at first print. ORIGINAL for the first print of a version,
 * COPY for a reprint of the same printed content, REVISED for the first print after the printed content changed.
 * Nothing about a claim, an amount or any financial record changes when a pack is printed.
 */
class PanelClaimPackIssueTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_first_print_is_the_original_and_an_unchanged_reprint_is_a_copy_of_the_same_version(): void
    {
        $claims = $this->claims(2);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->post(route('panel-claims.documents.store', $claims[0]), ['expected_branch_id' => $this->branch->id,
            'file' => UploadedFile::fake()->createWithContent('guarantee letter.png', $png)])->assertRedirect();
        $before = $this->claimEvidence();

        $first = $this->issue($claims);
        $second = $this->issue($claims);

        $this->assertSame('original', $first->label);
        $this->assertSame('copy', $second->label);
        $this->assertSame($first->panel_claim_pack_document_id, $second->panel_claim_pack_document_id);
        $this->assertSame(1, PanelClaimPackDocument::query()->count());
        $this->assertSame(2, PanelClaimPackPrint::query()->count());
        // Nothing about the claims changed.
        $this->assertEquals($before, $this->claimEvidence());

        $shown = app(PanelClaimPackIssueService::class)->show($this->officer(), $first->public_id);
        $this->assertSame(['Photo 1'], $shown['pack']['claims'][0]['photos']);
        $this->assertStringNotContainsString('guarantee letter', json_encode($shown, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('printedAt', $shown['pack']);
        $this->assertSame(1, $shown['issued']['version']);
        $this->assertSame([], $shown['issued']['notes']);
    }

    public function test_a_change_to_a_claim_makes_the_next_print_revised_and_the_old_version_stays_as_it_was(): void
    {
        $claims = $this->claims(1);
        $original = $this->issue($claims);
        $oldContent = DB::table('panel_claim_pack_documents')->where('id', $original->panel_claim_pack_document_id)->value('encrypted_snapshot');

        $this->post(route('panel-claims.decision', $claims[0]), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claims[0]->refresh()->lock_version,
            'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision'])->assertSessionHasNoErrors();
        $revised = $this->issue($claims);
        $again = $this->issue($claims);

        $this->assertSame('revised', $revised->label);
        $this->assertSame('copy', $again->label);
        $this->assertSame([1, 2], PanelClaimPackDocument::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame($oldContent, DB::table('panel_claim_pack_documents')->where('id', $original->panel_claim_pack_document_id)->value('encrypted_snapshot'));

        $old = app(PanelClaimPackIssueService::class)->show($this->officer(), $original->public_id);
        $this->assertSame('Submitted', $old['pack']['claims'][0]['status']);
        $this->assertStringContainsString('A later version of this claim pack exists (version 2)', $old['issued']['notes'][0]);
        $new = app(PanelClaimPackIssueService::class)->show($this->officer(), $revised->public_id);
        $this->assertNotSame('Submitted', $new['pack']['claims'][0]['status']);
    }

    public function test_the_same_request_key_returns_the_same_print_and_a_different_pack_with_that_key_is_refused(): void
    {
        $claims = $this->claims(2);
        $key = (string) Str::uuid();
        $one = app(PanelClaimPackIssueService::class)->issue($this->officer(), ['claims' => [$claims[0]->public_id], 'idempotency_key' => $key]);
        $again = app(PanelClaimPackIssueService::class)->issue($this->officer(), ['claims' => [$claims[0]->public_id], 'idempotency_key' => $key]);

        $this->assertSame($one->id, $again->id);
        $this->assertSame(1, PanelClaimPackPrint::query()->count());
        $this->expectException(ValidationException::class);
        app(PanelClaimPackIssueService::class)->issue($this->officer(), ['claims' => [$claims[1]->public_id], 'idempotency_key' => $key]);
    }

    public function test_only_the_person_who_made_the_latest_print_sees_original_again(): void
    {
        $claims = $this->claims(1);
        $print = $this->issue($claims);
        $other = $this->actor('panel_officer');

        $this->assertSame('original', app(PanelClaimPackIssueService::class)->show($this->officer(), $print->public_id)['issued']['label']);
        $this->assertSame('copy', app(PanelClaimPackIssueService::class)->show($other, $print->public_id)['issued']['label']);
        $this->assertSame('original', $print->label);
    }

    public function test_a_copy_that_shows_identity_is_not_reprinted_or_opened_by_someone_who_cannot_see_identity(): void
    {
        $claims = $this->claims(1);
        $print = $this->issue($claims);
        $document = PanelClaimPackDocument::query()->sole();

        $this->assertTrue($document->refresh()->shows_identity);
        $this->assertTrue(app(PanelClaimPackIssueService::class)->forClaim($this->officer(), $claims[0])[0]['canOpen']);

        Role::query()->where('name', 'panel_officer')->firstOrFail()->revokePermissionTo('panel.claims.patient_identity.view.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $officer = User::query()->findOrFail($this->officer()->id);
        // The list offers no dead-end buttons for a copy this viewer cannot open.
        $this->assertFalse(app(PanelClaimPackIssueService::class)->forClaim($officer, $claims[0])[0]['canOpen']);

        foreach ([fn () => app(PanelClaimPackIssueService::class)->show($officer, $print->public_id),
            fn () => app(PanelClaimPackIssueService::class)->reprint($officer, $document->public_id, ['idempotency_key' => (string) Str::uuid()])] as $read) {
            try {
                $read();
                $this->fail('A copy with identity numbers was opened without the identity permission.');
            } catch (ValidationException $e) {
                $this->assertSame(['pack' => ['This copy shows patient identity numbers, which your role cannot see.']], $e->errors());
            }
        }
        $this->assertSame(1, PanelClaimPackPrint::query()->count());
    }

    public function test_print_pack_records_the_print_and_opens_the_frozen_copy_and_the_claim_page_lists_it(): void
    {
        $claims = $this->claims(1);
        $claim = $claims[0];
        $this->selectBranch($this->officer(), $this->branch);

        $response = $this->post(route('panel-claims.pack.issue'), ['claims' => [$claim->public_id], 'idempotency_key' => (string) Str::uuid(), 'from' => $claim->public_id]);
        $print = PanelClaimPackPrint::query()->sole();
        $response->assertRedirect(route('panel-claims.pack.copy', ['print' => $print->public_id, 'from' => $claim->public_id]));
        $this->get(route('panel-claims.pack.copy', ['print' => $print->public_id, 'from' => $claim->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PanelClaims/Pack')
                ->where('issued.label', 'original')->where('issued.version', 1)->where('issued.printedByYou', true)
                ->where('backUrl', route('panel-claims.show', $claim))->where('backLabel', 'Back to claim')
                ->where('pack.claimCount', 1)->missing('pack.printedAt'));

        $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('issuedPacks', 1)->where('issuedPacks.0.version', 1)->where('issuedPacks.0.firstLabel', 'original')
            ->where('issuedPacks.0.claimCount', 1)->where('issuedPacks.0.prints', 1)
            ->where('issuedPacks.0.viewUrl', route('panel-claims.pack.copy', ['print' => $print->public_id, 'from' => $claim->public_id], absolute: false)));

        $document = PanelClaimPackDocument::query()->sole();
        $this->post(route('panel-claims.pack.reprint', $document->public_id), ['idempotency_key' => (string) Str::uuid(), 'from' => $claim->public_id])->assertRedirect();
        $this->assertSame(['original', 'copy'], PanelClaimPackPrint::query()->orderBy('id')->pluck('label')->all());
    }

    public function test_a_refused_print_returns_to_the_claim_page_and_records_nothing(): void
    {
        $claims = $this->claims(1);
        $this->selectBranch($this->officer(), $this->branch);

        $this->post(route('panel-claims.pack.issue'), ['claims' => [], 'idempotency_key' => (string) Str::uuid(), 'from' => $claims[0]->public_id])
            ->assertRedirect(route('panel-claims.show', $claims[0]))->assertSessionHasErrors('claims');
        $this->post(route('panel-claims.pack.issue'), ['claims' => [$claims[0]->public_id]])
            ->assertRedirect(route('panel-claims.index'))->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, PanelClaimPackDocument::query()->count());
        $this->assertSame(0, PanelClaimPackPrint::query()->count());
    }

    public function test_someone_without_the_claims_permission_cannot_print_reprint_or_open_a_frozen_pack(): void
    {
        $claims = $this->claims(1);
        $print = $this->issue($claims);
        $document = PanelClaimPackDocument::query()->sole();
        $ca = $this->actor('ca');
        $this->actingAs($ca);
        $this->selectBranch($ca, $this->branch);

        $this->post(route('panel-claims.pack.issue'), ['claims' => [$claims[0]->public_id], 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('panel-claims.pack.reprint', $document->public_id), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('panel-claims.pack.copy', $print->public_id))->assertForbidden();
        $this->assertSame(1, PanelClaimPackPrint::query()->count());
    }

    public function test_frozen_copies_and_prints_can_never_be_changed_or_deleted(): void
    {
        $claims = $this->claims(1);
        $print = $this->issue($claims);
        $document = PanelClaimPackDocument::query()->sole();
        $this->assertSame(1, $document->version);

        foreach ([
            fn () => DB::statement('update panel_claim_pack_documents set version = 9 where id = ?', [$document->id]),
            fn () => DB::statement('delete from panel_claim_pack_documents where id = ?', [$document->id]),
            fn () => DB::statement('update panel_claim_pack_prints set label = ? where id = ?', ['copy', $print->id]),
            fn () => DB::statement('delete from panel_claim_pack_prints where id = ?', [$print->id]),
            fn () => DB::statement('truncate panel_claim_pack_prints'),
        ] as $change) {
            DB::beginTransaction();
            try {
                $change();
                $this->fail('A frozen copy or print was changed.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    /** @param list<PanelClaim> $claims */
    private function issue(array $claims): PanelClaimPackPrint
    {
        return app(PanelClaimPackIssueService::class)->issue($this->officer(), [
            'claims' => array_map(static fn (PanelClaim $claim): string => $claim->public_id, $claims), 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    private function claimEvidence(): array
    {
        return ['claims' => DB::table('panel_claims')->orderBy('id')->get()->all(), 'events' => DB::table('panel_claim_events')->orderBy('id')->count(),
            'invoices' => DB::table('invoices')->orderBy('id')->get()->all(), 'payments' => DB::table('payments')->count()];
    }

    /** @return list<PanelClaim> */
    private function claims(int $count): array
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $claims = [];
        for ($i = 0; $i < $count; $i++) {
            [$allocation, $invoice] = $this->approvedAllocation($panel);
            $this->selectBranch($this->officer(), $this->branch);
            $this->post(route('panel-claims.store', $allocation), [
                'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
                'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
                'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            ])->assertSessionHasNoErrors();
            $claims[] = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
        }

        return $claims;
    }
}

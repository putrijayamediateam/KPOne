<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PNL-8 (owner decisions 2026-11-05): a printable claim pack for one claim or up to 50 claims of one Panel, with
 * the branch letterhead and the IC or passport number; photos are listed by name, never printed. Each print writes
 * one audit record with claim ids only.
 */
class PanelClaimPackTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_single_claim_pack_carries_the_saved_claim_identity_and_photo_names_and_is_audited(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-PACK']);
        [$claim] = $this->submittedClaims($panel, 1);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->post(route('panel-claims.documents.store', $claim), ['expected_branch_id' => $this->branch->id,
            'file' => UploadedFile::fake()->createWithContent('guarantee letter.png', $png)])->assertRedirect();
        $identifier = DB::table('patient_identifiers')->where('patient_id', $claim->patient_id)->whereNull('retired_at')->orderByDesc('id')->first();

        $this->get(route('panel-claims.pack', ['claims' => [$claim->public_id], 'from' => $claim->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PanelClaims/Pack')
                ->where('backUrl', route('panel-claims.show', $claim))
                ->where('backLabel', 'Back to claim')
                ->has('letterhead.details.clinic_name')
                ->where('pack.panelName', $claim->panel_name_snapshot)
                ->where('pack.claimCount', 1)
                ->where('pack.totalClaimSen', 3000)
                ->has('pack.claims', 1)
                ->where('pack.claims.0.claimNo', (string) $claim->id)
                ->where('pack.claims.0.status', 'Submitted')
                ->where('pack.claims.0.patientName', $claim->patient_name_snapshot)
                ->where('pack.claims.0.identifier', $identifier?->normalized_value)
                ->where('pack.claims.0.identityHidden', false)
                ->where('pack.claims.0.memberReference', 'SYNTHETIC-TEST-REF')
                ->where('pack.claims.0.invoiceNumber', $claim->invoice_number)
                ->where('pack.claims.0.claimAmountSen', 3000)
                ->where('pack.claims.0.photos', ['guarantee letter.png'])
                ->has('pack.claims.0.lines', fn (Assert $lines) => $lines->each(fn (Assert $line) => $line
                    ->hasAll(['number', 'name', 'code', 'quantity', 'unitPriceSen', 'totalSen'])))
                ->missing('pack.claims.0.diagnosis')
                ->missing('pack.claims.0.photoUrls'));

        $audit = DB::table('audit_logs')->where('event', 'panel_claim.pack_printed')->sole();
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame([(int) $claim->id], $metadata['claim_ids']);
        $this->assertSame(1, $metadata['claim_count']);
        $this->assertTrue($metadata['identity_shown']);
        $this->assertStringNotContainsString((string) $claim->patient_name_snapshot, (string) $audit->metadata);
        if ($identifier !== null) {
            $this->assertStringNotContainsString((string) $identifier->normalized_value, (string) $audit->metadata);
        }
    }

    public function test_a_batch_pack_covers_claims_of_one_panel_and_refuses_mixed_or_too_many_claims(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-BATCH']);
        $other = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-OTHER']);
        [$first, $second] = $this->submittedClaims($panel, 2);
        [$elsewhere] = $this->submittedClaims($other, 1);

        $this->get(route('panel-claims.index'))->assertInertia(fn (Assert $page) => $page
            ->where('work.claims.data.0.panelId', fn (int $id): bool => in_array($id, [$panel->id, $other->id], true)));

        $this->get(route('panel-claims.pack', ['claims' => [$second->public_id, $first->public_id]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('backUrl', route('panel-claims.index'))
                ->where('backLabel', 'Back to Panel Claims')
                ->where('pack.claimCount', 2)
                ->where('pack.totalClaimSen', 6000)
                ->where('pack.claims.0.publicId', $first->public_id)
                ->where('pack.claims.1.publicId', $second->public_id));

        // A refused GET returns to the page it was asked from, never to a stale "previous" page.
        $this->withSession(['_previous' => ['url' => route('panel-remittances.index')]])
            ->get(route('panel-claims.pack', ['claims' => [$first->public_id, $elsewhere->public_id]]))
            ->assertRedirect(route('panel-claims.index'))->assertSessionHasErrors(['claims' => 'Choose claims of one Panel.']);
        $this->withSession(['_previous' => ['url' => route('panel-remittances.index')]])
            ->get(route('panel-claims.pack', ['claims' => [$first->public_id, $elsewhere->public_id], 'from' => $first->public_id]))
            ->assertRedirect(route('panel-claims.show', $first));
        $this->get(route('panel-claims.pack', ['claims' => [$first->public_id, strtoupper($first->public_id)]]))
            ->assertSessionHasErrors(['claims.1' => 'A claim was chosen twice. Refresh the list and try again.']);
        $this->from(route('panel-claims.index'))->get(route('panel-claims.pack', ['claims' => array_map(static fn (): string => (string) Str::uuid(), range(1, 51))]))
            ->assertSessionHasErrors(['claims' => 'Choose at most 50 claims.']);
        $this->from(route('panel-claims.index'))->get(route('panel-claims.pack'))->assertSessionHasErrors(['claims' => 'Choose at least one claim.']);
        $this->get(route('panel-claims.pack', ['claims' => [$first->public_id, (string) Str::uuid()]]))->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.pack_printed')->count(), 'Only the printed pack is recorded.');
    }

    public function test_only_panel_officers_at_the_claims_branch_can_print_a_pack(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->submittedClaims($panel, 1);

        foreach (['ca', 'ca_supervisor', 'finance_officer', 'director', 'administrator'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->get(route('panel-claims.pack', ['claims' => [$claim->public_id]]))->assertForbidden();
        }

        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->get(route('panel-claims.pack', ['claims' => [$claim->public_id]]))->assertNotFound();
        $this->get(route('panel-claims.letterhead-logo', ['asset' => (string) Str::uuid()]))->assertNotFound();
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'panel_claim.pack_printed')->count());
    }

    public function test_without_the_identity_permission_the_pack_leaves_out_the_ic_number(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->submittedClaims($panel, 1);
        Role::findByName('panel_officer')->revokePermissionTo('panel.claims.patient_identity.view.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get(route('panel-claims.pack', ['claims' => [$claim->public_id]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pack.claims.0.identifier', null)
                ->where('pack.claims.0.identifierType', null)->where('pack.claims.0.identityHidden', true));
        $metadata = json_decode((string) DB::table('audit_logs')->where('event', 'panel_claim.pack_printed')->sole()->metadata, true);
        $this->assertFalse($metadata['identity_shown']);
    }

    /** @return list<PanelClaim> RM 30.00 Submitted claims */
    private function submittedClaims(Panel $panel, int $count): array
    {
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

<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelStatementUpload;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PNL-7 (owner decisions 2026-11-05): a Panel's CSV statement suggests claim matches for the PNL-3 split. Nothing
 * is recorded from it; the file is stored encrypted and deleted after 30 days.
 */
class PanelStatementTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_a_statement_suggests_one_claim_per_row_and_explains_rows_it_cannot_use(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-STMT']);
        [$byInvoice, $byMember, $byClaimNo, $paid] = $this->acceptedClaims($panel, 4);
        $this->pay($paid, 3000);
        $csv = implode("\n", [
            'Claim No,Invoice,Member ID,Patient Name,Amount Paid (RM)',
            ",{$byInvoice->invoice_number},,Synthetic Patient One,RM 25.00",
            ",{$byMember->invoice_number},,Synthetic Patient Two,\"1,000.00\"",
            "C-{$byClaimNo->id},,,Synthetic Patient Three,30",
            ",{$paid->invoice_number},,Synthetic Patient Four,30.00",
            ',UNKNOWN-1,,Synthetic Patient Five,10.00',
            ",{$byInvoice->invoice_number},,Synthetic Patient One,5.00",
            "{$byClaimNo->id},{$byInvoice->invoice_number},,Synthetic Mixed,10.00",
            // Every synthetic claim carries the same member reference, so matching by it alone is ambiguous.
            ',,synthetic-test-ref,Synthetic Shared,10.00',
            ",{$byClaimNo->invoice_number},,Synthetic Patient Three,not paid",
        ]);

        $this->selectBranch($this->officer(), $this->branch);
        $response = $this->post(route('panel-remittances.statements.store'), $this->upload($panel, $csv));
        $upload = PanelStatementUpload::query()->sole();
        $response->assertRedirect(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))->assertSessionHasNoErrors();

        $this->assertSame(9, $upload->row_count);
        $this->assertTrue($upload->delete_after->equalTo($upload->uploaded_at->addDays(30)));
        $stored = Storage::disk('local')->get($upload->stored_path);
        $this->assertStringNotContainsString('Synthetic Patient', (string) $stored, 'The file is stored encrypted.');
        $audit = (string) DB::table('audit_logs')->where('event', 'panel_statement.uploaded')->sole()->metadata;
        $this->assertStringNotContainsString('Synthetic', $audit);
        $this->assertSame(0, DB::table('panel_claim_payments')->where('panel_claim_id', '!=', $paid->id)->count(), 'Nothing is recorded.');

        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.mapping', ['amount' => 4, 'claim' => 0, 'invoice' => 1, 'member' => 2])
                ->where('statement.headers.3.label', 'Patient Name')
                ->has('statement.rows', 9)
                ->where('statement.rows.0.result', 'matched')->where('statement.rows.0.claim.publicId', $byInvoice->public_id)->where('statement.rows.0.amountSen', 2500)
                ->where('statement.rows.0.line', 2)
                ->where('statement.rows.1.result', 'over_unpaid')->where('statement.rows.1.claim.publicId', $byMember->public_id)->where('statement.rows.1.amountSen', 100000)
                ->where('statement.rows.2.result', 'matched')->where('statement.rows.2.claim.publicId', $byClaimNo->public_id)
                ->where('statement.rows.3.result', 'already_paid')
                ->where('statement.rows.4.result', 'not_found')->where('statement.rows.4.claim', null)
                ->where('statement.rows.5.result', 'repeated')
                ->where('statement.rows.6.result', 'ambiguous')
                ->where('statement.rows.7.result', 'ambiguous')
                ->where('statement.rows.8.result', 'unreadable_amount')
                ->where('statementLimits', ['maxRows' => 500, 'retentionDays' => 30])
                ->missing('statement.rows.0.patientName'));

        // A column can be switched off or chosen by hand; the same file uploaded again reuses the first copy.
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id, 'claim_col' => '', 'member_col' => '']))
            ->assertInertia(fn (Assert $page) => $page->where('statement.mapping.claim', null)->where('statement.rows.2.result', 'not_found'));
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, $csv))->assertSessionHasNoErrors();
        $this->assertSame(1, PanelStatementUpload::query()->count());
    }

    public function test_files_that_are_not_a_usable_csv_are_refused_and_nothing_is_kept(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->selectBranch($this->officer(), $this->branch);
        $cases = [
            'Choose a CSV file.' => UploadedFile::fake()->createWithContent('statement.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01"),
            'The file is over 1 MB. Split it and upload each part.' => UploadedFile::fake()->createWithContent('big.csv', "Invoice,Amount\n".str_repeat("INV-1,10.00\n", 90_000)),
            'The first row must hold column names.' => UploadedFile::fake()->createWithContent('numbers.csv', "10.00,20.00\n30.00,40.00\n"),
            'The file has more than 500 rows.' => UploadedFile::fake()->createWithContent('long.csv', "Invoice,Amount\n".str_repeat("INV-1,10.00\n", 501)),
            'The file has no rows under the column names.' => UploadedFile::fake()->createWithContent('empty.csv', "Invoice,Amount\n\n"),
        ];
        foreach ($cases as $message => $file) {
            $this->post(route('panel-remittances.statements.store'), ['expected_branch_id' => $this->branch->id, 'panel' => $panel->code, 'statement' => $file])
                ->assertSessionHasErrors(['statement' => $message]);
        }
        $this->assertSame(0, PanelStatementUpload::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_statement_is_deleted_after_30_days_or_earlier_on_request(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->acceptedClaims($panel, 1);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, "Invoice;Amount\nINV-X;10,00\n"))->assertSessionHasNoErrors();
        $first = PanelStatementUpload::query()->sole();

        $this->travel(29)->days();
        $this->artisan('panel-statements:purge')->assertSuccessful();
        $this->assertNull($first->refresh()->deleted_at);
        $this->travel(2)->days();
        $this->artisan('panel-statements:purge')->assertSuccessful();
        $this->assertNotNull($first->refresh()->deleted_at);
        $this->assertFalse(Storage::disk('local')->exists($first->stored_path));
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $first->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('statement', null));
        $this->assertSame('expired', json_decode((string) DB::table('audit_logs')->where('event', 'panel_statement.deleted')->sole()->metadata, true)['reason']);

        // Removing a file earlier deletes it at once; uploading the same file again makes a new copy.
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, "Invoice;Amount\nINV-X;10,00\n"))->assertSessionHasNoErrors();
        $second = PanelStatementUpload::query()->whereNull('deleted_at')->sole();
        $this->delete(route('panel-remittances.statements.destroy', $second->public_id), ['expected_branch_id' => $this->branch->id, 'panel' => $panel->code])
            ->assertRedirect(route('panel-remittances.index', ['panel' => $panel->code]));
        $this->assertNotNull($second->refresh()->deleted_at);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(2, PanelStatementUpload::query()->count(), 'The record of each upload is kept; only the file goes.');
    }

    public function test_only_panel_payment_recorders_at_the_branch_can_upload_or_see_a_statement(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->acceptedClaims($panel, 1);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, "Invoice,Amount\nINV-X,10.00\n"))->assertSessionHasNoErrors();
        $upload = PanelStatementUpload::query()->sole();

        foreach (['ca', 'ca_supervisor', 'director', 'administrator', 'resident_doctor'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->post(route('panel-remittances.statements.store'), $this->upload($panel, "Invoice,Amount\nINV-Y,10.00\n"))->assertForbidden();
            $this->delete(route('panel-remittances.statements.destroy', $upload->public_id), ['expected_branch_id' => $this->branch->id])->assertForbidden();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('statement.publicId', $upload->public_id));

        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('finance_officer', $otherBranch);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('statement', null));
        $this->delete(route('panel-remittances.statements.destroy', $upload->public_id), ['expected_branch_id' => $otherBranch->id]);
        $this->assertNull($upload->refresh()->deleted_at);
    }

    /** @return array<string, mixed> */
    private function upload(Panel $panel, string $csv): array
    {
        return ['expected_branch_id' => $this->branch->id, 'panel' => $panel->code,
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv)];
    }

    private function pay(PanelClaim $claim, int $amount): void
    {
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.payment', $claim), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => $amount,
            'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    /** @return list<PanelClaim> RM 30.00 claims accepted in full */
    private function acceptedClaims(Panel $panel, int $count): array
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
            $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
            $this->post(route('panel-claims.decision', $claim), [
                'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision',
            ])->assertSessionHasNoErrors();
            $claims[] = $claim->refresh();
        }

        return $claims;
    }
}

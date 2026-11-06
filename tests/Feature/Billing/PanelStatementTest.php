<?php

namespace Tests\Feature\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelStatementUpload;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;

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
            ",{$byInvoice->invoice_number},,Synthetic Patient One,\"5,00\"",
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

        // The claim column is never pre-selected: a Panel's own claim number is not KPOne's.
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.mapping', ['amount' => 4, 'claim' => null, 'invoice' => 1, 'member' => 2])
                ->where('statement.rows.2.result', 'not_found'));

        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id, 'claim_col' => '0']))->assertOk()
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
                ->where('statement.rows.5.result', 'repeated')->where('statement.rows.5.amountSen', 500)
                // The invoice number and the claim number point at different claims.
                ->where('statement.rows.6.result', 'ambiguous')
                ->where('statement.rows.7.result', 'ambiguous')
                ->where('statement.rows.8.result', 'unreadable_amount')
                ->where('statementLimits', ['maxRows' => 500, 'retentionDays' => 30])
                ->missing('statement.rows.0.patientName'));

        // A column can be switched off or chosen by hand; the same file uploaded again reuses the first copy.
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id, 'claim_col' => '0', 'member_col' => '']))
            ->assertInertia(fn (Assert $page) => $page->where('statement.mapping.member', null)->where('statement.rows.7.result', 'not_found'));
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id, 'claim_col' => '1.5']))
            ->assertInertia(fn (Assert $page) => $page->where('statement.mapping.claim', null));
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, $csv))->assertSessionHasNoErrors();
        $this->assertSame(1, PanelStatementUpload::query()->count());
    }

    public function test_a_not_found_invoice_that_differs_only_in_format_or_lookalike_letters_is_offered_as_a_near_match(): void
    {
        // PNL-18: a near match is only a suggestion, for one open claim, never a digit typed wrongly.
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-NEAR']);
        [$a, $b, $c, $d, $e, $paid] = $this->acceptedClaims($panel, 6);
        $this->pay($paid, 3000);
        $other = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-OTHER']);
        [$otherPanels] = $this->acceptedClaims($other, 1);
        $this->assertStringContainsString('0', $b->invoice_number);
        $format = static fn (PanelClaim $claim): string => strtolower(str_replace('-', ' ', $claim->invoice_number));
        $lastDigit = (int) substr($e->invoice_number, -1);
        $csv = implode("\n", [
            'Invoice,Amount',
            "{$format($a)},30.00",
            str_replace('0', 'O', $b->invoice_number).',10.00',
            "{$c->invoice_number},30.00",
            "{$format($c)},5.00",
            "{$format($d)},99.00",
            substr($e->invoice_number, 0, -1).(($lastDigit + 5) % 10).',10.00',
            "{$format($paid)},10.00",
            "{$format($a)},5.00",
            "{$format($otherPanels)},10.00",
            "{$e->invoice_number}-1,10.00",
        ]);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, $csv))->assertSessionHasNoErrors();
        $upload = PanelStatementUpload::query()->sole();

        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.rows.0.result', 'near_match')->where('statement.rows.0.claim.publicId', $a->public_id)
                ->where('statement.rows.0.nearReason', 'format')->where('statement.rows.0.statementInvoice', $format($a))
                ->where('statement.rows.0.claim.invoiceNumber', $a->invoice_number)
                ->where('statement.rows.1.result', 'near_match')->where('statement.rows.1.claim.publicId', $b->public_id)
                ->where('statement.rows.1.nearReason', 'lookalike')
                ->where('statement.rows.2.result', 'matched')->missing('statement.rows.2.nearReason')
                // Already matched exactly in another row.
                ->where('statement.rows.3.result', 'not_found')->where('statement.rows.3.claim', null)
                // More than the claim still owes.
                ->where('statement.rows.4.result', 'not_found')->where('statement.rows.4.claim', null)
                // A digit typed wrongly may be another real invoice: never suggested.
                ->where('statement.rows.5.result', 'not_found')->where('statement.rows.5.claim', null)
                // A paid claim no longer waits for payment.
                ->where('statement.rows.6.result', 'not_found')
                // One suggestion per claim.
                ->where('statement.rows.7.result', 'not_found')
                // Another Panel's claim is never suggested.
                ->where('statement.rows.8.result', 'not_found')->where('statement.rows.8.claim', null)
                // A suffix is never glued onto the number.
                ->where('statement.rows.9.result', 'not_found'));
        $this->assertSame(0, DB::table('panel_claim_payments')->where('panel_claim_id', '!=', $paid->id)->count(), 'Nothing is recorded.');
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

    public function test_matching_prefers_the_invoice_and_explains_claims_that_cannot_take_a_payment(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-ORDER']);
        [$open, $second] = $this->acceptedClaims($panel, 2);
        $this->pay($second, 3000);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
        $undecided = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();

        // "Claim Amount" comes first, but "Amount Paid" is the stronger hint. A quoted cell may hold a line break,
        // and the file is saved in the Windows character set.
        $csv = implode("\n", [
            'Invoice,Member,Claim Amount,Amount Paid,Remark',
            // A repeat patient: the member reference is shared, but the invoice decides.
            "{$open->invoice_number},SYNTHETIC-TEST-REF,30.00,12.00,\"first\nsecond line\"",
            // Only the member reference: the paid claim is left out, so the open claim is the only candidate.
            ',SYNTHETIC-TEST-REF,30.00,5.00,caf'."\xE9",
            "{$undecided->invoice_number},,30.00,10.00,",
        ]);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-remittances.statements.store'), $this->upload($panel, $csv))->assertSessionHasNoErrors();
        $upload = PanelStatementUpload::query()->sole();
        $this->assertSame(3, $upload->row_count);

        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.mapping', ['amount' => 3, 'claim' => null, 'invoice' => 0, 'member' => 1])
                ->has('statement.rows', 3)
                ->where('statement.rows.0.result', 'matched')->where('statement.rows.0.claim.publicId', $open->public_id)->where('statement.rows.0.amountSen', 1200)
                ->where('statement.rows.1.result', 'repeated')->where('statement.rows.1.claim.publicId', $open->public_id)
                ->where('statement.rows.2.result', 'not_accepted')->where('statement.rows.2.line', 4));

        // Another Panel's code never shows this Panel's statement.
        $other = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-OTHER']);
        $this->get(route('panel-remittances.index', ['panel' => $other->code, 'statement' => $upload->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('statement', null));

        // A file past its 30 days stops showing at once, before the daily clean-up runs.
        $this->travel(31)->days();
        $this->get(route('panel-remittances.index', ['panel' => $panel->code, 'statement' => $upload->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('statement', null));
    }

    public function test_a_first_row_that_holds_an_ic_or_phone_number_is_refused(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->selectBranch($this->officer(), $this->branch);
        foreach (["Synthetic Name,900101-14-5678,30.00\nA,B,C\n", "Synthetic Name,012-3456789,30.00\nA,B,C\n", "Synthetic Name,+60 12 345 6789,30.00\nA,B,C\n"] as $csv) {
            $this->post(route('panel-remittances.statements.store'), $this->upload($panel, $csv))
                ->assertSessionHasErrors(['statement' => 'The first row must hold column names.']);
        }
        $this->assertSame(0, PanelStatementUpload::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_save_that_fails_after_the_file_is_written_leaves_no_file_behind(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->selectBranch($this->officer(), $this->branch);
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post(route('panel-remittances.statements.store'), $this->upload($panel, "Invoice,Amount\nINV-X,10.00\n"));
            $this->fail('The save should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertSame(0, PanelStatementUpload::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
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

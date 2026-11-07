<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\IssuedBillingDocument;
use App\Domain\Visit\Billing\Models\IssuedBillingDocumentPrint;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-5 (owner decisions 2026-11-05): an invoice or receipt is frozen at first print (ORIGINAL), a reprint of the same
 * content is a COPY, and a changed document prints as a new REVISED version. The old live print pages are a PREVIEW.
 */
class IssuedBillingDocumentTest extends BillingTestCase
{
    public static function roles(): array
    {
        return [['ca', 302], ['ca_supervisor', 302], ['finance_officer', 403], ['director', 403], ['resident_doctor', 403], ['panel_officer', 403], ['administrator', 403]];
    }

    #[DataProvider('roles')]
    public function test_only_those_who_can_print_may_issue_a_copy(string $role, int $status): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertStatus($status);
        $this->assertSame($status === 302 ? 1 : 0, IssuedBillingDocumentPrint::query()->count());
    }

    public function test_first_print_is_original_the_same_content_reprints_as_copy_and_no_financial_record_changes(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($ca);
        $before = $this->financialEvidence();

        $first = $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit));
        $print = IssuedBillingDocumentPrint::query()->sole();
        $first->assertRedirect(route('billing.issued.show', [$visit, $print->public_id, 'from' => 'billing']));
        $document = IssuedBillingDocument::query()->sole();
        $this->assertSame(['invoice', 1, 'original'], [$document->document_type, $document->version, $print->label]);

        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Print')
            ->where('issued.label', 'original')->where('issued.version', 1)->where('issued.notes', [])
            ->where('billing.invoice.number', $invoice->invoice_number)->where('billing.invoice.state.due_now', 4000)
            ->where('receipt', null)->missing('billing.can')->missing('billing.payments'));

        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->assertSame(1, IssuedBillingDocument::query()->count());
        $this->assertSame(['original', 'copy'], IssuedBillingDocumentPrint::query()->orderBy('id')->pluck('label')->all());
        $this->assertSame($before, $this->financialEvidence());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'billing.document_issued')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'billing.document_printed')->count());
        // Encrypted at rest: no patient name or invoice number in the stored text.
        $this->assertStringNotContainsString($visit->patient->full_name, $document->encrypted_snapshot);
        $this->assertStringNotContainsString((string) $invoice->invoice_number, $document->encrypted_snapshot);
        $this->assertSame($visit->patient->full_name, json_decode(Crypt::decryptString($document->encrypted_snapshot), true)['billing']['patient']['name']);
    }

    public function test_a_changed_invoice_prints_as_revised_and_an_earlier_version_reprints_as_copy_with_a_note(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $versions = IssuedBillingDocument::query()->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $revised = IssuedBillingDocumentPrint::query()->latest('id')->first();
        $this->assertSame('revised', $revised->label);
        $this->get(route('billing.issued.show', [$visit, $revised->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('issued.label', 'revised')->where('issued.version', 2)->where('billing.invoice.state.due_now', 0));

        $this->post(route('billing.issued.reprint', [$visit, $versions[0]->public_id]), $this->input($visit))->assertRedirect();
        $copy = IssuedBillingDocumentPrint::query()->latest('id')->first();
        $this->assertSame(['copy', $versions[0]->id], [$copy->label, $copy->issued_billing_document_id]);
        $this->get(route('billing.issued.show', [$visit, $copy->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('issued.label', 'copy')->where('issued.version', 1)->where('billing.invoice.state.due_now', 4000)
            ->where('issued.notes', ['A later version of this invoice exists (version 2). This paper is not the current one.']));

        // The Billing page lists both versions for those who can print, and nothing for Finance.
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page
            ->has('billing.issuedCopies', 2)->where('billing.issuedCopies.0.prints', 2)->where('billing.issuedCopies.1.latest', true)
            ->where('billing.issuedCopies.1.firstPrintedAs', 'revised'));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.issuedCopies', null));
    }

    public function test_a_receipt_reversed_after_printing_prints_as_revised_and_its_original_copy_says_so(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $receipt = Payment::query()->sole();
        $this->post(route('billing.receipt.issue', [$visit, $invoice, $receipt]), $this->input($visit))->assertRedirect();
        $original = IssuedBillingDocument::query()->sole();
        $this->assertSame(['receipt', $receipt->id], [$original->document_type, $original->payment_id]);

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.reverse', [$visit, $invoice, $receipt]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $receipt->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic wrong invoice'])->assertSessionHasNoErrors();

        $this->selectBranch($ca);
        $this->post(route('billing.issued.reprint', [$visit, $original->public_id]), $this->input($visit))->assertRedirect();
        $copy = IssuedBillingDocumentPrint::query()->latest('id')->first();
        $this->get(route('billing.issued.show', [$visit, $copy->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('receipt.status', 'posted')->where('issued.notes', ['Current status: This receipt was later reversed (recording error).']));

        $this->post(route('billing.receipt.issue', [$visit, $invoice, $receipt]), $this->input($visit))->assertRedirect();
        $revised = IssuedBillingDocumentPrint::query()->latest('id')->first();
        $this->assertSame('revised', $revised->label);
        $this->get(route('billing.issued.show', [$visit, $revised->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('receipt.status', 'reversed')->where('issued.version', 2)->where('issued.notes', []));
    }

    public function test_a_retried_request_prints_once_and_a_reused_key_for_something_else_is_refused(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($ca);
        $input = $this->input($visit);
        $this->post(route('billing.issue', [$visit, $invoice]), $input)->assertRedirect();
        $this->post(route('billing.issue', [$visit, $invoice]), $input)->assertRedirect(route('billing.issued.show', [$visit, IssuedBillingDocumentPrint::query()->sole()->public_id, 'from' => 'billing']));
        $this->assertSame(1, IssuedBillingDocumentPrint::query()->count());
        $this->post(route('billing.issued.reprint', [$visit, IssuedBillingDocument::query()->sole()->public_id]), $input)->assertSessionHasErrors('document');
        $this->assertSame(1, IssuedBillingDocumentPrint::query()->count());
    }

    public function test_the_live_print_page_is_a_preview_and_copies_of_another_visit_are_not_reachable(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($ca);
        // BIL-14: the old live address no longer shows a preview; it sends staff to Issued copies and records nothing.
        $this->get(route('billing.print', [$visit, $invoice]))->assertRedirect(route('billing.show', $visit).'#issued-copies');
        $this->assertSame(0, IssuedBillingDocumentPrint::query()->count());

        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        [, , $other] = $this->servingFixture();
        $this->selectBranch($ca); // The fixture signs in its own people; check as the original CA.
        $this->get(route('billing.issued.show', [$other, IssuedBillingDocumentPrint::query()->sole()->public_id]))->assertNotFound();
        $this->post(route('billing.issued.reprint', [$other, IssuedBillingDocument::query()->sole()->public_id]), $this->input($other))->assertNotFound();
    }

    public function test_postgres_keeps_issued_copies_immutable_and_their_versions_and_labels_honest(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $document = IssuedBillingDocument::query()->sole();
        $raw = collect($document->getAttributes())->except(['id', 'public_id', 'created_at'])->all();

        $this->assertDatabaseRefuses('0A000', fn () => DB::table('issued_billing_documents')->where('id', $document->id)->update(['version' => 5]));
        $this->assertDatabaseRefuses('0A000', fn () => DB::table('issued_billing_documents')->where('id', $document->id)->delete());
        $this->assertDatabaseRefuses('0A000', fn () => DB::table('issued_billing_document_prints')->delete());
        $this->assertDatabaseRefuses('0A000', fn () => DB::statement('TRUNCATE issued_billing_document_prints'));
        // Versions follow on: a second version 1, or a jump to 3, is refused.
        $this->assertDatabaseRefuses('23514', fn () => DB::table('issued_billing_documents')->insert([...$raw, 'public_id' => (string) Str::uuid()]), 'versions must follow on');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('issued_billing_documents')->insert([...$raw, 'public_id' => (string) Str::uuid(), 'version' => 3]), 'versions must follow on');
        // A version already printed can only be printed again as COPY; ORIGINAL belongs to version 1 only.
        $print = ['organisation_id' => $document->organisation_id, 'issued_billing_document_id' => $document->id, 'printed_by_user_id' => $ca->id, 'printed_at' => now(), 'payload_hash' => str_repeat('0', 64)];
        $this->assertDatabaseRefuses('23514', fn () => DB::table('issued_billing_document_prints')->insert([...$print, 'public_id' => (string) Str::uuid(), 'label' => 'original', 'idempotency_key' => (string) Str::uuid()]), 'label does not match');
        DB::table('issued_billing_document_prints')->insert([...$print, 'public_id' => (string) Str::uuid(), 'label' => 'copy', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(2, IssuedBillingDocumentPrint::query()->count());
    }

    public function test_a_later_payment_does_not_revise_an_earlier_receipt_but_a_footer_change_revises_the_invoice(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->selectBranch($ca);
        $pay = fn (string $sen) => $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => $sen, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $pay('1000');
        $first = Payment::query()->sole();
        $this->post(route('billing.receipt.issue', [$visit, $invoice, $first]), $this->input($visit))->assertRedirect();
        $pay('3000');
        $this->post(route('billing.receipt.issue', [$visit, $invoice, $first]), $this->input($visit))->assertRedirect();
        $this->assertSame(1, IssuedBillingDocument::query()->where('payment_id', $first->id)->count());
        $this->assertSame('copy', IssuedBillingDocumentPrint::query()->latest('id')->value('label'));

        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        DB::table('branch_document_footers')->updateOrInsert(['branch_id' => $visit->branch_id], ['organisation_id' => $visit->organisation_id, 'text' => 'Synthetic changed footer', 'lock_version' => 2]);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->assertSame([1, 2], IssuedBillingDocument::query()->whereNull('payment_id')->orderBy('version')->pluck('version')->all());
        $this->assertSame('revised', IssuedBillingDocumentPrint::query()->latest('id')->value('label'));
    }

    public function test_reopening_a_print_shows_copy_except_to_its_own_printer_and_the_way_back_follows_where_it_started(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), [...$this->input($visit), 'return_to' => 'invoice'])
            ->assertRedirect(route('billing.issued.show', [$visit, IssuedBillingDocumentPrint::query()->sole()->public_id, 'from' => 'invoice']));
        $print = IssuedBillingDocumentPrint::query()->sole();
        $this->get(route('billing.issued.show', [$visit, $print->public_id, 'from' => 'invoice']))->assertInertia(fn (Assert $page) => $page
            ->where('issued.label', 'original')->where('issued.backUrl', route('visits.invoice', $visit, absolute: false))->where('issued.backLabel', 'Back to invoice'));
        // BIL-5b: a refused print started from the visit page lands back on it, and nothing is recorded.
        $before = IssuedBillingDocumentPrint::query()->count();
        $this->post(route('billing.issue', [$visit, $invoice]), [...$this->input($visit), 'return_to' => 'history', 'expected_branch_id' => 0])
            ->assertRedirect(route('visits.history', $visit))->assertSessionHasErrors('expected_branch_id');
        $this->assertSame($before, IssuedBillingDocumentPrint::query()->count());
        // BIL-5b: a print started from the visit page goes back there.
        $this->post(route('billing.issue', [$visit, $invoice]), [...$this->input($visit), 'return_to' => 'history'])
            ->assertRedirect(route('billing.issued.show', [$visit, IssuedBillingDocumentPrint::query()->latest('id')->value('public_id'), 'from' => 'history']));
        $this->get(route('billing.issued.show', [$visit, $print->public_id, 'from' => 'history']))->assertInertia(fn (Assert $page) => $page
            ->where('issued.backUrl', route('visits.history', $visit, absolute: false))->where('issued.backLabel', 'Back to visit'));
        $this->get(route('billing.issued.show', [$visit, $print->public_id, 'from' => 'https://example.test']))->assertInertia(fn (Assert $page) => $page
            ->where('issued.backUrl', route('billing.show', $visit, absolute: false)));

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('issued.label', 'copy')->where('issued.recordedLabel', 'original')->where('issued.printedByYou', false));
        // A later print supersedes the earlier one, even for its own printer.
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->selectBranch($ca);
        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertInertia(fn (Assert $page) => $page->where('issued.label', 'copy'));
    }

    public function test_a_document_cannot_be_issued_through_another_visit_and_every_printer_sees_every_printed_part(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        [, , $other] = $this->servingFixture();
        $this->selectBranch($ca);
        // This invoice's real receipt, through another visit's address: not found, nothing issued.
        $this->post(route('billing.receipt.issue', [$other, $invoice, Payment::query()->sole()]), $this->input($other))->assertNotFound();
        $this->post(route('billing.issue', [$other, $invoice]), $this->input($other))->assertNotFound();
        $this->assertSame(0, IssuedBillingDocument::query()->count());

        // The frozen copy must not depend on who prints: everyone who may print sees lines and Bill to.
        foreach (['ca', 'ca_supervisor'] as $role) {
            $actor = $this->actor($role);
            $this->assertTrue($actor->can('billing.bill_to.view.branch') && ($actor->can('billing.view.branch') || $actor->can('billing.lines.view.branch')), $role);
        }
        // If print were ever granted without Bill to, issuing is refused (role change rolled back with this test).
        Role::findByName('ca', 'web')->revokePermissionTo('billing.bill_to.view.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $ca->unsetRelation('roles')->unsetRelation('permissions');
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertSessionHasErrors('document');
        $this->assertSame(0, IssuedBillingDocument::query()->count());
    }

    /** @return array<string, mixed> */
    public function test_the_old_print_addresses_send_staff_to_issued_copies_record_nothing_and_keep_their_checks(): void
    {
        // BIL-14 (owner decision 2026-11-07): the old live invoice and receipt addresses only redirect, with a note.
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($ca);
        $before = [$this->financialEvidence(), DB::table('audit_logs')->count()];
        foreach ([route('billing.print', [$visit, $invoice]), route('billing.receipt', [$visit, $invoice, $payment])] as $url) {
            $this->get($url)->assertRedirect(route('billing.show', $visit).'#issued-copies')
                ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => 'Print from here so the copy is recorded.']);
        }
        $this->assertSame(0, IssuedBillingDocument::query()->count());
        $this->assertSame(0, IssuedBillingDocumentPrint::query()->count());
        $this->assertSame($before, [$this->financialEvidence(), DB::table('audit_logs')->count()]);
        // The same permission and branch checks as before: no print permission refuses, another branch is not found.
        $this->selectBranch($this->actor('technical_admin'));
        $this->get(route('billing.print', [$visit, $invoice]))->assertForbidden();
        $other = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '!=', $visit->branch_id)->firstOrFail();
        $this->selectBranch($this->actor('ca', $other), $other);
        $this->get(route('billing.receipt', [$visit, $invoice, $payment]))->assertNotFound();
    }

    private function input(Visit $visit): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'billing'];
    }

    private function cashMethod(Visit $visit): void
    {
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function financialEvidence(): array
    {
        $rows = [];
        foreach (['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'invoice_adjustments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function assertDatabaseRefuses(string $sqlState, callable $statement, ?string $message = null): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('PostgreSQL accepted a statement it must refuse.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null, $exception->getMessage());
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}

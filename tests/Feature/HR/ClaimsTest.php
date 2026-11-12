<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Claims\Services\ClaimService;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-6 staff claims (owner, 2026-11-06: "approve the HR-6 note with the defaults"; HR-PLAN 13.3). Synthetic data only.
 * The clock is frozen in Kuala Lumpur so dates are branch-local.
 */
class ClaimsTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-RECEIPT-ONE\n%%EOF\n";

    private const PDF_TWO = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-RECEIPT-TWO\n%%EOF\n";

    private const PURPOSE = 'Synthetic parking at the Cheras training day';

    private const MONEY_TABLES = ['invoices', 'invoice_lines', 'invoice_adjustments', 'payments', 'payment_allocations', 'payment_reversals', 'payment_refunds',
        'payment_methods', 'cash_closings', 'panel_claims', 'panel_claim_payments'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ClaimService::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_the_tv_account_gets_nothing_and_none_is_authority(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(ClaimPermissions::APPROVE));
        $this->assertSame(['director', 'hr_manager'], $holders(ClaimPermissions::MANAGE));
        $this->assertSame(['director', 'finance_officer'], $holders(ClaimPermissions::PAY));
        $own = $holders(ClaimPermissions::SUBMIT_OWN);
        $this->assertCount(11, $own);
        $this->assertNotContains('queue_display', $own);
        foreach (array_keys(ClaimPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.claims.index'))->assertForbidden();

        $this->selectBranch($this->actor('ca'));
        $this->get(route('hr.claims.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('claims.tabs', ['my'])
            ->where('claims.setUp', false)->where('claims.approvals', null)->where('claims.pay', null));
        $this->post(route('hr.claims.categories.save'), ['name' => 'Meals', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $this->selectBranch($this->actor('finance_officer'));
        $this->get(route('hr.claims.index'))->assertInertia(fn (Assert $page) => $page->where('claims.tabs', ['my', 'pay', 'monthly']));
        $this->selectBranch($this->actor('hr_manager'));
        $this->get(route('hr.claims.index'))->assertInertia(fn (Assert $page) => $page->where('claims.tabs', ['my', 'approvals', 'monthly', 'settings']));
        $this->assertSame(0, DB::table('hr_claim_categories')->count());
    }

    public function test_settings_and_categories_are_clinic_entered_versions_and_a_claim_is_checked_when_sent(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->category($hr, ['name' => 'x'])->assertSessionHasErrors('name');
        $this->category($hr, ['name' => 'Meals', 'per_claim_limit' => '12.345'])->assertSessionHasErrors('per_claim_limit');
        $meals = $this->makeCategory($hr, 'Meals', true, '50.00', '200.00');
        $this->category($hr, ['name' => 'meals'])->assertSessionHasErrors(['name' => 'Another category already has this name.']);
        $this->category($hr, ['category_id' => $meals, 'name' => 'Meals and drinks', 'needs_receipt' => true, 'per_claim_limit' => '60.00',
            'expected_lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['Meals and drinks', 'Meals'], DB::table('hr_claim_category_versions')->orderByDesc('version')->pluck('name')->all());
        $this->settings($hr, ['director_level' => '500.00', 'days_back' => 30, 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->settings($hr, ['director_level' => '400.00', 'days_back' => 30, 'expected_version' => 0])->assertSessionHasErrors('claims');

        // All errors in one submit; a needed receipt; dates on the branch-local clock.
        $this->send($ca, ['category_id' => '', 'spent_on' => '', 'amount' => '4.567', 'purpose' => 'x'])->assertSessionHasErrors(['category_id', 'spent_on', 'amount', 'purpose']);
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '45.60', 'purpose' => self::PURPOSE])
            ->assertSessionHasErrors(['receipts' => 'Meals and drinks needs a receipt.']);
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-11-07', 'amount' => '45.60', 'purpose' => self::PURPOSE], [$this->pdf()])
            ->assertSessionHasErrors(['spent_on' => 'Choose a date that is not in the future.']);
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-10-06', 'amount' => '45.60', 'purpose' => self::PURPOSE], [$this->pdf()])
            ->assertSessionHasErrors(['spent_on' => 'Claims go back at most 30 days.']);
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '45.60', 'purpose' => self::PURPOSE],
            [UploadedFile::fake()->createWithContent('a.txt', 'not a receipt')])->assertSessionHasErrors('receipts.0');
        $this->assertSame(0, DB::table('hr_staff_claims')->count());
        $this->assertSame([], Storage::disk(ClaimService::DISK)->allFiles());

        $key = (string) Str::uuid();
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-10-07', 'amount' => '45.60', 'purpose' => self::PURPOSE, 'idempotency_key' => $key], [$this->pdf()])->assertSessionHasNoErrors();
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-10-07', 'amount' => '45.60', 'purpose' => self::PURPOSE, 'idempotency_key' => $key], [$this->pdf()])->assertSessionHasNoErrors();
        $claim = DB::table('hr_staff_claims')->first();
        $this->assertNotNull($claim);
        $this->assertSame(1, DB::table('hr_staff_claims')->count());
        $this->assertSame('SC-000001', $claim->number);
        $this->assertSame(4560, (int) $claim->amount_sen);
        $this->assertSame((int) $this->branch->id, (int) $claim->branch_id);
        $this->assertStringNotContainsString('parking', (string) $claim->purpose_encrypted);
        $receipt = DB::table('hr_staff_claim_receipts')->first();
        $this->assertNotNull($receipt);
        $this->assertStringNotContainsString('SYNTHETIC', (string) Storage::disk(ClaimService::DISK)->get($receipt->stored_path));

        $this->selectBranch($ca);
        $this->get(route('hr.claims.index'))->assertInertia(fn (Assert $page) => $page->has('claims.my', 1)
            ->where('claims.my.0.amount', 'RM 45.60')->where('claims.my.0.statusWords', 'Waiting for approval')->where('claims.my.0.purpose', self::PURPOSE)
            ->where('claims.my.0.waitingFor', 'Someone else approves this')->where('claims.my.0.receipts.0.label', 'Receipt 1 of 1, PDF')
            ->missing('claims.my.0.dbId'));
        $this->assertSame(self::PDF, $this->get('/hr/claims/receipts/'.$receipt->public_id)->assertOk()->getContent());

        // Withdraw: only the sender, only while it waits.
        $other = $this->actor('ca');
        $this->selectBranch($other);
        $this->post(route('hr.claims.withdraw', $claim->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['claims' => 'Only the person who sent a claim withdraws it.']);
        $this->get('/hr/claims/receipts/'.$receipt->public_id)->assertForbidden();
        $this->selectBranch($ca);
        $this->post(route('hr.claims.withdraw', $claim->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', DB::table('hr_staff_claims')->where('id', $claim->id)->value('status'));
        $this->assertNoSecretsInAudit();
    }

    public function test_someone_else_decides_the_director_level_and_limits_warn_and_a_wrong_amount_is_sent_again(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $hr2 = $this->actor('hr_manager');
        $doctor = $this->actor('resident_doctor');
        $meals = $this->makeCategory($hr, 'Meals', true, '50.00', '80.00');
        $this->settings($hr, ['director_level' => '100.00', 'days_back' => 30, 'expected_version' => 0])->assertSessionHasNoErrors();

        $this->send($doctor, ['category_id' => $meals, 'spent_on' => '2026-11-03', 'amount' => '60.00', 'purpose' => self::PURPOSE], [$this->pdf()])->assertSessionHasNoErrors();
        $this->send($doctor, ['category_id' => $meals, 'spent_on' => '2026-11-04', 'amount' => '120.00', 'purpose' => self::PURPOSE], [$this->pdf(self::PDF_TWO)])->assertSessionHasNoErrors();
        $this->send($hr, ['category_id' => $meals, 'spent_on' => '2026-11-04', 'amount' => '20.00', 'purpose' => self::PURPOSE], [$this->pdf()])->assertSessionHasNoErrors();
        [$small, $big, $own] = DB::table('hr_staff_claims')->orderBy('id')->get()->all();

        // Warnings in words; an HR Manager sees the Director-level case and their own as not theirs to decide.
        $this->selectBranch($hr2);
        $this->get(route('hr.claims.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page->has('claims.approvals.claims', 3)
            ->where('claims.approvals.claims.0.warnings', ['Over the RM 50.00 limit for Meals', 'RM 180.00 in Meals this month, over the RM 80.00 monthly limit',
                'This receipt was also attached to claim SC-000003'])
            ->where('claims.approvals.claims.0.refusal', null)
            ->where('claims.approvals.claims.1.refusal', 'Waiting for the Director.')
            ->where('claims.approvals.claims.2.refusal', "Only a Director approves an HR Manager's claim."));
        $this->decide($hr2, $big, 'approve')->assertSessionHasErrors(['claims' => 'Waiting for the Director.']);
        // PostgreSQL: nobody decides their own claim.
        $this->assertRefused('23514', fn () => DB::table('hr_staff_claims')->where('id', $big->id)->update(['status' => 'approved', 'decided_by_user_id' => $doctor->id,
            'decided_at' => now()]));
        $this->decide($hr2, $own, 'approve')->assertSessionHasErrors(['claims' => "Only a Director approves an HR Manager's claim."]);
        $this->decide($hr, $own, 'approve')->assertSessionHasErrors(['claims' => 'Someone else approves this.']);
        $this->decide($hr2, $small, 'reject', ['reason' => 'other'])->assertSessionHasErrors('note');
        // Over the limit, it can still be approved (limits warn, never refuse).
        $this->decide($hr2, $small, 'approve')->assertSessionHasNoErrors();
        $this->assertSame((int) $hr2->id, (int) DB::table('hr_staff_claims')->where('id', $small->id)->value('decided_by_user_id'));
        $this->assertSame(1, (int) DB::table('hr_staff_claim_actions')->where('claim_id', $small->id)->where('action', 'approved')->value('settings_version'));
        $this->decide($director, $own, 'approve')->assertSessionHasNoErrors();
        $this->decide($director, $big, 'reject', ['reason' => 'wrong_amount'])->assertSessionHasNoErrors();

        // PostgreSQL: what was sent never changes.
        $this->assertRefused('P0001', fn () => DB::table('hr_staff_claims')->where('id', $small->id)->update(['amount_sen' => 1]));
        $this->assertRefused('P0001', fn () => DB::table('hr_staff_claims')->where('id', $small->id)->update(['status' => 'waiting', 'decided_by_user_id' => null, 'decided_at' => null]));

        // Send a new claim after Wrong amount: its receipts are copied in, and a copied receipt is not a duplicate warning.
        $this->selectBranch($doctor);
        $this->get(route('hr.claims.index'))->assertInertia(fn (Assert $page) => $page->where('claims.my.0.rejectReason', 'Wrong amount')->where('claims.my.0.canResend', true));
        $this->send($doctor, ['category_id' => $meals, 'spent_on' => '2026-11-04', 'amount' => '95.00', 'purpose' => self::PURPOSE, 'copy_from' => $big->public_id])->assertSessionHasNoErrors();
        $resent = DB::table('hr_staff_claims')->orderByDesc('id')->first();
        $this->assertNotNull($resent);
        $this->assertSame('SC-000004', $resent->number);
        $this->assertSame((int) $big->id, (int) $resent->copied_from_claim_id);
        $this->assertSame(1, DB::table('hr_staff_claim_receipts')->where('claim_id', $resent->id)->count());
        $this->send($doctor, ['category_id' => $meals, 'spent_on' => '2026-11-04', 'amount' => '95.00', 'purpose' => self::PURPOSE, 'copy_from' => $small->public_id], [$this->pdf()])
            ->assertSessionHasErrors(['claims' => 'Only your own claim rejected for a wrong amount is sent again.']);
        $this->selectBranch($hr2);
        $this->get(route('hr.claims.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page->where('claims.approvals.claims.0.number', 'SC-000004')
            ->where('claims.approvals.claims.0.warnings', ['Over the RM 50.00 limit for Meals', 'RM 155.00 in Meals this month, over the RM 80.00 monthly limit']));
        $this->assertNoSecretsInAudit();
    }

    public function test_payment_is_recorded_by_a_third_person_reversed_back_to_approved_and_clinic_money_is_untouched(): void
    {
        $director = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $finance = $this->actor('finance_officer');
        $ca = $this->actor('ca');
        $travel = $this->makeCategory($hr, 'Travel and parking', false, '', '');
        $before = $this->moneySnapshot();

        $this->send($ca, ['category_id' => $travel, 'spent_on' => '2026-11-05', 'amount' => '12.50', 'purpose' => self::PURPOSE])->assertSessionHasNoErrors();
        $this->send($finance, ['category_id' => $travel, 'spent_on' => '2026-11-05', 'amount' => '8.00', 'purpose' => self::PURPOSE])->assertSessionHasNoErrors();
        [$claim, $financeOwn] = DB::table('hr_staff_claims')->orderBy('id')->get()->all();
        $this->pay($finance, $claim, ['method' => 'bank_transfer', 'paid_on' => '2026-11-06', 'reference' => 'SYN-REF-4321'])
            ->assertSessionHasErrors(['claims' => 'Only an approved claim is paid. Reload the page.']);
        $this->decide($director, $claim, 'approve')->assertSessionHasNoErrors();
        $this->decide($director, $financeOwn, 'approve')->assertSessionHasNoErrors();

        // The approver and the claimant do not record the payment.
        $this->pay($director, $claim, ['method' => 'bank_transfer', 'paid_on' => '2026-11-06', 'reference' => 'SYN-REF-4321'])
            ->assertSessionHasErrors(['claims' => 'The person who approved a claim does not record its payment.']);
        $this->pay($finance, $financeOwn, ['method' => 'cash', 'paid_on' => '2026-11-06'])
            ->assertSessionHasErrors(['claims' => 'Someone else records the payment of your own claim.']);
        $this->pay($finance, $claim, ['method' => 'bank_transfer', 'paid_on' => '2026-11-07', 'reference' => 'SYN-REF-4321'])->assertSessionHasErrors('paid_on');
        $this->pay($finance, $claim, ['method' => 'bank_transfer', 'paid_on' => '2026-11-06', 'reference' => ''])->assertSessionHasErrors('reference');
        $this->pay($finance, $claim, ['method' => 'bank_transfer', 'paid_on' => '2026-11-06', 'reference' => 'SYN-REF-4321'])->assertSessionHasNoErrors();
        $this->assertSame('paid', DB::table('hr_staff_claims')->where('id', $claim->id)->value('status'));
        $payment = DB::table('hr_staff_claim_payments')->first();
        $this->assertNotNull($payment);
        $this->assertSame('4321', $payment->reference_last4);
        $this->assertStringNotContainsString('SYN-REF', (string) $payment->reference_encrypted);

        // Finance sees the person, branch, category, amount and payment only, never the purpose.
        $this->selectBranch($finance);
        $this->get(route('hr.claims.index', ['tab' => 'pay']))->assertInertia(fn (Assert $page) => $page
            ->where('claims.pay.claims.1.statusWords', 'Paid 6 Nov 2026')->where('claims.pay.claims.1.paid.last4', '4321')
            ->where('claims.pay.claims.1.purpose', null)->where('claims.pay.claims.0.refusal', 'Someone else records the payment of your own claim.'));
        // PostgreSQL: one live payment, and the payer is never the claimant or the approver.
        $this->assertRefused('23514', fn () => DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id,
            'claim_id' => $claim->id, 'kind' => 'payment', 'method' => 'cash', 'paid_on' => '2026-11-06', 'recorded_by_user_id' => $hr->id, 'created_at' => now()]));
        $this->assertRefused('23514', fn () => DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id,
            'claim_id' => $financeOwn->id, 'kind' => 'payment', 'method' => 'cash', 'paid_on' => '2026-11-06', 'recorded_by_user_id' => $director->id, 'created_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_staff_claim_payments')->where('id', $payment->id)->update(['paid_on' => '2026-11-01']));
        $this->assertRefused('P0001', fn () => DB::table('hr_staff_claim_actions')->delete());
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_staff_claims CASCADE'));
        $this->assertRefused('P0001', fn () => DB::table('hr_claim_counters')->update(['last_number' => 0]));

        // Reverse: back to Approved, both rows kept, then paid again.
        $this->post(route('hr.claims.reverse', $claim->public_id), ['reason' => 'payment_failed', 'expected_lock_version' => 3, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('approved', DB::table('hr_staff_claims')->where('id', $claim->id)->value('status'));
        $this->assertSame(2, DB::table('hr_staff_claim_payments')->where('claim_id', $claim->id)->count());
        $this->get(route('hr.claims.index', ['tab' => 'pay']))->assertInertia(fn (Assert $page) => $page->where('claims.pay.claims.0.reversed.reason', 'Payment failed')
            ->where('claims.pay.claims.0.statusWords', 'Approved'));
        $this->pay($finance, $claim, ['method' => 'cash', 'paid_on' => '2026-11-06'])->assertSessionHasNoErrors();

        // The monthly list totals by branch and category.
        $this->selectBranch($hr);
        $this->get(route('hr.claims.index', ['tab' => 'monthly', 'month' => '2026-11']))->assertInertia(fn (Assert $page) => $page
            ->where('claims.monthly.monthLabel', 'November 2026')->where('claims.monthly.total.claims', 2)->where('claims.monthly.total.totalSen', 2050)
            ->where('claims.monthly.total.paidSen', 1250)->where('claims.monthly.total.unpaidSen', 800));

        $this->assertSame($before, $this->moneySnapshot());
        $this->assertNoSecretsInAudit();
    }

    public function test_the_director_level_is_the_lower_of_then_and_now_and_receipts_and_purpose_stay_private(): void
    {
        $director = $this->actor('director');
        $director2 = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $finance = $this->actor('finance_officer');
        $doctor = $this->actor('resident_doctor');
        $meals = $this->makeCategory($hr, 'Meals', true, '', '');
        $this->settings($hr, ['director_level' => '100.00', 'days_back' => '', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->send($doctor, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '150.00', 'purpose' => self::PURPOSE], [$this->pdf()])->assertSessionHasNoErrors();
        $claim = DB::table('hr_staff_claims')->first();
        $this->assertNotNull($claim);

        // Raising the level later does not let the claim past the level it was sent under; clearing it keeps the old one.
        $this->settings($hr, ['director_level' => '200.00', 'days_back' => '', 'expected_version' => 1])->assertSessionHasNoErrors();
        $this->decide($hr, $claim, 'approve')->assertSessionHasErrors(['claims' => 'Waiting for the Director.']);
        $this->settings($hr, ['director_level' => '', 'days_back' => '', 'expected_version' => 2])->assertSessionHasNoErrors();
        $this->decide($hr, $claim, 'approve')->assertSessionHasErrors(['claims' => 'Waiting for the Director.']);
        $this->get(route('hr.claims.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page
            ->where('claims.approvals.claims.0.warnings', ['Needs the Director: over RM 100.00'])->where('claims.approvals.claims.0.refusal', 'Waiting for the Director.'));
        $this->selectBranch($doctor);
        $this->get(route('hr.claims.index'))->assertInertia(fn (Assert $page) => $page->where('claims.my.0.waitingFor', 'Waiting for the Director'));

        // A payer opens receipts only once the claim is approved.
        $receipt = DB::table('hr_staff_claim_receipts')->where('claim_id', $claim->id)->value('public_id');
        $this->selectBranch($finance);
        $this->get('/hr/claims/receipts/'.$receipt)->assertForbidden();
        $this->decide($director, $claim, 'approve')->assertSessionHasNoErrors();
        $this->selectBranch($finance);
        $this->get('/hr/claims/receipts/'.$receipt)->assertOk();

        // A Director's own claim: the HR Manager below the level, only another Director above it.
        $this->settings($hr, ['director_level' => '100.00', 'days_back' => '', 'expected_version' => 3])->assertSessionHasNoErrors();
        $this->send($director, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '40.00', 'purpose' => self::PURPOSE], [$this->pdf(self::PDF_TWO)])->assertSessionHasNoErrors();
        $this->send($director, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '300.00', 'purpose' => self::PURPOSE], [$this->pdf("%PDF-1.4\n% SYNTHETIC-THREE\n%%EOF\n")])->assertSessionHasNoErrors();
        [, $small, $big] = DB::table('hr_staff_claims')->orderBy('id')->get()->all();
        $this->decide($hr, $small, 'approve')->assertSessionHasNoErrors();
        $this->decide($hr, $big, 'approve')->assertSessionHasErrors(['claims' => 'Waiting for the Director.']);
        $this->decide($director, $big, 'approve')->assertSessionHasErrors(['claims' => 'Someone else approves this.']);
        $this->decide($director2, $big, 'approve')->assertSessionHasNoErrors();
        // An HR Manager's own claim reads "Waiting for the Director" to them.
        $this->send($hr, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '10.00', 'purpose' => self::PURPOSE], [$this->pdf("%PDF-1.4\n% SYNTHETIC-FOUR\n%%EOF\n")])->assertSessionHasNoErrors();
        $this->get(route('hr.claims.index'))->assertInertia(fn (Assert $page) => $page->where('claims.my.0.waitingFor', 'Waiting for the Director'));

        // PostgreSQL: a paid claim changes only through a reversal, and a second live payment is refused.
        $this->pay($finance, $small, ['method' => 'cash', 'paid_on' => '2026-11-06'])->assertSessionHasNoErrors();
        $this->assertRefused('P0001', fn () => DB::table('hr_staff_claims')->where('id', $small->id)->update(['status' => 'paid', 'lock_version' => 9]));
        $this->assertRefused('23514', fn () => DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id,
            'claim_id' => $small->id, 'kind' => 'payment', 'method' => 'cash', 'paid_on' => '2026-11-06', 'recorded_by_user_id' => $finance->id, 'created_at' => now()]));

        // A refused send never flashes the purpose into the session.
        $this->send($doctor, ['category_id' => $meals, 'spent_on' => '2026-11-05', 'amount' => '5.00', 'purpose' => 'Synthetic private purpose words'],
            [UploadedFile::fake()->createWithContent('bad.pdf', 'not a pdf')])->assertSessionHasErrors('receipts.0');
        $this->assertArrayNotHasKey('purpose', (array) session('_old_input', []));
        $this->assertStringNotContainsString('private purpose', (string) json_encode(session()->all()));
    }

    public function test_a_waiting_claim_says_when_it_was_sent(): void
    {
        // For the HR space's "oldest waiting" line (Daniel, Kavitha): when the claim was sent, ISO and in UTC.
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 02:15:00', 'UTC'));
        $meals = $this->makeCategory($hr, 'Meals', true, '50.00', '200.00');
        $this->settings($hr, ['director_level' => '500.00', 'days_back' => 30, 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->send($ca, ['category_id' => $meals, 'spent_on' => '2026-11-09', 'amount' => '45.60', 'purpose' => self::PURPOSE], [$this->pdf()])->assertSessionHasNoErrors();
        $this->travelBack();
        $this->selectBranch($hr);
        $this->get(route('hr.claims.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page
            ->where('claims.approvals.claims.0.sentAt', '2026-11-10T02:15:00+00:00'));
    }

    public function test_another_organisations_claims_and_categories_are_not_found(): void
    {
        $org = new Organisation;
        $org->forceFill(['code' => 'HR_OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $user = new User;
        $user->forceFill(['organisation_id' => $org->id, 'name' => 'Synthetic Other User', 'email' => 'other.'.Str::lower(Str::random(8)).'@kpone.test', 'is_active' => true])->save();
        $categoryPublic = (string) Str::uuid();
        $categoryId = DB::table('hr_claim_categories')->insertGetId(['public_id' => $categoryPublic, 'organisation_id' => $org->id, 'current_version' => 1,
            'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_claim_category_versions')->insert(['organisation_id' => $org->id, 'category_id' => $categoryId, 'version' => 1, 'name' => 'Other meals',
            'needs_receipt' => false, 'is_active' => true, 'set_by_user_id' => $user->id, 'created_at' => now()]);

        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->send($ca, ['category_id' => $categoryPublic, 'spent_on' => '2026-11-05', 'amount' => '5.00', 'purpose' => self::PURPOSE])->assertSessionHasErrors(['category_id' => 'Choose a category.']);
        $this->selectBranch($director);
        $this->post(route('hr.claims.categories.save'), ['category_id' => $categoryPublic, 'name' => 'Taken over', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.claims.decide', [(string) Str::uuid(), 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->get(route('hr.claims.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('claims.settings.categories', []));
    }

    /** @param array<string, mixed> $input */
    private function category(User $actor, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.categories.save'), ['needs_receipt' => false, 'is_active' => true, ...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function makeCategory(User $actor, string $name, bool $receipt, string $perClaim, string $monthly): string
    {
        $this->category($actor, ['name' => $name, 'needs_receipt' => $receipt, 'per_claim_limit' => $perClaim, 'monthly_limit' => $monthly])->assertSessionHasNoErrors();

        return (string) DB::table('hr_claim_category_versions as v')->join('hr_claim_categories as c', 'c.id', '=', 'v.category_id')->where('v.name', $name)->value('c.public_id');
    }

    /** @param array<string, mixed> $input */
    private function settings(User $actor, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.settings'), [...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $receipts
     */
    private function send(User $actor, array $input, array $receipts = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.send'), ['idempotency_key' => (string) Str::uuid(), ...$input, ...($receipts === [] ? [] : ['receipts' => $receipts])]);
    }

    /** @param array<string, mixed> $input */
    private function decide(User $actor, \stdClass $claim, string $decision, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.decide', [$claim->public_id, $decision]), [...$input,
            'expected_lock_version' => (int) DB::table('hr_staff_claims')->where('id', $claim->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function pay(User $actor, \stdClass $claim, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.claims.pay', $claim->public_id), [...$input,
            'expected_lock_version' => (int) DB::table('hr_staff_claims')->where('id', $claim->id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function pdf(string $content = self::PDF): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.pdf', $content);
    }

    /** @return array<string, string> */
    private function moneySnapshot(): array
    {
        $out = [];
        foreach (self::MONEY_TABLES as $table) {
            $out[$table] = md5((string) json_encode(DB::table($table)->orderBy('id')->get()));
        }

        return $out;
    }

    private function assertNoSecretsInAudit(): void
    {
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.claims.%')->pluck('metadata')->all());
        foreach (['parking', 'Synthetic', 'SYN-REF', 'SYNTHETIC', 'receipt.pdf'] as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
    }

    private function assertRefused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}

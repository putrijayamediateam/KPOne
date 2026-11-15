<?php

namespace Tests\Feature\Reports;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\TransactionReportService;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Billing\BillingTestCase;

/**
 * BIL-8: Transaction Detail runs on the shared BranchReportService boundary. Patient identity is prepared only for the
 * rows shown (or the capped download), never for the whole period. BIL-10 changed Transaction Detail on purpose, so the
 * frozen pre-BIL-8 copy and its byte-for-byte comparison were retired, as agreed when BIL-8 merged.
 */
class TransactionReportBoundaryTest extends BillingTestCase
{
    private function input(array $overrides = []): array
    {
        $date = now()->setTimezone($this->branch->timezone)->toDateString();

        return $overrides + ['expected_branch_id' => $this->branch->id, 'from' => $date, 'to' => $date];
    }

    /** 21 allocated Cash receipts (one reversed), one Card receipt and one receipt with no invoice allocation. */
    private function fixture(): User
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:15:00', $this->branch->timezone));
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $cash = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYN-CASH', 'name' => 'Synthetic cash', 'classification' => 'cash', 'is_active' => true]);
        $cash->save();
        $card = (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'SYN-CARD', 'name' => 'Synthetic card', 'classification' => null, 'is_active' => true]);
        $card->save();
        $payments = [];
        foreach (array_merge(array_fill(0, 21, $cash), [$card]) as $i => $method) {
            $this->travelTo(CarbonImmutable::parse('2026-11-06 10:15:00', $this->branch->timezone)->addMinutes($i));
            $payments[] = app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 100 + $i, 'method' => $method->code, 'idempotency_key' => (string) Str::uuid()]);
        }
        $finance = $this->actor('finance_officer');
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $payments[3], ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $payments[3]->refresh()->lock_version, 'recording_error_only' => true, 'reason' => 'Synthetic reversal']);
        (new Payment)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'patient_id' => $visit->patient_id,
            'payment_method_id' => $cash->id, 'receipt_number' => 'SYN-UNALLOCATED', 'amount_sen' => 777, 'currency' => 'MYR', 'method_snapshot' => $cash->name, 'status' => 'posted',
            'reference' => null, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'unallocated'), 'received_at' => now()->addHour()->utc(), 'recorded_by_user_id' => $ca->id, 'lock_version' => 1])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', $this->branch->timezone));
        $this->selectBranch($finance);

        return $finance->refresh();
    }

    /** @return array{0:mixed,1:list<array<string,mixed>>} */
    private function capture(callable $run): array
    {
        $before = AuditLog::query()->max('id') ?? 0;
        try {
            $result = $run();
        } catch (ValidationException $exception) {
            $result = ['refused' => $exception->errors()];
        } catch (HttpException $exception) {
            $result = ['http' => $exception->getStatusCode(), 'message' => $exception->getMessage()];
        }
        $audits = AuditLog::query()->where('id', '>', $before)->orderBy('id')->get(['event', 'metadata'])
            ->map(fn (AuditLog $log): array => ['event' => $log->event, 'metadata' => $log->metadata])->all();

        return [$result, $audits];
    }

    public function test_the_export_cap_is_checked_before_any_patient_identity_and_a_page_reads_only_its_own_patients(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The 5,001-row stand-in uses PostgreSQL generate_series.');
        }
        $actor = $this->fixture();
        $large = new class extends TransactionReportService
        {
            protected function query(User $actor, Branch $branch, array $filters): Builder
            {
                return Payment::query()->fromRaw('(select generate_series(1,5001) as id, 0 as amount_sen, \'posted\' as status, 0 as payment_method_id) as payments');
            }
        };
        DB::enableQueryLog();
        DB::flushQueryLog();
        // BIL-10 (owner-approved test change): the frozen pre-BIL-8 copy is retired; the live service must refuse.
        [$refused] = $this->capture(fn () => $large->execute($actor, $this->input(), false, true));
        $this->assertSame(['export' => ['More than 5,000 receipts match. Narrow the filters.']], $refused['refused'] ?? $refused);
        $this->assertSame([], $this->patientQueries(), 'A refused download must not look up any patient.');

        DB::flushQueryLog();
        app(TransactionReportService::class)->execute($actor, $this->input(['page' => 2]), true);
        $patients = $this->patientQueries();
        // Page 2 holds receipts 21 to 23: two allocated, one unallocated (masked, never looked up).
        $this->assertCount(1, $patients);
        $this->assertCount(1 + 2, $patients[0]['bindings'], 'Organisation plus the page\'s two allocated receipts only.');
        $allocations = array_values(array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/from "payment_allocations"/', $q['query']) === 1));
        $this->assertCount(1, $allocations);
        $this->assertCount(2 + 3, $allocations[0]['bindings'], 'Organisation, branch and the page\'s three receipts only.');
        DB::disableQueryLog();
    }

    public function test_the_filter_rules_are_only_reordered_never_changed(): void
    {
        $rules = ['expected_branch_id' => ['a'], 'from' => ['b'], 'to' => ['c'], 'page' => ['d'], 'methods' => ['e'], 'methods.*' => ['f'],
            'visit_types' => ['g'], 'visit_types.*' => ['h'], 'branch' => ['i'], 'doctor' => ['j'], 'treatment' => ['k'], 'diagnosis' => ['l']];
        $ordered = (new \ReflectionMethod(TransactionReportService::class, 'filterRules'))->invoke(app(TransactionReportService::class), $rules);
        $this->assertSame(['expected_branch_id', 'from', 'to', 'methods', 'methods.*', 'visit_types', 'visit_types.*', 'page', 'branch', 'doctor', 'treatment', 'diagnosis'], array_keys($ordered));
        $sorted = $ordered;
        ksort($sorted);
        ksort($rules);
        $this->assertSame($rules, $sorted);
    }

    /** @return list<array{query:string,bindings:array<int,mixed>}> */
    private function patientQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/from "patients"/', $q['query']) === 1));
    }
}

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
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\LegacyTransactionReportService;

/**
 * BIL-8: Transaction Detail runs on the shared BranchReportService boundary with byte-identical output. The oracle is a
 * verbatim copy of the service before the move; every screen page, filter, CSV byte, audit row and refusal must match.
 * Patient identity is still prepared only for the rows shown (or the capped download), never for the whole period.
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
        }
        $audits = AuditLog::query()->where('id', '>', $before)->orderBy('id')->get(['event', 'metadata'])
            ->map(fn (AuditLog $log): array => ['event' => $log->event, 'metadata' => $log->metadata])->all();

        return [$result, $audits];
    }

    public function test_every_page_filter_file_audit_and_refusal_is_byte_identical_to_the_service_before_the_move(): void
    {
        $actor = $this->fixture();
        $cashId = (string) PaymentMethod::query()->where('code', 'SYN-CASH')->value('id');
        $scenarios = [
            'form' => [$this->input(), false, false],
            'page 1' => [$this->input(), true, false],
            'page 2' => [$this->input(['page' => 2]), true, false],
            'cash only' => [$this->input(['methods' => [$cashId]]), true, false],
            'consultation only' => [$this->input(['visit_types' => ['consultation']]), true, false],
            'otc only' => [$this->input(['visit_types' => ['otc']]), true, false],
            'download' => [$this->input(), false, true],
            'download cash' => [$this->input(['methods' => [$cashId]]), false, true],
            'page unavailable' => [$this->input(['page' => 3]), true, false],
            'empty day' => [$this->input(['from' => '2026-11-01', 'to' => '2026-11-01']), true, false],
        ];
        foreach ($scenarios as $name => [$input, $run, $download]) {
            $legacy = $this->capture(fn () => app(LegacyTransactionReportService::class)->execute($actor, $input, $run, $download));
            $live = $this->capture(fn () => app(TransactionReportService::class)->execute($actor, $input, $run, $download));
            $this->assertSame($legacy, $live, $name);
        }
        // The fixture really covers what it claims: two pages, a masked unallocated receipt, a reversal and both classes.
        $page = app(TransactionReportService::class)->execute($actor, $this->input(), true);
        $this->assertSame([23, 2], [$page['result']['count'], $page['result']['pages']]);
        $file = app(TransactionReportService::class)->execute($actor, $this->input(), false, true);
        $this->assertStringContainsString('Not shown (no invoice allocation)', $file['csv']);
        $this->assertStringContainsString('Reversed (recording reversal)', $file['csv']);
        $this->assertStringContainsString('Not classified (Non-cash)', $file['csv']);
        $this->assertStringContainsString('Payment method code', $file['csv']);
    }

    public function test_the_export_cap_is_checked_before_any_patient_identity_and_a_page_reads_only_its_own_patients(): void
    {
        $actor = $this->fixture();
        $large = new class extends TransactionReportService
        {
            protected function query(User $actor, Branch $branch, array $filters): Builder
            {
                return Payment::query()->fromRaw('(select generate_series(1,5001) as id, 0 as amount_sen, \'posted\' as status, 0 as payment_method_id) as payments');
            }
        };
        $legacyLarge = new class extends LegacyTransactionReportService
        {
            protected function query(User $actor, Branch $branch, array $filters): Builder
            {
                return Payment::query()->fromRaw('(select generate_series(1,5001) as id, 0 as amount_sen, \'posted\' as status, 0 as payment_method_id) as payments');
            }
        };
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($this->capture(fn () => $legacyLarge->execute($actor, $this->input(), false, true)), $this->capture(fn () => $large->execute($actor, $this->input(), false, true)));
        $this->assertSame([], $this->patientQueries(), 'A refused download must not look up any patient.');

        DB::flushQueryLog();
        app(TransactionReportService::class)->execute($actor, $this->input(['page' => 2]), true);
        $patients = $this->patientQueries();
        $this->assertCount(1, $patients);
        $this->assertLessThanOrEqual(TransactionReportService::PAGE_SIZE, count($patients[0]['bindings']));
        DB::disableQueryLog();
    }

    /** @return list<array{query:string,bindings:array<int,mixed>}> */
    private function patientQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/from "patients"/', $q['query']) === 1));
    }
}

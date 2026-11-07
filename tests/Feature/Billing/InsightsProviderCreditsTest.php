<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Services\InvoiceSalesProjection;
use App\Domain\Shared\Services\OtcProviderRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-12 (owner decision 2026-11-07): each approved refund credit comes off the provider row of its invoice, its
 * attending doctor or the OTC row, so the rows add up to the headline. Exercised on the projection directly with
 * fixed credit rows, so OTC, several doctors, the 100-row cut and the organisation filter are each pinned.
 */
class InsightsProviderCreditsTest extends BillingTestCase
{
    public function test_credits_come_off_each_doctor_and_the_rows_still_add_up_after_re_sorting(): void
    {
        [$a, $b] = [$this->actor('resident_doctor'), $this->actor('resident_doctor')];
        $doctors = [['id' => $a->id, 'name' => 'Doctor A', 'salesSen' => 5000, 'patients' => 2], ['id' => $b->id, 'name' => 'Doctor B', 'salesSen' => 3000, 'patients' => 1]];
        $result = InvoiceSalesProjection::withCredits($a->organisation_id, $doctors, $this->credits([[1000, $a->id, null], [4000, $b->id, null]]), null, true);

        $this->assertSame([['name' => 'Doctor A', 'salesSen' => 4000, 'patients' => 2], ['name' => 'Doctor B', 'salesSen' => -1000, 'patients' => 1]], $result['doctors']);
        $this->assertSame(8000 - 5000, array_sum(array_column($result['doctors'], 'salesSen')));
        $this->assertNull($result['otc']);
    }

    public function test_an_otc_credit_comes_off_the_otc_row_or_creates_it_and_is_hidden_with_the_otc_row(): void
    {
        $doctor = $this->actor('resident_doctor');
        $otc = ['name' => OtcProviderRow::LABEL, 'salesSen' => 2000, 'patients' => 3, 'fixed' => true];
        $credits = fn () => $this->credits([[500, null, 77], [700, null, 78]]);

        $this->assertSame([...$otc, 'salesSen' => 800], InvoiceSalesProjection::withCredits($doctor->organisation_id, [], $credits(), $otc, true)['otc']);
        $this->assertSame(['name' => OtcProviderRow::LABEL, 'salesSen' => -1200, 'patients' => 0, 'fixed' => true],
            InvoiceSalesProjection::withCredits($doctor->organisation_id, [], $credits(), null, true)['otc']);
        $this->assertNull(InvoiceSalesProjection::withCredits($doctor->organisation_id, [], $credits(), null, false)['otc']);
    }

    public function test_a_doctor_with_only_a_credit_is_added_unless_the_list_was_cut_or_the_doctor_is_outside_the_organisation(): void
    {
        $doctor = $this->actor('resident_doctor');
        $name = (string) DB::table('users')->where('id', $doctor->id)->value('name');
        $credits = fn () => $this->credits([[900, $doctor->id, null]]);

        $this->assertSame([['name' => $name, 'salesSen' => -900, 'patients' => 0]], InvoiceSalesProjection::withCredits($doctor->organisation_id, [], $credits(), null, false)['doctors']);
        // Below a 100-row cut the doctor's sales are not in the list, so a credit-only row would be wrong: not added.
        $this->assertSame([], InvoiceSalesProjection::withCredits($doctor->organisation_id, [], $credits(), null, false, true)['doctors']);
        $this->assertSame([], InvoiceSalesProjection::withCredits($doctor->organisation_id + 1000, [], $credits(), null, false)['doctors']);
    }

    /** @param  list<array{0: int, 1: int|null, 2: int|null}>  $rows  amount_sen, doctor_id, dispensary_case_id */
    private function credits(array $rows): Builder
    {
        $values = implode(', ', array_fill(0, count($rows), '(?::bigint, ?::bigint, ?::bigint)'));

        return DB::query()->fromRaw("(VALUES {$values}) AS credits(amount_sen, doctor_id, dispensary_case_id)", array_merge(...$rows));
    }
}

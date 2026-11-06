<?php

namespace Tests\Unit\Agents;

use App\Domain\Agents\Assistant\QuickQuestions;
use PHPUnit\Framework\TestCase;

/** AG-5: the fixed answer templates and arithmetic of Dato' Arif's quick questions. */
class QuickQuestionsTest extends TestCase
{
    /** @param list<array<string, mixed>> $results @param array<string, string> $scope @return array<string, mixed> */
    private function build(string $key, array $results, array $scope = ['branchLabel' => 'all branches', 'date' => '2026-11-06']): array
    {
        return QuickQuestions::build($key, $results, $scope);
    }

    /** @param array<string, mixed> $answer @return list<list<int|string|null>> */
    private function values(array $answer): array
    {
        return array_map(fn (array $row): array => array_column($row, 'value'), $answer['rows']);
    }

    public function test_the_question_list_has_twelve_questions_in_four_groups(): void
    {
        $this->assertSame(['clinic' => 'Clinic today', 'money' => 'Money', 'panel' => 'Panel', 'stock' => 'Stock'], QuickQuestions::GROUPS);
        $this->assertCount(12, QuickQuestions::QUESTIONS);
        foreach (QuickQuestions::GROUPS as $group => $label) {
            $this->assertCount(3, array_filter(QuickQuestions::QUESTIONS, fn (array $q): bool => $q['group'] === $group), $label);
        }
    }

    public function test_ringgit_text_uses_whole_sen(): void
    {
        $this->assertSame(['RM 0.00', 'RM 1,234.05', '-RM 0.99', 'RM 10,000,000.00'], [QuickQuestions::ringgit(0), QuickQuestions::ringgit(123405), QuickQuestions::ringgit(-99), QuickQuestions::ringgit(1000000000)]);
    }

    public function test_the_week_runs_monday_to_today_against_the_same_days_last_week(): void
    {
        // 2026-11-05 is a Thursday.
        $this->assertSame(['from' => '2026-11-02', 'to' => '2026-11-05', 'previousFrom' => '2026-10-26', 'previousTo' => '2026-10-29'], QuickQuestions::week('2026-11-05', '2026-11-05'));
        $this->assertSame(['from' => '2026-10-26', 'to' => '2026-11-01', 'previousFrom' => '2026-10-19', 'previousTo' => '2026-10-25'], QuickQuestions::week('2026-10-28', '2026-11-05'));
        $this->assertSame(['from' => '2026-11-02', 'to' => '2026-11-02', 'previousFrom' => '2026-10-26', 'previousTo' => '2026-10-26'], QuickQuestions::week('2026-11-02', '2026-11-02'));
    }

    public function test_patients_seen_adds_a_total_row_for_several_branches(): void
    {
        $answer = $this->build('clinic.patients_seen', [['branches' => [['branch' => 'CHERAS', 'registered' => 5, 'served' => 3], ['branch' => 'PUCHONG', 'registered' => 2, 'served' => 1]]]]);

        $this->assertSame([['CHERAS', 5, 3], ['PUCHONG', 2, 1]], $this->values($answer));
        $this->assertSame(['All branches', 7, 4], array_column($answer['totals'], 'value'));
        $this->assertSame('4 patients seen on 2026-11-06 at all branches (7 registered).', $answer['sentence']);
        $one = $this->build('clinic.patients_seen', [['branches' => [['branch' => 'CHERAS', 'registered' => 1, 'served' => 1]]]], ['branchLabel' => 'CHERAS', 'date' => '2026-11-06']);
        $this->assertNull($one['totals']);
        $this->assertSame('1 patient seen on 2026-11-06 at CHERAS (1 registered).', $one['sentence']);
    }

    public function test_waiting_now_names_the_branch_with_the_longest_average_wait(): void
    {
        $answer = $this->build('clinic.waiting_now', [['branches' => [
            ['branch' => 'CHERAS', 'waitingNow' => 4, 'averageWaitMinutes' => 12, 'averageConsultationMinutes' => 8],
            ['branch' => 'PUCHONG', 'waitingNow' => 1, 'averageWaitMinutes' => 25, 'averageConsultationMinutes' => 6],
        ]]]);

        $this->assertSame('5 patients are waiting now at all branches. The longest average wait today is 25 minutes at PUCHONG.', $answer['sentence']);
        $this->assertSame(['count', 'minutes'], [$answer['rows'][0][1]['kind'], $answer['rows'][0][2]['kind']]);
        $quiet = $this->build('clinic.waiting_now', [['branches' => [['branch' => 'CHERAS', 'waitingNow' => 1, 'averageWaitMinutes' => 0, 'averageConsultationMinutes' => 0]]]], ['branchLabel' => 'CHERAS', 'date' => '2026-11-06']);
        $this->assertSame('1 patient is waiting now at CHERAS.', $quiet['sentence']);
    }

    public function test_patients_per_doctor_are_sorted_busiest_first(): void
    {
        $answer = $this->build('clinic.seen_per_doctor', [['branches' => [
            ['branch' => 'CHERAS', 'doctors' => [['doctor' => 'Dr Alpha', 'seen' => 2, 'averageConsultationMinutes' => 9], ['doctor' => 'Dr Beta', 'seen' => 5, 'averageConsultationMinutes' => 7]]],
            ['branch' => 'PUCHONG', 'doctors' => [['doctor' => 'Dr Alpha', 'seen' => 2, 'averageConsultationMinutes' => 4]]],
        ]]]);

        $this->assertSame([['CHERAS', 'Dr Beta', 5, 7], ['CHERAS', 'Dr Alpha', 2, 9], ['PUCHONG', 'Dr Alpha', 2, 4]], $this->values($answer));
        $this->assertSame('9 patients seen by 2 doctors on 2026-11-06 at all branches.', $answer['sentence']);
    }

    public function test_the_days_money_keeps_received_apart_from_panel_and_pay_later(): void
    {
        $row = fn (string $branch, int $net, int $cash, int $nonCash, int $panel, int $later): array => ['branch' => $branch, 'netSalesSen' => $net, 'cashNetSen' => $cash, 'nonCashNetSen' => $nonCash, 'totalReceivedSen' => $cash + $nonCash, 'panelApprovedSen' => $panel, 'payLaterDueSen' => $later];
        $answer = $this->build('money.day', [['branches' => [$row('CHERAS', 10000, 3000, 2000, 4000, 1000), $row('PUCHONG', 5050, 5050, 0, 0, 0)]]]);

        $this->assertSame(['All branches', 15050, 8050, 2000, 10050, 4000, 1000], array_column($answer['totals'], 'value'));
        $this->assertSame('Net sales on 2026-11-06 at all branches: RM 150.50. Received RM 100.50 (cash RM 80.50, non-cash RM 20.00); Panel RM 40.00 and pay later RM 10.00 are not received yet.', $answer['sentence']);
    }

    public function test_the_week_compares_net_sales_with_the_same_days_last_week(): void
    {
        $sales = fn (int $net, string $from, string $to): array => ['from' => $from, 'to' => $to, 'netSen' => $net, 'grossSen' => $net + 500, 'discountsSen' => 500, 'surchargesSen' => 0, 'uniqueVisitors' => 3, 'perVisitorSen' => intdiv($net, 3)];
        $scope = ['branchLabel' => 'CHERAS', 'from' => '2026-11-02', 'to' => '2026-11-05'];
        $up = $this->build('money.week', [$sales(30000, '2026-11-02', '2026-11-05'), $sales(24000, '2026-10-26', '2026-10-29')], $scope);

        $this->assertSame('Net sales at CHERAS from 2026-11-02 to 2026-11-05: RM 300.00, RM 60.00 more than the same days last week (2026-10-26 to 2026-10-29, RM 240.00).', $up['sentence']);
        $this->assertSame(['Net sales', 30000, 24000, 6000], $this->values($up)[0]);
        $this->assertSame(['Distinct patients', 3, 3, 0], $this->values($up)[4]);
        $down = $this->build('money.week', [$sales(100, '2026-11-02', '2026-11-05'), $sales(200, '2026-10-26', '2026-10-29')], $scope);
        $this->assertStringContainsString('RM 1.00 less than', $down['sentence']);
        $same = $this->build('money.week', [$sales(100, '2026-11-02', '2026-11-05'), $sales(100, '2026-10-26', '2026-10-29')], $scope);
        $this->assertStringContainsString('RM 1.00, the same as the same days', $same['sentence']);
    }

    public function test_receipts_by_method_add_up_across_branches(): void
    {
        $method = fn (string $code, string $name, bool $cash, int $receipts, int $reversed): array => ['methodCode' => $code, 'method' => $name, 'cash' => $cash, 'receiptsSen' => $receipts, 'reversedSen' => $reversed, 'netSen' => $receipts - $reversed];
        $answer = $this->build('money.receipts_by_method', [['branches' => [
            ['branch' => 'CHERAS', 'receiptsByMethod' => [$method('CASH', 'Cash', true, 5000, 1000), $method('CARD', 'Card', false, 2000, 0), $method('QR', 'DuitNow QR', false, 0, 0)]],
            ['branch' => 'PUCHONG', 'receiptsByMethod' => [$method('CARD', 'Card', false, 3000, 0)]],
        ]]]);

        $this->assertSame([['Card', 'Non-cash', 5000, 0, 5000], ['Cash', 'Cash', 5000, 1000, 4000]], $this->values($answer));
        $this->assertSame(['All methods', null, 10000, 1000, 9000], array_column($answer['totals'], 'value'));
        $this->assertSame('RM 90.00 received on 2026-11-06 at all branches through 2 payment methods; RM 40.00 of it in cash.', $answer['sentence']);
    }

    public function test_panel_answers_add_up_per_panel_and_say_when_nothing_is_owed(): void
    {
        $panel = fn (string $name, int $count, int $sen): array => ['panel' => $name, 'unclaimedInvoices' => $count, 'unclaimedSen' => $sen, 'overdueUnclaimedInvoices' => 0, 'overdueUnclaimedSen' => 0, 'acceptedUnpaidClaims' => 0, 'acceptedOutstandingSen' => 0];
        $answer = $this->build('panel.unclaimed', [['branches' => [
            ['branch' => 'CHERAS', 'panels' => [$panel('Alpha Care', 2, 9000), $panel('Beta Health', 1, 12000)]],
            ['branch' => 'PUCHONG', 'panels' => [$panel('Alpha Care', 1, 4500)]],
        ]]]);

        $this->assertSame([['Alpha Care', 3, 13500], ['Beta Health', 1, 12000]], $this->values($answer));
        $this->assertSame('4 approved Panel invoices worth RM 255.00 are not yet claimed, across 2 Panels.', $answer['sentence']);
        $none = $this->build('panel.overdue', [['branches' => [['branch' => 'CHERAS', 'panels' => [$panel('Alpha Care', 2, 9000)]]]]]);
        $this->assertSame([[], 'Nothing to show: every Panel is up to date.'], [$none['rows'], $none['sentence']]);
    }

    public function test_stock_answers_list_only_affected_items_with_their_unit(): void
    {
        $sku = fn (string $code, int $low, int $expiring, string $quantity): array => ['sku' => 'x', 'skuCode' => $code, 'item' => 'Item '.$code, 'stockUnit' => 'tablet', 'lowStockLocations' => $low, 'expiringBatches' => $expiring, 'expiringQuantity' => $quantity, 'expiredBatches' => 0, 'expiredQuantity' => '0.000'];
        $results = [['branch' => 'CHERAS', 'skus' => [$sku('A', 1, 0, '0.000'), $sku('B', 0, 2, '30.500')]], ['branch' => 'PUCHONG', 'skus' => [$sku('A', 2, 0, '0.000')]]];

        $low = $this->build('stock.low', $results);
        $this->assertSame([['CHERAS', 'Item A', 'A', 1], ['PUCHONG', 'Item A', 'A', 2]], $this->values($low));
        $this->assertSame('2 items are at or below their reorder level at CHERAS, PUCHONG.', $low['sentence']);
        $expiring = $this->build('stock.expiring', $results);
        $this->assertSame([['CHERAS', 'Item B', 'B', 2, '30.500 tablet']], $this->values($expiring));
        $this->assertSame('Nothing to show: no item is expired and still on hand.', $this->build('stock.expired', $results)['sentence']);
    }
}

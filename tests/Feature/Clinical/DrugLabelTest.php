<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryLabelPrint;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Feature\Billing\OtcBillingTest;
use Tests\Support\OtcDispensaryFixtures;

class DrugLabelTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private function ready(): array
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);

        return [$f, $case->refresh(), $item];
    }

    private function evidence(): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (preg_match('/^(invoices|invoice_|payments|payment_|panel_|receivables|stock_movements|inventory_stock_balances|dispensary_|treatment_plan|consultation_checkouts|visits|clinical_encounters|audit_logs)/', $table->tablename)) {
                $out[$table->tablename] = DB::table($table->tablename)->orderBy('id')->get()->toJson();
            }
        }

        return $out;
    }

    public function test_exact_grants_and_permission_only_authorisation_with_fresh_revocation(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(DrugLabelService::PERMISSION, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['ca', 'ca_supervisor'], $holders);
        [$f, $case] = $this->ready();
        $url = route('dispensary.labels', $case);
        foreach (['resident_doctor', 'director', 'administrator', 'technical_admin', 'finance_officer', 'panel_officer', 'hr_manager', 'marketing', 'business_development', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role), $f['visit']->branch);
            $this->get($url)->assertForbidden();
        }
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $f['ca']->roles()->sole()->revokePermissionTo(DrugLabelService::PERMISSION);
        $this->get($url)->assertForbidden();
    }

    public function test_saved_otc_text_stale_token_and_removed_lines_without_side_effects(): void
    {
        [$f, $case, $item] = $this->ready();
        $service = app(DrugLabelService::class);
        $before = $this->evidence();
        $preview = $service->preview($f['ca'], $case);
        $this->assertCount(1, $preview['items']);
        $this->assertSame([], $preview['blocked']);
        $this->assertSame($before, $this->evidence());
        $url = route('dispensary.labels', $case);
        // DSP-6b (owner-approved 2026-11-06): the fresh check is a POST to the print address; every expected result is unchanged.
        $query = ['token' => $preview['token'], 'branch' => $case->branch_id];
        $this->postJson(route('dispensary.labels.print', $case), $query)->assertOk()->assertJson(['current' => true]);
        $item = app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item, [...$this->addPayload($f, $case), 'item_lock_version' => $item->lock_version, 'dosage' => 'Two tablets']);
        $this->postJson(route('dispensary.labels.print', $case), $query)->assertStatus(409);
        $new = $service->preview($f['ca'], $case);
        $this->assertSame('Two tablets', $new['items'][0]['dosage']);
        $this->assertNotSame($preview['token'], $new['token']);
        app(DispensaryService::class)->removeItem($f['ca'], $case->refresh(), $item, ['expected_branch_id' => $case->branch_id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version]);
        $this->get($url)->assertNotFound();
    }

    public function test_over_budget_fields_are_named_with_length_and_limit_and_never_rendered(): void
    {
        [$f, $case, $item] = $this->ready();
        app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item, [...$this->addPayload($f, $case), 'item_lock_version' => $item->lock_version, 'administration_instruction' => str_repeat('x', 385)]);
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);
        $this->assertSame([], $labels['items']);
        // DSP-10 (approved test change): the landscape label's instruction limit is 144 (was 384).
        $this->assertContains('Instruction: 385 characters; label limit 144.', $labels['blocked'][0]['errors']);
        $this->assertSame(str_repeat('x', 385), $item->refresh()->effective('administration_instruction'));
        $this->postJson(route('dispensary.labels.print', $case), ['token' => $labels['token'], 'branch' => $case->branch_id])->assertStatus(409);
    }

    public function test_every_budget_boundary_and_newline_wide_character_total_line_policy_are_server_side(): void
    {
        // DSP-10 (owner decisions 2026-11-06, approved test change): the 100 × 62 mm landscape label, 48 cells × 13 rows,
        // with per-field character limits and per-block row limits; render() takes the expiry and the controlled flag.
        $layout = new DrugLabelLayout;
        $titles = ['clinic' => 'Clinic name', 'name' => 'Medicine name', 'phone' => 'Phone'];
        foreach (DrugLabelLayout::FIELD_BUDGETS as $field => $limit) {
            foreach ([$limit, $limit + 1] as $length) {
                $result = $layout->render(['dosage' => '1', 'frequency' => '1', $field => str_repeat('W', $length)], '01/01/2027', null, false);
                $message = ($titles[$field] ?? ucfirst($field)).": {$length} characters; label limit {$limit}.";
                if ($length > $limit) {
                    $this->assertContains($message, $result['errors']);
                } else {
                    $this->assertNotContains($message, $result['errors']);
                }
            }
        }
        $this->assertSame([], $layout->render(['dosage' => '', 'frequency' => ''], '', null, false)['lines']);
        $base = ['clinic' => 'Synthetic Clinic', 'patient' => 'Synthetic Patient', 'name' => 'Synthetic medicine', 'quantity' => '1', 'dosage' => '1', 'frequency' => '1'];
        // A saved newline never adds a printed row: directions are one wrapped block.
        $newlines = $layout->render([...$base, 'instruction' => "Line one\n\n\nline two"], '01/01/2027', null, false);
        $this->assertContains('1, 1, LINE ONE LINE TWO', $newlines['lines']);
        // Directions over three rows, or a label over 13 rows, are blocked and never cut.
        $long = $layout->render([...$base, 'instruction' => str_repeat('word ', 28)], '01/01/2027', null, false);
        $this->assertSame([], $long['lines']);
        $this->assertContains('Directions: 4 rows (145 characters); label limit 3 rows.', $long['errors']);
        $full = $layout->render([...$base, 'address' => str_repeat('a ', 40), 'phone' => '03-0000 0000', 'patient' => str_repeat('p ', 30), 'name' => str_repeat('n ', 25), 'instruction' => str_repeat('i ', 50), 'precaution' => str_repeat('c ', 25), 'duration' => '3 days', 'indication' => str_repeat('d ', 25)], '01/01/2027', null, false);
        $this->assertSame(['Label rows: 17 rows; label limit 13.'], $full['errors'], 'every block is within its own limit; only the total is over');
        $wide = $layout->render([...$base, 'dosage' => str_repeat('界', 48)], '01/01/2027', null, false);
        $this->assertSame([], $wide['errors']);
        $this->assertNotSame([], $wide['lines']);
        foreach ($wide['lines'] as $line) {
            $this->assertLessThanOrEqual(DrugLabelLayout::COLUMNS, mb_strwidth($line));
        }
        $this->assertSame(['width' => 100, 'height' => 62], DrugLabelLayout::DRUG_LABEL_PAGE_SIZE);
    }

    public function test_a_word_ending_at_the_row_edge_stays_on_its_row_and_no_character_is_lost(): void
    {
        // DSP-10 review: directions of exactly 3 full rows (144 cells) fit; one more word blocks.
        $layout = new DrugLabelLayout;
        $base = ['clinic' => 'Synthetic Clinic', 'patient' => 'Synthetic Patient', 'name' => 'Synthetic medicine', 'quantity' => '1'];
        $row = str_repeat('abc ', 11).'abcd'; // exactly 48 cells
        $first = str_repeat('abc ', 10).'ab'; // with "1, 1, " in front, exactly 48 cells
        $fit = $layout->render([...$base, 'dosage' => '1', 'frequency' => '1', 'instruction' => "{$first} {$row} {$row}"], '01/01/2027', null, false);
        $this->assertSame([], $fit['errors']);
        $this->assertSame(['1, 1, '.mb_strtoupper($first), mb_strtoupper($row), mb_strtoupper($row)], array_slice($fit['lines'], 3, 3), 'each full row keeps its last word');
        $over = $layout->render([...$base, 'dosage' => '1', 'frequency' => '1', 'instruction' => "{$first} {$row} {$row} x"], '01/01/2027', null, false);
        $this->assertSame(['Directions: 4 rows (148 characters); label limit 3 rows.'], $over['errors']);

        // Joining the printed directions rows with single spaces gives back the cleaned text exactly.
        $text = 'Take one tablet after food with a full glass of water, then rest; avoid alcohol and driving while drowsy.';
        $render = $layout->render([...$base, 'dosage' => '1', 'frequency' => '3 times a day', 'instruction' => "  {$text}\n"], '01/01/2027', null, false);
        $this->assertSame([], $render['errors']);
        $directions = array_slice($render['lines'], 3, 3);
        $this->assertSame(mb_strtoupper('1, 3 times a day, '.$text), implode(' ', array_map('rtrim', $directions)));

        // An empty clinic name, patient name, medicine name or quantity blocks the label instead of printing a blank row.
        foreach (['clinic' => 'Clinic name', 'patient' => 'Patient', 'name' => 'Medicine name', 'quantity' => 'Quantity'] as $field => $title) {
            $empty = $layout->render([...$base, 'dosage' => '1', 'frequency' => '1', $field => '  '], '01/01/2027', null, false);
            $this->assertSame([], $empty['lines']);
            $this->assertContains("{$title}: 0 characters; minimum 1.", $empty['errors']);
        }
    }

    public function test_realistic_long_directions_fit_with_emphasised_identity_and_exact_decimal_display(): void
    {
        // DSP-10 (approved test change): the owner's sample label, laid out on the landscape grid.
        $fields = ['clinic' => 'Synthetic Label Clinic', 'address' => '42 Test Street, Taman Contoh, 50000, Test City, Test State, MY', 'phone' => '03-0000 1234',
            'patient' => 'Synthetic Label Patient', 'name' => 'PAIN -Synthorelax tab 35mg/450mg', 'quantity' => '10',
            'dosage' => '1 tablet', 'frequency' => '2 atau 3 kali sehari', 'instruction' => 'after meal/selepas makan.',
            'precaution' => 'Mengantuk / drowsy', 'duration' => '3 days', 'indication' => 'Muscle relaxant / sakit otot'];
        $render = (new DrugLabelLayout)->render($fields, '20/10/2026', '30/10/2027', false);
        $this->assertSame([], $render['errors']);
        $this->assertSame('SYNTHETIC LABEL CLINIC', $render['lines'][0]);
        $this->assertContains('Tel: (03-0000 1234)', $render['lines']);
        $this->assertSame(array_search('Tel: (03-0000 1234)', $render['lines'], true) + 1, $render['ruleAfter']);
        $bold = array_intersect_key($render['lines'], array_flip($render['emphasis']));
        $this->assertContains('Synthetic Label Patient', $bold);
        $this->assertContains(str_pad('PAIN -Synthorelax tab 35mg/450mg', 37).'    QTY: 10', $bold);
        $this->assertContains('1 TABLET, 2 ATAU 3 KALI SEHARI, AFTER', $render['lines']);
        $this->assertContains('MEAL/SELEPAS MAKAN.', $render['lines']);
        $this->assertContains('Precaution: MENGANTUK / DROWSY', $render['lines']);
        $this->assertContains('Duration:   3 DAYS', $render['lines']);
        $this->assertContains('Indication: MUSCLE RELAXANT / SAKIT OTOT', $render['lines']);
        $this->assertSame('20/10/2026'.str_repeat(' ', 23).'EXP: 30/10/2027', end($render['lines']));
        $this->assertLessThanOrEqual(DrugLabelLayout::MAX_ROWS, count($render['lines']));
        foreach (['2.000' => '2', '0.500' => '0.5', '2.125' => '2.125', '999999999999999.125' => '999999999999999.125'] as $saved => $display) {
            $this->assertSame($display, DrugLabelLayout::displayQuantity($saved));
        }
    }

    public function test_one_blocked_line_does_not_block_an_individual_eligible_label(): void
    {
        [$f, $case, $item] = $this->ready();
        $otherFixture = $this->fixture();
        $other = app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), [...$this->addPayload($otherFixture, $case), 'administration_instruction' => str_repeat('x', 385)]);
        $service = app(DrugLabelService::class);
        $all = $service->preview($f['ca'], $case->refresh());
        $this->assertCount(1, $all['blocked']);
        $this->assertCount(1, $all['items']);
        $this->assertSame(route('dispensary.items.label', [$case, $item->public_id]), $all['items'][0]['previewUrl']);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->get($all['items'][0]['previewUrl'])->assertOk();
        $single = $service->preview($f['ca'], $case, $item->public_id);
        $this->assertSame([], $single['blocked']);
        $this->assertCount(1, $single['items']);
        $this->postJson($single['items'][0]['previewUrl'].'/print', ['token' => $single['token'], 'branch' => $case->branch_id])->assertOk();
        $this->assertSame(str_repeat('x', 385), $other->refresh()->effective('administration_instruction'));
    }

    public function test_completed_paid_visit_labels_preserve_all_financial_stock_and_clinical_rows(): void
    {
        [$f, $case] = $this->ready();
        $case = $this->complete($f, $case);
        // Reuse the existing OTC pricing fixture; no production-data bootstrap path.
        (new \ReflectionMethod(OtcBillingTest::class, 'price'))->invoke(new OtcBillingTest('test_an_otc_invoice_has_the_dispensed_medicine_only_and_no_consultation_line'), $f, 250);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($f['ca'], $f['visit']->refresh(), ['expected_branch_id' => $case->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $case->branch_id, 'lock_version' => $invoice->lock_version]);
        (new PaymentMethod)->forceFill(['organisation_id' => $case->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        app(PaymentService::class)->add($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $case->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '500', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        app(CompleteVisitationService::class)->complete($f['ca'], $f['visit']->refresh(), ['expected_branch_id' => $case->branch_id, 'visit_lock_version' => $f['visit']->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $before = $this->evidence();
        $url = route('dispensary.labels', $case);
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);
        $this->assertCount(1, $labels['items']);
        $this->assertStringContainsString('no-store', $this->get($url)->assertOk()->headers->get('Cache-Control'));
        $this->get($url)->assertOk();
        $this->postJson($url.'/print', ['token' => $labels['token'], 'branch' => $case->branch_id])->assertOk();
        // DSP-6 (owner-approved change): a print now adds only its print-log row and one audit row; every other table is identical.
        $after = $this->evidence();
        $this->assertSame(array_diff_key($before, array_flip(['dispensary_label_prints', 'audit_logs'])), array_diff_key($after, array_flip(['dispensary_label_prints', 'audit_logs'])));
        $this->assertSame(1, DispensaryLabelPrint::query()->count());
        $this->assertSame(['dispensary.labels_printed'], array_column(array_slice(json_decode($after['audit_logs'], true), count(json_decode($before['audit_logs'], true))), 'event'));
        $this->get(route('dispensary.show', $case))->assertInertia(fn (Assert $page) => $page->where('dispensary.labelUrl', $url)->missing('dispensary.items'));
    }

    public function test_branch_scope_parent_item_scope_and_missing_otc_allergy_are_refused(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $url = route('dispensary.labels', $case);
        $this->get($url)->assertStatus(409);
        $this->confirmAllergy($f, $case);
        $this->get(route('dispensary.items.label', [$case, (string) Str::uuid()]))->assertNotFound();
        $other = $this->fixture();
        $otherCase = $this->open($other);
        $this->confirmAllergy($other, $otherCase);
        $this->get(route('dispensary.items.label', [$otherCase, $item->public_id]))->assertNotFound();
        $branch = Branch::query()->where('organisation_id', $f['visit']->organisation_id)->where('id', '!=', $case->branch_id)->firstOrFail();
        $this->selectBranch($this->actor('ca', $branch), $branch);
        $this->get($url)->assertNotFound();
    }
}

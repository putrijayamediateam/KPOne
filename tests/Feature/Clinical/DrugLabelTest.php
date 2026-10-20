<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
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
        $query = ['verify' => 1, 'token' => $preview['token'], 'branch' => $case->branch_id];
        $this->getJson($url.'?'.http_build_query($query))->assertOk()->assertJson(['current' => true]);
        $item = app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item, [...$this->addPayload($f, $case), 'item_lock_version' => $item->lock_version, 'dosage' => 'Two tablets']);
        $this->getJson($url.'?'.http_build_query($query))->assertStatus(409);
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
        $this->assertContains('Instruction: 385 characters; label limit 384.', $labels['blocked'][0]['errors']);
        $this->assertSame(str_repeat('x', 385), $item->refresh()->effective('administration_instruction'));
        $this->getJson(route('dispensary.labels', $case).'?'.http_build_query(['verify' => 1, 'token' => $labels['token'], 'branch' => $case->branch_id]))->assertStatus(409);
    }

    public function test_every_budget_boundary_and_newline_wide_character_total_line_policy_are_server_side(): void
    {
        $layout = new DrugLabelLayout;
        foreach (DrugLabelLayout::FIELD_BUDGETS as $field => $limit) {
            foreach ([$limit, $limit + 1] as $length) {
                $result = $layout->render(['dosage' => '1', 'frequency' => '1', $field => str_repeat('W', $length)], '01/01/2027', false, false);
                $message = ucfirst($field).": {$length} characters; label limit {$limit}.";
                if ($length > $limit) {
                    $this->assertContains($message, $result['errors']);
                } else {
                    $this->assertNotContains($message, $result['errors']);
                }
            }
        }
        $this->assertSame([], $layout->render(['dosage' => '', 'frequency' => ''], '', false, false)['lines']);
        $this->assertSame([], $layout->render(['dosage' => '1', 'frequency' => '1', 'instruction' => str_repeat("\n", 40)], '', false, false)['lines']);
        $wide = $layout->render(['dosage' => str_repeat('界', 48), 'frequency' => '1'], '01/01/2027', false, false);
        foreach ($wide['lines'] as $line) {
            $this->assertLessThanOrEqual(DrugLabelLayout::COLUMNS, mb_strwidth($line));
        }
        $this->assertSame(['width' => 100, 'height' => 150], DrugLabelLayout::DRUG_LABEL_PAGE_SIZE);
    }

    public function test_realistic_long_directions_fit_with_emphasised_identity_and_exact_decimal_display(): void
    {
        $instruction = 'Arahan contoh untuk ujian susun atur sahaja. Sila baca keseluruhan arahan yang diberikan pada label ini. Jika ada bahagian yang tidak jelas, minta penjelasan daripada petugas sebelum menggunakan ubat. Jangan gunakan arahan daripada label lama selepas arahan dikemas kini.';
        $precaution = 'Peringatan contoh untuk ujian cetakan sahaja. Simpan label bersama bekas asal dan rujuk petugas jika label rosak atau tidak dapat dibaca.';
        $fields = ['clinic' => 'Synthetic Label Clinic', 'address' => '42 Test Street, 50000, Test City, Test State, MY', 'contact' => 'clinic@label.test', 'patient' => 'Synthetic Label Patient', 'reference' => 'KP-000001 / V-000001', 'name' => 'Synthetic medicine for layout test', 'strength' => '500 mg', 'form' => 'Tablet', 'quantity' => '2 tablet', 'dosage' => 'Dos contoh', 'frequency' => 'Kekerapan contoh', 'duration' => 'Tempoh contoh', 'route' => 'Laluan contoh', 'instruction' => $instruction, 'precaution' => $precaution];
        $render = (new DrugLabelLayout)->render($fields, '20/10/2026', false, true, '20/10/2026 12:00 (Asia/Kuala_Lumpur)');
        $this->assertGreaterThan(96, mb_strlen($instruction));
        $this->assertSame($instruction, implode('', (new \ReflectionMethod(DrugLabelLayout::class, 'wrap'))->invoke(new DrugLabelLayout, $instruction)));
        $this->assertSame([], $render['errors']);
        $this->assertContains('Synthetic Label Patient', array_intersect_key($render['lines'], array_flip($render['emphasis'])));
        $this->assertContains('Synthetic medicine for layout test', array_intersect_key($render['lines'], array_flip($render['emphasis'])));
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
        $this->getJson($single['items'][0]['previewUrl'].'?'.http_build_query(['verify' => 1, 'token' => $single['token'], 'branch' => $case->branch_id]))->assertOk();
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
        $this->getJson($url.'?'.http_build_query(['verify' => 1, 'token' => $labels['token'], 'branch' => $case->branch_id]))->assertOk();
        $this->assertSame($before, $this->evidence());
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

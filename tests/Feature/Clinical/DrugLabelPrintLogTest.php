<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryLabelPrint;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-6 (owner decisions 2026-11-05): each press of "Print current labels" that passes the fresh check logs who
 * printed which line's label and when (append-only, no label content); a line printed before is marked REPRINT.
 * Synthetic data only.
 */
class DrugLabelPrintLogTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array{0: array<string, mixed>, 1: DispensaryCase} */
    private function ready(): array
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '1.000'));
        $this->confirmAllergy($f, $case->refresh());
        $this->actingAs($f['ca']);

        return [$f, $case->refresh()];
    }

    /**
     * DSP-6b (owner-approved 2026-11-06): the fresh check that logs a print is a POST to the label page's address
     * plus /print, with the token and branch in the body; every expected result is unchanged.
     *
     * @param  array<string, mixed>  $labels
     * @return TestResponse<JsonResponse>
     */
    private function printLabels(string $url, array $labels, ?string $token = null, ?int $branch = null): TestResponse
    {
        return $this->postJson($url.'/print', ['token' => $token ?? $labels['token'], 'branch' => $branch ?? $labels['branchId']]);
    }

    public function test_each_print_is_logged_per_line_and_a_second_print_is_a_reprint(): void
    {
        [$f, $case] = $this->ready();
        $url = route('dispensary.labels', $case);
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('labelReprints', []));

        $this->printLabels($url, $labels)->assertOk()->assertExactJson(['current' => true, 'reprints' => []]);
        $row = DispensaryLabelPrint::query()->sole();
        $this->assertSame([1, $f['ca']->id, $case->id, $case->branch_id, $case->handoffs()->sole()->items()->sole()->id], [$row->print_number, $row->printed_by_user_id, $row->dispensary_case_id, $row->branch_id, $row->dispensary_item_id]);
        $audit = AuditLog::query()->where('event', 'dispensary.labels_printed')->sole();
        $this->assertSame(['label_count' => 1, 'reprint_count' => 0], $audit->metadata);

        $previewUrls = array_column($labels['items'], 'previewUrl');
        $this->printLabels($url, $labels)->assertOk()->assertExactJson(['current' => true, 'reprints' => $previewUrls]);
        $this->assertSame([1, 2], DispensaryLabelPrint::query()->orderBy('id')->pluck('print_number')->all());
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('labelReprints', $previewUrls));
        $this->assertSame(['label_count' => 1, 'reprint_count' => 1], AuditLog::query()->where('event', 'dispensary.labels_printed')->latest('id')->first()->metadata);
    }

    public function test_a_print_from_the_single_label_page_counts_for_the_all_labels_page(): void
    {
        [$f, $case] = $this->ready();
        $item = $case->handoffs()->sole()->items()->sole();
        $single = app(DrugLabelService::class)->preview($f['ca'], $case, $item->public_id);
        $singleUrl = $single['items'][0]['previewUrl'];

        $this->printLabels($singleUrl, $single)->assertOk()->assertJson(['reprints' => []]);
        $this->assertSame([$item->id], DispensaryLabelPrint::query()->pluck('dispensary_item_id')->all());
        $this->get(route('dispensary.labels', $case))->assertInertia(fn (Assert $page) => $page->where('labelReprints', [$singleUrl]));
        $this->get($singleUrl)->assertInertia(fn (Assert $page) => $page->where('labelReprints', [$singleUrl]));
    }

    public function test_the_old_get_check_and_a_get_on_the_print_address_log_nothing(): void
    {
        // DSP-6b: the check that logs a print is a POST only. The label page ignores the old ?verify=1 query and
        // renders the preview, and a GET on the print address is not allowed.
        [$f, $case] = $this->ready();
        $url = route('dispensary.labels', $case);
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);

        $this->get($url.'?'.http_build_query(['verify' => 1, 'token' => $labels['token'], 'branch' => $labels['branchId']]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Labels'));
        $this->getJson($url.'/print')->assertStatus(405);

        $this->assertSame(0, DispensaryLabelPrint::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.labels_printed')->count());
    }

    public function test_the_print_check_needs_a_well_formed_token_and_branch(): void
    {
        [$f, $case] = $this->ready();
        $url = route('dispensary.labels.print', $case);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['token', 'branch']);
        $this->postJson($url, ['token' => 'not-a-token', 'branch' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['token', 'branch']);
        $this->assertSame(0, DispensaryLabelPrint::query()->count());
    }

    public function test_stale_blocked_or_unauthorised_prints_log_nothing(): void
    {
        [$f, $case] = $this->ready();
        $url = route('dispensary.labels', $case);
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);

        $this->printLabels($url, $labels, token: str_repeat('a', 64))->assertStatus(409);
        $this->printLabels($url, $labels, branch: $labels['branchId'] + 1)->assertStatus(409);
        $item = $case->handoffs()->sole()->items()->sole();
        app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item, [...$this->addPayload($f, $case, '1.000'), 'item_lock_version' => $item->lock_version, 'administration_instruction' => str_repeat('x', 385)]);
        $blocked = app(DrugLabelService::class)->preview($f['ca'], $case->refresh());
        $this->printLabels($url, $blocked)->assertStatus(409);
        $this->actingAs($this->actor('resident_doctor'));
        $this->printLabels($url, $labels)->assertForbidden();

        $this->assertSame(0, DispensaryLabelPrint::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.labels_printed')->count());
    }

    public function test_a_completed_case_reprints_and_another_branch_logs_nothing(): void
    {
        [$f, $case] = $this->ready();
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);
        $this->printLabels(route('dispensary.labels', $case), $labels)->assertOk();
        $case = $this->complete($f, $case);

        $completed = app(DrugLabelService::class)->preview($f['ca'], $case->refresh());
        $this->printLabels(route('dispensary.labels', $case), $completed)->assertOk()->assertJson(['reprints' => array_column($completed['items'], 'previewUrl')]);
        $this->assertSame([1, 2], DispensaryLabelPrint::query()->orderBy('id')->pluck('print_number')->all());

        $other = Branch::query()->where('organisation_id', $case->organisation_id)->whereKeyNot($case->branch_id)->firstOrFail();
        $this->selectBranch($this->actor('ca', $other), $other);
        $this->printLabels(route('dispensary.labels', $case), $completed)->assertNotFound();
        $this->assertSame(2, DispensaryLabelPrint::query()->count());
    }

    public function test_the_log_is_append_only_in_the_model_and_the_database(): void
    {
        [$f, $case] = $this->ready();
        $labels = app(DrugLabelService::class)->preview($f['ca'], $case);
        $this->printLabels(route('dispensary.labels', $case), $labels)->assertOk();
        $row = DispensaryLabelPrint::query()->sole();

        foreach ([fn () => $row->forceFill(['print_number' => 5])->save(), fn () => $row->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('The model must refuse changes.');
            } catch (LogicException) {
            }
        }
        foreach (['UPDATE dispensary_label_prints SET print_number = 9', 'DELETE FROM dispensary_label_prints', 'TRUNCATE dispensary_label_prints'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL must refuse: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        try {
            DB::transaction(fn () => DB::table('dispensary_label_prints')->insert(['public_id' => (string) str()->uuid(), 'organisation_id' => $row->organisation_id, 'branch_id' => $row->branch_id,
                'dispensary_case_id' => $row->dispensary_case_id, 'dispensary_item_id' => $row->dispensary_item_id, 'print_number' => 3, 'printed_by_user_id' => $row->printed_by_user_id, 'printed_at' => now()->utc()]));
            $this->fail('A skipped print number must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invalid dispensary label print source', $e->getMessage());
        }
        $otherBranch = Branch::query()->whereKeyNot($row->branch_id)->firstOrFail();
        try {
            DB::transaction(fn () => DB::table('dispensary_label_prints')->insert(['public_id' => (string) str()->uuid(), 'organisation_id' => $row->organisation_id, 'branch_id' => $otherBranch->id,
                'dispensary_case_id' => $row->dispensary_case_id, 'dispensary_item_id' => $row->dispensary_item_id, 'print_number' => 2, 'printed_by_user_id' => $row->printed_by_user_id, 'printed_at' => now()->utc()]));
            $this->fail('A row for another branch than its case must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invalid dispensary label print source', $e->getMessage());
        }
        $this->assertSame(1, DispensaryLabelPrint::query()->count());
    }

    public function test_the_first_label_row_keeps_the_reprint_cells_free(): void
    {
        // DSP-10 (approved test change): the REPRINT mark moved to the reserved cells at the top right of the first row,
        // so the clinic name (the first row) must stay clear of them; before, the mark sat at the end of a short last row.
        $layout = new DrugLabelLayout;
        $fields = ['clinic' => str_repeat('C', DrugLabelLayout::COLUMNS - DrugLabelLayout::REPRINT_CELLS), 'patient' => 'Synthetic Patient', 'name' => 'Synthetic medicine', 'quantity' => '1', 'dosage' => '1', 'frequency' => '1'];
        $render = $layout->render($fields, '01/01/2027', null, false);
        $this->assertSame([], $render['errors']);
        $this->assertLessThanOrEqual(DrugLabelLayout::COLUMNS - DrugLabelLayout::REPRINT_CELLS, mb_strwidth($render['lines'][0]));
        $this->assertContains('Clinic name: 41 characters; label limit 40.', $layout->render([...$fields, 'clinic' => str_repeat('C', 41)], '01/01/2027', null, false)['errors']);
    }
}

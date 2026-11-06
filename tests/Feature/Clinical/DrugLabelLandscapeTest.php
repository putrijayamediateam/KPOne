<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-10 (owner decisions 2026-11-06): the clinic's landscape drug label. The service fills it with the phone only (PrintedPhone format),
 * the patient name only, the medicine's catalogue indication and no route, batch number, logo or patient number.
 * Synthetic data only.
 */
class DrugLabelLandscapeTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_the_label_carries_the_owner_sample_fields_and_nothing_that_identifies_the_patient_further(): void
    {
        $f = $this->fixture();
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        app(BusinessDetailsService::class)->save($director, ['expected_branch_id' => $f['visit']->branch_id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Label Clinic', 'address_line_1' => '42 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'country' => 'MY',
            'phone' => '+60 3-2123 4567', 'email' => 'clinic@label.test']);
        $f['medicine']->forceFill(['default_indication' => 'Synthetic relief / lega'])->save();
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, [...$this->addPayload($f, $case, '10.000'), 'route' => 'Synthetic route', 'precaution' => 'Synthetic caution']);
        $this->confirmAllergy($f, $case);

        $labels = app(DrugLabelService::class)->preview($f['ca'], $case->refresh());
        $item = $labels['items'][0];
        $text = implode("\n", $item['lines']);

        $this->assertSame([], $labels['blocked']);
        $this->assertSame(['width' => 100, 'height' => 62], $labels['pageSize']);
        $this->assertSame('SYNTHETIC LABEL CLINIC', $item['lines'][0]);
        $phone = BranchBusinessProfile::query()->where('branch_id', $f['visit']->branch_id)->value('phone');
        $this->assertSame('+60321234567', $phone, 'Business details stores the phone unchanged');
        $this->assertContains('Tel: 03-2123 4567', $item['lines'], 'the print shows the reading format (owner decision 2026-11-06)');
        $this->assertStringNotContainsString('clinic@label.test', $text, 'the email is not printed');
        $this->assertStringContainsString($f['visit']->patient->full_name, $text);
        $this->assertStringNotContainsString($f['visit']->patient->patient_number, $text, 'no patient number');
        $this->assertStringNotContainsString($f['visit']->visit_number, $text, 'no visit number');
        $this->assertStringNotContainsString('SYNTHETIC ROUTE', mb_strtoupper($text), 'route is not printed');
        $this->assertStringNotContainsString($f['batch']->batch_number, $text, 'no batch number');
        $this->assertMatchesRegularExpression('/^Synthetic OTC medicine\s+QTY: 10$/', $item['lines'][array_search(true, array_map(fn (string $l): bool => str_starts_with($l, 'Synthetic OTC medicine'), $item['lines']), true)]);
        $this->assertContains('Precaution: SYNTHETIC CAUTION', $item['lines']);
        $this->assertContains('Indication: SYNTHETIC RELIEF / LEGA', $item['lines']);
        $this->assertStringContainsString('EXP: ', (string) end($item['lines']));
        $this->assertLessThanOrEqual(DrugLabelLayout::MAX_ROWS, count($item['lines']));
        $this->assertNull($item['controlled']);
        $this->assertSame(array_search('Tel: 03-2123 4567', $item['lines'], true) + 1, $item['ruleAfter']);
        $this->assertArrayNotHasKey('logo', $labels);

        // The saved directions keep their own case; capitals are print only.
        $this->assertSame('Synthetic caution', $case->handoffs()->sole()->items()->sole()->effective('precaution'));
    }

    public function test_a_changed_indication_retires_an_open_preview_and_an_over_long_one_blocks_the_label(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '1.000'));
        $this->confirmAllergy($f, $case);
        $service = app(DrugLabelService::class);
        $before = $service->preview($f['ca'], $case->refresh());

        $f['medicine']->forceFill(['default_indication' => 'Synthetic indication'])->save();
        $after = $service->preview($f['ca'], $case->refresh());
        $this->assertNotSame($before['token'], $after['token'], 'the indication is part of the label, so a change retires the preview');

        $f['medicine']->forceFill(['default_indication' => str_repeat('x', 73)])->save();
        $blocked = $service->preview($f['ca'], $case->refresh());
        $this->assertSame([], $blocked['items']);
        $this->assertContains('Indication: 73 characters; label limit 72.', $blocked['blocked'][0]['errors']);
        $this->assertStringContainsString('Indication: 73 characters', implode(' ', $blocked['blocked'][0]['errors']));
    }

    public function test_the_preview_page_shows_the_provisional_landscape_size(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '1.000'));
        $this->confirmAllergy($f, $case);
        $this->actingAs($f['ca']);

        $this->get(route('dispensary.labels', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Labels')
            ->where('labels.pageSize', ['width' => 100, 'height' => 62])
            ->where('labels.items.0.controlled', null)
            ->missing('labels.logo'));
    }
}

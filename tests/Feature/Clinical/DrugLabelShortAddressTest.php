<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-21 (owner decision 2026-11-12, option A): the drug label prints the branch's short "Address on drug labels" when
 * it is set, and the full address otherwise. Invoices, receipts and letters keep the full address. Synthetic data only.
 */
class DrugLabelShortAddressTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    // 109 characters once joined, over the label's 96-character address budget, like the demo Cheras address.
    private const LONG_ADDRESS = ['address_line_1' => 'No. 19 G, Jalan Synthetic Panjang 1, Taman Synthetic Permai Indah', 'postcode' => '56000', 'city' => 'Kuala Lumpur', 'state' => 'Wilayah Persekutuan'];

    private User $director;

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $overrides
     */
    private function saveDetails(array $f, array $overrides = []): void
    {
        $branchId = $f['visit']->branch_id;
        $this->director ??= $this->actor('director');
        $this->selectBranch($this->director, $f['visit']->branch);
        app(BusinessDetailsService::class)->save($this->director, [
            'expected_branch_id' => $branchId,
            'organisation_lock_version' => (int) DB::table('organisation_business_profiles')->where('organisation_id', $f['visit']->organisation_id)->value('lock_version'),
            'branch_lock_version' => (int) DB::table('branch_business_profiles')->where('branch_id', $branchId)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Label Clinic',
            ...self::LONG_ADDRESS, 'country' => 'MY', 'phone' => '+60 3-2123 4567', 'email' => 'clinic@label-address.test',
            ...$overrides,
        ]);
        $this->selectBranch($f['ca'], $f['visit']->branch);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function labels(array $f): array
    {
        $case = $this->open($f);
        if ($case->handoffs()->sole()->items()->count() === 0) {
            app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '1.000'));
            $this->confirmAllergy($f, $case);
        }

        return app(DrugLabelService::class)->preview($f['ca'], $case->refresh());
    }

    public function test_a_long_full_address_without_a_label_address_still_blocks_the_label(): void
    {
        $f = $this->fixture();
        $this->saveDetails($f);

        $labels = $this->labels($f);

        $this->assertSame([], $labels['items']);
        $this->assertCount(1, $labels['blocked']);
        $this->assertStringContainsString('address', mb_strtolower(implode(' ', $labels['blocked'][0]['errors'])));
    }

    public function test_the_label_prints_the_short_label_address_when_it_is_set(): void
    {
        $f = $this->fixture();
        $this->saveDetails($f, ['label_address' => 'No. 19 G, Jalan Synthetic 1, Cheras, 56000 KL']);

        $labels = $this->labels($f);
        $text = implode("\n", $labels['items'][0]['lines']);

        $this->assertSame([], $labels['blocked']);
        $this->assertStringContainsString('No. 19 G, Jalan Synthetic 1, Cheras, 56000 KL', $text);
        $this->assertStringNotContainsString('Taman Synthetic Permai Indah', $text, 'the full address is not printed');
    }

    public function test_without_a_label_address_a_short_full_address_prints_as_before(): void
    {
        $f = $this->fixture();
        $this->saveDetails($f, ['address_line_1' => '42 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State']);

        $text = implode("\n", $this->labels($f)['items'][0]['lines']);

        $this->assertStringContainsString('42 Synthetic Street, 50000, Synthetic City, Synthetic State, MY', str_replace("\n", ' ', $text));
    }

    public function test_setting_or_clearing_the_label_address_retires_an_open_preview(): void
    {
        $f = $this->fixture();
        $this->saveDetails($f, ['label_address' => 'Short synthetic address']);
        $before = $this->labels($f)['token'];

        $this->saveDetails($f, ['label_address' => 'Another short synthetic address']);
        $changed = $this->labels($f);
        $this->assertNotSame($before, $changed['token']);

        $this->saveDetails($f, ['label_address' => '']);
        $cleared = $this->labels($f);
        $this->assertNotSame($changed['token'], $cleared['token']);
        $this->assertSame([], $cleared['items'], 'back to the long full address, so the label is blocked again');
    }
}

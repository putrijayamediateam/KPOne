<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PrintFooterReleaseTest extends BillingTestCase
{
    public function test_invoice_and_receipt_footer_variants_preserve_payload_contract_routes_amounts_and_every_row(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'requires_reference' => false, 'is_active' => true])->save();
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $urls = [route('billing.print', [$visit, $invoice]), route('billing.receipt', [$visit, $invoice, $payment])];
        $routes = $this->routeContract();
        $baseline = [];
        $long = str_repeat('Synthetic footer line wraps without truncation. ', 20);
        $this->assertLessThanOrEqual(1000, strlen($long));
        foreach ([null, "Synthetic branch footer\nA & B", $long, null] as $text) {
            BranchDocumentFooter::query()->updateOrCreate(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id], ['text' => $text, 'lock_version' => 1]);
            $before = $this->allRows();
            foreach ($urls as $index => $url) {
                $response = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
                $this->assertSame('Billing/Print', $response->viewData('page')['component']);
                $props = json_decode(json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($text, $props['letterhead']['footerText']);
                $this->assertSame(4000, $props['billing']['invoice']['state']['total']);
                if ($index === 1) {
                    $this->assertSame(1000, $props['receipt']['amountSen']);
                    $this->assertArrayNotHasKey('billTo', $props);
                } else {
                    $this->assertNull($props['receipt']);
                    $this->assertArrayHasKey('billTo', $props);
                }
                unset($props['letterhead']['footerText']);
                if (! isset($baseline[$index])) {
                    $baseline[$index] = $props;
                }
                $this->assertSame($baseline[$index], $props, 'Only the existing footer value may differ between footer variants.');
            }
            $this->assertSame($before, $this->allRows(), 'Opening either print must leave every database row unchanged.');
            $this->assertSame($routes, $this->routeContract());
        }
    }

    private function routeContract(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): array => [$route->methods(), $route->uri(), $route->getName(), $route->getActionName(), $route->gatherMiddleware()])->all();
    }

    private function allRows(): array
    {
        $snapshot = [];
        foreach (Schema::getTables() as $table) {
            if ($table['schema'] !== 'public') {
                continue;
            }
            $name = $table['name'];
            $query = DB::table($name);
            if (Schema::hasColumn($name, 'id')) {
                $query->orderBy('id');
            }
            $rows = $query->get()->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            if (! Schema::hasColumn($name, 'id')) {
                sort($rows);
            }
            $snapshot[$name] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }
}

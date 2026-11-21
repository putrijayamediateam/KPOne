<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * PNL-26b/c: a Panel's optional limit per visit. It only warns and never blocks anything.
 * The doctor sees one line with a total and the limit and never an item price; billing compares the invoice total.
 */
final class PanelVisitLimit
{
    /** The Panel's limit for this visit in sen, or null for a Self-pay visit or a Panel with no limit. */
    public function limitSen(Visit $visit): ?int
    {
        if ($visit->coverage_type !== 'panel' || $visit->panel_id === null) {
            return null;
        }
        $limit = DB::table('panels')->where('id', $visit->panel_id)->where('organisation_id', $visit->organisation_id)->value('visit_limit_sen');

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Billing: the invoice total (already at Panel prices) against the limit.
     *
     * @return array{limitSen: int, totalSen: int, over: bool}|null
     */
    public function forInvoiceTotal(Visit $visit, int $totalSen): ?array
    {
        $limit = $this->limitSen($visit);

        return $limit === null ? null : ['limitSen' => $limit, 'totalSen' => $totalSen, 'over' => $totalSen > $limit];
    }

    /**
     * The doctor: the Panel total of the consultation and the active orders of the plan, at the Panel's prices.
     * `partial` is true when some item has no Panel price yet, so the total may be too low. Read only, no locks.
     *
     * @return array{limitSen: int, totalSen: int, over: bool, partial: bool}|null
     */
    public function estimateForEncounter(Visit $visit, int $encounterId): ?array
    {
        $limit = $this->limitSen($visit);
        if ($limit === null) {
            return null;
        }
        $plan = TreatmentPlan::query()->where('clinical_encounter_id', $encounterId)->first();
        $lines = [['key' => 'consultation', 'unit' => 'consultation', 'quantity' => '1.000']];
        if ($plan !== null) {
            foreach (TreatmentPlanMedicineOrder::query()->where('treatment_plan_id', $plan->id)->where('status', 'active')->get() as $order) {
                $lines[] = ['key' => 'medicine:'.$order->medicine_catalogue_item_id, 'unit' => (string) $order->unit_snapshot, 'quantity' => (string) $order->quantity_ordered];
            }
            foreach (TreatmentPlanServiceOrder::query()->where('treatment_plan_id', $plan->id)->where('status', 'active')->get() as $order) {
                $lines[] = ['key' => 'service:'.$order->clinical_service_catalogue_item_id, 'unit' => (string) $order->unit_snapshot, 'quantity' => (string) $order->quantity_ordered];
            }
        }

        $charges = ChargeDefinition::query()->where('organisation_id', $visit->organisation_id)->whereIn('source_key', array_column($lines, 'key'))->where('is_active', true)->get()->keyBy('source_key');
        $books = PriceBook::query()->where('organisation_id', $visit->organisation_id)->where('currency', 'MYR')->where('is_active', true)
            ->where('price_tier', 'panel')->whereNull('branch_id')
            ->where(fn ($scope) => $scope->whereNull('panel_id')->orWhere('panel_id', $visit->panel_id))
            ->orderBy('id')->get();
        $entries = PriceEntry::query()->where('organisation_id', $visit->organisation_id)->whereIn('price_book_id', $books->pluck('id'))
            ->whereIn('charge_definition_id', $charges->pluck('id'))->where('effective_at', '<=', now()->utc())->orderBy('id')->get();

        $resolver = new PriceResolutionService;
        $total = 0;
        $partial = false;
        foreach ($lines as $line) {
            $charge = $charges->get($line['key']);
            $price = $resolver->select($books, $entries, $charge, true, $visit->panel_id, (int) $visit->branch_id);
            if ($charge === null || $price === null || $charge->unit !== $line['unit']) {
                $partial = true;

                continue;
            }
            $total += ExactMoney::line(ExactMoney::quantity($line['quantity']), $price->unit_price_sen);
        }

        return ['limitSen' => $limit, 'totalSen' => $total, 'over' => $total > $limit, 'partial' => $partial];
    }
}

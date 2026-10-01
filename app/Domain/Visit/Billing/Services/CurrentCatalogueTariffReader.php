<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Models\User;

final class CurrentCatalogueTariffReader
{
    /**
     * @param  list<int>  $medicineIds
     * @return array<int, array{selfPayAmountRm: ?string, panelDefaultAmountRm: ?string}>
     */
    public function forMedicines(User $actor, array $medicineIds): array
    {
        return $this->forCatalogueItems($actor, 'medicine_catalogue_item_id', $medicineIds);
    }

    /**
     * @param  list<int>  $serviceIds
     * @return array<int, array{selfPayAmountRm: ?string, panelDefaultAmountRm: ?string}>
     */
    public function forClinicalServices(User $actor, array $serviceIds): array
    {
        return $this->forCatalogueItems($actor, 'clinical_service_catalogue_item_id', $serviceIds);
    }

    /**
     * @param  list<int>  $catalogueItemIds
     * @return array<int, array{selfPayAmountRm: ?string, panelDefaultAmountRm: ?string}>
     */
    private function forCatalogueItems(User $actor, string $sourceColumn, array $catalogueItemIds): array
    {
        if ($catalogueItemIds === [] || ! $actor->can(PricingReferenceAdministrationService::PERMISSION)) {
            return [];
        }

        $charges = ChargeDefinition::query()
            ->where('organisation_id', $actor->organisation_id)
            ->whereIn($sourceColumn, $catalogueItemIds)
            ->get(['id', $sourceColumn]);
        if ($charges->isEmpty()) {
            return [];
        }

        $chargeIds = $charges->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $priceBooks = PriceBook::query()
            ->where('organisation_id', $actor->organisation_id)
            ->whereNull('branch_id')
            ->where('is_active', true)
            ->whereIn('scope_key', ['organisation', 'panel:default'])
            ->get(['id', 'scope_key']);
        if ($priceBooks->isEmpty()) {
            return [];
        }

        $bookIds = $priceBooks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $latestVersions = PriceEntry::query()
            ->select('charge_definition_id', 'price_book_id')
            ->selectRaw('MAX(version) as version')
            ->where('organisation_id', $actor->organisation_id)
            ->whereIn('charge_definition_id', $chargeIds)
            ->whereIn('price_book_id', $bookIds)
            ->groupBy('charge_definition_id', 'price_book_id');
        $entries = PriceEntry::query()
            ->joinSub($latestVersions, 'latest_catalogue_prices', function ($join): void {
                $join->on('price_entries.charge_definition_id', '=', 'latest_catalogue_prices.charge_definition_id')
                    ->on('price_entries.price_book_id', '=', 'latest_catalogue_prices.price_book_id')
                    ->on('price_entries.version', '=', 'latest_catalogue_prices.version');
            })
            ->get([
                'price_entries.charge_definition_id',
                'price_entries.price_book_id',
                'price_entries.unit_price_sen',
            ]);
        $scopeByBook = $priceBooks->mapWithKeys(
            fn (PriceBook $book): array => [(int) $book->id => $book->scope_key],
        );
        $selfPayAmounts = [];
        $panelDefaultAmounts = [];
        foreach ($entries as $entry) {
            $scope = $scopeByBook->get((int) $entry->price_book_id);
            $chargeId = (int) $entry->charge_definition_id;
            if ($scope === 'organisation') {
                $selfPayAmounts[$chargeId] = $this->toRm((int) $entry->unit_price_sen);
            } elseif ($scope === 'panel:default') {
                $panelDefaultAmounts[$chargeId] = $this->toRm((int) $entry->unit_price_sen);
            }
        }

        $pricesByCharge = [];
        foreach ($charges as $charge) {
            $chargeId = (int) $charge->id;
            $pricesByCharge[$chargeId] = [
                'selfPayAmountRm' => $selfPayAmounts[$chargeId] ?? null,
                'panelDefaultAmountRm' => $panelDefaultAmounts[$chargeId] ?? null,
            ];
        }

        $pricesByItem = [];
        foreach ($charges as $charge) {
            $itemId = (int) $charge->getAttribute($sourceColumn);
            $pricesByItem[$itemId] = $pricesByCharge[(int) $charge->id];
        }

        return $pricesByItem;
    }

    private function toRm(int $amountSen): string
    {
        return intdiv($amountSen, 100).'.'.str_pad((string) ($amountSen % 100), 2, '0', STR_PAD_LEFT);
    }
}

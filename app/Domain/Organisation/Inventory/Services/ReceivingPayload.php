<?php

namespace App\Domain\Organisation\Inventory\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Canonical, bounded receiving input; no quantity or cost is converted through a float. */
final class ReceivingPayload
{
    public const REASONS = ['damaged', 'wrong_item', 'short_expiry', 'documentation_missing', 'other_approved'];

    /** @param array<string,mixed> $input
     * @return array{delivery_reference:string,lines:list<array<string,mixed>>}
     */
    public function normalise(array $input): array
    {
        // Keep the legacy canonical quantity validation and its error keys intact.
        Validator::make($input, ['lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.line_public_id' => ['required', 'uuid', 'distinct']])->validate();
        $lines = [];
        $lotCount = 0;
        foreach ($input['lines'] as $index => $line) {
            $rich = array_key_exists('lots', $line);
            $rawLots = $rich ? $line['lots'] : [$line];
            Validator::make(['lines' => [$line]], $rich ? ['lines.0.physical_quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'], 'lines.0.lots' => ['required', 'array', 'min:1', 'max:20']] : ['lines.0.quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']])->validate();
            if (! $rich && StockUnitQuantity::scaled($line['quantity'], true) === 0) {
                $this->refuse('Received quantities must be positive.');
            }
            $lots = [];
            $sum = 0;
            foreach ($rawLots as $lot) {
                if (++$lotCount > 100) {
                    $this->refuse('A receipt may contain at most 100 lots. Split the delivery into separately referenced receipts.');
                }
                if (array_key_exists('sku_public_id', $lot) || array_key_exists('inventory_sku_id', $lot) || array_key_exists('item_public_id', $lot)) {
                    $this->refuse('Substitution is not accepted against this order line. Reject the wrong item and use an approved order for the correct product.');
                }
                $data = Validator::make($lot, ['quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'], 'batch_number' => ['required', 'string', 'max:100', 'regex:/^[^\p{C}<>]+$/u'], 'expiry_date' => ['required', 'date_format:Y-m-d'], 'outcome' => ['sometimes', Rule::in(['accepted', 'quarantined', 'rejected'])], 'reason' => ['nullable', Rule::in(self::REASONS)], 'unit_cost_sen' => ['nullable', 'integer', 'min:0', 'max:999999999999'], 'unit' => ['nullable', 'string', 'max:100']])->validate();
                if ($data['expiry_date'] === '9999-12-31') {
                    $this->refuse('Enter the actual expiry date, not a sentinel date.');
                }
                $outcome = $data['outcome'] ?? 'accepted';
                $reason = $data['reason'] ?? null;
                if (($outcome !== 'accepted' && $reason === null) || ($outcome === 'accepted' && $reason !== null)) {
                    $this->refuse('Accepted lots have no exception reason. Quarantined and rejected lots need a controlled reason.');
                }
                if ($reason === 'wrong_item' && $outcome !== 'rejected') {
                    $this->refuse('A wrong item must be rejected. Receive the correct product against an approved order; do not substitute it silently.');
                }
                $quantity = StockUnitQuantity::scaled($data['quantity']);
                $sum += $quantity;
                if ($sum > StockUnitQuantity::MAX) {
                    $this->refuse('The physical quantity exceeds the stock range.');
                }
                $lots[] = ['quantity' => StockUnitQuantity::format($quantity), 'batch_number' => trim($data['batch_number']), 'expiry_date' => $data['expiry_date'], 'outcome' => $outcome, 'reason' => $reason, 'unit_cost_sen' => isset($data['unit_cost_sen']) ? (int) $data['unit_cost_sen'] : null, 'unit' => $data['unit'] ?? null];
            }
            $physical = StockUnitQuantity::scaled($rich ? $line['physical_quantity'] : $line['quantity']);
            if ($sum !== $physical) {
                $this->refuse('Accepted, quarantined and rejected lot quantities must equal the physical quantity entered.');
            }
            Validator::make($line, ['short_delivery' => ['sometimes', 'boolean']])->validate();
            $lines[] = ['line_public_id' => $line['line_public_id'], 'physical_quantity' => StockUnitQuantity::format($physical), 'short_delivery' => (bool) ($line['short_delivery'] ?? false), 'lots' => $lots];
        }
        $reference = Validator::make($input, ['delivery_reference' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}\p{Zl}\p{Zp}<>]+$/u']])->validate()['delivery_reference'];
        $reference = trim($reference);
        if ($reference === '') {
            throw ValidationException::withMessages(['delivery_reference' => 'Enter the supplier delivery note or invoice reference.']);
        }
        usort($lines, fn ($a, $b) => $a['line_public_id'] <=> $b['line_public_id']);

        return ['delivery_reference' => $reference, 'lines' => $lines];
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['lines' => $message]);
    }
}

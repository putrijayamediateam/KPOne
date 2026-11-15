<?php

namespace App\Domain\Agents\Proposals;

/**
 * Wave 1 proposal types (docs/agents/CONTRACT.md section 6): who proposes, which domain permission
 * decides, and the exact payload fields. Payloads hold public ids, codes, dates and quantities only.
 */
final class ProposalTypes
{
    public const QUANTITY = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,3})?$/'];

    private const UUID = ['required', 'uuid'];

    /** @return array<string, array{label: string, agent: string, permission: string, rules: array<string, list<string>>}> */
    public static function all(): array
    {
        $decide = 'inventory.stock_reviews.manage.branch';

        return [
            'inventory.restock_suggestion' => ['label' => 'Restock suggestion', 'agent' => 'inventory_analyst', 'permission' => $decide, 'rules' => [
                'sku_public_id' => self::UUID, 'location_public_id' => self::UUID, 'current_quantity' => self::QUANTITY,
                'reorder_level' => self::QUANTITY, 'suggested_quantity' => self::QUANTITY, 'reason_code' => ['required', 'in:at_or_below_reorder_level'],
                'max_level' => ['nullable', 'string', self::QUANTITY[2]],
            ]],
            'inventory.expiry_warning' => ['label' => 'Expiry warning', 'agent' => 'inventory_analyst', 'permission' => $decide, 'rules' => [
                'sku_public_id' => self::UUID, 'batch_public_id' => self::UUID, 'location_public_id' => self::UUID, 'quantity' => self::QUANTITY,
                'expiry_date' => ['required', 'date_format:Y-m-d'], 'days_left' => ['required', 'integer', 'min:-36500', 'max:90'],
                'suggested_action' => ['required', 'in:use_first,write_off_review'],
            ]],
            'inventory.transfer_suggestion' => ['label' => 'Transfer suggestion', 'agent' => 'inventory_analyst', 'permission' => $decide, 'rules' => [
                'sku_public_id' => self::UUID, 'from_branch_code' => ['required', 'string', 'max:40'], 'to_branch_code' => ['required', 'string', 'max:40', 'different:from_branch_code'],
                'quantity' => self::QUANTITY, 'from_current_quantity' => self::QUANTITY, 'from_reorder_level' => self::QUANTITY,
                'to_current_quantity' => self::QUANTITY, 'to_reorder_level' => self::QUANTITY,
            ]],
            'inventory.slow_moving' => ['label' => 'Slow-moving item', 'agent' => 'inventory_analyst', 'permission' => $decide, 'rules' => [
                'sku_public_id' => self::UUID, 'location_public_id' => self::UUID, 'quantity' => self::QUANTITY,
                'last_dispensed_date' => ['present', 'nullable', 'date_format:Y-m-d'], 'days_without_dispensing' => ['required', 'integer', 'min:0', 'max:36500'],
                'suggested_action' => ['required', 'in:stop_reordering,review'],
            ]],
        ];
    }

    /** @return array{label: string, agent: string, permission: string, rules: array<string, list<string>>} */
    public static function get(string $type): array
    {
        return self::all()[$type] ?? throw new \InvalidArgumentException("Unknown proposal type [{$type}].");
    }
}

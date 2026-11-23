<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * STK-52: the "Pack conversion" block of the SKU edit screen. Daniel's rule (21 Nov): a person who may check a waiting
 * change never receives its number: not on screen and not in these page props. For the same reason, the numbers of
 * refused and withdrawn versions are hidden from anyone who can check (they could be re-proposed and copied); checked
 * versions, which took effect, are shown to everyone.
 */
final class PackConversionDirectory
{
    public function __construct(private PackConversionService $conversions, private InventoryStaffNames $names) {}

    /**
     * @param  list<stdClass>  $skus  rows with id, stock_unit, pack_size and purchase_unit
     * @return array<int, array<string, mixed>> keyed by SKU id
     */
    public function blocks(User $viewer, array $skus, string $timezone): array
    {
        $canPropose = $viewer->can(PackConversionService::PROPOSE);
        $canVerify = $viewer->can(PackConversionService::VERIFY);
        $versions = [];
        $people = [];
        foreach ($skus as $sku) {
            $versions[(int) $sku->id] = $this->conversions->versions((int) $sku->id);
            foreach ($versions[(int) $sku->id] as $v) {
                $people[] = $v->proposed_by_user_id;
                $people[] = $v->recorded_by_user_id;
            }
        }
        $name = $this->names->resolver($viewer, $people);
        $when = fn (mixed $at): ?string => $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($timezone)->format('j M Y');
        $blocks = [];
        foreach ($skus as $sku) {
            $list = $versions[(int) $sku->id];
            $current = $this->conversions->current($list);
            $waiting = $this->conversions->waiting($list);
            $latest = $list === [] ? 0 : (int) end($list)->version;
            $mine = $waiting !== null && (int) $waiting->proposed_by_user_id === $viewer->id;
            // The checker must not see the number they are about to type.
            $hideWaitingNumber = $waiting !== null && $canVerify && ! $mine;
            $blocks[(int) $sku->id] = [
                'baseUnit' => (string) $sku->stock_unit,
                'expectedVersion' => $latest,
                'canPropose' => $canPropose && $waiting === null,
                'current' => $current === null || $current->pack_unit === null ? null : [
                    'packUnit' => (string) $current->pack_unit, 'basePerPack' => (int) $current->base_per_pack, 'version' => (int) $current->version,
                    'checkedBy' => $name($current->recorded_by_user_id), 'checkedOn' => $when($current->recorded_at),
                ],
                'waiting' => $waiting === null ? null : [
                    'publicId' => (string) $waiting->public_id, 'version' => (int) $waiting->version, 'stop' => $waiting->pack_unit === null,
                    'proposedBy' => $name($waiting->proposed_by_user_id), 'proposedOn' => $when($waiting->proposed_at), 'mine' => $mine,
                    'canCheck' => $canVerify && ! $mine, 'canWithdraw' => $mine,
                    ...($hideWaitingNumber ? [] : ['packUnit' => $waiting->pack_unit, 'basePerPack' => $waiting->base_per_pack === null ? null : (int) $waiting->base_per_pack]),
                ],
                'history' => array_values(array_map(function (stdClass $v) use ($name, $when, $canVerify): array {
                    $showNumber = $v->outcome === 'checked' || ! $canVerify;

                    return ['version' => (int) $v->version, 'outcome' => (string) $v->outcome, 'stop' => $v->pack_unit === null,
                        'by' => $name($v->recorded_by_user_id), 'on' => $when($v->recorded_at),
                        ...($showNumber ? ['packUnit' => $v->pack_unit, 'basePerPack' => $v->base_per_pack === null ? null : (int) $v->base_per_pack] : [])];
                }, array_filter($list, fn (stdClass $v): bool => $v->outcome !== null && $v !== $current))),
                // The legacy note appears only against a checked conversion, so the block itself never hands a checker a
                // number to copy (the legacy pack fields elsewhere on the screen are a known limit, STK-52 note).
                ...($current !== null && $current->pack_unit !== null
                    && (strcasecmp((string) $sku->purchase_unit, (string) $current->pack_unit) !== 0 || (int) round((float) $sku->pack_size) !== (int) $current->base_per_pack)
                    ? ['legacyDiffers' => true, 'legacy' => ['packSize' => (string) $sku->pack_size, 'purchaseUnit' => (string) $sku->purchase_unit]]
                    : ['legacyDiffers' => false, 'legacy' => null]),
            ];
        }

        return $blocks;
    }
}

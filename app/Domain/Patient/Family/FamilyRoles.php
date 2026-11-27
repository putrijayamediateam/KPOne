<?php

namespace App\Domain\Patient\Family;

/**
 * FAM-1: a member's place in the family. Generic labels only: no gender, legal guardianship or biological parenthood is
 * inferred, and no role carries authority over another member's record.
 */
final class FamilyRoles
{
    public const LABELS = [
        'parent' => 'Parent',
        'child' => 'Child',
        'grandparent' => 'Grandparent',
        'other_relative' => 'Other relative',
    ];

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => self::LABELS[$value]], array_keys(self::LABELS));
    }
}

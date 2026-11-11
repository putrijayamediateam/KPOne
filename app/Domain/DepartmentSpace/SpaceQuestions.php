<?php

namespace App\Domain\DepartmentSpace;

use App\Models\User;

/**
 * DS-3a: the preset questions of each space (the Ask panel). Not an agent and not an AI service: a fixed list in the
 * shape of QuickQuestions (key, space, label, permission, date mode, builder). A person picks one; nothing typed is
 * ever interpreted. Each answer is built by the page's own service under that page's own permission.
 */
final class SpaceQuestions
{
    /** Date mode: every first-set question answers for now (today, this branch). */
    public const NOW = 'now';

    /**
     * @var array<string, array{space: string, label: string, permission: string, date: string, builder: string, patientLevel: bool}>
     */
    public const REGISTRY = [
        'pharmacy.left_to_dispense' => ['space' => 'pharmacy', 'label' => 'What is left to dispense?', 'permission' => 'dispensary.view.branch', 'date' => self::NOW, 'builder' => 'leftToDispense', 'patientLevel' => true],
        'pharmacy.returns_waiting' => ['space' => 'pharmacy', 'label' => 'Which returns wait for a check?', 'permission' => 'dispensary.view.branch', 'date' => self::NOW, 'builder' => 'returnsWaiting', 'patientLevel' => false],
    ];

    /** @return array<string, array{space: string, label: string, permission: string, date: string, builder: string, patientLevel: bool}> */
    public static function forSpace(string $space): array
    {
        return array_filter(self::REGISTRY, fn (array $q): bool => $q['space'] === $space);
    }

    /** @return array{space: string, label: string, permission: string, date: string, builder: string, patientLevel: bool}|null */
    public static function find(string $key): ?array
    {
        return self::REGISTRY[$key] ?? null;
    }

    public static function holdsPermission(User $actor, string $key): bool
    {
        $question = self::find($key);

        return $question !== null && $actor->can($question['permission']);
    }
}

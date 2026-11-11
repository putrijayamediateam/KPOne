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
        'pharmacy.longest_waiting' => ['space' => 'pharmacy', 'label' => 'Who has waited longest?', 'permission' => 'dispensary.view.branch', 'date' => self::NOW, 'builder' => 'longestWaiting', 'patientLevel' => true],
        'pharmacy.returns_waiting' => ['space' => 'pharmacy', 'label' => 'Which returns wait for a check?', 'permission' => 'dispensary.view.branch', 'date' => self::NOW, 'builder' => 'returnsWaiting', 'patientLevel' => false],
        'pharmacy.stock_attention' => ['space' => 'pharmacy', 'label' => 'What stock needs attention?', 'permission' => 'inventory.view.branch', 'date' => self::NOW, 'builder' => 'stockAttention', 'patientLevel' => false],
        'front_desk.longest_waiting' => ['space' => 'front_desk', 'label' => 'Who is waiting longest?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'frontDeskLongestWaiting', 'patientLevel' => true],
        'front_desk.waiting_counts' => ['space' => 'front_desk', 'label' => 'How many are waiting?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'frontDeskCounts', 'patientLevel' => false],
        'front_desk.qr_pending' => ['space' => 'front_desk', 'label' => 'How many QR check-ins wait for review?', 'permission' => 'public_intakes.review.branch', 'date' => self::NOW, 'builder' => 'frontDeskQrPending', 'patientLevel' => false],
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

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
        'front_desk.waited_over_30' => ['space' => 'front_desk', 'label' => 'Who has waited over 30 minutes?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'frontDeskWaitedOver30', 'patientLevel' => true],
        'front_desk.not_ready' => ['space' => 'front_desk', 'label' => 'Which patients are not ready to call?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'frontDeskNotReady', 'patientLevel' => true],
        'front_desk.awaiting_billing' => ['space' => 'front_desk', 'label' => 'Which visits are awaiting billing?', 'permission' => 'billing.view.branch', 'date' => self::NOW, 'builder' => 'frontDeskAwaitingBilling', 'patientLevel' => true],
        'front_desk.qr_pending' => ['space' => 'front_desk', 'label' => 'QR check-ins to review?', 'permission' => 'public_intakes.review.branch', 'date' => self::NOW, 'builder' => 'frontDeskQrPending', 'patientLevel' => false],
        'billing.over_60' => ['space' => 'billing', 'label' => 'Which pay-later bills are over 60 days?', 'permission' => 'outstanding.view.branch', 'date' => self::NOW, 'builder' => 'billingOver60', 'patientLevel' => true],
        'billing.owes_most' => ['space' => 'billing', 'label' => 'Who owes the most?', 'permission' => 'outstanding.view.branch', 'date' => self::NOW, 'builder' => 'billingOwesMost', 'patientLevel' => true],
        'billing.closings_waiting' => ['space' => 'billing', 'label' => 'Which cash closings wait for approval?', 'permission' => 'payments.cash_closing.approve.branch', 'date' => self::NOW, 'builder' => 'billingClosingsWaiting', 'patientLevel' => false],
        'billing.closings_missing' => ['space' => 'billing', 'label' => 'Which days this month have no cash count?', 'permission' => 'payments.reconcile.branch', 'date' => self::NOW, 'builder' => 'billingClosingsMissing', 'patientLevel' => false],
        'consultation.longest_waiting' => ['space' => 'consultation', 'label' => 'Who has waited longest for me?', 'permission' => 'queue.view.own', 'date' => self::NOW, 'builder' => 'consultationLongest', 'patientLevel' => true],
        'consultation.not_ready' => ['space' => 'consultation', 'label' => 'Which of my patients are not ready to call?', 'permission' => 'queue.view.own', 'date' => self::NOW, 'builder' => 'consultationNotReady', 'patientLevel' => true],
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

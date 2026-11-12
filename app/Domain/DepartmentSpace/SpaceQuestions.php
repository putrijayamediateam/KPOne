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
        'front_desk.waits_week' => ['space' => 'front_desk', 'label' => 'What changed in waits this week?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'explainWaitsWeek', 'patientLevel' => false],
        'front_desk.waits_today' => ['space' => 'front_desk', 'label' => 'Why is today slower or faster than usual?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'explainWaitsToday', 'patientLevel' => false],
        'front_desk.busiest_hours' => ['space' => 'front_desk', 'label' => 'When are our busiest hours, and are we staffed for them?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'explainBusiestHours', 'patientLevel' => false],
        'front_desk.left_waiting' => ['space' => 'front_desk', 'label' => 'How many patients left the queue before being called this week?', 'permission' => 'visits.view.branch', 'date' => self::NOW, 'builder' => 'explainLeftWaiting', 'patientLevel' => false],
        'billing.over_60' => ['space' => 'billing', 'label' => 'Which pay-later bills are over 60 days?', 'permission' => 'outstanding.view.branch', 'date' => self::NOW, 'builder' => 'billingOver60', 'patientLevel' => true],
        'billing.owes_most' => ['space' => 'billing', 'label' => 'Who owes the most?', 'permission' => 'outstanding.view.branch', 'date' => self::NOW, 'builder' => 'billingOwesMost', 'patientLevel' => true],
        'billing.closings_waiting' => ['space' => 'billing', 'label' => 'Which cash closings wait for approval?', 'permission' => 'payments.cash_closing.approve.branch', 'date' => self::NOW, 'builder' => 'billingClosingsWaiting', 'patientLevel' => false],
        'billing.closings_missing' => ['space' => 'billing', 'label' => 'Which days this month have no cash count?', 'permission' => 'payments.reconcile.branch', 'date' => self::NOW, 'builder' => 'billingClosingsMissing', 'patientLevel' => false],
        'consultation.longest_waiting' => ['space' => 'consultation', 'label' => 'Who has waited longest for me?', 'permission' => 'queue.view.own', 'date' => self::NOW, 'builder' => 'consultationLongest', 'patientLevel' => true],
        'consultation.not_ready' => ['space' => 'consultation', 'label' => 'Which of my waiting patients are not ready to call?', 'permission' => 'queue.view.own', 'date' => self::NOW, 'builder' => 'consultationNotReady', 'patientLevel' => true],
        'panel.most_unclaimed' => ['space' => 'panel', 'label' => 'Which Panels have the most unclaimed?', 'permission' => 'panel.claims.manage.branch', 'date' => self::NOW, 'builder' => 'panelMostUnclaimed', 'patientLevel' => false],
        'panel.overdue_amount' => ['space' => 'panel', 'label' => 'How much is overdue per Panel?', 'permission' => 'panel.claims.manage.branch', 'date' => self::NOW, 'builder' => 'panelOverdueAmount', 'patientLevel' => false],
        'stock.held_batches' => ['space' => 'stock', 'label' => 'Which batches are on hold?', 'permission' => 'inventory.view.branch', 'date' => self::NOW, 'builder' => 'stockHeldBatches', 'patientLevel' => false],
        'stock.written_off' => ['space' => 'stock', 'label' => 'What was written off this month?', 'permission' => 'inventory.view.branch', 'date' => self::NOW, 'builder' => 'stockWrittenOff', 'patientLevel' => false],
        'stock.no_order' => ['space' => 'stock', 'label' => 'Which low-stock items have nothing on order?', 'permission' => 'inventory.view.branch', 'date' => self::NOW, 'builder' => 'stockNoOrder', 'patientLevel' => false],
        'hr.away_this_week' => ['space' => 'hr', 'label' => 'Who is away this week?', 'permission' => 'hr.leave.manage.organisation', 'date' => self::NOW, 'builder' => 'hrAwayThisWeek', 'patientLevel' => false],
        'hr.credentials_due' => ['space' => 'hr', 'label' => 'Whose credentials are due for renewal soon?', 'permission' => 'hr.credentials.manage.organisation', 'date' => self::NOW, 'builder' => 'hrCredentialsDue', 'patientLevel' => false],
        'management.documents_attention' => ['space' => 'management', 'label' => 'Which branches need their letters or footer fixed?', 'permission' => 'catalogue.settings.manage.organisation', 'date' => self::NOW, 'builder' => 'managementDocuments', 'patientLevel' => false],
        'management.inactive_staff' => ['space' => 'management', 'label' => 'Which staff accounts are inactive?', 'permission' => 'staff.view.organisation', 'date' => self::NOW, 'builder' => 'managementInactiveStaff', 'patientLevel' => false],
        'me.next_day_off' => ['space' => 'me', 'label' => 'When is my next day off?', 'permission' => 'hr.roster.view.own', 'date' => self::NOW, 'builder' => 'meNextDayOff', 'patientLevel' => false],
        'me.leave_taken' => ['space' => 'me', 'label' => 'How much leave have I taken this year?', 'permission' => 'hr.leave.request.own', 'date' => self::NOW, 'builder' => 'meLeaveTaken', 'patientLevel' => false],
        'me.leave_requests' => ['space' => 'me', 'label' => 'Which leave requests are still open?', 'permission' => 'hr.leave.request.own', 'date' => self::NOW, 'builder' => 'meLeaveRequests', 'patientLevel' => false],
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

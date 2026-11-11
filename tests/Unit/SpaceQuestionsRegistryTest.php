<?php

namespace Tests\Unit;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
use App\Domain\DepartmentSpace\SpaceAskService;
use App\Domain\DepartmentSpace\SpaceQuestions;
use PHPUnit\Framework\TestCase;

/** DS-3a: the registry is pinned, so adding or changing a question shows up as a failing test. */
final class SpaceQuestionsRegistryTest extends TestCase
{
    public function test_the_registry_is_exactly_the_agreed_first_set(): void
    {
        $this->assertSame([
            'pharmacy.longest_waiting' => ['pharmacy', 'dispensary.view.branch', true],
            'pharmacy.returns_waiting' => ['pharmacy', 'dispensary.view.branch', false],
            'pharmacy.stock_attention' => ['pharmacy', 'inventory.view.branch', false],
            'front_desk.waited_over_30' => ['front_desk', 'visits.view.branch', true],
            'front_desk.not_ready' => ['front_desk', 'visits.view.branch', true],
            'front_desk.awaiting_billing' => ['front_desk', 'billing.view.branch', true],
            'front_desk.qr_pending' => ['front_desk', 'public_intakes.review.branch', false],
            'billing.over_60' => ['billing', 'outstanding.view.branch', true],
            'billing.owes_most' => ['billing', 'outstanding.view.branch', true],
            'billing.closings_waiting' => ['billing', 'payments.cash_closing.approve.branch', false],
            'billing.closings_missing' => ['billing', 'payments.reconcile.branch', false],
            'consultation.longest_waiting' => ['consultation', 'queue.view.own', true],
            'consultation.not_ready' => ['consultation', 'queue.view.own', true],
            'panel.most_unclaimed' => ['panel', 'panel.claims.manage.branch', false],
            'panel.overdue_amount' => ['panel', 'panel.claims.manage.branch', false],
        ], array_map(fn (array $q): array => [$q['space'], $q['permission'], $q['patientLevel']], SpaceQuestions::REGISTRY));
    }

    public function test_every_question_belongs_to_a_real_space_names_a_real_permission_and_has_a_builder(): void
    {
        $all = array_unique(array_merge(...array_values(PermissionCatalogue::roles())));
        foreach (SpaceQuestions::REGISTRY as $key => $q) {
            $this->assertArrayHasKey($q['space'], DepartmentSpaceNavigation::SPACES, $key);
            $this->assertContains($q['permission'], $all, $key);
            $this->assertTrue(method_exists(SpaceAskService::class, $q['builder']), $key);
            $this->assertSame(SpaceQuestions::NOW, $q['date'], $key);
        }
    }
}

<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

/**
 * The department spaces a person may enter (DS-1). Built on the server from real permissions and the active branch,
 * like ClinicManagementNavigation: a space appears only when the person holds at least one permission it needs.
 * It grants nothing; each space's own pages and widgets still check their own permission and branch.
 */
final class DepartmentSpaceNavigation
{
    /** Space key => [title, permissions of which at least one is needed]. Order is the sidebar order. */
    public const SPACES = [
        'front_desk' => ['Front desk', ['visits.create.branch']],
        'pharmacy' => ['Pharmacy', ['dispensary.view.branch']],
        'billing' => ['Billing', ['billing.view.branch', 'outstanding.view.branch', 'payments.reconcile.branch', 'finance.work.view.branch']],
        'consultation' => ['Consultation', ['queue.view.own']],
        'panel' => ['Panel', ['panel.work.view.branch', 'panel.claims.manage.branch', 'reports.panel_summary.view.branch']],
        'stock' => ['Stock', ['inventory.stock_reviews.manage.branch', 'inventory.references.manage.organisation']],
        'hr' => ['HR', ['hr.roster.manage.organisation', 'hr.leave.manage.organisation', 'hr.attendance.review.organisation']],
        'management' => ['Management', ['business_details.manage.organisation', 'access.manage.organisation']],
    ];

    public const ME = ['me', 'Me'];

    public function __construct(private BranchAccessService $branches) {}

    /** @return list<array{key: string, title: string}> spaces in order, "Me" always last for a human account. */
    public function spaces(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor) !== null;
        $held = [];
        foreach (self::SPACES as [, $needs]) {
            foreach ($needs as $permission) {
                if ($actor->can($permission)) {
                    $held[] = $permission;
                }
            }
        }

        return self::forPermissions($held, $actor->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE), $branch, $actor->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE));
    }

    /**
     * The pure rule, shared with the matrix test: a space needs one of its permissions and an active branch; the
     * protected director authority always sees all eight (widgets inside still filter by what it really holds).
     *
     * @param  list<string>  $held
     * @param  bool  $tv  the waiting-room TV account sees no space.
     * @return list<array{key: string, title: string}>
     */
    public static function forPermissions(array $held, bool $director, bool $branch, bool $tv = false): array
    {
        if ($tv) {
            return [];
        }

        $out = [];
        foreach (self::SPACES as $key => [$title, $needs]) {
            if ($director || ($branch && array_intersect($needs, $held) !== [])) {
                $out[] = ['key' => $key, 'title' => $title];
            }
        }
        $out[] = ['key' => self::ME[0], 'title' => self::ME[1]];

        return $out;
    }

    public function canEnter(User $actor, string $space): bool
    {
        foreach ($this->spaces($actor) as $row) {
            if ($row['key'] === $space) {
                return true;
            }
        }

        return false;
    }

    /**
     * The existing screen a person should be sent to from an empty space, so nobody is stranded (Daniel, DS-1).
     * First candidate whose permission the person holds; null when none applies.
     *
     * @return array{title: string, href: string}|null
     */
    public function existingHome(User $actor, string $space): ?array
    {
        $title = $space === 'me' ? 'Me' : (self::SPACES[$space][0] ?? $space);
        $candidates = match ($space) {
            'front_desk', 'pharmacy' => [['visits.view.branch', 'registration.index']],
            'billing' => [['finance.work.view.branch', 'billing.work'], ['visits.view.branch', 'registration.index']],
            'consultation' => [['queue.view.own', 'queue.index']],
            'panel' => [['panel.claims.manage.branch', 'panel-claims.index']],
            'stock' => [['inventory.view.branch', 'inventory.index']],
            'hr' => [['hr.roster.manage.organisation', 'hr.dashboard.index'], ['hr.leave.manage.organisation', 'hr.dashboard.index'], ['hr.attendance.review.organisation', 'hr.dashboard.index']],
            'me' => [['hr.roster.view.own', 'hr.roster.index']],
            default => [],
        };
        foreach ($candidates as [$permission, $route]) {
            if ($actor->can($permission)) {
                return ['title' => $title, 'href' => route($route, absolute: false)];
            }
        }
        if (app(ClinicManagementNavigation::class)->hasDestinations($actor)) {
            return ['title' => $title, 'href' => route('clinic-management.index', absolute: false)];
        }

        return null;
    }
}

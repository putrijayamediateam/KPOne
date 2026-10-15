<?php

namespace App\Domain\Access;

use App\Models\User;

/** Read-only destinations; their existing controllers remain the authority for access and scope. */
final class ClinicManagementNavigation
{
    public function __construct(private BranchAccessService $branches, private BillingWorkAccess $billing) {}

    /** @return list<array{title: string, icon: string, links: list<array{title: string, description: string, href: string}>}> */
    public function groups(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor) !== null;
        $settings = $branch && $actor->can('catalogue.settings.manage.organisation');
        $panels = $actor->can('panels.manage.organisation');
        $display = $actor->can('queue_display.manage.organisation') || $actor->can('queue_display.manage.branch');
        $groups = [];
        $add = function (string $title, string $icon, array $rows) use (&$groups): void {
            $links = [];
            foreach ($rows as [$allowed, $label, $description, $route]) {
                if ($allowed) {
                    $links[] = ['title' => $label, 'description' => $description, 'href' => route($route, absolute: false)];
                }
            }
            if ($links !== []) {
                $groups[] = ['title' => $title, 'icon' => $icon, 'links' => $links];
            }
        };
        $add('In-clinic', 'clinic', [
            [$settings, 'Consultation settings', 'Manage preset diagnoses for consultations.', 'diagnosis-presets.index'],
            [$panels, 'Checkout settings', 'Manage Panels, verification details and claim due rules.', 'panel-settings.index'],
            [$actor->can('payment_methods.manage.organisation'), 'Payment Methods', 'Configure the payment methods available at checkout.', 'payment-methods.index'],
        ]);
        $add('Inventory & services', 'inventory', [
            [$actor->can('medicines.manage.organisation'), 'Medicine Catalogue', 'Manage medicines and their catalogue details.', 'medicines.index'],
            [$actor->can('clinical_services.manage.organisation'), 'Clinical Service Catalogue', 'Manage clinical services and consultation tariffs.', 'clinical-services.index'],
            [$actor->can('pricing.references.manage.organisation'), 'Pricing', 'Manage charge definitions and governed price books.', 'pricing.index'],
            [$settings, 'Medicine details', 'Configure shared prescribing options.', 'medicine-details.index'],
            [$settings, 'Suppliers', 'Manage supplier names and contact details.', 'supplier-settings.index'],
            [$branch && $actor->can('inventory.view.branch'), 'Inventory', 'Review stock, batches and movements for this branch.', 'inventory.index'],
        ]);
        $add('Patients', 'patients', [
            [$actor->can('patients.search.organisation'), 'Patient Records', 'Find patients within your existing access.', 'patients.index'],
            [$branch && $this->billing->finance($actor), 'Finance / Billing', 'Review invoices and outstanding balances for this branch.', 'billing.work'],
        ]);
        $add('Queue', 'queue', [
            [$branch && $display, 'Queue Display settings', 'Manage rooms and waiting-room TV content.', 'queue-display.settings'],
            [$branch && ($display || $actor->can('queue.display.branch')), 'TV Screen', 'Open the queue display for this branch.', 'queue-display.screen'],
        ]);
        $add('Reports', 'reports', [
            [$actor->can('insights.view.organisation'), 'Insights', 'Review existing aggregate clinic reports.', 'insights.today'],
        ]);
        $add('Panel', 'panel', [
            [$branch && $actor->can('panel.claims.manage.branch'), 'Panel Claims', 'Manage manual claims and their progress.', 'panel-claims.index'],
            [$branch && $actor->can('panel.invoices.export.branch'), 'Panel invoices', 'Review the existing branch Panel invoice report.', 'panel-invoices.index'],
            [$panels, 'Claims message template', 'Manage the claim message in Checkout settings.', 'panel-settings.index'],
        ]);
        $add('Staff & branches', 'staff', [
            [$actor->can('staff.view.own') || $actor->can('staff.view.branch') || $actor->can('staff.view.organisation'), 'Staff', 'Review staff within your existing access.', 'staff.index'],
            [$actor->can('branches.view.branch') || $actor->can('branches.view.organisation'), 'Branches', 'Review the clinic locations you may access.', 'branches.index'],
            [$actor->can('audit.view.organisation'), 'Audit logs', 'Review authorized administrative and security activity.', 'audit-logs.index'],
        ]);

        return $groups;
    }
}

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
            [$settings, 'Business details', 'Manage organisation identity, branch contact details and the shared logo.', 'business-details.index'],
            [$settings, 'Registration settings', 'Set required details in registration.', 'registration-settings.index'],
            [$actor->can('public_checkin_links.manage.organisation') || $actor->can('public_checkin_links.manage.branch'), 'Public Check-In (QR links)', 'Manage the public check-in QR links for your authorized branches.', 'public-checkin-links.index'],
            [$settings, 'Consultation settings', 'Manage preset diagnoses for consultations.', 'diagnosis-presets.index'],
            [$panels, 'Checkout settings', 'Manage Panels, verification details and claim due rules.', 'panel-settings.index'],
            [$actor->can('payment_methods.manage.organisation'), 'Payment Methods', 'Configure the payment methods available at checkout.', 'payment-methods.index'],
            [$actor->can('billing.approval_limits.manage.organisation'), 'Billing Approval Limits', 'Manage the existing Panel and pay-later approval limits.', 'billing-approval-limits.index'],
            [$branch && $actor->can('visits.view.branch'), 'Registration', 'Open the existing branch registration board.', 'registration.index'],
            [$branch && ($actor->can('queue.view.own') || $actor->can('queue.view.branch')), 'Consultation', 'Open your authorized consultation queue.', 'queue.index'],
            [$branch && $actor->can('public_intakes.review.branch'), 'Pendaftaran QR', 'Semak pendaftaran QR sebelum penerimaan oleh staf.', 'registration-review.index'],
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
            [$branch && $actor->can('payments.reconcile.branch'), 'Terminal Reconciliation', 'Review the branch terminal closing and recorded payments.', 'payment-reconciliations.index'],
        ]);
        $add('Queue', 'queue', [
            [$branch && $display, 'Queue Display settings', 'Manage rooms and waiting-room TV content.', 'queue-display.settings'],
            [$branch && ($display || $actor->can('queue.display.branch')), 'TV Screen', 'Open the queue display for this branch.', 'queue-display.screen'],
        ]);
        $add('Reports', 'reports', [
            [$branch && $actor->can('reports.transactions.view.branch'), 'Transaction Detail', 'Reconcile branch receipt-date transactions with invoice-linked patient identities.', 'reports.transactions.index'],
            [$branch && $actor->can('reports.stock_movement.view.branch'), 'Stock movement summary', 'Review branch quantities and reconciled movements without valuation.', 'reports.stock-movement.index'],
            [$branch && $actor->can('reports.panel_summary.view.branch'), 'Panel summary', 'Review aggregate responsibility, claims and ageing for this branch.', 'reports.panel-summary.index'],
            [$branch && $actor->can('reports.daily_summary.view.branch'), 'Daily summary', 'Review receipt-date cash and non-cash with separately dated invoice responsibility.', 'reports.daily-summary.index'],
            [$branch && $actor->can('reports.patient_billing.view.branch'), 'Billing Detail', 'Reconcile patient invoices and current settlement for this branch.', 'patient-billing-report.index'],
            [$actor->can('insights.view.organisation'), 'Insights', 'Review existing aggregate clinic reports.', 'insights.today'],
        ]);
        $add('Panel', 'panel', [
            [$branch && $this->billing->panel($actor), 'Panel Responsibility', 'Review Panel responsibility and approvals for this branch.', 'clinic.panel-responsibility'],
            [$branch && $actor->can('panel.claims.manage.branch'), 'Panel Claims', 'Manage manual claims and their progress.', 'panel-claims.index'],
            [$branch && $actor->can('panel.invoices.export.branch'), 'Panel invoices', 'Review the existing branch Panel invoice report.', 'panel-invoices.index'],
            [$panels, 'Claims message template', 'Manage the claim message in Checkout settings.', 'panel-settings.index'],
        ]);
        $add('Website', 'website', [
            [$branch && $actor->can('website.content.view.organisation'), 'Doctor profiles', 'Manual private public-profile drafts. Nothing is public yet.', 'website.doctors.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'Careers', 'Private careers content only. No applications or publishing.', 'website.careers.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'Promotions', 'Private manual offer drafts. No coupons or publishing.', 'website.promotions.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'Activities', 'Private activity drafts. Dates trigger nothing.', 'website.activities.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'FAQs', 'Private questions and answers. Nothing is public yet.', 'website.faqs.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'Service categories', 'Private category and subcategory drafts. Nothing is public yet.', 'website.categories.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'Services', 'Manual public-service drafts. No clinical or pricing connection.', 'website.services.index'],
            [$branch && $actor->can('website.content.view.organisation'), 'Branch public profiles', 'Edit private EN and BM website drafts. Nothing is public yet.', 'website.profiles'],
            [$branch && $actor->can('website.assets.manage.organisation'), 'Media library', 'Manage sanitised private website images and rights references.', 'website.media'],
        ]);
        $add('Documents', 'documents', [
            [$settings, 'MC template previews', 'Review fixed English and Bahasa Melayu wording. No issuance.', 'medical-certificate-templates.index'],
        ]);
        $add('Staff & branches', 'staff', [
            [$branch && $actor->can('clinician_credentials.manage.organisation'), 'Clinician credentials', 'Verify professional names and MMC registration numbers.', 'clinician-credentials.index'],
            [$branch && $actor->can('staff.view.own') && $actor->can('encounters.view.own'), 'My clinician credentials', 'Review your own professional details.', 'clinician-credentials.own'],
            [$actor->can('staff.view.own') || $actor->can('staff.view.branch') || $actor->can('staff.view.organisation'), 'Staff', 'Review staff within your existing access.', 'staff.index'],
            [$actor->can('branches.view.branch') || $actor->can('branches.view.organisation'), 'Branches', 'Review the clinic locations you may access.', 'branches.index'],
            [$actor->can('access.view.organisation'), 'Access Control', 'Review existing roles and permissions.', 'access-control.index'],
            [$actor->can('audit.view.organisation'), 'Audit logs', 'Review authorized administrative and security activity.', 'audit-logs.index'],
        ]);

        return $groups;
    }
}

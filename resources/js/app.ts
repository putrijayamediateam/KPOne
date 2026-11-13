import '@/lib/v2-leave-popstate';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import ClinicLayout from '@/layouts/ClinicLayout.vue';
import PublicCheckInLayout from '@/layouts/PublicCheckInLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import V2Layout from '@/layouts/V2Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { installAuthenticationHistoryBoundary } from '@/lib/inertia-auth-history-boundary';
import { readInitialInertiaPage } from '@/lib/inertia-bootstrap';
import { applyUiVersion, isV2Page, uiVersionFor } from '@/lib/ui-version';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const initialPage = readInitialInertiaPage();
const authenticationHistory = installAuthenticationHistoryBoundary(
    router,
    initialPage,
);

if (authenticationHistory.ready) {
    router.on('navigate', (event) => {
        authenticationHistory.boundary?.acceptServerPage(event.detail.page);
        applyUiVersion(uiVersionFor(event.detail.page));
    });

    createInertiaApp({
        page: initialPage,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        layout: (name) => {
            switch (true) {
                case name === 'Welcome':
                case name === 'Dispensary/Labels':
                case name === 'Billing/Print':
                case name === 'HR/Payslip':
                case name === 'HR/Letter':
                case name === 'Inventory/PurchaseOrderPrint':
                case name === 'Inventory/ControlledRegisterPrint':
                case name === 'PanelClaims/Pack':
                case name === 'QueueDisplay/Screen':
                    return null;
                case isV2Page(name):
                    return V2Layout;
                case name.startsWith('PublicCheckIn/'):
                case name.startsWith('Careers/'):
                    return PublicCheckInLayout;
                case name.startsWith('auth/'):
                    return AuthLayout;
                case name.startsWith('settings/'):
                    return [AdminLayout, SettingsLayout];
                case name.startsWith('Registration/'):
                case name.startsWith('Queue/'):
                case name.startsWith('Patient/'):
                case name.startsWith('Clinical/'):
                case name.startsWith('Dispensary/'):
                case name.startsWith('Billing/'):
                case name.startsWith('Clinic/'):
                    return ClinicLayout;
                default:
                    return AdminLayout;
            }
        },
        progress: {
            color: '#4B5563',
        },
    });

    // This will set light / dark mode on page load...
    initializeTheme();

    // This will listen for flash toast data from the server...
    initializeFlashToast();
}

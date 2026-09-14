import type { Page } from '@inertiajs/core';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import ClinicLayout from '@/layouts/ClinicLayout.vue';
import PublicCheckInLayout from '@/layouts/PublicCheckInLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { installAuthenticationHistoryBoundary } from '@/lib/inertia-auth-history-boundary';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const initialPageElement = document.getElementById('app');
const initialPage = JSON.parse(
    initialPageElement?.dataset.page ?? '{}',
) as Page;
const authenticationHistory = installAuthenticationHistoryBoundary(
    router,
    initialPage,
);

if (authenticationHistory.ready) {
    router.on('navigate', (event) => {
        authenticationHistory.boundary?.acceptServerPage(event.detail.page);
    });

    createInertiaApp({
        page: initialPage,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        layout: (name) => {
            switch (true) {
                case name === 'Welcome':
                case name === 'Dispensary/Labels':
                case name === 'Billing/Print':
                    return null;
                case name.startsWith('PublicCheckIn/'):
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

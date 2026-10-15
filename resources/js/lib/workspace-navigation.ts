export type WorkspaceNavigationCapabilities = {
    clinicManagement: boolean;
    registration: boolean;
    registrationReview: boolean;
    consultation: boolean;
    inventory: boolean;
    medicineCatalogue: boolean;
    clinicalServiceCatalogue: boolean;
    paymentMethods: boolean;
    panelSettings: boolean;
    catalogueSettings: boolean;
    consultationSettings: boolean;
    billingApprovalLimits: boolean;
    paymentReconciliations: boolean;
    patientRecords: boolean;
    panelWork: boolean;
    panelClaims: boolean;
    panelInvoices: boolean;
    financeWork: boolean;
    staff: boolean;
    branches: boolean;
    accessControl: boolean;
    auditLogs: boolean;
    publicCheckInLinks: boolean;
    queueDisplay: boolean;
    queueDisplayScreen: boolean;
    insights: boolean;
};

export type WorkspaceDestination = {
    key: keyof WorkspaceNavigationCapabilities;
    label: string;
    description: string;
    href: string;
    icon: 'activity' | 'building' | 'clipboard' | 'file' | 'shield' | 'users';
};

export type WorkspaceDestinationGroup = {
    label: string;
    destinations: WorkspaceDestination[];
};

const destinations: Record<
    keyof WorkspaceNavigationCapabilities,
    WorkspaceDestination
> = {
    clinicManagement: {
        key: 'clinicManagement',
        label: 'Clinic management',
        description: 'Open clinic settings and authorized management screens.',
        href: '/clinic-management',
        icon: 'building',
    },
    registration: {
        key: 'registration',
        label: 'Registration',
        description: 'Register visits and manage the active clinic board.',
        href: '/registration',
        icon: 'clipboard',
    },
    registrationReview: {
        key: 'registrationReview',
        label: 'Pendaftaran QR',
        description: 'Semak pendaftaran QR dan masukkan pesakit ke queue.',
        href: '/registration-review',
        icon: 'clipboard',
    },
    consultation: {
        key: 'consultation',
        label: 'Consultation',
        description: 'Open the doctor queue and consultation workspace.',
        href: '/queue',
        icon: 'activity',
    },
    inventory: {
        key: 'inventory',
        label: 'Inventory',
        description: 'Review branch stock, batches, expiry and movements.',
        href: '/inventory',
        icon: 'clipboard',
    },
    medicineCatalogue: {
        key: 'medicineCatalogue',
        label: 'Medicine Catalogue',
        description: 'Create and manage the organisation Medicine Catalogue.',
        href: '/medicines',
        icon: 'clipboard',
    },
    clinicalServiceCatalogue: {
        key: 'clinicalServiceCatalogue',
        label: 'Clinical Service Catalogue',
        description:
            'Create and manage clinical services available for ordering.',
        href: '/clinical-services',
        icon: 'clipboard',
    },
    paymentMethods: {
        key: 'paymentMethods',
        label: 'Payment Methods',
        description:
            'Configure organisation payment methods available at checkout.',
        href: '/payment-methods',
        icon: 'file',
    },
    panelSettings: {
        key: 'panelSettings',
        label: 'Checkout Settings',
        description:
            'Manage Panels: contact, verification link and claim due rule.',
        href: '/checkout-settings',
        icon: 'file',
    },
    consultationSettings: {
        key: 'consultationSettings',
        label: 'Consultation settings',
        description: 'Manage preset diagnosis names for the organisation.',
        href: '/consultation-settings',
        icon: 'clipboard',
    },
    catalogueSettings: {
        key: 'catalogueSettings',
        label: 'Inventory and services settings',
        description: 'Manage shared catalogue settings and supplier contacts.',
        href: '/inventory-services-settings/suppliers',
        icon: 'clipboard',
    },
    billingApprovalLimits: {
        key: 'billingApprovalLimits',
        label: 'Billing Approval Limits',
        description:
            'Set branch-specific approval limits for Panel and Pay later.',
        href: '/billing-approval-limits',
        icon: 'file',
    },
    paymentReconciliations: {
        key: 'paymentReconciliations',
        label: 'Terminal Reconciliation',
        description:
            'Compare the terminal closing summary with recorded KPOne payments.',
        href: '/payment-reconciliations',
        icon: 'file',
    },
    patientRecords: {
        key: 'patientRecords',
        label: 'Patient Records',
        description: 'Find and review authorized Patient records.',
        href: '/patients',
        icon: 'users',
    },
    panelWork: {
        key: 'panelWork',
        label: 'Panel Responsibility',
        description: 'Review current Panel responsibility requests.',
        href: '/panel-responsibility',
        icon: 'file',
    },
    panelClaims: {
        key: 'panelClaims',
        label: 'Panel Claims',
        description:
            'Prepare claim packs, record Panel responses and track remittances.',
        href: '/panel-claims',
        icon: 'file',
    },
    panelInvoices: {
        key: 'panelInvoices',
        label: 'Panel Invoices',
        description:
            'Filter invoices billed to a Panel and download them as a CSV file.',
        href: '/panel-invoices',
        icon: 'file',
    },
    financeWork: {
        key: 'financeWork',
        label: 'Finance / Billing',
        description: 'Review invoices and outstanding balances.',
        href: '/financial-work',
        icon: 'file',
    },
    staff: {
        key: 'staff',
        label: 'Staff',
        description: 'Review Staff within your authorized scope.',
        href: '/staff',
        icon: 'users',
    },
    branches: {
        key: 'branches',
        label: 'Branches',
        description: 'Review available clinic locations.',
        href: '/branches',
        icon: 'building',
    },
    accessControl: {
        key: 'accessControl',
        label: 'Access Control',
        description: 'Review roles and permissions.',
        href: '/access-control',
        icon: 'shield',
    },
    auditLogs: {
        key: 'auditLogs',
        label: 'Audit Logs',
        description: 'Review administrative and security activity.',
        href: '/audit-logs',
        icon: 'file',
    },
    publicCheckInLinks: {
        key: 'publicCheckInLinks',
        label: 'Public Check-In',
        description: 'Manage branch public check-in links.',
        href: '/public-checkin-links',
        icon: 'building',
    },
    queueDisplay: {
        key: 'queueDisplay',
        label: 'Queue Display (TV)',
        description:
            'Rooms, posters, video and scrolling text for the waiting-room TV.',
        href: '/queue-display-settings',
        icon: 'building',
    },
    queueDisplayScreen: {
        key: 'queueDisplayScreen',
        label: 'TV Screen',
        description: 'Open the waiting-room TV screen for this branch.',
        href: '/queue-display',
        icon: 'activity',
    },
    insights: {
        key: 'insights',
        label: 'Insights',
        description: 'Review today’s clinic sales and operations overview.',
        href: '/insights/today',
        icon: 'activity',
    },
};

const available = (
    capabilities: WorkspaceNavigationCapabilities,
    keys: (keyof WorkspaceNavigationCapabilities)[],
) => keys.filter((key) => capabilities[key]).map((key) => destinations[key]);

export const mainMenuGroups = (
    capabilities: WorkspaceNavigationCapabilities,
): WorkspaceDestinationGroup[] =>
    [
        {
            label: 'Insights',
            destinations: available(capabilities, ['insights']),
        },
        {
            label: 'Clinic Operations',
            destinations: available(capabilities, [
                'registration',
                'consultation',
                'inventory',
                'queueDisplayScreen',
            ]),
        },
        {
            label: 'Reference Data',
            destinations: available(capabilities, [
                'medicineCatalogue',
                'clinicalServiceCatalogue',
                'catalogueSettings',
                'consultationSettings',
            ]),
        },
        {
            label: 'Patients',
            destinations: available(capabilities, ['patientRecords']),
        },
        {
            label: 'Panel',
            destinations: available(capabilities, [
                'panelClaims',
                'panelWork',
                'panelInvoices',
                'panelSettings',
            ]),
        },
        {
            label: 'Finance',
            destinations: available(capabilities, [
                'financeWork',
                'paymentMethods',
                'billingApprovalLimits',
                'paymentReconciliations',
            ]),
        },
        {
            label: 'Administration',
            destinations: available(capabilities, [
                'clinicManagement',
                'staff',
                'branches',
                'accessControl',
                'auditLogs',
                'publicCheckInLinks',
                'queueDisplay',
            ]),
        },
    ].filter((group) => group.destinations.length > 0);

export const headerDestinations = (
    capabilities: WorkspaceNavigationCapabilities,
    context: 'clinic' | 'admin',
): WorkspaceDestination[] =>
    // The logo and KPOne name already open the Main Menu, so it is not repeated here.
    available(
        capabilities,
        context === 'clinic'
            ? [
                  'insights',
                  'registration',
                  'consultation',
                  'inventory',
                  'patientRecords',
                  'panelClaims',
                  'panelWork',
                  'financeWork',
                  'paymentMethods',
                  'billingApprovalLimits',
                  'queueDisplayScreen',
              ]
            : [
                  'insights',
                  'panelClaims',
                  'panelWork',
                  'financeWork',
                  'paymentMethods',
                  'billingApprovalLimits',
                  'staff',
                  'branches',
                  'accessControl',
                  'auditLogs',
                  'publicCheckInLinks',
                  'queueDisplay',
                  'queueDisplayScreen',
              ],
    );

const pathname = (url: string): string => url.split(/[?#]/, 1)[0] ?? '';

export const isWorkspaceDestinationActive = (
    currentUrl: string,
    destinationHref: string,
): boolean => {
    const currentPath = pathname(currentUrl);
    const destinationPath = pathname(destinationHref);

    return (
        currentPath === destinationPath ||
        (destinationPath !== '/dashboard' &&
            currentPath.startsWith(`${destinationPath}/`))
    );
};

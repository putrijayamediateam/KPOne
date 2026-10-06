export type WorkspaceNavigationCapabilities = {
    registration: boolean;
    registrationReview: boolean;
    consultation: boolean;
    inventory: boolean;
    medicineCatalogue: boolean;
    clinicalServiceCatalogue: boolean;
    paymentMethods: boolean;
    billingApprovalLimits: boolean;
    paymentReconciliations: boolean;
    patientRecords: boolean;
    panelWork: boolean;
    financeWork: boolean;
    staff: boolean;
    branches: boolean;
    accessControl: boolean;
    auditLogs: boolean;
    publicCheckInLinks: boolean;
    queueDisplay: boolean;
    insights: boolean;
};

export type WorkspaceDestination = {
    key: keyof WorkspaceNavigationCapabilities | 'mainMenu';
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
        href: '/panel-claims',
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
    insights: {
        key: 'insights',
        label: 'Insights',
        description: 'Review today’s clinic sales and operations overview.',
        href: '/insights/today',
        icon: 'activity',
    },
};

const mainMenu: WorkspaceDestination = {
    key: 'mainMenu',
    label: 'Main Menu',
    description: 'Open the KPOne module hub.',
    href: '/dashboard',
    icon: 'activity',
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
            ]),
        },
        {
            label: 'Reference Data',
            destinations: available(capabilities, [
                'medicineCatalogue',
                'clinicalServiceCatalogue',
            ]),
        },
        {
            label: 'Patients',
            destinations: available(capabilities, ['patientRecords']),
        },
        {
            label: 'Panel',
            destinations: available(capabilities, ['panelWork']),
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
): WorkspaceDestination[] => [
    mainMenu,
    ...available(
        capabilities,
        context === 'clinic'
            ? [
                  'insights',
                  'registration',
                  'consultation',
                  'inventory',
                  'patientRecords',
                  'panelWork',
                  'financeWork',
                  'paymentMethods',
                  'billingApprovalLimits',
              ]
            : [
                  'insights',
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
              ],
    ),
];

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

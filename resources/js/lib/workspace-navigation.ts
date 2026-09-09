export type WorkspaceNavigationCapabilities = {
    registration: boolean;
    consultation: boolean;
    patientRecords: boolean;
    panelWork: boolean;
    financeWork: boolean;
    staff: boolean;
    branches: boolean;
    accessControl: boolean;
    auditLogs: boolean;
    publicCheckInLinks: boolean;
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
    consultation: {
        key: 'consultation',
        label: 'Consultation',
        description: 'Open the doctor queue and consultation workspace.',
        href: '/queue',
        icon: 'activity',
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
            label: 'Clinic Operations',
            destinations: available(capabilities, [
                'registration',
                'consultation',
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
            destinations: available(capabilities, ['financeWork']),
        },
        {
            label: 'Administration',
            destinations: available(capabilities, [
                'staff',
                'branches',
                'accessControl',
                'auditLogs',
                'publicCheckInLinks',
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
                  'registration',
                  'consultation',
                  'patientRecords',
                  'panelWork',
                  'financeWork',
              ]
            : [
                  'panelWork',
                  'financeWork',
                  'staff',
                  'branches',
                  'accessControl',
                  'auditLogs',
                  'publicCheckInLinks',
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

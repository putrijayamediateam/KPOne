export type StaffBranch = {
    id: number;
    code: string;
    name: string;
};

export type StaffAssignment = {
    id: number;
    branch: StaffBranch;
    assignmentType: string;
    isPrimary: boolean;
    validFrom: string;
    validUntil: string | null;
    state: 'current' | 'future' | 'ended';
    canBecomePrimary: boolean;
};

export type StaffSummary = {
    id: number;
    name: string;
    email: string;
    staffNumber: string | null;
    jobTitle: string | null;
    department: string | null;
    departmentId: number | null;
    isActive: boolean;
    roles: string[];
    primaryBranch: StaffBranch | null;
    currentAssignments: StaffAssignment[];
    assignmentState: 'current' | 'future' | 'ended';
};

export type StaffDetail = StaffSummary & {
    signInConfiguration:
        'password' | 'google_awaiting_first_sign_in' | 'google_linked';
    createdAt: string | null;
    assignments: StaffAssignment[];
    recentAudit: Array<{
        id: number;
        event: string;
        roleNames: string[];
        actor: string | null;
        occurredAt: string;
    }>;
    can: {
        update: boolean;
        manageAccess: boolean;
        manageRoles: boolean;
        manageStatus: boolean;
    };
};

export type StaffOptions = {
    branches: StaffBranch[];
    departments: Array<{ id: number; name: string }>;
    roles: string[];
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginatedStaff = {
    data: StaffSummary[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: PaginationLink[];
};

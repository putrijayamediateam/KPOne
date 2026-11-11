export type AuditPhoto = { id: string; label: string };
export type AuditItem = {
    id: string;
    title: string;
    help: string | null;
    photoRequired: boolean;
    result: 'pass' | 'fail' | 'na' | null;
    note: string | null;
    photos: AuditPhoto[];
    taskStatus: string | null;
};
export type AuditDetail = {
    id: string;
    status: string;
    lockVersion: number;
    branchName: string;
    monthLabel: string;
    verifyDoneTasks: boolean;
    canEdit: boolean;
    sections: { title: string; items: AuditItem[] }[];
};
export type AuditRow = {
    id: string;
    branchId: number;
    branchName: string;
    month: string;
    monthLabel: string;
    status: string;
    startedBy: string;
    submittedBy: string | null;
    submittedAt: string | null;
    tasks: number;
    unfinishedTasks: number;
};
export type TaskRow = {
    id: string;
    auditId: string;
    lockVersion: number;
    branchName: string;
    itemTitle: string;
    monthLabel: string;
    status: string;
    verifyRequired: boolean;
    doneBy: string | null;
    doneByMe: boolean;
    doneNote: string | null;
    photos: AuditPhoto[];
    canAct: boolean;
};
export type AuditsTabData = {
    canPerform: boolean;
    canManage: boolean;
    checklistReady: boolean;
    startable: { id: number; name: string }[];
    audits: AuditRow[];
    missedLastMonth: {
        branchId: number;
        branchName: string;
        monthLabel: string;
        canStart: boolean;
    }[];
    selected: AuditDetail | null;
    tasks: TaskRow[];
    cancelReasons: { value: string; label: string }[];
};
export type ChecklistItemInput = {
    section: string;
    title: string;
    help: string;
    photoRequired: boolean;
};
export type AuditSettings = {
    version: number | null;
    items: ChecklistItemInput[];
    verifyDoneTasks: boolean;
    photoRetentionDays: number | null;
    lockVersion: number;
    minRetentionDays: number;
};

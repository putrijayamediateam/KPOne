export * from './auth';
export * from './clinical';
export * from './navigation';
export * from './patient';
export * from './patientBoard';
export * from './queue';
export * from './staff';
export * from './ui';
export * from './visit';

export type BranchSummary = {
    id: number;
    code: string;
    name: string;
};

export type BranchContext = {
    active: BranchSummary | null;
    available: BranchSummary[];
    canSwitch: boolean;
} | null;

/** UX-17: the Version 2 sidebar, sent only when "Try the new layout" is on (V2ShellNavigation). */
export type V2Shell = {
    groups: Array<{
        title: string;
        items: Array<{
            key: string;
            title: string;
            href: string;
            links: Array<{ title: string; href: string }>;
        }>;
    }>;
    more: Array<{
        title: string;
        links: Array<{ title: string; href: string }>;
    }>;
    current: { key: string; title: string; href: string } | null;
};

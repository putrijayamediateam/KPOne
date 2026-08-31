export * from './auth';
export * from './clinical';
export * from './navigation';
export * from './patient';
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
} | null;

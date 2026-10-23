import type { ReleaseValue } from '@/types/website-releases';
export type OutputPlanPage = {
    branchId: number;
    release: { id: number; number: string; hash: string; state: string } | null;
    targets: { key: string; kind: string; name: string }[];
    availablePages: string[];
    labels: Record<string, { en: string; bm: string }>;
    designProfile: Record<string, ReleaseValue>;
    assets: { public_id: string; alt_en: string; alt_bm: string }[];
    plans: {
        id: number;
        number: string;
        releaseNumber: string;
        state: string;
        creator: string;
        approver: string | null;
    }[];
    detail: {
        id: number;
        number: string;
        state: string;
        lockVersion: number;
        hash: string;
        content: Record<string, ReleaseValue>;
        aliasRows: { key: string; name: string; kind: string; alias: string }[];
        actions: {
            action: string;
            actor: string;
            at: string;
            reason: string | null;
        }[];
    } | null;
    usable: boolean;
    canApprove: boolean;
};

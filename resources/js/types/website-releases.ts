export type ReleaseValue =
    | string
    | number
    | boolean
    | null
    | ReleaseValue[]
    | { [key: string]: ReleaseValue };
export type ReleaseItem = {
    key: string;
    type: string;
    language: string;
    content_hash: string;
    projection: Record<string, ReleaseValue>;
};
export type ReleaseSummary = {
    id: number;
    number: string;
    state: string;
    creator: string;
    approver: string | null;
    approvedAt: string | null;
    createdAt: string;
};
export type ReleaseDetail = {
    id: number;
    number: string;
    state: string;
    lockVersion: number;
    hash: string;
    items: ReleaseItem[];
    media: {
        filename: string;
        alt_en: string;
        alt_bm: string;
        width: number;
        height: number;
    }[];
    omissions: { key: string; type: string; reason: string }[];
    changes: Record<string, string[]>;
    comparedTo: string;
    samePreparerApprover: boolean;
    actions: {
        action: string;
        actor: string;
        at: string;
        reason: string | null;
    }[];
};
export type ReleasePage = {
    branchId: number;
    releases: ReleaseSummary[];
    detail: ReleaseDetail | null;
    canApprove: boolean;
};

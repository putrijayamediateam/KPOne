export type WebsiteBuildPage = {
    branchId: number;
    releaseId: number;
    releaseNumber: string;
    releaseHash: string;
    releaseVersion: number;
    available: boolean;
    plans: { id: number; number: string; hash: string; version: number }[];
    attempts: {
        id: number;
        state: string;
        failureCode: string | null;
        plan: string;
        number: string | null;
        count: number | null;
        bytes: number | null;
        hash: string | null;
        files: { path: string; bytes: number; sha256: string }[];
    }[];
};

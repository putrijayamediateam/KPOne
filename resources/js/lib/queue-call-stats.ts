export type QueueCallStats = {
    operationalDate: string;
    total: number;
    recalls: number;
    byService: Record<string, { calls: number; recalls: number }>;
    roomPagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
    };
    byRoom: Array<{ room: string; calls: number }>;
};

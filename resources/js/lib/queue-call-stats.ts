export type QueueCallStats = {
    operationalDate: string;
    total: number;
    recalls: number;
    byService: Record<string, { calls: number; recalls: number }>;
    byRoom: Array<{ room: string; calls: number }>;
};

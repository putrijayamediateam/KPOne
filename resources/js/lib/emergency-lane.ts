import { formatDateTime } from '@/lib/presentation';
import type { EmergencyLaneEntry } from '@/types/queue';

export function emergencyBoardMetadata<Row>(
    entry: EmergencyLaneEntry<Row>,
    timezone: string,
) {
    return {
        alertLabel: entry.alertStartedAt
            ? `${entry.alertOrigin === 'activation' ? 'Alert observed at' : 'Alert recorded at'} ${formatDateTime(entry.alertStartedAt, timezone)}`
            : 'Alert time unavailable',
        takenLabel: entry.takenBy
            ? `Taken by ${entry.takenBy}${entry.takenAt ? ` · ${formatDateTime(entry.takenAt, timezone)}` : ''}`
            : 'Not taken',
    };
}

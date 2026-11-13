import { formatDate } from '@/lib/presentation';

/**
 * Plain-words echo of a date typed in a native date box ("From 13 Nov 2026"). The box shows the browser's own format
 * (11/13/2026 can be misread as 13/11), so a V2 form says the chosen date in words under the field. Returns undefined
 * until the value is a full ISO date.
 */
export const echoDate = (
    value: string,
    lead: string,
    tail = '',
): string | undefined =>
    /^\d{4}-\d{2}-\d{2}$/.test(value)
        ? `${lead} ${formatDate(value)}${tail}`
        : undefined;

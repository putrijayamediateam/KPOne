/**
 * Version 2 Roster presentation helpers (pure; no requests). The server's warning lines stay the source of truth: these
 * only regroup them for reading (Daniel, 2026-11-13). Any line that does not match a known shape is shown as it is.
 */

export type DayChecks = { day: string; gaps: string[] };
export type GroupedChecks = { days: DayChecks[]; other: string[] };

/** "Short on Mon 16 Nov: Morning 0 CAs (minimum 2); Evening 1 Resident Doctor (minimum 2)." */
const SHORT = /^Short on (.+?): (.+?)\.?$/;
/** "Morning 0 CAs (minimum 2)" */
const GAP = /^(.+?) (\d+) (.+) \(minimum (\d+)\)$/;

// The server names the role by the count ("0 CAs", "1 CA"); the line reads against the minimum ("0 of 1 CA").
const byMinimum = (noun: string, count: number, minimum: number) => {
    if (minimum === 1 && count !== 1 && noun.endsWith('s')) {
        return noun.slice(0, -1);
    }

    return minimum > 1 && count === 1 && !noun.endsWith('s')
        ? `${noun}s`
        : noun;
};

/** "Morning 0 CAs (minimum 2)" becomes "Morning: 0 of 2 CAs". */
export const gapLine = (gap: string): string => {
    const m = GAP.exec(gap.trim());

    if (!m) {
        return gap.trim();
    }

    const [, session, count, noun, minimum] = m;

    return `${session}: ${count} of ${minimum} ${byMinimum(noun, Number(count), Number(minimum))}`;
};

/** One entry per day that is below its minimum staffing, in the server's order; other warnings are kept apart. */
export const groupChecks = (warnings: string[]): GroupedChecks => {
    const days: DayChecks[] = [];
    const other: string[] = [];

    for (const line of warnings) {
        const m = SHORT.exec(line.trim());

        if (!m) {
            other.push(line);

            continue;
        }

        days.push({ day: m[1], gaps: m[2].split('; ').map(gapLine) });
    }

    return { days, other };
};

/** "1 gap" or "3 gaps". */
export const gapCount = (n: number): string =>
    n === 1 ? '1 gap' : `${n} gaps`;

export type ComplianceWarning = {
    kind: string;
    count: number;
    message: string;
};

export const COMPLIANCE_GROUPS: { kind: string; title: string }[] = [
    { kind: 'coverage', title: 'Doctor cover' },
    { kind: 'days_in_row', title: 'Days in a row' },
    { kind: 'rest', title: 'Rest between shifts' },
    { kind: 'hours', title: 'Planned hours' },
];

/** The compliance warnings grouped under their titles, keeping the server's order inside each group. */
export const groupCompliance = (warnings: ComplianceWarning[]) =>
    COMPLIANCE_GROUPS.map((g) => ({
        ...g,
        lines: warnings.filter((w) => w.kind === g.kind).map((w) => w.message),
    })).filter((g) => g.lines.length > 0);

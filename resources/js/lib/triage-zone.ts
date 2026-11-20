// TRI-1b: the triage zone rules for the live suggestion on the triage form. The same rules live in PHP
// (app/Domain/Clinical/Triage/TriageZoneRules.php and TriageService) and SQL (kp_triage_assess and the insert
// trigger); tests/Fixtures/triage-zone-cases.json keeps the three in step. The server decides; this only shows the CA
// what the server will accept. No floats: readings and limits are compared in whole hundredths.

export type TriageZone = 'green' | 'yellow' | 'red';
export type AgeBand = 'adult' | 'child' | 'unknown';
export type TriageLimit = {
    reading: string;
    age_band: 'adult' | 'child';
    direction: 'very_low' | 'very_high';
    limit_value: string;
    zone: 'yellow' | 'red';
};
export type Crossed = Record<
    string,
    { direction: 'very_low' | 'very_high'; zone: 'yellow' | 'red' }
>;

export const READINGS = [
    'systolic_bp',
    'diastolic_bp',
    'pulse_bpm',
    'temperature_celsius',
    'spo2_percent',
    'weight_kg',
    'height_cm',
] as const;

export const READING_NAMES: Record<string, string> = {
    systolic_bp: 'blood pressure (systolic)',
    diastolic_bp: 'blood pressure (diastolic)',
    pulse_bpm: 'pulse',
    temperature_celsius: 'temperature',
    spo2_percent: 'SpO2',
    weight_kg: 'weight',
    height_cm: 'height',
};

const RANK: Record<TriageZone, number> = { green: 1, yellow: 2, red: 3 };

export function rank(zone: TriageZone): number {
    return RANK[zone];
}

export function higher(a: TriageZone, b: TriageZone): TriageZone {
    return rank(b) > rank(a) ? b : a;
}

const blank = (value: unknown): boolean =>
    value === null || value === undefined || String(value).trim() === '';

/** "38", "38.0" and "38.50" in whole hundredths, or null when it is not a reading with at most two decimals. */
export function hundredths(value: unknown): number | null {
    const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(String(value ?? '').trim());

    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '0').padEnd(2, '0'));
}

export function suggest(
    readings: Record<string, unknown>,
    redFlags: string[],
    yellowSigns: string[],
    ageBand: AgeBand,
    limits: TriageLimit[],
): { zone: TriageZone; crossed: Crossed; missing: string[] } {
    const bands = ageBand === 'unknown' ? ['adult', 'child'] : [ageBand];
    let zone: TriageZone = 'green';
    const crossed: Crossed = {};

    for (const limit of limits) {
        if (!bands.includes(limit.age_band) || blank(readings[limit.reading])) {
            continue;
        }

        const value = hundredths(readings[limit.reading]);
        const bound = hundredths(limit.limit_value);

        if (value === null || bound === null) {
            continue;
        }

        const past =
            limit.direction === 'very_high' ? value >= bound : value <= bound;

        if (!past) {
            continue;
        }

        const previous = crossed[limit.reading]?.zone ?? 'green';

        if (rank(limit.zone) > rank(previous)) {
            crossed[limit.reading] = {
                direction: limit.direction,
                zone: limit.zone,
            };
        }

        zone = higher(zone, limit.zone);
    }

    if (yellowSigns.length > 0) {
        zone = higher(zone, 'yellow');
    }

    if (redFlags.length > 0) {
        zone = 'red';
    }

    const missing = READINGS.filter((field) => blank(readings[field]));

    return { zone, crossed, missing };
}

export type LoweringContext = {
    suggested: TriageZone;
    crossed: Crossed;
    readings: Record<string, unknown>;
    redFlags: string[];
    previousZone: TriageZone | null;
    hasEarlierTriage: boolean;
    hadRedFlag: boolean;
    readingsToRetake: string[];
};

export function readingNames(fields: string[]): string {
    const names = fields.map((field) => READING_NAMES[field] ?? field);
    const last = names.pop() ?? '';
    const text = names.length === 0 ? last : `${names.join(', ')} and ${last}`;

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * TRI-1b owner rule 1: why choosing this zone would be refused as a lowering, or null when it is allowed (a lower
 * reason is still needed below the suggestion). Mirrors TriageService::assertZoneChoice after the reason checks.
 */
export function loweringRefusal(
    chosen: TriageZone,
    context: LoweringContext,
): string | null {
    const belowSuggestion = rank(chosen) < rank(context.suggested);
    const belowPrevious =
        context.previousZone !== null &&
        rank(chosen) < rank(context.previousZone);

    if (!belowSuggestion && !belowPrevious) {
        return null;
    }

    if (
        !context.hasEarlierTriage ||
        READINGS.every((field) => blank(context.readings[field]))
    ) {
        return 'Take the readings again before choosing a lower zone.';
    }

    if (context.redFlags.length > 0) {
        return 'A zone cannot be lowered while a red flag is ticked.';
    }

    if (chosen !== 'red' && context.hadRedFlag) {
        return 'A red flag was ticked at this visit, so the zone stays red.';
    }

    const above = Object.entries(context.crossed)
        .filter(([, c]) => rank(c.zone) > rank(chosen))
        .map(([field]) => field);
    const notRetaken = READINGS.filter(
        (field) =>
            context.readingsToRetake.includes(field) &&
            (blank(context.readings[field]) || above.includes(field)),
    );

    if (notRetaken.length > 0) {
        return `Take the readings again: ${readingNames([...notRetaken])} must be retaken and within limits before the zone is lowered.`;
    }

    if (belowSuggestion && above.length > 0) {
        return `Take the readings again: ${readingNames(READINGS.filter((field) => above.includes(field)))} must be within limits before choosing a lower zone than suggested.`;
    }

    return null;
}

/** The zone the form offers to confirm: the suggestion, or the lowest zone above it the server will accept now. */
export function zoneToConfirm(context: LoweringContext): {
    zone: TriageZone;
    heldBy: string | null;
} {
    for (const zone of ['green', 'yellow', 'red'] as TriageZone[]) {
        if (rank(zone) < rank(context.suggested)) {
            continue;
        }

        const refusal = loweringRefusal(zone, context);

        if (refusal === null) {
            return {
                zone,
                heldBy:
                    zone === context.suggested
                        ? null
                        : (loweringRefusal(context.suggested, context) ?? null),
            };
        }
    }

    return { zone: 'red', heldBy: loweringRefusal(context.suggested, context) };
}

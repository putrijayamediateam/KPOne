// Vitals the doctor typed into the clinical note (owner decision 2026-11-21). Reading only: the note is never changed, nothing is
// saved, and the consultation page fills the EMPTY vitals boxes by itself (with Undo); the doctor still has to save. Anything not clearly a reading, or
// not plausible, is simply not suggested, and a reading written twice with different values is left out as ambiguous.

export type VitalsField =
    | 'systolic_bp'
    | 'diastolic_bp'
    | 'pulse_bpm'
    | 'temperature_celsius'
    | 'spo2_percent'
    | 'weight_kg'
    | 'height_cm';

export type NoteReading = {
    /** One short label for the chip line, for example "BP 120/80". */
    label: string;
    /** The form boxes this reading fills (blood pressure fills two). */
    values: Partial<Record<VitalsField, string>>;
};

export type NoteVitals = {
    readings: NoteReading[];
    /** Readings written more than once with different values: not suggested. */
    ambiguous: string[];
    /** Readings that are named but written in another unit (°F, lb, inches): never read, only reported. */
    notRead: string[];
};

// "Check" marks, not judgement: a reading outside these wide ranges is still filled, but the line says "Check: ..." so a typo in the
// note does not slide into the record unseen.
// PENDING DOCTOR CONFIRMATION: these ranges are a placeholder, not clinical limits. The approved source is the TRI-1a triage guideline
// (TriageGuidelineService, yellow limits per reading and age group). The doctor's consultation page does not receive it today (it is
// served to the triage form only, and needs the patient's age group), so reading it here is a separate slice. Until then, one table.
const USUAL: Partial<Record<VitalsField, [number, number]>> = {
    systolic_bp: [90, 179],
    diastolic_bp: [50, 109],
    pulse_bpm: [50, 120],
    temperature_celsius: [35.5, 38.9],
    spo2_percent: [92, 100],
};

export const isUnusual = (values: Partial<Record<VitalsField, string>>) =>
    (Object.keys(values) as VitalsField[]).some((field) => {
        const range = USUAL[field];
        const value = Number.parseFloat(values[field] ?? '');

        return (
            range !== undefined &&
            Number.isFinite(value) &&
            (value < range[0] || value > range[1])
        );
    });

const num = (text: string) => Number.parseFloat(text.replace(',', '.'));
const between = (value: number, low: number, high: number) =>
    Number.isFinite(value) && value >= low && value <= high;
// "37.20" becomes "37.2"; whole numbers stay whole.
const plain = (value: number) => String(Number(value.toFixed(1)));

type Hit = { key: string; reading: NoteReading };

const bloodPressure = (note: string): Hit[] => {
    const hits: Hit[] = [];
    const pattern =
        /\b(?:bp|b\/p|td|tekanan\s+darah|blood\s+pressure)\s*[:=-]?\s*(\d{2,3})\s*[/\\]\s*(\d{2,3})\b|\b(\d{2,3})\s*\/\s*(\d{2,3})\s*mm\s?hg\b/gi;

    for (const match of note.matchAll(pattern)) {
        const systolic = Number(match[1] ?? match[3]);
        const diastolic = Number(match[2] ?? match[4]);

        if (
            between(systolic, 50, 300) &&
            between(diastolic, 20, 200) &&
            systolic > diastolic
        ) {
            hits.push({
                key: 'bp',
                reading: {
                    label: `BP ${systolic}/${diastolic}`,
                    values: {
                        systolic_bp: String(systolic),
                        diastolic_bp: String(diastolic),
                    },
                },
            });
        }
    }

    return hits;
};

const single = (
    note: string,
    key: string,
    pattern: RegExp,
    low: number,
    high: number,
    field: VitalsField,
    label: (value: string) => string,
): Hit[] => {
    const hits: Hit[] = [];

    for (const match of note.matchAll(pattern)) {
        const value = num(match[1] ?? match[2] ?? '');

        if (between(value, low, high)) {
            const text = plain(value);
            hits.push({
                key,
                reading: { label: label(text), values: { [field]: text } },
            });
        }
    }

    return hits;
};

// Named but written in a unit KPOne does not take: reported so the doctor sees nothing was read, and never converted or filled.
const unitsNotRead = (note: string) => {
    const found: string[] = [];
    const add = (label: string, pattern: RegExp) => {
        if (pattern.test(note) && !found.includes(label)) {
            found.push(label);
        }
    };

    add(
        'temperature in °F',
        /\b(?:temp(?:erature)?|suhu)\s*[:=-]?\s*\d{2,3}(?:[.,]\d)?\s*(?:°|º)?\s*(?:f\b|fahrenheit)|(?<![\d.,])\d{2,3}(?:[.,]\d)?\s*(?:°|º)\s*f\b/i,
    );
    add(
        'weight in lb',
        /\b(?:wt|weight|bb|berat(?:\s+badan)?)\s*[:=-]?\s*\d{1,3}(?:[.,]\d)?\s*(?:lbs?|pounds?)\b/i,
    );
    add(
        'height in inches or feet',
        /\b(?:ht|height|tb|tinggi)\s*[:=-]?\s*\d{1,3}(?:[.,]\d)?\s*(?:in\b|inch(?:es)?\b|ft\b|feet\b|')/i,
    );

    return found;
};

export const parseVitalsFromNote = (note: string): NoteVitals => {
    const text = note.normalize('NFKC');
    const hits: Hit[] = [
        ...bloodPressure(text),
        ...single(
            text,
            'temperature',
            /\b(?:temp(?:erature)?|suhu)\s*[:=-]?\s*(\d{2}(?:[.,]\d)?)(?!\d|[.,]\d)(?!\s*(?:°|º)?\s*(?:f\b|fahrenheit))|(?<![\d.,])(\d{2}(?:[.,]\d)?)\s*(?:°|º|deg(?:rees?)?\s*)\s*c\b/gi,
            30,
            45,
            'temperature_celsius',
            (value) => `Temp ${value} °C`,
        ),
        ...single(
            text,
            'pulse',
            /\b(?:pulse|pr|(?<!\d\s*)hr|heart\s+rate|kadar\s+nadi|nadi)\s*[:=-]?\s*(\d{2,3})(?!\d|[.,]\d)|(?<![\d.,])(\d{2,3})\s*(?:bpm|x\s*\/\s*min|\/\s*min)\b/gi,
            20,
            250,
            'pulse_bpm',
            (value) => `Pulse ${value}`,
        ),
        ...single(
            text,
            'spo2',
            /\b(?:sp\s?o\s?[2₂]|o2\s*sat(?:uration)?s?|sat(?:uration)?s?|oxygen\s+sat(?:uration)?)\s*[:=-]?\s*(\d{2,3})(?:[.,]\d)?\s*%?(?![\d])/gi,
            50,
            100,
            'spo2_percent',
            (value) => `SpO2 ${value}%`,
        ),
        // Weight and height are taken only when named AND written with their unit, so a number such as "lost 2 kg" is never read.
        ...single(
            text,
            'weight',
            /\b(?:wt|weight|bb|berat(?:\s+badan)?)\s*[:=-]?\s*(\d{1,3}(?:[.,]\d)?)\s*kg\b/gi,
            1,
            400,
            'weight_kg',
            (value) => `Weight ${value} kg`,
        ),
        ...single(
            text,
            'height',
            /\b(?:ht|height|tb|tinggi)\s*[:=-]?\s*(\d{2,3}(?:[.,]\d)?)\s*cm\b/gi,
            30,
            250,
            'height_cm',
            (value) => `Height ${value} cm`,
        ),
    ];

    const readings: NoteReading[] = [];
    const ambiguous: string[] = [];
    const names: Record<string, string> = {
        bp: 'blood pressure',
        temperature: 'temperature',
        pulse: 'pulse',
        spo2: 'SpO2',
        weight: 'weight',
        height: 'height',
    };

    for (const key of Object.keys(names)) {
        const found = hits.filter((hit) => hit.key === key);

        if (found.length === 0) {
            continue;
        }

        const labels = new Set(found.map((hit) => hit.reading.label));

        if (labels.size > 1) {
            ambiguous.push(names[key]);
        } else {
            readings.push(found[0].reading);
        }
    }

    return { readings, ambiguous, notRead: unitsNotRead(text) };
};

/** Stable text for one suggestion, so a dismissed one stays dismissed until the readings in the note change. */
export const noteVitalsKey = (vitals: NoteVitals) =>
    [
        ...vitals.readings.map((reading) => reading.label),
        ...vitals.notRead.map((text) => `not read: ${text}`),
    ].join('|');

type Boxes = Partial<Record<VitalsField, string | number | null | undefined>>;

const isEmpty = (value: string | number | null | undefined) =>
    value === null || value === undefined || String(value).trim() === '';
const same = (a: string | number | null | undefined, b: string) =>
    !isEmpty(a) && Number(a) === Number(b);

/**
 * What pressing Fill would do: write only into boxes that are empty. A reading whose boxes already hold the same numbers is left as
 * it is; one whose boxes hold something different is listed as not filled and is never overwritten. Blood pressure is one unit:
 * both boxes or neither, because the server needs both or neither.
 */
export const planFill = (vitals: NoteVitals, boxes: Boxes) => {
    const fill: Partial<Record<VitalsField, string>> = {};
    const filled: string[] = [];
    const skipped: string[] = [];
    const skippedDetails: { label: string; current: string }[] = [];

    for (const reading of vitals.readings) {
        const fields = Object.keys(reading.values) as VitalsField[];

        if (
            fields.every((field) =>
                same(boxes[field], reading.values[field] ?? ''),
            )
        ) {
            continue;
        }

        if (fields.every((field) => isEmpty(boxes[field]))) {
            for (const field of fields) {
                fill[field] = reading.values[field];
            }

            filled.push(reading.label);
        } else {
            skipped.push(reading.label);
            skippedDetails.push({
                label: reading.label,
                current: fields
                    .map((field) => boxes[field])
                    .filter((value) => !isEmpty(value))
                    .join('/'),
            });
        }
    }

    return { fill, filled, skipped, skippedDetails };
};

// Q-2 treatment progress (owner decisions 2026-11-05). Board and consultation page wording only.
// Self-contained so tests can run it directly; the server stays the authority for every rule.
export type TreatmentProgressView = {
    label: string | null;
    state: 'started' | 'done' | null;
    startedAt: string | null;
    doneAt: string | null;
};

export const TREATMENT_STEPS: ReadonlyArray<readonly [string, string]> = [
    ['nebuliser', 'Nebuliser'],
    ['wound_dressing', 'Wound dressing'],
    ['injection_observation', 'Injection and observation'],
    ['iv_drip', 'IV drip'],
    ['observation_after_medicine', 'Observation after medicine'],
    ['ecg', 'ECG'],
    ['blood_test_poc', 'Blood test (point-of-care)'],
    ['other_procedure', 'Other procedure'],
];

export const TREATMENT_CANCEL_REASONS: ReadonlyArray<
    readonly [string, string]
> = [
    ['wrong_patient', 'Wrong patient'],
    ['wrong_step', 'Wrong step'],
    ['marked_too_early', 'Marked too early'],
];

/** Whole minutes since the step started (running) or was marked done; null when unknown. */
export const treatmentMinutes = (
    progress: TreatmentProgressView | null | undefined,
    nowMs: number,
): number | null => {
    const since =
        progress?.state === 'done' ? progress.doneAt : progress?.startedAt;

    if (!progress?.state || !since) {
        return null;
    }

    const start = Date.parse(since);

    return Number.isFinite(start)
        ? Math.max(0, Math.floor((nowMs - start) / 60_000))
        : null;
};

const ago = (minutes: number | null): string =>
    minutes === null ? '' : minutes === 0 ? ' just now' : ` ${minutes} min ago`;

export const treatmentBadgeText = (
    progress: TreatmentProgressView | null | undefined,
    minutes: number | null,
): string | null => {
    if (!progress?.state || !progress.label) {
        return null;
    }

    return progress.state === 'done'
        ? `${progress.label}: done${ago(minutes)} · Ready to resume`
        : `${progress.label}: started${ago(minutes)}`;
};

export const treatmentAriaLabel = (
    progress: TreatmentProgressView | null | undefined,
    minutes: number | null,
): string | null => {
    if (!progress?.state || !progress.label) {
        return null;
    }

    const when =
        minutes === null
            ? ''
            : minutes === 0
              ? ' just now'
              : ` ${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;

    return progress.state === 'done'
        ? `Treatment progress: ${progress.label} done${when}, ready to resume`
        : `Treatment progress: ${progress.label} started${when}`;
};

export const treatmentButtonLabel = (
    progress: TreatmentProgressView | null | undefined,
): string =>
    progress?.state === 'started'
        ? 'Mark done'
        : progress?.state === 'done'
          ? 'Start next step'
          : 'Start treatment step';

/** Q-2c: a finished step still waiting for the doctor beside the main step. */
export type TreatmentWaitingView = { label: string; doneAt: string | null };

/** Q-2c (Yasmin): at most this many waiting lines on a chip, then "+N more done". */
export const TREATMENT_WAITING_SHOWN = 2;

const minutesSince = (iso: string | null, nowMs: number): number | null => {
    const start = iso ? Date.parse(iso) : Number.NaN;

    return Number.isFinite(start)
        ? Math.max(0, Math.floor((nowMs - start) / 60_000))
        : null;
};

const spokenAgo = (minutes: number | null): string =>
    minutes === null
        ? ''
        : minutes === 0
          ? ' just now'
          : ` ${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;

/**
 * Q-2c: the chip's lines when finished steps wait beside the main step. "Ready to resume" appears once, on the
 * last line. Returns null when nothing is waiting, so the single-line chip stays exactly as before.
 */
export const treatmentChipLines = (
    progress: TreatmentProgressView | null | undefined,
    minutes: number | null,
    waiting: ReadonlyArray<TreatmentWaitingView> | null | undefined,
    nowMs: number,
): string[] | null => {
    if (!progress?.state || !progress.label || !waiting?.length) {
        return null;
    }

    const main =
        progress.state === 'done'
            ? `${progress.label}: done${ago(minutes)}`
            : `${progress.label}: started${ago(minutes)}`;
    const lines = waiting
        .slice(0, TREATMENT_WAITING_SHOWN)
        .map(
            (item) =>
                `${item.label}: done${ago(minutesSince(item.doneAt, nowMs))}`,
        );

    if (waiting.length > TREATMENT_WAITING_SHOWN) {
        lines.push(`+${waiting.length - TREATMENT_WAITING_SHOWN} more done`);
    }

    // Daniel: a no-break space keeps the dot with the words before it when the chip wraps.
    lines[lines.length - 1] += '\u00a0· Ready to resume';

    return [main, ...lines];
};

/** Q-2c: one screen-reader sentence for the whole chip when finished steps are waiting. */
export const treatmentWaitingAriaLabel = (
    progress: TreatmentProgressView | null | undefined,
    minutes: number | null,
    waiting: ReadonlyArray<TreatmentWaitingView> | null | undefined,
    nowMs: number,
): string | null => {
    if (!progress?.state || !progress.label || !waiting?.length) {
        return null;
    }

    const main = `${progress.label} ${progress.state === 'done' ? 'done' : 'started'}${spokenAgo(minutes)}`;
    const done = waiting.map(
        (item) =>
            `${item.label} done${spokenAgo(minutesSince(item.doneAt, nowMs))}`,
    );

    return `Treatment progress: ${[main, ...done].join('; ')}, ready to resume`;
};

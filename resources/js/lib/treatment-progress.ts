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
          ? 'Cancel step…'
          : 'Start treatment step';

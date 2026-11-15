// Q-1 (owner decision 2026-11-05): a waiting patient's time on the Queue board
// and the Registration Waiting tab turns amber from 30 minutes and red from
// 60 minutes, so a forgotten patient stands out. Fixed for every branch.
// Display only: queue order and every backend rule are unchanged.
export const WAITING_AMBER_MINUTES = 30;
export const WAITING_RED_MINUTES = 60;

export type WaitingTone = 'normal' | 'amber' | 'red';

export const waitingTone = (
    minutes: number | null | undefined,
): WaitingTone => {
    if (typeof minutes !== 'number' || !Number.isFinite(minutes)) {
        return 'normal';
    }

    if (minutes >= WAITING_RED_MINUTES) {
        return 'red';
    }

    return minutes >= WAITING_AMBER_MINUTES ? 'amber' : 'normal';
};

// Spoken by screen readers after the time, so colour is not the only signal.
export const waitingToneDescription = (tone: WaitingTone): string | null =>
    ({
        normal: null,
        amber: `long wait, ${WAITING_AMBER_MINUTES} minutes or more`,
        red: `very long wait, ${WAITING_RED_MINUTES} minutes or more`,
    })[tone];

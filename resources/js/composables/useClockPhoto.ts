import { reactive, readonly } from 'vue';

/**
 * HR-PHOTO (owner, 2026-11-12, option 2 "Camera app"): one camera flow for every Clock in and Clock out button. The
 * state is module-level, so the one ClockPhotoDialog a layout mounts serves the Me page, the top bar, the account menu
 * and the Attendance page alike. open() refuses while a flow is already running.
 */
export type ClockKind = 'in' | 'out';

export type ClockConfirmation = {
    kind: ClockKind;
    title: string;
    time: string;
    branch: string;
    chip: {
        code: 'on_time' | 'late' | 'left_early' | 'no_shift' | 'on_call';
        label: string;
    };
};

/**
 * HR-16a, one Clock in (owner, 18 Nov 2026): a branch that checks location sends this with the clock state, and the Me
 * press runs the check inside the camera dialog. Consent text is set only while consent is still needed.
 */
export type ClockLocation = {
    consentNeeded: boolean;
    consentEn: string | null;
    consentBm: string | null;
};

export type ClockPhotoStep =
    | 'idle'
    | 'consent'
    | 'locating'
    | 'starting'
    | 'notice'
    | 'camera'
    | 'review'
    | 'sending'
    | 'done'
    | 'failed';

type State = {
    step: ClockPhotoStep;
    kind: ClockKind;
    extra: Record<string, unknown>;
    location: ClockLocation | null;
    token: string;
    key: string;
    error: string;
    result: ClockConfirmation | null;
    onDone: (() => void) | null;
};

const state = reactive<State>({
    step: 'idle',
    kind: 'in',
    extra: {},
    location: null,
    token: '',
    key: '',
    error: '',
    result: null,
    onDone: null,
});

export const CAMERA_REFUSED =
    'KPOne needs your camera to clock in. Allow the camera, or ask HR to add your time as a correction.';

export const csrfToken = (): string =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

/**
 * The server's refusals for a capture pass that ran out or cannot be used (ClockPhotoService::TOO_LONG and OPEN_AGAIN).
 * The photo is then thrown away and the sheet goes back to the camera (owner, 16 Nov 2026).
 */
export const PASS_REFUSALS = [
    'The camera was open too long. Take the photo again.',
    'Open the camera again and take a new photo.',
];

export const TOOK_TOO_LONG =
    'That took too long, so the photo was not used. Take it again.';

/** The pass was already used or not found (OPEN_AGAIN): no time ran out, so the line does not say it took too long. */
export const PASS_NOT_USABLE =
    'That photo could not be used, so it was not kept. Take a new one.';

/** The camera line for each pass refusal: only a pass that ran out says it took too long. */
export const passRefusalNote = (message: string): string =>
    message === PASS_REFUSALS[0] ? TOOK_TOO_LONG : PASS_NOT_USABLE;

/** Thrown when the person has not yet seen the current "About clock-in photos" notice. */
export class NoticeNeeded extends Error {}

/**
 * A capture pass, good for one press of this kind for five minutes. The sheet asks for one when the camera opens (which
 * also shows the notice first if needed) and a fresh one at the shutter, so the five minutes start when the photo is
 * taken (owner, 16 Nov 2026).
 */
export const takeToken = async (kind: ClockKind): Promise<string> => {
    const response = await fetch('/hr/attendance/capture', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ kind }),
    });

    if (response.status === 422) {
        const json = await response.json().catch(() => ({}));

        if (json?.errors?.notice) {
            throw new NoticeNeeded();
        }
    }

    if (!response.ok) {
        throw new Error('token');
    }

    return String((await response.json()).token ?? '');
};

/** The person has read the notice; the server keeps who, when and which version. */
export const markNoticeSeen = async (version: string): Promise<void> => {
    const response = await fetch('/hr/attendance/photo-notice', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ version }),
    });

    if (!response.ok) {
        throw new Error('notice');
    }
};

/** Sends the press. Resolves with the confirmation, or rejects with the first message the server gave. */
export const sendPress = async (photo: Blob): Promise<ClockConfirmation> => {
    const body = new FormData();
    body.append('idempotency_key', state.key);
    body.append('capture_token', state.token);
    body.append('photo', photo, 'photo.jpg');
    appendExtra(body, state.extra);
    const response = await fetch(
        state.kind === 'in'
            ? '/hr/attendance/clock-in'
            : '/hr/attendance/clock-out',
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body,
        },
    );
    const json = await response.json().catch(() => ({}));

    if (response.ok) {
        return json as ClockConfirmation;
    }

    const errors = (json?.errors ?? {}) as Record<string, string[]>;
    const first = Object.values(errors)[0]?.[0];

    throw new Error(
        first ??
            (response.status === 419
                ? 'Your session ended. Reload the page and try again.'
                : 'This did not go through. Try again.'),
    );
};

const appendExtra = (
    body: FormData,
    extra: Record<string, unknown>,
    prefix = '',
): void => {
    for (const [name, value] of Object.entries(extra)) {
        const key = prefix ? `${prefix}[${name}]` : name;

        if (value === null || value === undefined) {
            continue;
        }

        if (typeof value === 'object') {
            appendExtra(body, value as Record<string, unknown>, key);
        } else if (typeof value === 'boolean') {
            body.append(key, value ? '1' : '0');
        } else {
            body.append(key, String(value));
        }
    }
};

export const clockPhotoState = state;

/**
 * HR-16a: the location result for a press, read once, at the press, and never before or after. A refusal, a failure or
 * "without location" still records the press. `consent` is true when the person has just agreed in the dialog.
 */
export const readLocation = (
    consent: boolean,
): Promise<Record<string, unknown>> => {
    const extra: Record<string, unknown> = consent
        ? { location_consent: true }
        : {};

    if (!('geolocation' in navigator)) {
        return Promise.resolve({ ...extra, location_unavailable: 'failed' });
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    ...extra,
                    clock_position: {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                    },
                }),
            (error) =>
                resolve({
                    ...extra,
                    location_unavailable:
                        error.code === 1 ? 'denied' : 'failed',
                }),
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    });
};

/** "Clock in without location": the press is recorded with the reason, and no position is read. */
export const WITHOUT_LOCATION = { location_unavailable: 'declined' } as const;

export function useClockPhoto() {
    /**
     * Opens the camera for a press. `extra` carries what the Attendance page adds (for example the HR-16a location
     * result); `onDone` runs after Done is pressed on the confirmation. `location` (the Me press) makes the dialog run
     * the branch's location check first: the consent step when it is still needed, then one location read.
     */
    const open = (
        kind: ClockKind,
        options: {
            extra?: Record<string, unknown>;
            location?: ClockLocation | null;
            onDone?: () => void;
        } = {},
    ): boolean => {
        if (state.step !== 'idle') {
            return false;
        }

        state.kind = kind;
        state.extra = options.extra ?? {};
        state.onDone = options.onDone ?? null;
        state.token = '';
        state.key = crypto.randomUUID();
        state.error = '';
        state.result = null;
        state.location = options.location ?? null;
        state.step = state.location
            ? state.location.consentNeeded
                ? 'consent'
                : 'locating'
            : 'starting';

        return true;
    };

    return {
        open,
        state: readonly(state),
        busy: () => state.step !== 'idle',
    };
}

/** Back to idle; the dialog calls this when it closes. */
export const resetClockPhoto = (): void => {
    state.step = 'idle';
    state.token = '';
    state.error = '';
    state.result = null;
    state.extra = {};
    state.location = null;
    state.onDone = null;
};

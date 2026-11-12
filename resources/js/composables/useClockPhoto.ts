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
        code: 'on_time' | 'late' | 'left_early' | 'no_shift';
        label: string;
    };
};

export type ClockPhotoStep =
    'idle' | 'starting' | 'camera' | 'review' | 'sending' | 'done' | 'failed';

type State = {
    step: ClockPhotoStep;
    kind: ClockKind;
    extra: Record<string, unknown>;
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

/** The capture token is taken when the camera opens; it is good for one press of this kind for five minutes. */
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

    if (!response.ok) {
        throw new Error('token');
    }

    return String((await response.json()).token ?? '');
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

export function useClockPhoto() {
    /**
     * Opens the camera for a press. `extra` carries what the Attendance page adds (for example the HR-16a location
     * result); `onDone` runs after Done is pressed on the confirmation.
     */
    const open = (
        kind: ClockKind,
        options: { extra?: Record<string, unknown>; onDone?: () => void } = {},
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
        state.step = 'starting';

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
    state.onDone = null;
};

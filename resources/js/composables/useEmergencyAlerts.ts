import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';

export type EmergencyAlert = {
    visitNumber: string;
    queueNumber: string;
    waitingMinutes: number;
    takenBy: string | null;
    mine: boolean;
    canTake: boolean;
    alertOrigin: 'clinical' | 'activation' | null;
    episodeId: number | null;
    lockVersion: number | null;
    alertStartedAt: string | null;
    seenByMe: boolean | null;
    legacy: boolean;
};
type Context = { branchId: number; userId: number; canRespond: boolean } | null;
type Pending = {
    kind: 'seen' | 'take';
    visitNumber: string;
    payload: Record<string, string | number>;
    generation: number;
};
export function useEmergencyAlerts(
    context: Ref<Context>,
    fromVisit: () => string | undefined,
) {
    const alerts = ref<EmergencyAlert[]>([]);
    const presence = ref<{
        available: boolean;
        doctorCount: number | null;
        windowSeconds: number;
    } | null>(null);
    const paused = ref(false);
    const busy = ref(false);
    const uncertain = ref(false);
    const error = ref('');
    const soundReady = ref(false);
    const mutedUntil = ref(0);
    const now = ref(Date.now());
    let generation = 0;
    let controller: AbortController | undefined;
    let commandController: AbortController | undefined;
    let cancelTake: (() => void) | undefined;
    let pending: Pending | null = null;
    let pollTimer: ReturnType<typeof setInterval> | undefined;
    let soundTimer: ReturnType<typeof setInterval> | undefined;
    let clockTimer: ReturnType<typeof setInterval> | undefined;
    let audio: AudioContext | undefined;
    let originalTitle: string | null = null;
    let titleObserver: MutationObserver | undefined;
    const untaken = computed(() =>
        alerts.value.filter((row) => row.takenBy === null),
    );
    const emergencyTitle = '(!) Emergency · KPOne';
    const syncTitle = () => {
        if (untaken.value.length > 0 && context.value?.canRespond) {
            if (document.title !== emergencyTitle) {
                originalTitle = document.title;
                document.title = emergencyTitle;
            }
        } else if (originalTitle !== null) {
            if (document.title === emergencyTitle) {
                document.title = originalTitle;
            }

            originalTitle = null;
        }
    };
    watch(
        () => [untaken.value.length, context.value?.canRespond] as const,
        syncTitle,
    );
    const muted = computed(() => mutedUntil.value > now.value);
    const muteKey = () =>
        `kpone-emergency-mute:${context.value?.userId}:${context.value?.branchId}`;
    const loadMute = () => {
        try {
            mutedUntil.value = Math.min(
                Number(localStorage.getItem(muteKey())) || 0,
                Date.now() + 120_000,
            );
        } catch {
            mutedUntil.value = 0;
        }
    };
    const mute = () => {
        mutedUntil.value = Date.now() + 120_000;

        try {
            localStorage.setItem(muteKey(), String(mutedUntil.value));
        } catch {
            /* This tab still respects its bounded mute. */
        }
    };
    const tone = () => {
        if (
            !context.value?.canRespond ||
            !soundReady.value ||
            muted.value ||
            !untaken.value.length ||
            !audio ||
            audio.state !== 'running'
        ) {
            return;
        }

        const oscillator = audio.createOscillator();
        const volume = audio.createGain();
        oscillator.type = 'triangle';
        oscillator.frequency.setValueAtTime(740, audio.currentTime);
        oscillator.frequency.setValueAtTime(520, audio.currentTime + 0.2);
        volume.gain.setValueAtTime(0.08, audio.currentTime);
        volume.gain.exponentialRampToValueAtTime(
            0.001,
            audio.currentTime + 0.55,
        );
        oscillator.connect(volume);
        volume.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.6);
    };
    const enableSound = async () => {
        if (!context.value?.canRespond) {
            return;
        }

        try {
            audio ??= new AudioContext();
            await audio.resume();
            soundReady.value = audio.state === 'running';
            tone();
        } catch {
            soundReady.value = false;
        }
    };
    const read = async () => {
        const current = context.value;

        if (!current || document.visibilityState === 'hidden') {
            return;
        }

        controller?.abort();
        const request = new AbortController();
        controller = request;
        const version = generation;

        try {
            const body = await requestJson<{
                alerts: EmergencyAlert[];
                presence?: typeof presence.value;
            }>(
                `/queue/red-zone-alerts?expected_branch_id=${current.branchId}`,
                { signal: request.signal },
            );

            if (request.signal.aborted || version !== generation) {
                return;
            }

            alerts.value = body.alerts;
            presence.value = body.presence ?? null;
            paused.value = false;
        } catch {
            if (request.signal.aborted || version !== generation) {
                return;
            }

            paused.value = true;
            presence.value = null;
        }
    };
    const message = (payload: unknown) => {
        const data = payload as {
            errors?: Record<string, string | string[]>;
            message?: string;
        };
        const first = Object.values(data?.errors ?? {})[0];

        return (
            (Array.isArray(first) ? first[0] : first) ||
            data?.message ||
            'The emergency action could not be completed. Reload and review.'
        );
    };
    const run = async () => {
        const command = pending;

        if (
            !command ||
            busy.value ||
            command.generation !== generation ||
            !context.value?.canRespond
        ) {
            return;
        }

        busy.value = true;
        error.value = '';
        const valid = () => command.generation === generation;

        if (command.kind === 'take') {
            router.post(
                `/visits/${command.visitNumber}/queue/take-red-zone`,
                command.payload,
                {
                    preserveState: true,
                    preserveScroll: true,
                    onCancelToken: (token) => {
                        cancelTake = () => token.cancel();
                    },
                    onSuccess: () => {
                        if (valid()) {
                            pending = null;
                            uncertain.value = false;
                        }
                    },
                    onError: (errors) => {
                        if (valid()) {
                            error.value =
                                Object.values(errors)[0] ??
                                'Could not take this patient.';
                            uncertain.value = false;
                            pending = null;
                        }
                    },
                    onCancel: () => {
                        if (valid()) {
                            uncertain.value = true;
                            error.value =
                                'The result is not confirmed. Retry unchanged or reload before another action.';
                        }
                    },
                    onNetworkError: () => {
                        if (valid()) {
                            uncertain.value = true;
                            error.value =
                                'The result is not confirmed. Retry unchanged or reload before another action.';
                        }

                        return false;
                    },
                    onHttpException: () => {
                        if (valid()) {
                            uncertain.value = true;
                            error.value =
                                'The result is not confirmed. Retry unchanged or reload before another action.';
                        }

                        return false;
                    },
                    onFinish: () => {
                        if (valid()) {
                            busy.value = false;
                            cancelTake = undefined;
                            void read();
                        }
                    },
                },
            );

            return;
        }

        commandController = new AbortController();

        try {
            const cookie = document.cookie
                .split('; ')
                .find((value) => value.startsWith('XSRF-TOKEN='));
            await requestJson(
                `/visits/${command.visitNumber}/queue/emergency-seen`,
                {
                    method: 'POST',
                    signal: commandController.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': cookie
                            ? decodeURIComponent(cookie.slice(11))
                            : '',
                    },
                    body: JSON.stringify(command.payload),
                },
            );

            if (!valid()) {
                return;
            }

            pending = null;
            uncertain.value = false;
        } catch (failure) {
            if (!valid()) {
                return;
            }

            if (failure instanceof JsonRequestError && failure.status < 500) {
                error.value = message(failure.payload);
                pending = null;
                uncertain.value = false;
            } else {
                uncertain.value = true;
                error.value =
                    'The result is not confirmed. Retry unchanged or reload before another action.';
            }
        } finally {
            if (valid()) {
                busy.value = false;
                void read();
            }
        }
    };
    const command = (kind: 'seen' | 'take', alert: EmergencyAlert) => {
        const current = context.value;

        if (
            !current?.canRespond ||
            busy.value ||
            uncertain.value ||
            (kind === 'seen' && (alert.legacy || alert.seenByMe))
        ) {
            return;
        }

        const originalVisit = fromVisit();
        pending = {
            kind,
            visitNumber: alert.visitNumber,
            generation,
            payload: {
                expected_branch_id: current.branchId,
                ...(!alert.legacy &&
                alert.episodeId !== null &&
                alert.lockVersion !== null
                    ? {
                          episode_id: alert.episodeId,
                          lock_version: alert.lockVersion,
                          idempotency_key: crypto.randomUUID(),
                      }
                    : {}),
                ...(kind === 'take' && originalVisit
                    ? { from: 'consultation', from_visit: originalVisit }
                    : {}),
            },
        };
        void run();
    };
    const reset = () => {
        generation++;
        controller?.abort();
        commandController?.abort();
        cancelTake?.();
        cancelTake = undefined;
        pending = null;
        alerts.value = [];
        presence.value = null;
        paused.value = false;
        busy.value = false;
        uncertain.value = false;
        error.value = '';
        soundReady.value = false;
        void audio?.close();
        audio = undefined;
        loadMute();
    };
    watch(
        () =>
            `${context.value?.userId}:${context.value?.branchId}:${context.value?.canRespond}`,
        () => {
            reset();
            void read();
        },
    );
    const visibility = () => {
        if (document.visibilityState !== 'hidden') {
            void read();
            tone();
        }
    };
    onMounted(() => {
        titleObserver = new MutationObserver(syncTitle);
        titleObserver.observe(document.head, {
            childList: true,
            subtree: true,
            characterData: true,
        });
        syncTitle();
        loadMute();
        void read();
        pollTimer = setInterval(() => void read(), 5_000);
        soundTimer = setInterval(tone, 10_000);
        clockTimer = setInterval(() => (now.value = Date.now()), 1_000);
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('storage', loadMute);
    });
    onBeforeUnmount(() => {
        titleObserver?.disconnect();

        if (originalTitle !== null) {
            document.title = originalTitle;
            originalTitle = null;
        }

        reset();
        clearInterval(pollTimer);
        clearInterval(soundTimer);
        clearInterval(clockTimer);
        document.removeEventListener('visibilitychange', visibility);
        window.removeEventListener('storage', loadMute);
    });

    return {
        alerts,
        presence,
        paused,
        busy,
        uncertain,
        error,
        soundReady,
        muted,
        untaken,
        read,
        command,
        retry: run,
        enableSound,
        mute,
    };
}

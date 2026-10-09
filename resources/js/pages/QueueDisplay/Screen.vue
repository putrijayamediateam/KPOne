<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';
import type { DisplayCall, DisplayFeed } from '@/lib/queue-display';
import {
    announcementText,
    callLabel,
    formatCallTime,
    formatDisplayDate,
    formatDisplayTime,
    HIGHLIGHT_MILLISECONDS,
    nextPosterIndex,
    pickAnnouncementVoice,
    POLL_MILLISECONDS,
    roomLabel,
    unannouncedCalls,
    youtubeEmbedUrl,
} from '@/lib/queue-display';

const props = defineProps<{ feed: DisplayFeed; feedUrl: string }>();

const feed = ref<DisplayFeed>(props.feed);
const now = ref(new Date());
const offline = ref(false);
const sessionEnded = ref(false);
const soundOn = ref(false);
const theme = ref<'light' | 'dark'>('light');
const isFullscreen = ref(false);
const highlightId = ref<number | null>(null);
const posterIndex = ref(0);
const AUTH_RETRY_MILLISECONDS = 30_000;
const MAX_SPOKEN_CALLS = 3;
const seen = new Set<number>(props.feed.calls.map((call) => call.id));

const timeZone = computed(() => feed.value.branch.timezone);
const current = computed<DisplayCall | null>(() => feed.value.calls[0] ?? null);
const previous = computed(() => feed.value.calls.slice(1, 6));
const videoUrl = computed(() =>
    youtubeEmbedUrl(feed.value.settings.youtubeVideoId),
);
const posters = computed(() => feed.value.settings.posters);
const poster = computed(
    () => posters.value[posterIndex.value % Math.max(posters.value.length, 1)],
);
const ticker = computed(
    () =>
        feed.value.settings.tickerText ??
        'Welcome to Klinik Putrijaya. Please wait for your number to be called.',
);

const syncFullscreen = () => {
    isFullscreen.value = document.fullscreenElement !== null;
};

const requestFullscreen = async () => {
    try {
        await document.documentElement.requestFullscreen?.();
    } catch {
        // Fullscreen is optional; some TV browsers refuse it.
    }
};

const toggleFullscreen = async () => {
    if (!document.fullscreenElement) {
        await requestFullscreen();

        return;
    }

    try {
        await document.exitFullscreen();
    } catch {
        // Fullscreen is optional; some TV browsers refuse it.
    }
};

const toggleTheme = () => {
    theme.value = theme.value === 'light' ? 'dark' : 'light';
};

let audio: AudioContext | undefined;
const chime = () => {
    if (!soundOn.value || !audio) {
        return;
    }

    // A soft two-note ding-dong; the spoken call starts after it has faded.
    [
        { freq: 659, start: 0, length: 1.0 },
        { freq: 523, start: 0.5, length: 1.1 },
    ].forEach(({ freq, start, length }) => {
        const osc = audio!.createOscillator();
        const gain = audio!.createGain();
        const t = audio!.currentTime + start;
        osc.type = 'triangle';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(0.45, t + 0.04);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + length);
        osc.connect(gain).connect(audio!.destination);
        osc.start(t);
        osc.stop(t + length + 0.02);
    });
};

let voices: SpeechSynthesisVoice[] = [];
const loadVoices = () => {
    voices = window.speechSynthesis?.getVoices() ?? [];
};
const speak = (calls: DisplayCall[]) => {
    const synth = window.speechSynthesis;

    if (!soundOn.value || !synth) {
        return;
    }

    const { voice, language } = pickAnnouncementVoice(voices);
    calls.slice(-MAX_SPOKEN_CALLS).forEach((call) => {
        const utterance = new SpeechSynthesisUtterance(
            announcementText(call, language),
        );
        utterance.lang = voice?.lang ?? (language === 'ms' ? 'ms-MY' : 'en-GB');
        utterance.voice = voice;
        utterance.rate = 0.9;
        synth.speak(utterance);
    });
};

let wakeLock: { release: () => Promise<void> } | null = null;
const keepAwake = async () => {
    try {
        wakeLock =
            (await (
                navigator as Navigator & {
                    wakeLock?: {
                        request: (
                            type: 'screen',
                        ) => Promise<{ release: () => Promise<void> }>;
                    };
                }
            ).wakeLock?.request('screen')) ?? null;
    } catch {
        wakeLock = null;
    }
};

const start = async () => {
    audio ??= new AudioContext();
    await audio.resume();
    soundOn.value = true;
    chime();
    loadVoices();
    void keepAwake();

    await requestFullscreen();
};

let highlightTimer: number | undefined;
const announce = (calls: DisplayCall[]) => {
    if (calls.length === 0) {
        return;
    }

    calls.forEach((call) => seen.add(call.id));
    highlightId.value = calls[calls.length - 1].id;
    chime();
    window.setTimeout(() => speak(calls), 1_400);
    window.clearTimeout(highlightTimer);
    highlightTimer = window.setTimeout(
        () => (highlightId.value = null),
        HIGHLIGHT_MILLISECONDS,
    );
};

let polling = false;
let lastAuthFailure = 0;
const poll = async () => {
    if (
        polling ||
        (sessionEnded.value &&
            Date.now() - lastAuthFailure < AUTH_RETRY_MILLISECONDS)
    ) {
        return;
    }

    polling = true;

    try {
        const next = await requestJson<DisplayFeed>(props.feedUrl);
        offline.value = false;
        sessionEnded.value = false;
        const fresh = unannouncedCalls(next.calls, seen);

        if (next.settings.posters.length !== posters.value.length) {
            posterIndex.value = 0;
        }

        feed.value = next;
        announce(fresh);
    } catch (error) {
        if (
            error instanceof JsonRequestError &&
            [401, 403, 419].includes(error.status)
        ) {
            // Keep trying: the account is remembered, so a returning network or a fresh sign-in
            // elsewhere on this TV resumes the screen without a reload.
            sessionEnded.value = true;
            lastAuthFailure = Date.now();
        } else {
            offline.value = true;
        }
    } finally {
        polling = false;
    }
};

const signOut = () => {
    if (window.confirm('Sign out of this TV display?')) {
        router.post('/logout');
    }
};

let pollTimer: number | undefined;
let clockTimer: number | undefined;
let posterTimer: number | undefined;
const schedulePosters = () => {
    window.clearInterval(posterTimer);
    posterTimer = window.setInterval(
        () => {
            posterIndex.value = nextPosterIndex(
                posterIndex.value,
                posters.value.length,
            );
        },
        Math.max(feed.value.settings.posterSeconds, 5) * 1000,
    );
};
watch(() => feed.value.settings.posterSeconds, schedulePosters);
const onVisibility = () => {
    if (document.visibilityState === 'visible') {
        void keepAwake();
        void poll();
    }
};

onMounted(() => {
    syncFullscreen();
    document.addEventListener('fullscreenchange', syncFullscreen);
    // Some TV browsers allow sound without a tap (kiosk or site setting); use it when they do.
    audio = new AudioContext();
    soundOn.value = audio.state === 'running';
    loadVoices();
    window.speechSynthesis?.addEventListener('voiceschanged', loadVoices);
    void keepAwake();
    pollTimer = window.setInterval(poll, POLL_MILLISECONDS);
    clockTimer = window.setInterval(() => (now.value = new Date()), 1_000);
    schedulePosters();
    document.addEventListener('visibilitychange', onVisibility);
});
onBeforeUnmount(() => {
    window.clearInterval(pollTimer);
    window.clearInterval(clockTimer);
    window.clearInterval(posterTimer);
    window.clearTimeout(highlightTimer);
    document.removeEventListener('visibilitychange', onVisibility);
    document.removeEventListener('fullscreenchange', syncFullscreen);
    window.speechSynthesis?.removeEventListener('voiceschanged', loadVoices);
    window.speechSynthesis?.cancel();
    void wakeLock?.release();
    void audio?.close();
});
</script>

<template>
    <Head title="Queue Display" />
    <div
        class="flex h-svh min-h-0 flex-col overflow-hidden"
        :class="
            theme === 'dark'
                ? 'bg-zinc-950 text-white'
                : 'bg-white text-zinc-950'
        "
        data-testid="queue-display"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 lg:px-10"
            :class="
                theme === 'dark'
                    ? 'border-zinc-800 bg-zinc-950'
                    : 'border-zinc-200 bg-white'
            "
        >
            <div class="flex min-w-0 items-center gap-3">
                <img
                    src="/kp-mark.png"
                    alt="Klinik Putrijaya logo"
                    class="size-11 shrink-0 object-contain lg:size-14"
                />
                <div class="min-w-0">
                    <p
                        class="truncate text-xl font-bold tracking-wide lg:text-4xl"
                        :class="
                            theme === 'dark' ? 'text-pink-400' : 'text-pink-700'
                        "
                    >
                        Klinik Putrijaya
                    </p>
                    <p
                        class="truncate text-sm lg:text-xl"
                        :class="
                            theme === 'dark' ? 'text-zinc-300' : 'text-zinc-600'
                        "
                    >
                        Branch {{ feed.branch.name }}
                    </p>
                </div>
            </div>
            <div
                class="flex shrink-0 items-center gap-3 sm:gap-5"
                :class="theme === 'dark' ? 'text-white' : 'text-zinc-950'"
            >
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-2.5 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:outline-none"
                        :class="
                            theme === 'dark'
                                ? 'border-zinc-700 hover:bg-zinc-800'
                                : 'border-zinc-300 hover:bg-zinc-100'
                        "
                        :aria-label="
                            isFullscreen
                                ? 'Exit full screen'
                                : 'Enter full screen'
                        "
                        :aria-pressed="isFullscreen"
                        :title="
                            isFullscreen
                                ? 'Exit full screen'
                                : 'Enter full screen'
                        "
                        @click="toggleFullscreen"
                    >
                        <svg
                            v-if="!isFullscreen"
                            aria-hidden="true"
                            viewBox="0 0 24 24"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                d="M8 3H5a2 2 0 0 0-2 2v3m13-5h3a2 2 0 0 1 2 2v3M3 16v3a2 2 0 0 0 2 2h3m13-5v3a2 2 0 0 1-2 2h-3"
                            />
                        </svg>
                        <svg
                            v-else
                            aria-hidden="true"
                            viewBox="0 0 24 24"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M8 3v5H3m13-5v5h5M3 16h5v5m13-5h-5v5" />
                        </svg>
                        <span class="hidden xl:inline">{{
                            isFullscreen ? 'Exit full screen' : 'Full screen'
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-2.5 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:outline-none"
                        :class="
                            theme === 'dark'
                                ? 'border-zinc-700 hover:bg-zinc-800'
                                : 'border-zinc-300 hover:bg-zinc-100'
                        "
                        :aria-label="
                            theme === 'light'
                                ? 'Switch to dark theme'
                                : 'Switch to light theme'
                        "
                        :aria-pressed="theme === 'dark'"
                        :title="
                            theme === 'light'
                                ? 'Switch to dark theme'
                                : 'Switch to light theme'
                        "
                        @click="toggleTheme"
                    >
                        <svg
                            v-if="theme === 'dark'"
                            aria-hidden="true"
                            viewBox="0 0 24 24"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="12" cy="12" r="4" />
                            <path
                                d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"
                            />
                        </svg>
                        <svg
                            v-else
                            aria-hidden="true"
                            viewBox="0 0 24 24"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"
                            />
                        </svg>
                        <span class="hidden sm:inline">{{
                            theme === 'light' ? 'Dark mode' : 'Light mode'
                        }}</span>
                    </button>
                </div>
                <div class="text-right">
                    <p
                        class="font-mono text-2xl font-bold tabular-nums lg:text-5xl"
                        role="timer"
                        aria-live="off"
                    >
                        {{ formatDisplayTime(now, timeZone) }}
                    </p>
                    <p
                        class="text-xs lg:text-xl"
                        :class="
                            theme === 'dark' ? 'text-zinc-300' : 'text-zinc-600'
                        "
                    >
                        {{ formatDisplayDate(now, timeZone) }}
                    </p>
                </div>
            </div>
        </header>

        <main
            class="grid min-h-0 flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-6 lg:p-6"
        >
            <section
                class="flex min-h-0 min-w-0 flex-col gap-4"
                aria-label="Queue calls"
            >
                <div
                    class="rounded-2xl p-5 text-center transition-colors lg:p-8"
                    :class="
                        current && highlightId === current.id
                            ? 'animate-pulse bg-pink-600 text-white'
                            : theme === 'dark'
                              ? 'border border-zinc-800 bg-zinc-900 text-white'
                              : 'border border-zinc-200 bg-white text-zinc-950 shadow-sm'
                    "
                    aria-live="assertive"
                    data-testid="current-call"
                >
                    <p class="text-lg font-semibold uppercase lg:text-2xl">
                        {{ current?.isRecall ? 'Recall' : 'Now calling' }}
                    </p>
                    <template v-if="current">
                        <p
                            class="text-balance break-words"
                            :class="
                                current.name
                                    ? 'text-5xl leading-tight font-black lg:text-8xl'
                                    : 'font-mono text-7xl leading-none font-black tabular-nums lg:text-[9rem]'
                            "
                            data-testid="current-label"
                        >
                            {{ callLabel(current) }}
                        </p>
                        <p
                            class="mt-3 text-3xl font-bold text-balance break-words lg:text-5xl"
                            data-testid="current-room"
                        >
                            {{ roomLabel(current.room, current.service) }}
                        </p>
                    </template>
                    <p
                        v-else
                        class="py-8 text-2xl"
                        :class="
                            theme === 'dark' ? 'text-zinc-400' : 'text-zinc-500'
                        "
                    >
                        No calls yet today
                    </p>
                </div>

                <div
                    class="min-h-0 flex-1 overflow-hidden rounded-2xl"
                    :class="
                        theme === 'dark'
                            ? 'bg-zinc-900'
                            : 'border border-zinc-200 bg-white'
                    "
                >
                    <div
                        class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] gap-3 border-b px-5 py-3 text-sm font-semibold uppercase lg:text-lg"
                        :class="
                            theme === 'dark'
                                ? 'border-zinc-700 text-zinc-300'
                                : 'border-zinc-200 text-zinc-600'
                        "
                    >
                        <span>Number</span>
                        <span>Room</span>
                        <span>Time</span>
                    </div>
                    <ol>
                        <li
                            v-for="call in previous"
                            :key="call.id"
                            class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] items-center gap-3 border-b px-5 py-3 last:border-0"
                            :class="
                                highlightId === call.id
                                    ? theme === 'dark'
                                        ? 'border-zinc-800 bg-pink-950/50 text-pink-100'
                                        : 'border-zinc-200 bg-pink-100 text-pink-950'
                                    : theme === 'dark'
                                      ? 'border-zinc-800'
                                      : 'border-zinc-200'
                            "
                        >
                            <span
                                class="truncate text-3xl font-bold lg:text-5xl"
                                :class="
                                    call.name ? '' : 'font-mono tabular-nums'
                                "
                                >{{ callLabel(call) }}</span
                            >
                            <span
                                class="truncate text-xl font-semibold lg:text-3xl"
                                >{{ roomLabel(call.room, call.service) }}</span
                            >
                            <span
                                class="text-base tabular-nums lg:text-xl"
                                :class="
                                    theme === 'dark'
                                        ? 'text-zinc-400'
                                        : 'text-zinc-500'
                                "
                                >{{
                                    formatCallTime(call.calledAt, timeZone)
                                }}</span
                            >
                        </li>
                    </ol>
                </div>
            </section>

            <section
                class="hidden min-h-0 min-w-0 flex-col gap-4 lg:flex"
                aria-label="Clinic information"
            >
                <div
                    v-if="videoUrl"
                    class="aspect-video w-full shrink-0 overflow-hidden rounded-2xl bg-black"
                    :class="posters.length === 0 ? 'flex-1' : ''"
                >
                    <iframe
                        :src="videoUrl"
                        class="h-full w-full"
                        title="Clinic video"
                        allow="autoplay; encrypted-media; picture-in-picture"
                        referrerpolicy="strict-origin-when-cross-origin"
                        sandbox="allow-scripts allow-same-origin allow-presentation"
                    />
                </div>
                <div
                    v-if="poster"
                    class="flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-2xl"
                    :class="
                        theme === 'dark'
                            ? 'bg-zinc-900'
                            : 'border border-zinc-200 bg-zinc-100'
                    "
                >
                    <img
                        :key="poster.id"
                        :src="poster.url"
                        alt="Clinic poster"
                        class="h-full w-full object-contain"
                    />
                </div>
                <div
                    v-if="!videoUrl && !poster"
                    class="flex flex-1 items-center justify-center rounded-2xl p-10 text-center text-3xl"
                    :class="
                        theme === 'dark'
                            ? 'bg-zinc-900 text-zinc-300'
                            : 'border border-zinc-200 bg-zinc-100 text-zinc-600'
                    "
                >
                    Thank you for choosing Klinik Putrijaya.
                </div>
            </section>
        </main>

        <footer class="overflow-hidden bg-pink-700 py-2 text-white">
            <p
                class="queue-display-ticker text-xl font-semibold whitespace-nowrap lg:text-3xl"
            >
                {{ ticker }}
            </p>
        </footer>

        <button
            v-if="!soundOn && !sessionEnded"
            type="button"
            class="fixed bottom-14 left-3 z-40 rounded-xl bg-pink-700 px-5 py-3 text-lg font-bold text-white shadow-xl focus:ring-4 focus:ring-pink-300 focus:outline-none lg:bottom-16 lg:text-2xl"
            autofocus
            @click="start"
        >
            Click to enable sound
        </button>

        <div
            v-if="sessionEnded"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-zinc-950 p-6 text-center text-white"
            role="alert"
        >
            <p class="text-3xl font-bold lg:text-5xl">
                Display session expired
            </p>
            <p class="text-xl text-zinc-300 lg:text-2xl">
                Please sign in again with this branch's TV display account.
            </p>
            <a
                href="/login"
                class="rounded-xl bg-pink-700 px-8 py-4 text-2xl font-bold"
                >Sign in</a
            >
        </div>

        <div
            class="fixed right-3 bottom-14 z-30 flex items-center gap-2 text-xs lg:bottom-16"
        >
            <span
                v-if="offline"
                class="rounded bg-red-600 px-2 py-1 font-semibold"
                role="status"
                >Connection lost — retrying</span
            >
            <button
                type="button"
                class="rounded px-2 py-1 transition-colors"
                :class="
                    theme === 'dark'
                        ? 'bg-zinc-800/90 text-zinc-300 hover:bg-zinc-700'
                        : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200'
                "
                @click="signOut"
            >
                Sign out
            </button>
        </div>
    </div>
</template>

<style scoped>
.queue-display-ticker {
    display: inline-block;
    padding-left: 100%;
    animation: queue-display-ticker 28s linear infinite;
}

@keyframes queue-display-ticker {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(-100%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .queue-display-ticker {
        animation: none;
        padding-left: 1.5rem;
    }
}
</style>

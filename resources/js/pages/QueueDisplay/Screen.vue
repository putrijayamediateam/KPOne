<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';
import type { DisplayCall, DisplayFeed } from '@/lib/queue-display';
import {
    announcementText,
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
        'Selamat datang ke Klinik Putrijaya. Sila tunggu nombor anda dipanggil.',
);

let audio: AudioContext | undefined;
const chime = () => {
    if (!soundOn.value || !audio) {
        return;
    }

    [784, 988, 1175].forEach((freq, i) => {
        const osc = audio!.createOscillator();
        const gain = audio!.createGain();
        const t = audio!.currentTime + i * 0.4;
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(0.5, t + 0.05);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
        osc.connect(gain).connect(audio!.destination);
        osc.start(t);
        osc.stop(t + 0.37);
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

    try {
        await document.documentElement.requestFullscreen?.();
    } catch {
        // Fullscreen is optional; some TV browsers refuse it.
    }
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
    if (window.confirm('Log keluar dari paparan TV ini?')) {
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
    window.speechSynthesis?.removeEventListener('voiceschanged', loadVoices);
    window.speechSynthesis?.cancel();
    void wakeLock?.release();
    void audio?.close();
});
</script>

<template>
    <Head title="Paparan Giliran" />
    <div
        class="flex h-svh min-h-0 flex-col overflow-hidden bg-slate-950 text-white"
        data-testid="queue-display"
    >
        <header
            class="flex items-center justify-between gap-4 bg-teal-700 px-6 py-3 lg:px-10"
        >
            <div class="min-w-0">
                <p
                    class="truncate text-2xl font-bold tracking-wide lg:text-4xl"
                >
                    Klinik Putrijaya
                </p>
                <p class="truncate text-base text-teal-100 lg:text-2xl">
                    Cawangan {{ feed.branch.name }}
                </p>
            </div>
            <div class="shrink-0 text-right">
                <p
                    class="font-mono text-3xl font-bold tabular-nums lg:text-5xl"
                    role="timer"
                    aria-live="off"
                >
                    {{ formatDisplayTime(now, timeZone) }}
                </p>
                <p class="text-sm text-teal-100 lg:text-xl">
                    {{ formatDisplayDate(now, timeZone) }}
                </p>
            </div>
        </header>

        <main
            class="grid min-h-0 flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-6 lg:p-6"
        >
            <section
                class="flex min-h-0 min-w-0 flex-col gap-4"
                aria-label="Panggilan giliran"
            >
                <div
                    class="rounded-2xl p-5 text-center transition-colors lg:p-8"
                    :class="
                        current && highlightId === current.id
                            ? 'animate-pulse bg-amber-400 text-slate-950'
                            : 'bg-white text-slate-950'
                    "
                    aria-live="assertive"
                    data-testid="current-call"
                >
                    <p class="text-lg font-semibold uppercase lg:text-2xl">
                        {{
                            current?.isRecall
                                ? 'Panggilan semula'
                                : 'Sedang dipanggil'
                        }}
                    </p>
                    <template v-if="current">
                        <p
                            class="font-mono text-7xl leading-none font-black tabular-nums lg:text-[9rem]"
                        >
                            {{ current.number }}
                        </p>
                        <p class="mt-3 truncate text-3xl font-bold lg:text-5xl">
                            {{ roomLabel(current.room, current.service) }}
                        </p>
                    </template>
                    <p v-else class="py-8 text-2xl text-slate-500">
                        Belum ada panggilan hari ini
                    </p>
                </div>

                <div
                    class="min-h-0 flex-1 overflow-hidden rounded-2xl bg-slate-900"
                >
                    <div
                        class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] gap-3 border-b border-slate-700 px-5 py-3 text-sm font-semibold text-slate-300 uppercase lg:text-lg"
                    >
                        <span>Nombor</span>
                        <span>Bilik</span>
                        <span>Masa</span>
                    </div>
                    <ol>
                        <li
                            v-for="call in previous"
                            :key="call.id"
                            class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] items-center gap-3 border-b border-slate-800 px-5 py-3 last:border-0"
                            :class="
                                highlightId === call.id ? 'bg-amber-400/20' : ''
                            "
                        >
                            <span
                                class="font-mono text-3xl font-bold tabular-nums lg:text-5xl"
                                >{{ call.number }}</span
                            >
                            <span
                                class="truncate text-xl font-semibold lg:text-3xl"
                                >{{ roomLabel(call.room, call.service) }}</span
                            >
                            <span
                                class="text-base text-slate-400 tabular-nums lg:text-xl"
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
                aria-label="Maklumat klinik"
            >
                <div
                    v-if="videoUrl"
                    class="aspect-video w-full shrink-0 overflow-hidden rounded-2xl bg-black"
                    :class="posters.length === 0 ? 'flex-1' : ''"
                >
                    <iframe
                        :src="videoUrl"
                        class="h-full w-full"
                        title="Video klinik"
                        allow="autoplay; encrypted-media; picture-in-picture"
                        referrerpolicy="strict-origin-when-cross-origin"
                        sandbox="allow-scripts allow-same-origin allow-presentation"
                    />
                </div>
                <div
                    v-if="poster"
                    class="flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-2xl bg-slate-900"
                >
                    <img
                        :key="poster.id"
                        :src="poster.url"
                        alt="Poster klinik"
                        class="h-full w-full object-contain"
                    />
                </div>
                <div
                    v-if="!videoUrl && !poster"
                    class="flex flex-1 items-center justify-center rounded-2xl bg-slate-900 p-10 text-center text-3xl text-slate-300"
                >
                    Terima kasih kerana memilih Klinik Putrijaya.
                </div>
            </section>
        </main>

        <footer class="overflow-hidden bg-amber-400 py-2 text-slate-950">
            <p
                class="queue-display-ticker text-xl font-semibold whitespace-nowrap lg:text-3xl"
            >
                {{ ticker }}
            </p>
        </footer>

        <button
            v-if="!soundOn && !sessionEnded"
            type="button"
            class="fixed bottom-14 left-3 z-40 rounded-xl bg-teal-600 px-5 py-3 text-lg font-bold text-white shadow-xl focus:ring-4 focus:ring-amber-300 focus:outline-none lg:bottom-16 lg:text-2xl"
            autofocus
            @click="start"
        >
            Ketik untuk aktifkan bunyi
        </button>

        <div
            v-if="sessionEnded"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-slate-950 p-6 text-center"
            role="alert"
        >
            <p class="text-3xl font-bold lg:text-5xl">Sesi paparan tamat</p>
            <p class="text-xl text-slate-300 lg:text-2xl">
                Sila log masuk semula dengan akaun paparan TV cawangan ini.
            </p>
            <a
                href="/login"
                class="rounded-xl bg-teal-600 px-8 py-4 text-2xl font-bold"
                >Log masuk</a
            >
        </div>

        <div
            class="fixed right-3 bottom-14 z-30 flex items-center gap-2 text-xs lg:bottom-16"
        >
            <span
                v-if="offline"
                class="rounded bg-red-600 px-2 py-1 font-semibold"
                role="status"
                >Sambungan terputus — mencuba semula</span
            >
            <button
                type="button"
                class="rounded bg-slate-800/70 px-2 py-1 text-slate-300 hover:bg-slate-700"
                @click="signOut"
            >
                Log keluar
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

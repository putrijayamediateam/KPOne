<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BellRing,
    CheckCircle2,
    Clock3,
    RefreshCw,
    ShieldAlert,
    Volume2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    status: {
        state: string;
        message: string;
        branch: string;
        queueNumber: string | null;
        queueState: string | null;
        firstName: string | null;
        ahead: number | null;
        waitRange: { minMinutes: number; maxMinutes: number } | null;
        branches: {
            name: string;
            address: string | null;
            mapUrl: string | null;
            waiting: number;
            current: boolean;
        }[];
    };
}>();
const refreshing = ref(false);
let timer: number | undefined;
const called = computed(() => props.status.queueState === 'serving');
const terminal = computed(
    () =>
        ['rejected', 'expired'].includes(props.status.state) ||
        (props.status.state === 'accepted' &&
            ['serving', 'removed'].includes(props.status.queueState ?? '')),
);
const steps = ['Dihantar', 'Disemak', 'Disahkan', 'Dipanggil'];
const currentStep = computed(() => {
    if (called.value) {
        return 3;
    }

    if (props.status.state === 'accepted') {
        return 2;
    }

    return props.status.state === 'under_review' ? 1 : 0;
});
const soundOn = ref(false);
let audio: AudioContext | undefined;
const chime = () => {
    if (!audio) {
        return;
    }

    [660, 880, 1100].forEach((freq, i) => {
        const osc = audio!.createOscillator();
        const gain = audio!.createGain();
        const t = audio!.currentTime + i * 0.35;
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(0.4, t + 0.05);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
        osc.connect(gain).connect(audio!.destination);
        osc.start(t);
        osc.stop(t + 0.32);
    });
};
const enableSound = () => {
    audio ??= new AudioContext();
    void audio.resume();
    soundOn.value = true;
    chime();
};
watch(called, (value) => {
    if (value) {
        chime();
        navigator.vibrate?.([300, 150, 300, 150, 300]);
    }
});
const refresh = () => {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.reload({
        only: ['status'],
        onFinish: () => (refreshing.value = false),
    });
};
onMounted(() => {
    if (!terminal.value) {
        timer = window.setInterval(refresh, 10_000);
    }
});
onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer);
    }
});
</script>

<template>
    <Head title="Status pendaftaran"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto grid min-h-svh max-w-xl place-items-center px-4 py-8">
        <section
            class="w-full space-y-6 rounded-3xl border bg-white p-6 shadow-xl sm:p-9 dark:bg-zinc-900"
            aria-live="polite"
        >
            <header class="flex items-center gap-3">
                <img
                    src="/kp-mark.png"
                    alt="Klinik Putrijaya"
                    class="size-11 object-contain"
                />
                <div>
                    <p class="text-sm font-semibold text-pink-700">
                        KPOne · Klinik Putrijaya
                    </p>
                    <p class="text-sm text-zinc-600">{{ status.branch }}</p>
                </div>
            </header>
            <div class="space-y-4 text-center">
                <div
                    class="mx-auto grid size-16 place-items-center rounded-full"
                    :class="
                        status.state === 'accepted'
                            ? 'bg-emerald-100 text-emerald-700'
                            : status.state === 'rejected' ||
                                status.state === 'expired'
                              ? 'bg-amber-100 text-amber-700'
                              : 'bg-pink-100 text-pink-700'
                    "
                >
                    <CheckCircle2
                        v-if="status.state === 'accepted'"
                        class="size-8"
                    />
                    <ShieldAlert
                        v-else-if="
                            status.state === 'rejected' ||
                            status.state === 'expired'
                        "
                        class="size-8"
                    />
                    <Clock3 v-else class="size-8" />
                </div>
                <div v-if="status.queueNumber" class="space-y-1">
                    <p
                        v-if="status.firstName"
                        class="text-lg font-semibold text-zinc-800 dark:text-zinc-100"
                    >
                        {{ status.firstName }}
                    </p>
                    <p class="text-sm font-medium text-zinc-500">
                        Nombor queue anda
                    </p>
                    <p
                        class="text-6xl font-bold tracking-tight text-emerald-700"
                    >
                        {{ status.queueNumber }}
                    </p>
                </div>
                <h1 v-else class="text-2xl font-semibold">
                    Status pendaftaran
                </h1>
                <p
                    class="mx-auto max-w-sm leading-7 text-zinc-600 dark:text-zinc-300"
                >
                    {{ status.message }}
                </p>
            </div>
            <div
                v-if="status.ahead !== null"
                class="rounded-2xl bg-zinc-50 p-4 text-center dark:bg-zinc-800"
            >
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    <template v-if="status.ahead > 0"
                        >Pesakit di hadapan anda:
                        <strong>{{ status.ahead }}</strong></template
                    ><template v-else>Anda seterusnya dalam giliran.</template>
                </p>
                <p
                    v-if="status.waitRange"
                    class="mt-1 text-sm text-zinc-600 dark:text-zinc-300"
                >
                    Anggaran masa menunggu: kira-kira
                    <strong
                        >{{ status.waitRange.minMinutes }}–{{
                            status.waitRange.maxMinutes
                        }}
                        minit</strong
                    >
                    <span class="block text-xs text-zinc-500"
                        >Anggaran berdasarkan purata terkini, bukan
                        jaminan.</span
                    >
                </p>
            </div>
            <div
                v-if="called"
                class="flex items-center gap-3 rounded-2xl bg-emerald-600 p-4 text-white"
                role="alert"
            >
                <BellRing class="size-7 shrink-0 animate-pulse" />
                <p class="font-semibold">
                    Giliran anda! Sila masuk ke bilik doktor.
                </p>
            </div>
            <ol
                v-if="
                    ['pending', 'under_review', 'accepted'].includes(
                        status.state,
                    )
                "
                class="grid grid-cols-4 gap-2 text-center text-xs"
                aria-label="Kemajuan pendaftaran"
            >
                <li v-for="(label, i) in steps" :key="label" class="space-y-1">
                    <span
                        class="mx-auto block h-1.5 rounded-full"
                        :class="
                            i <= currentStep
                                ? 'bg-emerald-500'
                                : 'bg-zinc-200 dark:bg-zinc-700'
                        "
                    ></span>
                    <span
                        :class="
                            i === currentStep
                                ? 'font-semibold text-zinc-900 dark:text-zinc-100'
                                : 'text-zinc-500'
                        "
                        >{{ label }}</span
                    >
                </li>
            </ol>
            <button
                v-if="status.state === 'accepted' && !terminal && !soundOn"
                type="button"
                class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-pink-700 font-semibold text-white"
                @click="enableSound"
            >
                <Volume2 class="size-5" />Aktifkan bunyi pemberitahuan
            </button>
            <button
                v-if="!terminal"
                type="button"
                class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border font-semibold"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCw
                    class="size-5"
                    :class="{ 'animate-spin': refreshing }"
                />Semak status
            </button>
            <section
                v-if="status.branches.length > 1"
                class="space-y-2"
                aria-label="Cawangan lain"
            >
                <h2 class="text-sm font-semibold">Cawangan Klinik Putrijaya</h2>
                <ul class="space-y-2">
                    <li
                        v-for="branch in status.branches"
                        :key="branch.name"
                        class="flex items-center justify-between gap-3 rounded-xl border p-3 text-sm"
                        :class="
                            branch.current
                                ? 'border-pink-300 bg-pink-50 dark:bg-pink-950/30'
                                : ''
                        "
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ branch.name
                                }}<span
                                    v-if="branch.current"
                                    class="ml-2 text-xs text-pink-700"
                                    >Cawangan anda</span
                                >
                            </p>
                            <p
                                v-if="branch.address"
                                class="truncate text-xs text-zinc-500"
                            >
                                {{ branch.address }}
                            </p>
                            <a
                                v-if="branch.mapUrl"
                                :href="branch.mapUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-xs font-medium text-pink-700 underline"
                                >Buka peta</a
                            >
                        </div>
                        <p class="shrink-0 text-right">
                            <strong>{{ branch.waiting }}</strong>
                            <span class="block text-xs text-zinc-500"
                                >menunggu</span
                            >
                        </p>
                    </li>
                </ul>
            </section>
            <p class="text-center text-xs text-zinc-500">
                Halaman ini tidak menyimpan maklumat anda dalam storan pelayar.
            </p>
        </section>
    </main>
</template>

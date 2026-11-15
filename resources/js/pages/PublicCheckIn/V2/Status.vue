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
import { statusAnnouncement } from '@/lib/public-intake-announcement';

const props = defineProps<{
    status: {
        state: string;
        message: string;
        branch: string;
        visitKind?: string;
        called?: boolean;
        queueNumber: string | null;
        queueState: string | null;
        firstName: string | null;
        ahead: number | null;
        waitRange: { minMinutes: number; maxMinutes: number } | null;
        branches: {
            name: string;
            address: string | null;
            mapUrl: string | null;
            latitude: number | null;
            longitude: number | null;
            waiting: number;
            current: boolean;
        }[];
    };
}>();
type Coordinates = { latitude: number; longitude: number };
type Branch = (typeof props.status.branches)[number];
const refreshing = ref(false);
const patientLocation = ref<Coordinates | null>(null);
const locationMessage = ref('');
const locating = ref(false);
let timer: number | undefined;
const medicineOnly = computed(() => props.status.visitKind === 'otc');
const called = computed(
    () => props.status.called ?? props.status.queueState === 'serving',
);
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
// A screen reader hears only the key facts when they change (see statusAnnouncement). A call to the room is
// announced by its own alert, so nothing is announced here then. The text stays empty until the first change, so
// the page is not read twice when it opens.
const announcement = computed(() =>
    statusAnnouncement(props.status, called.value),
);
const announced = ref('');
watch(announcement, (text) => {
    if (text !== null) {
        announced.value = text;
    }
});
const soundOn = ref(false);
let audio: AudioContext | undefined;
const distanceKm = (from: Coordinates, to: Coordinates): number => {
    const radians = (degrees: number) => (degrees * Math.PI) / 180;
    const latitudeDelta = radians(to.latitude - from.latitude);
    const longitudeDelta = radians(to.longitude - from.longitude);
    const a =
        Math.sin(latitudeDelta / 2) ** 2 +
        Math.cos(radians(from.latitude)) *
            Math.cos(radians(to.latitude)) *
            Math.sin(longitudeDelta / 2) ** 2;

    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};
const branchDistance = (branch: Branch): number | null => {
    if (
        !patientLocation.value ||
        branch.latitude === null ||
        branch.longitude === null
    ) {
        return null;
    }

    return distanceKm(patientLocation.value, {
        latitude: branch.latitude,
        longitude: branch.longitude,
    });
};
const sortedBranches = computed(() => {
    const branches = props.status.branches.map((branch) => ({
        ...branch,
        distance: branchDistance(branch),
    }));

    if (!patientLocation.value) {
        return branches;
    }

    return branches.sort(
        (left, right) =>
            (left.distance ?? Number.POSITIVE_INFINITY) -
            (right.distance ?? Number.POSITIVE_INFINITY),
    );
});
const nearestBranch = computed(() => {
    const nearest = sortedBranches.value.find(
        (branch) => branch.distance !== null,
    );

    return nearest?.name ?? null;
});
const findNearbyBranches = () => {
    locationMessage.value = '';

    if (!navigator.geolocation) {
        locationMessage.value =
            'Pelayar ini tidak menyokong perkongsian lokasi.';

        return;
    }

    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            patientLocation.value = {
                latitude: coords.latitude,
                longitude: coords.longitude,
            };
            locationMessage.value =
                'Jarak ialah anggaran garis lurus, bukan jarak perjalanan.';
            locating.value = false;
        },
        (error) => {
            locationMessage.value =
                error.code === error.PERMISSION_DENIED
                    ? 'Kebenaran lokasi tidak diberikan. Anda masih boleh melihat cawangan dan peta.'
                    : 'Lokasi tidak dapat diperoleh. Sila cuba lagi atau buka peta.';
            locating.value = false;
        },
        { enableHighAccuracy: false, maximumAge: 300_000, timeout: 10_000 },
    );
};
const formatDistance = (distance: number | null) =>
    distance === null ? null : `${distance.toFixed(1)} km`;
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
    <main
        class="mx-auto grid min-h-svh max-w-xl grid-cols-1 place-items-center px-4 py-8"
        lang="ms"
        data-testid="public-status-v2"
    >
        <section
            class="w-full min-w-0 space-y-6 rounded-3xl border bg-card p-5 text-card-foreground shadow-sm sm:p-8"
        >
            <p
                class="sr-only"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-test="status-announcer"
            >
                {{ announced }}
            </p>
            <header class="flex items-center gap-3">
                <img
                    src="/kp-mark.png"
                    alt="Klinik Putrijaya"
                    class="size-11 object-contain"
                />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-primary">
                        KPOne · Klinik Putrijaya
                    </p>
                    <p class="text-sm break-words text-muted-foreground">
                        {{ status.branch }}
                    </p>
                </div>
            </header>
            <div class="space-y-4 text-center">
                <div
                    class="mx-auto grid size-16 place-items-center rounded-full"
                    :class="
                        status.state === 'accepted'
                            ? 'bg-success/10 text-success'
                            : status.state === 'rejected' ||
                                status.state === 'expired'
                              ? 'bg-warning/15 text-warning'
                              : 'bg-primary/10 text-primary'
                    "
                >
                    <CheckCircle2
                        v-if="status.state === 'accepted'"
                        class="size-8"
                        aria-hidden="true"
                    />
                    <ShieldAlert
                        v-else-if="
                            status.state === 'rejected' ||
                            status.state === 'expired'
                        "
                        class="size-8"
                        aria-hidden="true"
                    />
                    <Clock3 v-else class="size-8" aria-hidden="true" />
                </div>
                <div v-if="status.queueNumber" class="space-y-1">
                    <p
                        v-if="status.firstName"
                        class="text-lg font-semibold break-words"
                    >
                        {{ status.firstName }}
                    </p>
                    <p class="text-sm font-medium text-muted-foreground">
                        Nombor queue anda
                    </p>
                    <p
                        class="text-6xl font-bold tracking-tight whitespace-nowrap text-success tabular-nums"
                    >
                        {{ status.queueNumber }}
                    </p>
                </div>
                <h1 v-else class="text-2xl font-semibold">
                    Status pendaftaran
                </h1>
                <p class="mx-auto max-w-sm leading-7 text-muted-foreground">
                    {{ status.message }}
                </p>
            </div>
            <div
                v-if="status.ahead !== null"
                class="rounded-2xl bg-muted p-4 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    <template v-if="status.ahead > 0"
                        >Pesakit di hadapan anda:
                        <strong class="text-foreground">{{
                            status.ahead
                        }}</strong></template
                    ><template v-else>Anda seterusnya dalam giliran.</template>
                </p>
                <p
                    v-if="status.waitRange"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    Anggaran masa menunggu: kira-kira
                    <strong class="text-foreground"
                        >{{ status.waitRange.minMinutes }}–{{
                            status.waitRange.maxMinutes
                        }}
                        minit</strong
                    >
                    <span class="block text-xs"
                        >Anggaran berdasarkan purata terkini, bukan
                        jaminan.</span
                    >
                </p>
            </div>
            <div
                v-if="called"
                class="flex items-center gap-3 rounded-2xl bg-success p-4 text-success-foreground"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-testid="called-banner"
            >
                <BellRing
                    class="size-7 shrink-0 motion-safe:animate-pulse"
                    aria-hidden="true"
                />
                <p class="font-semibold">
                    {{
                        medicineOnly
                            ? 'Giliran anda! Sila ke kaunter farmasi.'
                            : 'Giliran anda! Sila masuk ke bilik doktor.'
                    }}
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
                <li
                    v-for="(label, i) in steps"
                    :key="label"
                    class="space-y-1.5"
                    :aria-current="i === currentStep ? 'step' : undefined"
                >
                    <span
                        class="mx-auto block h-1.5 rounded-full"
                        :class="i <= currentStep ? 'bg-success' : 'bg-border'"
                    ></span>
                    <span
                        :class="
                            i === currentStep
                                ? 'font-semibold text-foreground'
                                : 'text-muted-foreground'
                        "
                        >{{ label
                        }}<span v-if="i < currentStep" class="sr-only">
                            (selesai)</span
                        ></span
                    >
                </li>
            </ol>
            <button
                v-if="status.state === 'accepted' && !terminal && !soundOn"
                type="button"
                class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                @click="enableSound"
            >
                <Volume2 class="size-5" aria-hidden="true" />Aktifkan bunyi
                pemberitahuan
            </button>
            <button
                v-if="!terminal"
                type="button"
                class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border bg-card font-semibold hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCw
                    class="size-5"
                    :class="{ 'motion-safe:animate-spin': refreshing }"
                    aria-hidden="true"
                />Semak status
            </button>
            <section
                v-if="status.branches.length > 1"
                class="space-y-2"
                aria-label="Cawangan lain"
            >
                <h2 class="text-sm font-semibold">Cawangan Klinik Putrijaya</h2>
                <button
                    v-if="!patientLocation"
                    type="button"
                    class="flex min-h-12 w-full items-center justify-center rounded-xl border border-primary/40 bg-card font-semibold text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60"
                    :disabled="locating"
                    @click="findNearbyBranches"
                >
                    {{
                        locating
                            ? 'Mendapatkan lokasi…'
                            : 'Cari cawangan terdekat'
                    }}
                </button>
                <p
                    v-if="locationMessage"
                    class="text-xs text-muted-foreground"
                    role="status"
                >
                    {{ locationMessage }}
                </p>
                <p
                    v-if="patientLocation && nearestBranch"
                    class="text-sm font-medium text-success"
                >
                    Cawangan paling dekat: {{ nearestBranch }}
                </p>
                <ul class="space-y-2">
                    <li
                        v-for="branch in sortedBranches"
                        :key="branch.name"
                        class="flex items-center justify-between gap-3 rounded-xl border p-3 text-sm"
                        :class="
                            branch.current
                                ? 'border-primary/50 bg-primary/5'
                                : ''
                        "
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">
                                {{ branch.name
                                }}<span
                                    v-if="branch.current"
                                    class="ml-2 text-xs text-primary"
                                    >Cawangan anda</span
                                >
                            </p>
                            <p
                                v-if="branch.distance !== null"
                                class="text-xs font-medium text-success"
                            >
                                Kira-kira
                                {{ formatDistance(branch.distance) }}
                                (garis lurus)
                            </p>
                            <p
                                v-if="branch.address"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ branch.address }}
                            </p>
                            <a
                                v-if="branch.mapUrl"
                                :href="branch.mapUrl"
                                :aria-label="`Buka peta ${branch.name} (tab baharu)`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-11 items-center text-xs font-medium text-primary underline"
                                >Buka peta</a
                            >
                        </div>
                        <p class="shrink-0 text-right">
                            <strong class="tabular-nums">{{
                                branch.waiting
                            }}</strong>
                            <span class="block text-xs text-muted-foreground"
                                >menunggu</span
                            >
                        </p>
                    </li>
                </ul>
            </section>
            <p
                v-if="patientLocation"
                class="text-center text-xs text-muted-foreground"
            >
                Lokasi anda hanya digunakan pada peranti ini untuk mengira jarak
                dan tidak dihantar atau disimpan.
            </p>
            <p class="text-center text-xs text-muted-foreground">
                Halaman ini tidak menyimpan maklumat anda dalam storan pelayar.
            </p>
        </section>
    </main>
</template>

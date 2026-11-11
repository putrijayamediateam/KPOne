<script setup lang="ts">
// HR-17b candidate status page (HR-PLAN 19.5). The private link carries its token in the URL fragment, which never
// reaches the server's logs; this page sends it once, the server swaps it for an httpOnly cookie for this page only,
// and the fragment is cleared from the address bar. The page shows the stage in plain words and the clinic's
// messages, never HR's rating, note or checklist, and nothing the candidate sent.
import { Head, router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';

type Message = { label: string; subject: string; body: string; at: string };
type Application = {
    reference: string;
    position: string;
    stage: string;
    stageLabel: string;
    stageLabelMs: string;
    open: boolean;
    submittedOn: string;
    messages: Message[];
    // HR-17c: the slot picker at Scheduling, the confirmed time at Interview.
    interview: {
        branch?: string | null;
        picked?: string[];
        days?: {
            date: string;
            label: string;
            slots: { start: string; label: string }[];
        }[];
        picks?: number;
        zone?: string;
        confirmed?: string;
    } | null;
    // HR-17d: the clinic's documents for a confirmed interview.
    documents?: { id: string; title: string; size: string }[];
    // HR-17e: whether they ticked the talent pool, and whether they left it.
    pool?: { consented: boolean; left: boolean };
};

const props = defineProps<{
    clinicName: string;
    application: Application | null;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const exchanging = ref(false);
const confirming = ref(false);
const withdrawButton = ref<HTMLButtonElement | null>(null);
const confirmPanel = ref<HTMLElement | null>(null);
// The confirm takes focus when it opens; Keep it and Esc close it and give focus back (Daniel, HR-17b).
const askWithdraw = async () => {
    confirming.value = true;
    await nextTick();
    confirmPanel.value?.focus();
};
const keepIt = async () => {
    confirming.value = false;
    await nextTick();
    withdrawButton.value?.focus();
};
const withdrawing = ref(false);

onMounted(() => {
    const token = window.location.hash.slice(1);

    if (!/^[A-Za-z0-9_-]{43}$/.test(token)) {
        return;
    }

    // Clear the token from the address bar and history before anything else.
    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname,
    );
    exchanging.value = true;
    router.post(
        '/careers/status/exchange',
        { token },
        {
            preserveScroll: true,
            onFinish: () => (exchanging.value = false),
        },
    );
});

// HR-17c: choose exactly three times; nothing is kept in the browser.
const chosen = ref<string[]>([]);
const sending = ref(false);
const toggle = (start: string) => {
    chosen.value = chosen.value.includes(start)
        ? chosen.value.filter((s) => s !== start)
        : chosen.value.length < 3
          ? [...chosen.value, start]
          : chosen.value;
};
// Each chosen time with its day, for the "Your choices" list (Daniel, HR-17c).
const slotLabels = computed(() => {
    const labels: Record<string, string> = {};

    for (const day of props.application?.interview?.days ?? []) {
        for (const slot of day.slots) {
            labels[slot.start] = `${day.label}, ${slot.label}`;
        }
    }

    return labels;
});
const chosenIn = (starts: { start: string }[]) =>
    starts.filter((s) => chosen.value.includes(s.start)).length;
const sendPicks = () => {
    sending.value = true;
    router.post(
        '/careers/status/picks',
        { starts: chosen.value },
        {
            preserveScroll: true,
            onSuccess: () => (chosen.value = []),
            onFinish: () => (sending.value = false),
        },
    );
};

// HR-17e: leaving the talent pool asks first, then is final.
const leavingPool = ref(false);
const poolBusy = ref(false);
const leavePool = () => {
    poolBusy.value = true;
    router.post(
        '/careers/status/leave-pool',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                poolBusy.value = false;
                leavingPool.value = false;
            },
        },
    );
};

const withdraw = () => {
    withdrawing.value = true;
    router.post(
        '/careers/status/withdraw',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                withdrawing.value = false;
                confirming.value = false;
            },
        },
    );
};

const date = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(iso));

const submitted = computed(() =>
    props.application
        ? new Intl.DateTimeFormat('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              timeZone: 'UTC',
          }).format(new Date(`${props.application.submittedOn}T00:00:00Z`))
        : '',
);

const closedWell = computed(() => props.application?.stage === 'passed');
</script>

<template>
    <Head title="Your application / Permohonan anda"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto min-h-svh max-w-xl px-4 py-5 sm:px-6 sm:py-8">
        <section
            class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-xl shadow-zinc-950/5 dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="h-1.5 bg-pink-600" aria-hidden="true"></div>
            <div class="space-y-5 p-5 sm:p-8" data-test="status-page">
                <p
                    class="text-sm font-semibold text-pink-700 dark:text-pink-400"
                >
                    {{ clinicName }}
                </p>

                <template v-if="application">
                    <h1 class="text-xl font-semibold">
                        Your application
                        <span
                            lang="ms"
                            class="block text-base font-normal text-zinc-600 dark:text-zinc-400"
                            >Permohonan anda</span
                        >
                    </h1>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="min-w-0">
                            <dt class="text-zinc-600 dark:text-zinc-400">
                                Position / Jawatan
                            </dt>
                            <dd class="font-medium break-words">
                                {{ application.position }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-600 dark:text-zinc-400">
                                Sent on / Dihantar pada
                            </dt>
                            <dd class="font-medium">{{ submitted }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-600 dark:text-zinc-400">
                                Reference / Rujukan
                            </dt>
                            <dd class="font-mono font-medium">
                                {{ application.reference }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        class="rounded-2xl border p-4"
                        :class="
                            application.open || closedWell
                                ? 'border-pink-200 bg-pink-50 dark:border-pink-900/60 dark:bg-pink-950/30'
                                : 'border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950'
                        "
                        data-test="status-stage"
                    >
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Where it is now / Status semasa
                        </p>
                        <p class="text-lg font-semibold">
                            {{ application.stageLabel }}
                        </p>
                        <p
                            lang="ms"
                            class="text-sm text-zinc-700 dark:text-zinc-300"
                        >
                            {{ application.stageLabelMs }}
                        </p>
                    </div>

                    <section
                        v-if="application.interview?.confirmed"
                        class="rounded-2xl border border-pink-200 p-4 dark:border-pink-900/60"
                        aria-labelledby="interview-heading"
                        data-test="status-interview"
                    >
                        <h2
                            id="interview-heading"
                            class="text-base font-semibold"
                        >
                            Your interview
                            <span
                                lang="ms"
                                class="block text-sm font-normal text-zinc-600 dark:text-zinc-400"
                                >Temu duga anda</span
                            >
                        </h2>
                        <p class="mt-2 text-lg font-semibold">
                            {{ application.interview.confirmed }}
                        </p>
                        <p class="text-sm">
                            {{ clinicName }} {{ application.interview.branch }}
                        </p>
                        <div
                            v-if="application.documents?.length"
                            class="mt-4 space-y-2 border-t border-pink-200 pt-3 dark:border-pink-900/60"
                            data-test="status-documents"
                        >
                            <h3 class="text-sm font-semibold">
                                Please read before your interview
                                <span
                                    lang="ms"
                                    class="block font-normal text-zinc-600 dark:text-zinc-400"
                                    >Sila baca sebelum temu duga anda</span
                                >
                            </h3>
                            <ul class="space-y-2">
                                <li
                                    v-for="doc in application.documents"
                                    :key="doc.id"
                                >
                                    <a
                                        :href="`/careers/status/documents/${doc.id}`"
                                        class="inline-flex min-h-11 items-center gap-2 text-sm font-medium break-words text-pink-700 dark:text-pink-400"
                                        ><span
                                            class="underline underline-offset-4"
                                            >{{ doc.title }}</span
                                        >
                                        <span
                                            class="font-normal text-zinc-600 dark:text-zinc-400"
                                            >PDF · {{ doc.size }}</span
                                        ></a
                                    >
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section
                        v-else-if="
                            application.stage === 'scheduling' &&
                            application.interview
                        "
                        class="space-y-3"
                        aria-labelledby="slots-heading"
                        data-test="status-slots"
                    >
                        <h2 id="slots-heading" class="text-base font-semibold">
                            Choose your interview times
                            <span
                                lang="ms"
                                class="block text-sm font-normal text-zinc-600 dark:text-zinc-400"
                                >Pilih masa temu duga anda</span
                            >
                        </h2>
                        <template v-if="application.interview.picked?.length">
                            <p class="text-sm">
                                Your three times are with the clinic. They will
                                confirm one, or ask you for new times.
                            </p>
                            <p
                                lang="ms"
                                class="text-sm text-zinc-600 dark:text-zinc-400"
                            >
                                Tiga masa anda telah dihantar kepada klinik.
                                Klinik akan mengesahkan satu, atau meminta masa
                                baharu.
                            </p>
                            <ul
                                class="list-disc space-y-1 pl-5 text-sm font-medium"
                                data-test="status-picked"
                            >
                                <li
                                    v-for="p in application.interview.picked"
                                    :key="p"
                                >
                                    {{ p }}
                                </li>
                            </ul>
                        </template>
                        <template
                            v-else-if="application.interview.days?.length"
                        >
                            <p class="text-sm">
                                Tick three one-hour times that suit you, at
                                {{ clinicName }}
                                {{ application.interview.branch }}. The clinic
                                confirms one.
                            </p>
                            <p
                                lang="ms"
                                class="text-sm text-zinc-600 dark:text-zinc-400"
                            >
                                Tandakan tiga masa satu jam yang sesuai. Klinik
                                akan mengesahkan satu.
                            </p>
                            <p
                                v-if="errors.starts"
                                role="alert"
                                class="text-sm font-medium text-red-700 dark:text-red-400"
                                data-test="status-slots-error"
                            >
                                {{ errors.starts }}
                            </p>
                            <p
                                v-if="application.interview.zone"
                                class="text-sm font-medium"
                                data-test="status-slots-zone"
                            >
                                {{ application.interview.zone }} time / Waktu
                                {{ application.interview.zone }}
                            </p>
                            <!-- One disclosure per day, the first open (Daniel, HR-17c). -->
                            <details
                                v-for="(day, i) in application.interview.days"
                                :key="day.date"
                                class="group rounded-lg border border-zinc-200 dark:border-zinc-800"
                                :open="i === 0"
                                data-test="status-slots-day"
                            >
                                <summary
                                    class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-3 text-sm font-semibold [&::-webkit-details-marker]:hidden"
                                >
                                    <ChevronRight
                                        class="size-4 shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                    {{ day.label }}
                                    <span
                                        v-if="chosenIn(day.slots)"
                                        class="font-normal text-pink-700 dark:text-pink-400"
                                        >· {{ chosenIn(day.slots) }} chosen /
                                        dipilih</span
                                    >
                                </summary>
                                <div class="flex flex-wrap gap-2 px-3 pb-3">
                                    <label
                                        v-for="slot in day.slots"
                                        :key="slot.start"
                                        class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm has-[:checked]:border-pink-600 has-[:checked]:bg-pink-50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50 dark:has-[:checked]:bg-pink-950/40"
                                    >
                                        <input
                                            type="checkbox"
                                            class="size-4"
                                            :value="slot.start"
                                            :checked="
                                                chosen.includes(slot.start)
                                            "
                                            :disabled="
                                                !chosen.includes(slot.start) &&
                                                chosen.length >= 3
                                            "
                                            @change="toggle(slot.start)"
                                        />
                                        {{ slot.label }}
                                    </label>
                                </div>
                            </details>
                            <div
                                v-if="chosen.length"
                                class="space-y-2"
                                data-test="status-slots-choices"
                            >
                                <h3 class="text-sm font-semibold">
                                    Your choices / Pilihan anda
                                </h3>
                                <ul class="space-y-2">
                                    <li
                                        v-for="start in chosen"
                                        :key="start"
                                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-1 text-sm dark:border-zinc-800"
                                    >
                                        {{ slotLabels[start] ?? start }}
                                        <button
                                            type="button"
                                            class="inline-flex min-h-11 items-center px-2 text-sm font-medium text-pink-700 underline underline-offset-4 dark:text-pink-400"
                                            :aria-label="`Remove ${slotLabels[start] ?? start}`"
                                            @click="toggle(start)"
                                        >
                                            Remove / Buang
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <div
                                class="sticky bottom-0 flex flex-wrap items-center gap-3 border-t border-zinc-200 bg-white py-3 dark:border-zinc-800 dark:bg-zinc-900"
                            >
                                <p
                                    role="status"
                                    class="text-sm"
                                    data-test="status-slots-count"
                                >
                                    {{ chosen.length }} of 3 chosen /
                                    {{ chosen.length }} daripada 3 dipilih
                                </p>
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-lg bg-pink-700 px-4 text-sm font-medium text-white hover:bg-pink-800 focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                    :disabled="chosen.length !== 3 || sending"
                                    data-test="status-slots-send"
                                    @click="sendPicks"
                                >
                                    {{
                                        chosen.length === 3
                                            ? 'Send my three times / Hantar tiga masa saya'
                                            : 'Choose 3 times / Pilih 3 masa'
                                    }}
                                </button>
                            </div>
                        </template>
                        <p v-else class="text-sm" data-test="status-slots-none">
                            The clinic will contact you to arrange a time. /
                            Klinik akan menghubungi anda untuk menetapkan masa.
                        </p>
                    </section>

                    <section
                        aria-labelledby="messages-heading"
                        class="space-y-3"
                    >
                        <h2
                            id="messages-heading"
                            class="text-base font-semibold"
                        >
                            Messages from the clinic
                            <span
                                lang="ms"
                                class="block text-sm font-normal text-zinc-600 dark:text-zinc-400"
                                >Mesej daripada klinik</span
                            >
                        </h2>
                        <p
                            v-if="!application.messages.length"
                            class="text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            No messages yet. / Tiada mesej lagi.
                        </p>
                        <ol
                            v-else
                            class="space-y-3"
                            data-test="status-messages"
                        >
                            <li
                                v-for="(m, i) in application.messages"
                                :key="i"
                                class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800"
                            >
                                <p
                                    class="text-xs text-zinc-600 dark:text-zinc-400"
                                >
                                    <time :datetime="m.at">{{
                                        date(m.at)
                                    }}</time>
                                </p>
                                <p class="mt-1 text-sm font-semibold">
                                    {{ m.subject }}
                                </p>
                                <p
                                    class="mt-2 text-sm break-words whitespace-pre-line"
                                >
                                    {{ m.body }}
                                </p>
                            </li>
                        </ol>
                    </section>

                    <section
                        v-if="application.pool?.consented"
                        class="space-y-3 border-t border-zinc-200 pt-5 dark:border-zinc-800"
                        aria-labelledby="pool-heading"
                        data-test="status-pool"
                    >
                        <h2 id="pool-heading" class="text-base font-semibold">
                            Talent pool
                            <span
                                lang="ms"
                                class="block text-sm font-normal text-zinc-600 dark:text-zinc-400"
                                >Kumpulan bakat</span
                            >
                        </h2>
                        <p
                            v-if="application.pool.left"
                            class="text-sm"
                            data-test="status-pool-left"
                        >
                            You are not in the talent pool. The clinic will not
                            contact you about other openings.
                            <span
                                lang="ms"
                                class="block text-zinc-600 dark:text-zinc-400"
                                >Anda tiada dalam kumpulan bakat. Klinik tidak
                                akan menghubungi anda tentang jawatan
                                lain.</span
                            >
                        </p>
                        <template v-else>
                            <p class="text-sm">
                                You asked the clinic to keep your details for
                                future openings of the same kind.
                                <span
                                    lang="ms"
                                    class="block text-zinc-600 dark:text-zinc-400"
                                    >Anda meminta klinik menyimpan butiran anda
                                    untuk jawatan akan datang yang sama
                                    jenis.</span
                                >
                            </p>
                            <p
                                v-if="errors.pool"
                                role="alert"
                                class="text-sm text-red-700 dark:text-red-400"
                            >
                                {{ errors.pool }}
                            </p>
                            <button
                                v-if="!leavingPool"
                                type="button"
                                class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 px-4 text-sm font-medium hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:outline-none dark:border-zinc-700 dark:hover:bg-zinc-800"
                                data-test="status-pool-leave"
                                @click="leavingPool = true"
                            >
                                Remove me from the talent pool / Keluarkan saya
                            </button>
                            <div
                                v-else
                                class="space-y-3 rounded-xl border border-zinc-300 p-4 dark:border-zinc-700"
                                role="group"
                                aria-labelledby="pool-confirm-title"
                                data-test="status-pool-confirm"
                                @keydown.esc="leavingPool = false"
                            >
                                <p
                                    id="pool-confirm-title"
                                    class="text-sm font-medium"
                                >
                                    Leave the talent pool? You cannot join it
                                    again from this application.
                                    <span
                                        lang="ms"
                                        class="block font-normal text-zinc-700 dark:text-zinc-300"
                                        >Keluar daripada kumpulan bakat? Anda
                                        tidak boleh menyertainya semula melalui
                                        permohonan ini.</span
                                    >
                                </p>
                                <div class="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex min-h-11 items-center rounded-lg bg-pink-700 px-4 text-sm font-medium text-white hover:bg-pink-800 focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                        :disabled="poolBusy"
                                        data-test="status-pool-leave-yes"
                                        @click="leavePool"
                                    >
                                        Remove me / Keluarkan saya
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 px-4 text-sm font-medium dark:border-zinc-700"
                                        @click="leavingPool = false"
                                    >
                                        Stay in it / Kekal
                                    </button>
                                </div>
                            </div>
                        </template>
                    </section>

                    <section
                        v-if="application.open"
                        class="space-y-3 border-t border-zinc-200 pt-5 dark:border-zinc-800"
                    >
                        <p
                            v-if="errors.withdraw"
                            role="alert"
                            class="text-sm text-red-700 dark:text-red-400"
                        >
                            {{ errors.withdraw }}
                        </p>
                        <button
                            v-if="!confirming"
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 px-4 text-sm font-medium hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-pink-500 focus-visible:outline-none dark:border-zinc-700 dark:hover:bg-zinc-800"
                            ref="withdrawButton"
                            data-test="status-withdraw"
                            @click="askWithdraw"
                        >
                            Withdraw my application / Tarik balik permohonan
                        </button>
                        <div
                            v-else
                            class="space-y-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/30"
                            ref="confirmPanel"
                            tabindex="-1"
                            role="group"
                            aria-labelledby="withdraw-confirm-title"
                            data-test="status-withdraw-confirm"
                            @keydown.esc="keepIt"
                        >
                            <p
                                id="withdraw-confirm-title"
                                class="text-sm font-medium"
                            >
                                Withdraw your application? This cannot be
                                undone.
                            </p>
                            <p
                                lang="ms"
                                class="text-sm text-zinc-700 dark:text-zinc-300"
                            >
                                Tarik balik permohonan anda? Ini tidak boleh
                                dibatalkan.
                            </p>
                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-lg bg-red-700 px-4 text-sm font-medium text-white hover:bg-red-800 focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                    :disabled="withdrawing"
                                    data-test="status-withdraw-yes"
                                    @click="withdraw"
                                >
                                    Withdraw / Tarik balik
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 px-4 text-sm font-medium dark:border-zinc-700"
                                    @click="keepIt"
                                >
                                    Keep it / Kekalkan
                                </button>
                            </div>
                        </div>
                    </section>
                </template>

                <template v-else>
                    <h1 class="text-xl font-semibold">
                        Your application
                        <span
                            lang="ms"
                            class="block text-base font-normal text-zinc-600 dark:text-zinc-400"
                            >Permohonan anda</span
                        >
                    </h1>
                    <p
                        v-if="exchanging"
                        role="status"
                        class="text-sm text-zinc-600 dark:text-zinc-400"
                    >
                        Opening your application… / Membuka permohonan anda…
                    </p>
                    <template v-else>
                        <p
                            v-if="errors.link"
                            role="alert"
                            class="text-sm font-medium text-red-700 dark:text-red-400"
                            data-test="status-link-error"
                        >
                            {{ errors.link }}
                        </p>
                        <p class="text-sm">
                            Open the private link from your thank-you page or
                            from the clinic's email to see your application.
                        </p>
                        <p
                            lang="ms"
                            class="text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            Buka pautan peribadi daripada halaman terima kasih
                            atau e-mel klinik untuk melihat permohonan anda.
                        </p>
                        <a
                            href="/careers/apply"
                            class="inline-flex min-h-11 items-center text-sm font-medium text-pink-700 underline underline-offset-4 dark:text-pink-400"
                            data-test="status-apply"
                            >Apply for a job / Mohon jawatan</a
                        >
                    </template>
                </template>
            </div>
        </section>
    </main>
</template>

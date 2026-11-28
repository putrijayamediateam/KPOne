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
    open: boolean;
    canDelete: boolean;
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
        // Owner, 2026-11-11: the last day to choose before the application closes.
        chooseBy?: string | null;
        confirmed?: string;
        // HR-15e: an online interview and its link.
        online?: boolean;
        videoLink?: string | null;
    } | null;
    // HR-17d: the clinic's documents for a confirmed interview.
    documents?: { id: string; title: string; size: string }[];
    // HR-17e: whether they ticked the talent pool, and whether they left it.
    pool?: { consented: boolean; left: boolean };
};

const props = defineProps<{
    clinicName: string;
    application: Application | null;
    erased?: boolean;
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
// Owner decision 2026-11-11: the candidate deletes their application and its details themselves.
const deleting = ref(false);
const askingDelete = ref(false);
const deleteButton = ref<HTMLButtonElement | null>(null);
const deletePanel = ref<HTMLElement | null>(null);
const askDelete = async () => {
    askingDelete.value = true;
    await nextTick();
    deletePanel.value?.focus();
};
const keepDetails = async () => {
    askingDelete.value = false;
    await nextTick();
    deleteButton.value?.focus();
};
const erase = () => {
    deleting.value = true;
    router.post(
        '/careers/status/delete',
        {},
        {
            onFinish: () => {
                deleting.value = false;
                askingDelete.value = false;
            },
        },
    );
};

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
    <Head title="Your application"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto min-h-svh max-w-xl px-4 py-5 sm:px-6 sm:py-8">
        <section
            class="overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-card)]"
        >
            <div class="h-1.5 bg-brand" aria-hidden="true"></div>
            <div class="space-y-5 p-5 sm:p-8" data-test="status-page">
                <p class="text-sm font-semibold text-brand-strong">
                    {{ clinicName }}
                </p>

                <template v-if="application">
                    <h1 class="text-xl font-semibold">Your application</h1>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="min-w-0">
                            <dt class="text-muted-foreground">Position</dt>
                            <dd class="font-medium break-words">
                                {{ application.position }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Sent on</dt>
                            <dd class="font-medium">{{ submitted }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Reference</dt>
                            <dd class="font-mono font-medium">
                                {{ application.reference }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        class="rounded-xl border p-4"
                        :class="
                            application.open || closedWell
                                ? 'border-border bg-card-quiet'
                                : 'border-border bg-card-quiet'
                        "
                        data-test="status-stage"
                    >
                        <p class="text-sm text-muted-foreground">
                            Where it is now
                        </p>
                        <p class="text-lg font-semibold">
                            {{ application.stageLabel }}
                        </p>
                    </div>

                    <section
                        v-if="application.interview?.confirmed"
                        class="rounded-xl border border-border p-4"
                        aria-labelledby="interview-heading"
                        data-test="status-interview"
                    >
                        <h2
                            id="interview-heading"
                            class="text-base font-semibold"
                        >
                            Your interview
                        </h2>
                        <p class="mt-2 text-lg font-semibold">
                            {{ application.interview.confirmed }}
                        </p>
                        <div
                            v-if="
                                application.interview.online &&
                                application.interview.videoLink
                            "
                            class="mt-1 space-y-1 text-sm"
                            data-test="status-interview-online"
                        >
                            <p>Online. Join with this link:</p>
                            <a
                                :href="application.interview.videoLink"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="break-all underline underline-offset-2"
                                >{{ application.interview.videoLink }}</a
                            >
                        </div>
                        <p v-else class="text-sm">
                            {{ clinicName }} {{ application.interview.branch }}
                        </p>
                        <div
                            v-if="application.documents?.length"
                            class="mt-4 space-y-2 border-t border-border pt-3"
                            data-test="status-documents"
                        >
                            <h3 class="text-sm font-semibold">
                                Please read before your interview
                            </h3>
                            <ul class="space-y-2">
                                <li
                                    v-for="doc in application.documents"
                                    :key="doc.id"
                                >
                                    <a
                                        :href="`/careers/status/documents/${doc.id}`"
                                        class="inline-flex min-h-11 items-center gap-2 text-sm font-medium break-words text-brand-strong"
                                        ><span
                                            class="underline underline-offset-4"
                                            >{{ doc.title }}</span
                                        >
                                        <span
                                            class="font-normal text-muted-foreground"
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
                        </h2>
                        <template v-if="application.interview.picked?.length">
                            <p class="text-sm">
                                Your three times are with the clinic. They will
                                confirm one, or ask you for new times.
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
                                v-if="application.interview.chooseBy"
                                class="text-sm"
                                data-test="status-choose-by"
                            >
                                Choose by the end of
                                {{ application.interview.chooseBy }}. After
                                that, the application closes.
                            </p>
                            <p
                                v-if="errors.starts"
                                role="alert"
                                class="text-sm font-medium text-destructive"
                                data-test="status-slots-error"
                            >
                                {{ errors.starts }}
                            </p>
                            <p
                                v-if="application.interview.zone"
                                class="text-sm font-medium"
                                data-test="status-slots-zone"
                            >
                                {{ application.interview.zone }} time
                            </p>
                            <!-- One disclosure per day, the first open (Daniel, HR-17c). -->
                            <details
                                v-for="(day, i) in application.interview.days"
                                :key="day.date"
                                class="group rounded-lg border border-border"
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
                                        class="font-normal text-brand-strong"
                                        >· {{ chosenIn(day.slots) }} chosen /
                                        dipilih</span
                                    >
                                </summary>
                                <div class="flex flex-wrap gap-2 px-3 pb-3">
                                    <label
                                        v-for="slot in day.slots"
                                        :key="slot.start"
                                        class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-accent has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50"
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
                                    Your choices
                                </h3>
                                <ul class="space-y-2">
                                    <li
                                        v-for="start in chosen"
                                        :key="start"
                                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-3 py-1 text-sm"
                                    >
                                        {{ slotLabels[start] ?? start }}
                                        <button
                                            type="button"
                                            class="inline-flex min-h-11 items-center px-2 text-sm font-medium text-brand-strong underline underline-offset-4"
                                            :aria-label="`Remove ${slotLabels[start] ?? start}`"
                                            @click="toggle(start)"
                                        >
                                            Remove
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <div
                                class="sticky bottom-0 flex flex-wrap items-center gap-3 border-t border-border bg-card py-3"
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
                                    class="inline-flex min-h-11 items-center rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                    :disabled="chosen.length !== 3 || sending"
                                    data-test="status-slots-send"
                                    @click="sendPicks"
                                >
                                    {{
                                        chosen.length === 3
                                            ? 'Send my three times'
                                            : 'Choose 3 times'
                                    }}
                                </button>
                            </div>
                        </template>
                        <p v-else class="text-sm" data-test="status-slots-none">
                            The clinic will contact you to arrange a time.
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
                        </h2>
                        <p
                            v-if="!application.messages.length"
                            class="text-sm text-muted-foreground"
                        >
                            No messages yet.
                        </p>
                        <ol
                            v-else
                            class="space-y-3"
                            data-test="status-messages"
                        >
                            <li
                                v-for="(m, i) in application.messages"
                                :key="i"
                                class="rounded-xl border border-border p-4"
                            >
                                <p class="text-xs text-muted-foreground">
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
                        class="space-y-3 border-t border-border pt-5"
                        aria-labelledby="pool-heading"
                        data-test="status-pool"
                    >
                        <h2 id="pool-heading" class="text-base font-semibold">
                            Talent pool
                        </h2>
                        <p
                            v-if="application.pool.left"
                            class="text-sm"
                            data-test="status-pool-left"
                        >
                            You are not in the talent pool. The clinic will not
                            contact you about other openings.
                        </p>
                        <template v-else>
                            <p class="text-sm">
                                You asked the clinic to keep your details for
                                future openings of the same kind.
                            </p>
                            <p
                                v-if="errors.pool"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.pool }}
                            </p>
                            <button
                                v-if="!leavingPool"
                                type="button"
                                class="inline-flex min-h-11 items-center rounded-full border border-input px-4 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                data-test="status-pool-leave"
                                @click="leavingPool = true"
                            >
                                Remove me from the talent pool
                            </button>
                            <div
                                v-else
                                class="space-y-3 rounded-xl border border-input p-4"
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
                                </p>
                                <div class="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex min-h-11 items-center rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                        :disabled="poolBusy"
                                        data-test="status-pool-leave-yes"
                                        @click="leavePool"
                                    >
                                        Remove me
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex min-h-11 items-center rounded-full border border-input px-4 text-sm font-medium"
                                        @click="leavingPool = false"
                                    >
                                        Stay in it
                                    </button>
                                </div>
                            </div>
                        </template>
                    </section>

                    <section
                        v-if="application.open"
                        class="space-y-3 border-t border-border pt-5"
                    >
                        <p class="text-sm text-muted-foreground">
                            Tell us you are no longer applying. We keep your
                            application for now.
                        </p>
                        <p
                            v-if="errors.withdraw"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.withdraw }}
                        </p>
                        <button
                            v-if="!confirming"
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-full border border-input px-4 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            ref="withdrawButton"
                            data-test="status-withdraw"
                            @click="askWithdraw"
                        >
                            Withdraw my application
                        </button>
                        <div
                            v-else
                            class="space-y-3 rounded-xl border border-destructive bg-destructive/10 p-4"
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

                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-full bg-destructive px-4 text-sm font-medium text-primary-foreground hover:bg-destructive/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                    :disabled="withdrawing"
                                    data-test="status-withdraw-yes"
                                    @click="withdraw"
                                >
                                    Withdraw
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-full border border-input px-4 text-sm font-medium"
                                    @click="keepIt"
                                >
                                    Keep it
                                </button>
                            </div>
                        </div>
                    </section>
                    <section
                        v-if="application.canDelete"
                        class="space-y-3 border-t border-border pt-5"
                        data-test="status-delete"
                    >
                        <p class="text-sm text-muted-foreground">
                            Remove your application and the details you sent us,
                            including your CV, now.
                        </p>
                        <p
                            v-if="errors.erase"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.erase }}
                        </p>
                        <button
                            v-if="!askingDelete"
                            ref="deleteButton"
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-full border border-input px-4 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            data-test="status-delete-ask"
                            @click="askDelete"
                        >
                            Delete my application
                        </button>
                        <div
                            v-else
                            ref="deletePanel"
                            class="space-y-3 rounded-xl border border-destructive bg-destructive/10 p-4"
                            tabindex="-1"
                            role="group"
                            aria-labelledby="delete-confirm-title"
                            data-test="status-delete-confirm"
                            @keydown.esc="keepDetails"
                        >
                            <p
                                id="delete-confirm-title"
                                class="text-sm font-medium"
                            >
                                Delete your application?
                                {{
                                    application.open
                                        ? 'It is withdrawn first. '
                                        : ''
                                }}Your details and CV are removed, this link
                                stops working, and this cannot be undone.
                            </p>

                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-full bg-destructive px-4 text-sm font-medium text-primary-foreground hover:bg-destructive/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                                    :disabled="deleting"
                                    data-test="status-delete-yes"
                                    @click="erase"
                                >
                                    Delete
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-full border border-input px-4 text-sm font-medium"
                                    @click="keepDetails"
                                >
                                    Keep it
                                </button>
                            </div>
                        </div>
                    </section>
                </template>

                <template v-else>
                    <h1 class="text-xl font-semibold">Your application</h1>
                    <p
                        v-if="exchanging"
                        role="status"
                        class="text-sm text-muted-foreground"
                    >
                        Opening your application…
                    </p>
                    <p
                        v-else-if="erased"
                        role="status"
                        class="text-sm font-medium"
                        data-test="status-erased"
                    >
                        Your application and its details have been deleted.
                    </p>
                    <template v-else>
                        <p
                            v-if="errors.link"
                            role="alert"
                            class="text-sm font-medium text-destructive"
                            data-test="status-link-error"
                        >
                            {{ errors.link }}
                        </p>
                        <p class="text-sm">
                            Open the private link from your thank-you page or
                            from the clinic's email to see your application.
                        </p>

                        <a
                            href="/careers/apply"
                            class="inline-flex min-h-11 items-center text-sm font-medium text-brand-strong underline underline-offset-4"
                            data-test="status-apply"
                            >Apply for a job</a
                        >
                    </template>
                </template>
            </div>
        </section>
    </main>
</template>

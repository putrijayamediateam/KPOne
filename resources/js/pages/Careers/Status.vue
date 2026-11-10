<script setup lang="ts">
// HR-17b candidate status page (HR-PLAN 19.5). The private link carries its token in the URL fragment, which never
// reaches the server's logs; this page sends it once, the server swaps it for an httpOnly cookie for this page only,
// and the fragment is cleared from the address bar. The page shows the stage in plain words and the clinic's
// messages, never HR's rating, note or checklist, and nothing the candidate sent.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

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
                            data-test="status-withdraw"
                            @click="confirming = true"
                        >
                            Withdraw my application / Tarik balik permohonan
                        </button>
                        <div
                            v-else
                            class="space-y-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/30"
                            data-test="status-withdraw-confirm"
                        >
                            <p class="text-sm font-medium">
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
                                    @click="confirming = false"
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
                    </template>
                </template>
            </div>
        </section>
    </main>
</template>

<script setup lang="ts">
import { Copy, ExternalLink, Send } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ErrorState } from '@/components/ui/state';
import { Textarea } from '@/components/ui/textarea';
import {
    cancelAsk,
    CLAUDE_EXPIRED_MESSAGE,
    CLAUDE_LABEL,
    classifyError,
    copyPrompt,
    createAsk,
    getAskRequest,
    hasPending,
    newRequestKey,
    orderRequests,
    REMOVED_MESSAGE,
} from '@/lib/ask-kpone';
import type {
    AskClaudeRequest,
    AskClaudeState,
    AskError,
} from '@/lib/ask-kpone';
import { formatDateTime } from '@/lib/presentation';

// CHAT-2b: the "Ask through Claude" path of the Ask KPOne panel. The Director types a question, KPOne opens the Director's own
// Claude with it filled in, Claude answers and saves the answer back, and this panel polls and shows it under the question.
const props = defineProps<{ state: AskClaudeState; examples: string[] }>();

const requests = ref<AskClaudeRequest[]>(orderRequests(props.state.requests));
const text = ref('');
const sending = ref(false);
const error = ref<AskError | null>(null);
const lastSent = ref('');
const lastKey = ref('');
const copied = ref<string | null>(null);
const copyFailed = ref<string | null>(null);
const scroller = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const maxChars = computed(() => props.state.maxChars);
const canSend = computed(
    () =>
        !sending.value &&
        text.value.trim() !== '' &&
        text.value.length <= maxChars.value &&
        !(error.value !== null && text.value.trim() === lastSent.value),
);

const scrollDown = async () => {
    await nextTick();
    const box = scroller.value;

    if (!box) {
        return;
    }

    // While Claude is still working, show the newest question at the top of the area so it is never cut off and the waiting
    // card below it stays whole; otherwise show the latest content at the bottom.
    const questions = box.querySelectorAll('[data-ask-message="user"]');
    const last = questions[questions.length - 1];

    if (hasPending(requests.value) && last) {
        const gap =
            last.getBoundingClientRect().top - box.getBoundingClientRect().top;
        box.scrollTo({ top: Math.max(0, box.scrollTop + gap - 12) });

        return;
    }

    box.scrollTo({ top: box.scrollHeight });
};

const focusInput = async () => {
    await nextTick();
    document.getElementById('ask-claude-input')?.focus();
};

const replace = (fresh: AskClaudeRequest) => {
    const index = requests.value.findIndex(
        (request) => request.id === fresh.id,
    );

    if (index === -1) {
        requests.value.push(fresh);
    } else {
        requests.value[index] = fresh;
    }
};

// A pending request is checked every 3 seconds while this tab is visible; the answer, an expiry or a cancel ends the polling.
const poll = async () => {
    if (document.visibilityState !== 'visible') {
        return;
    }

    for (const request of requests.value.filter(
        (r) => r.status === 'pending',
    )) {
        try {
            const fresh = await getAskRequest(request.id);
            replace(fresh);

            if (fresh.status === 'answered') {
                await scrollDown();
            }
        } catch {
            // Keep what is shown; the next check tries again.
        }
    }

    if (!hasPending(requests.value) && timer !== null) {
        clearInterval(timer);
        timer = null;
    }
};

const startPolling = () => {
    if (timer === null && hasPending(requests.value)) {
        timer = setInterval(() => void poll(), 3000);
    }
};

onMounted(() => {
    startPolling();
    void scrollDown();
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

const send = async () => {
    if (!canSend.value) {
        return;
    }

    const body = text.value.trim();
    sending.value = true;
    error.value = null;

    if (body !== lastSent.value || lastKey.value === '') {
        lastKey.value = newRequestKey();
    }

    lastSent.value = body;

    try {
        replace(await createAsk(body, lastKey.value));
        text.value = '';
        lastSent.value = '';
        lastKey.value = '';
        startPolling();
        await scrollDown();
    } catch (caught) {
        error.value = classifyError(caught);
    } finally {
        sending.value = false;
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void send();
    }
};

const fill = (value: string) => {
    text.value = value;
    error.value = null;
    void focusInput();
};

const copyAndOpen = async (request: AskClaudeRequest) => {
    if (!request.prompt) {
        return;
    }

    const ok = await copyPrompt(request.prompt);
    copied.value = ok ? request.id : null;
    copyFailed.value = ok ? null : request.id;

    // The by-hand box makes the card taller; keep the question at the top of the area and the card whole.
    await scrollDown();

    if (!ok) {
        // Bring the by-hand box fully into view (on a phone the form below it takes much of the screen).
        await nextTick();
        document
            .getElementById(`ask-prompt-${request.id}`)
            ?.scrollIntoView({ block: 'nearest' });
    }

    if (ok && request.webLink) {
        window.open(request.webLink, '_blank', 'noopener');
    }
};

const selectAll = (event: Event) => {
    (event.target as HTMLTextAreaElement).select();
};

const cancel = async (request: AskClaudeRequest) => {
    try {
        replace(await cancelAsk(request.id));
    } catch {
        // The request may have been answered meanwhile; the next check shows it.
    }
};
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" data-ask-claude>
        <div
            ref="scroller"
            class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3"
            aria-live="polite"
        >
            <div v-if="requests.length === 0" class="space-y-2">
                <p class="text-sm text-muted-foreground">
                    Your question opens in your own Claude, which answers using
                    the KPOne connector and saves the answer here. Try one of
                    these:
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="example in examples"
                        :key="example"
                        type="button"
                        class="min-h-11 rounded-full border border-input bg-card px-3 py-1.5 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-8"
                        @click="fill(example)"
                    >
                        {{ example }}
                    </button>
                </div>
            </div>

            <template v-for="request in requests" :key="request.id">
                <div
                    class="flex justify-end"
                    data-ask-message="user"
                    :data-ask-id="request.id"
                >
                    <p
                        v-if="request.question !== null"
                        class="max-w-[85%] rounded-lg bg-muted px-3 py-2 text-sm break-words whitespace-pre-wrap text-foreground"
                    >
                        {{ request.question }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ REMOVED_MESSAGE }}
                    </p>
                </div>

                <div
                    v-if="request.status === 'pending'"
                    class="min-w-0 space-y-3 rounded-lg border border-border bg-card px-3 py-3 text-sm text-card-foreground"
                    data-ask-claude-pending
                >
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="flex items-center gap-2 font-medium"
                            role="status"
                        >
                            <Spinner /> Waiting for Claude
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-11 lg:min-h-8"
                            @click="cancel(request)"
                            >Cancel</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Waits up to {{ state.expiryMinutes }} minutes.
                    </p>
                    <ol
                        class="list-decimal space-y-1 pl-5 text-muted-foreground"
                    >
                        <li>Open Claude with your question filled in.</li>
                        <li>Press Enter in Claude.</li>
                        <li>The answer appears here when Claude saves it.</li>
                    </ol>
                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <Button
                            v-if="request.desktopLink"
                            as-child
                            variant="default"
                            class="min-h-11 lg:min-h-9"
                        >
                            <a :href="request.desktopLink" data-ask-desktop>
                                <ExternalLink aria-hidden="true" />
                                Open in Claude desktop
                            </a>
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            data-ask-copy
                            @click="copyAndOpen(request)"
                        >
                            <Copy aria-hidden="true" />
                            Copy the question and open Claude
                        </Button>
                    </div>
                    <p
                        v-if="copied === request.id"
                        class="text-xs text-muted-foreground"
                        role="status"
                    >
                        Copied. Paste it in Claude and press Enter.
                    </p>
                    <div v-if="copyFailed === request.id" class="space-y-1">
                        <label
                            :for="`ask-prompt-${request.id}`"
                            class="text-xs text-muted-foreground"
                            >Copy this by hand, then paste it in Claude at
                            claude.ai.</label
                        >
                        <Textarea
                            :id="`ask-prompt-${request.id}`"
                            class="min-h-14 text-xs"
                            readonly
                            :model-value="request.prompt"
                            @focus="selectAll"
                        />
                    </div>
                </div>

                <div
                    v-else-if="request.status === 'answered'"
                    class="min-w-0 space-y-2 rounded-lg border border-border bg-card px-3 py-3 text-sm text-card-foreground"
                    data-ask-claude-answer
                >
                    <p
                        v-if="request.answer !== null"
                        class="break-words whitespace-pre-wrap"
                    >
                        {{ request.answer }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ REMOVED_MESSAGE }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ request.label ?? CLAUDE_LABEL }}
                        <span v-if="request.answeredAt">
                            · {{ formatDateTime(request.answeredAt) }}</span
                        >
                    </p>
                </div>

                <div
                    v-else-if="request.status === 'expired'"
                    class="min-w-0 space-y-2 rounded-lg border border-border bg-muted px-3 py-3 text-sm text-foreground"
                    data-ask-claude-expired
                >
                    <p>{{ CLAUDE_EXPIRED_MESSAGE }}</p>
                    <Button
                        v-if="request.question !== null"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="min-h-11 lg:min-h-8"
                        @click="fill(request.question ?? '')"
                        >Ask again</Button
                    >
                </div>

                <p v-else class="text-xs text-muted-foreground">Cancelled.</p>
            </template>

            <ErrorState
                v-if="error && error.kind === 'retry'"
                title="Not started"
                :message="error.message"
            />
        </div>

        <form
            class="space-y-2 border-t border-border px-4 py-3"
            @submit.prevent="send()"
        >
            <label for="ask-claude-input" class="text-sm font-medium"
                >Ask a question about your branches</label
            >
            <p class="text-xs text-muted-foreground">
                Do not type patient names or identity numbers.
            </p>
            <p
                v-if="error && error.kind !== 'retry'"
                id="ask-claude-error"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ error.message }}
            </p>
            <Textarea
                id="ask-claude-input"
                v-model="text"
                class="min-h-16 lg:min-h-20"
                :aria-describedby="
                    error && error.kind !== 'retry'
                        ? 'ask-claude-error ask-claude-count'
                        : 'ask-claude-count'
                "
                :aria-invalid="
                    text.length > maxChars || error?.kind === 'personal_data'
                "
                @keydown="onKeydown"
            />
            <div class="flex items-center justify-between gap-2">
                <span
                    id="ask-claude-count"
                    class="text-xs tabular-nums"
                    :class="
                        text.length > maxChars
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                    >{{ text.length }} / {{ maxChars }}</span
                >
                <Button
                    type="submit"
                    class="min-h-11 px-5 lg:min-h-9"
                    :disabled="!canSend"
                >
                    <Send aria-hidden="true" />
                    Ask through Claude
                </Button>
            </div>
        </form>
    </div>
</template>

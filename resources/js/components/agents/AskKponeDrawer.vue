<script setup lang="ts">
import { ArrowLeft, Ellipsis, History, MessageCircle, Send } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import AskKponeMessage from '@/components/agents/AskKponeMessage.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ErrorState } from '@/components/ui/state';
import { Textarea } from '@/components/ui/textarea';
import {
    archiveThread,
    classifyError,
    getChat,
    getOtherThread,
    getOthers,
    getThread,
    newRequestKey,
    sendMessage,
} from '@/lib/ask-kpone';
import type {
    AskChatState,
    AskError,
    AskMessage,
    AskOtherThread,
    AskThreadSummary,
} from '@/lib/ask-kpone';
import { formatDateTime } from '@/lib/presentation';

// CHAT-1 slice 1: the "Ask KPOne" button and right-hand drawer. Renders nothing unless GET /agents/chat succeeds
// (404 switched off, 403 not permitted). Not mounted on any page here.
type View = 'chat' | 'history' | 'others';

const state = ref<AskChatState | null>(null);
const open = ref(false);
const view = ref<View>('chat');
const scroller = ref<HTMLElement | null>(null);

const messages = ref<AskMessage[]>([]);
const threadId = ref<number | null>(null);
const readOnly = ref(false);
const viewingOther = ref(false);
const text = ref('');
const sending = ref(false);
const loading = ref(false);
const loadError = ref('');
const error = ref<AskError | null>(null);
const lastSent = ref('');
const lastKey = ref('');
const threads = ref<AskThreadSummary[]>([]);
const others = ref<AskOtherThread[]>([]);
const switchedOff = ref(false);
let localId = 0;

const maxChars = computed(() => state.value?.maxChars ?? 1000);
const limited = computed(
    () =>
        state.value?.limitReached === true ||
        error.value?.kind === 'limit_reached',
);
const resetsOn = computed(() => state.value?.resetsOn ?? '');
const limitText = computed(() =>
    error.value?.kind === 'limit_reached'
        ? error.value.message
        : `The monthly AI limit is reached. It resets on ${resetsOn.value}.`,
);
// While a refusal for this very text is showing (for example identity numbers), Send stays off until the text is changed.
const canSend = computed(
    () =>
        !sending.value &&
        text.value.trim() !== '' &&
        text.value.length <= maxChars.value &&
        !(
            showError.value &&
            error.value?.kind !== 'retry' &&
            text.value.trim() === lastSent.value
        ),
);
const showError = computed(
    () => error.value !== null && error.value.kind !== 'limit_reached',
);
const hasInput = computed(
    () => !readOnly.value && !limited.value && !switchedOff.value,
);

onMounted(async () => {
    try {
        state.value = await getChat();
        threads.value = state.value.threads;
    } catch {
        state.value = null;
    }
});

const scrollDown = async () => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
};

// After an answer, show it from the question down (not from its tail), so the lead sentence stays in view.
const scrollToMessage = async (id: string | number) => {
    await nextTick();
    const box = scroller.value;
    const target = box?.querySelector<HTMLElement>(`[data-ask-id="${id}"]`);

    if (!box) {
        return;
    }

    if (!target) {
        box.scrollTo({ top: box.scrollHeight });

        return;
    }

    box.scrollTo({
        top:
            target.getBoundingClientRect().top -
            box.getBoundingClientRect().top +
            box.scrollTop -
            8,
    });
};

const focusInput = async () => {
    await nextTick();
    document.getElementById('ask-kpone-input')?.focus();
};

const onOpenAutoFocus = (event: Event) => {
    event.preventDefault();
    void focusInput();
};

// The sheet is not modal, so focus is returned by hand to the header button.
const onCloseAutoFocus = (event: Event) => {
    event.preventDefault();
    document.getElementById('ask-kpone-trigger')?.focus();
};

const toggle = () => {
    open.value = !open.value;
};

const resetChat = () => {
    messages.value = [];
    threadId.value = null;
    readOnly.value = false;
    viewingOther.value = false;
    error.value = null;
    loadError.value = '';
    text.value = '';
    view.value = 'chat';
    void focusInput();
};

const refreshThreads = async () => {
    try {
        const fresh = await getChat();
        state.value = fresh;
        threads.value = fresh.threads;
    } catch {
        // The list is a convenience; keep what is shown.
    }
};

const fill = (example: string) => {
    text.value = example;
    void focusInput();
};

const send = async (retry = false) => {
    if (sending.value) {
        return;
    }

    const body = retry ? lastSent.value : text.value.trim();

    if (body === '' || body.length > maxChars.value) {
        return;
    }

    sending.value = true;
    error.value = null;
    lastSent.value = body;

    if (!retry || lastKey.value === '') {
        lastKey.value = newRequestKey();
    }

    const pending: AskMessage = {
        id: `local-${++localId}`,
        role: 'user',
        text: body,
        purged: false,
        figures: [],
        report: null,
    };

    if (!retry) {
        messages.value.push(pending);
        text.value = '';
    }

    void scrollDown();

    let answered = false;

    try {
        const answer = await sendMessage(body, threadId.value, lastKey.value);
        threadId.value = answer.thread.id;
        messages.value.push(answer.message);
        void refreshThreads();
        answered = true;
    } catch (caught) {
        const failure = classifyError(caught);
        error.value = failure;

        if (failure.kind === 'off') {
            switchedOff.value = true;
        }

        // A refused question was never accepted: take the bubble back and keep the typed text in the box.
        if (failure.kind !== 'retry' && !retry) {
            messages.value = messages.value.filter((m) => m.id !== pending.id);
            text.value = body;
        } else if (failure.kind !== 'retry') {
            text.value = body;
        }
    } finally {
        sending.value = false;
        void (answered ? scrollToMessage(pending.id) : scrollDown());
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();

        if (canSend.value) {
            void send();
        }
    }
};

const showHistory = () => {
    view.value = 'history';
    error.value = null;
};

const loadInto = async (
    fetcher: () => ReturnType<typeof getThread>,
    other: boolean,
) => {
    loading.value = true;
    loadError.value = '';

    try {
        const result = await fetcher();
        messages.value = result.messages;
        threadId.value = result.thread.id;
        readOnly.value = true;
        viewingOther.value = other;
        error.value = null;
        view.value = 'chat';
        void scrollDown();
    } catch {
        loadError.value = "Couldn't open that conversation. Try again.";
    } finally {
        loading.value = false;
    }
};

const openThread = (id: number) => loadInto(() => getThread(id), false);
const openOther = (id: number) => loadInto(() => getOtherThread(id), true);

const showOthers = async () => {
    view.value = 'others';
    loading.value = true;
    loadError.value = '';

    try {
        others.value = (await getOthers()).threads;
    } catch {
        loadError.value = "Couldn't load the list. Try again.";
    } finally {
        loading.value = false;
    }
};

const archive = async (id: number) => {
    try {
        await archiveThread(id);
        threads.value = threads.value.filter((t) => t.id !== id);
    } catch {
        loadError.value = "Couldn't archive that conversation. Try again.";
    }
};
</script>

<template>
    <div v-if="state" data-ask-kpone>
        <Button
            id="ask-kpone-trigger"
            type="button"
            variant="outline"
            class="min-h-11 lg:min-h-9"
            :aria-expanded="open"
            aria-haspopup="dialog"
            @click="toggle"
        >
            <MessageCircle aria-hidden="true" />
            Ask KPOne
        </Button>

        <Sheet v-model:open="open" :modal="false">
            <SheetContent
                side="right"
                class="w-full gap-0 sm:max-w-md"
                @open-auto-focus="onOpenAutoFocus"
                @close-auto-focus="onCloseAutoFocus"
                @interact-outside.prevent
            >
                <SheetHeader class="gap-1 border-b border-border pr-12">
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="view !== 'chat'"
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-11 lg:size-9"
                            aria-label="Back to the chat"
                            @click="view = 'chat'"
                        >
                            <ArrowLeft aria-hidden="true" />
                        </Button>
                        <SheetTitle class="flex-1">Ask KPOne</SheetTitle>
                        <Button
                            v-if="view === 'chat'"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="min-h-11 lg:min-h-8"
                            @click="showHistory"
                        >
                            <History aria-hidden="true" />
                            History
                        </Button>
                        <DropdownMenu v-else-if="state.canViewOthers">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="size-11 lg:size-9"
                                    aria-label="More history options"
                                >
                                    <Ellipsis aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    class="min-h-11 lg:min-h-8"
                                    @select="showOthers"
                                >
                                    View other people's history
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <SheetDescription>
                        Ask about your branches. Answers come from KPOne's
                        reports.
                    </SheetDescription>
                </SheetHeader>

                <!-- History -->
                <div
                    v-if="view === 'history'"
                    class="min-h-0 flex-1 overflow-y-auto px-4 py-3"
                >
                    <h2 class="mb-2 text-sm font-semibold">History</h2>
                    <ErrorState v-if="loadError" :message="loadError" />
                    <p
                        v-if="threads.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No earlier questions yet.
                    </p>
                    <ul class="divide-y divide-border">
                        <li
                            v-for="t in threads"
                            :key="t.id"
                            class="flex items-center gap-2"
                        >
                            <button
                                type="button"
                                class="min-h-11 min-w-0 flex-1 py-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                @click="openThread(t.id)"
                            >
                                <span class="block truncate text-sm">{{
                                    t.title ?? 'Removed after 90 days.'
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ formatDateTime(t.date) }}</span
                                >
                            </button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="min-h-11 lg:min-h-8"
                                :aria-label="`Archive: ${t.title ?? 'removed question'}`"
                                @click="archive(t.id)"
                                >Archive</Button
                            >
                        </li>
                    </ul>
                </div>

                <!-- Other people's history (names and dates only) -->
                <div
                    v-else-if="view === 'others'"
                    class="min-h-0 flex-1 overflow-y-auto px-4 py-3"
                >
                    <h2 class="mb-2 text-sm font-semibold">
                        Other people's history
                    </h2>
                    <p class="mb-2 text-xs text-muted-foreground">
                        Every view is recorded.
                    </p>
                    <ErrorState v-if="loadError" :message="loadError" />
                    <div
                        v-if="loading"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                        role="status"
                    >
                        <Spinner /> Loading...
                    </div>
                    <p
                        v-else-if="others.length === 0 && !loadError"
                        class="text-sm text-muted-foreground"
                    >
                        Nothing to show.
                    </p>
                    <ul class="divide-y divide-border">
                        <li v-for="o in others" :key="o.id">
                            <button
                                type="button"
                                class="min-h-11 w-full py-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                @click="openOther(o.id)"
                            >
                                <span class="block truncate text-sm">{{
                                    o.person
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ formatDateTime(o.date) }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Chat -->
                <template v-else>
                    <div
                        v-if="viewingOther"
                        class="border-b border-border bg-muted px-4 py-2 text-xs text-foreground"
                        role="note"
                    >
                        You are viewing someone else's questions. Every view is
                        recorded.
                    </div>
                    <div
                        v-if="readOnly"
                        class="border-b border-border px-4 py-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="min-h-11 lg:min-h-8"
                            @click="resetChat"
                            >Ask a new question</Button
                        >
                    </div>

                    <div
                        ref="scroller"
                        class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3"
                        aria-live="polite"
                    >
                        <div
                            v-if="limited && !readOnly"
                            class="rounded-lg border border-border bg-muted px-3 py-3 text-sm text-foreground"
                            role="status"
                            data-ask-limit
                        >
                            {{ limitText }}
                        </div>
                        <p
                            v-if="switchedOff"
                            class="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground"
                        >
                            Ask KPOne is switched off.
                        </p>
                        <ErrorState v-if="loadError" :message="loadError" />
                        <div
                            v-if="loading"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            <Spinner /> Loading...
                        </div>

                        <div
                            v-if="messages.length === 0 && !loading && hasInput"
                            class="space-y-2"
                        >
                            <p class="text-sm text-muted-foreground">
                                Try one of these:
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="example in state.examples"
                                    :key="example"
                                    type="button"
                                    class="min-h-11 rounded-full border border-input bg-card px-3 py-1.5 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-8"
                                    @click="fill(example)"
                                >
                                    {{ example }}
                                </button>
                            </div>
                        </div>

                        <AskKponeMessage
                            v-for="m in messages"
                            :key="m.id"
                            :message="m"
                        />

                        <p
                            v-if="sending"
                            class="text-xs text-muted-foreground"
                            role="status"
                        >
                            Working on it...
                        </p>

                        <ErrorState
                            v-if="showError && error?.kind === 'retry'"
                            title="Not answered"
                            :message="error.message"
                        >
                            <template #action>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="min-h-11 lg:min-h-8"
                                    :disabled="sending"
                                    @click="send(true)"
                                    >Try again</Button
                                >
                            </template>
                        </ErrorState>
                    </div>

                    <form
                        v-if="hasInput"
                        class="space-y-2 border-t border-border px-4 py-3"
                        @submit.prevent="send()"
                    >
                        <label for="ask-kpone-input" class="text-sm font-medium"
                            >Ask a question about your branches</label
                        >
                        <p
                            v-if="showError && error && error.kind !== 'retry'"
                            id="ask-kpone-error"
                            class="text-sm text-destructive"
                            role="alert"
                        >
                            {{ error.message }}
                        </p>
                        <Textarea
                            id="ask-kpone-input"
                            v-model="text"
                            class="min-h-20"
                            :aria-describedby="
                                showError && error?.kind !== 'retry'
                                    ? 'ask-kpone-error ask-kpone-count'
                                    : 'ask-kpone-count'
                            "
                            :aria-invalid="
                                text.length > maxChars ||
                                error?.kind === 'personal_data'
                            "
                            @keydown="onKeydown"
                        />
                        <div class="flex items-center justify-between gap-2">
                            <span
                                id="ask-kpone-count"
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
                                Send
                            </Button>
                        </div>
                    </form>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>

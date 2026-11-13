<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type {
    FeedAgent,
    Light,
    OfficeFeed,
} from '@/components/agents/office/office-layout';
import {
    LIGHT_COLOURS,
    LIGHT_LABELS,
    recentMessages,
} from '@/components/agents/office/office-layout';
import OfficeScene from '@/components/agents/office/OfficeScene.vue';
import { PageHeader } from '@/components/ui/page-header';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status';
import { useScrollStrip } from '@/composables/useScrollStrip';
import { useUiVersion } from '@/composables/useUiVersion';
import { requestJson } from '@/lib/json-client';
import { formatDateTime } from '@/lib/presentation';

type AgentDetail = {
    code: string;
    displayName: string;
    jobTitle: string;
    department: string | null;
    isEnabled: boolean;
    steps: { kind: string; summary: string; at: string | null }[];
    pendingTotal: number;
    pendingBranch: string | null;
    pending: {
        id: number;
        typeLabel: string;
        createdAt: string | null;
    }[];
};

const props = defineProps<{
    feed: OfficeFeed;
    canOpenInbox: boolean;
}>();

const POLL_MS = 10_000;

const feed = ref<OfficeFeed>(props.feed);
const paused = ref(false);

// "Pause motion" stops the scene's movement (never the live data). A person who has asked their device for reduced motion
// already gets a still scene from the CSS, so the button is not offered to them; the choice is kept in this browser only.
const MOTION_KEY = 'kpone.agents.office.motion';
const osReducedMotion =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const readMotionOff = (): boolean => {
    try {
        return window.localStorage.getItem(MOTION_KEY) === 'off';
    } catch {
        return false;
    }
};
const motionOff = ref(readMotionOff());
const toggleMotion = () => {
    motionOff.value = !motionOff.value;

    try {
        window.localStorage.setItem(MOTION_KEY, motionOff.value ? 'off' : 'on');
    } catch {
        // The choice then lasts only for this visit.
    }
};
const clock = ref(new Date());
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
let disposed = false;

const schedule = () => {
    clearTimeout(timer);

    if (!disposed && !document.hidden) {
        timer = setTimeout(() => void refresh(), POLL_MS);
    }
};
const refresh = async () => {
    if (disposed) {
        return;
    }

    controller?.abort();
    controller = new AbortController();

    try {
        feed.value = await requestJson<OfficeFeed>('/agents/office-feed', {
            signal: controller.signal,
        });
        paused.value = false;
        clock.value = new Date();
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            paused.value = true;
        }
    } finally {
        schedule();
    }
};
const visibilityChanged = () => {
    if (document.hidden) {
        clearTimeout(timer);
        controller?.abort();
    } else {
        void refresh();
    }
};
onMounted(() => {
    document.addEventListener('visibilitychange', visibilityChanged);
    schedule();
});
onBeforeUnmount(() => {
    disposed = true;
    clearTimeout(timer);
    controller?.abort();
    detailController?.abort();
    document.removeEventListener('visibilitychange', visibilityChanged);
    desktopQuery?.removeEventListener('change', desktopChanged);
});

const selected = ref<string | null>(null);
const detail = ref<AgentDetail | null>(null);
const detailError = ref('');
const selectedAgent = computed<FeedAgent | null>(
    () =>
        feed.value.agents.find((agent) => agent.code === selected.value) ??
        null,
);
// Only the newest detail request may update the panel; older ones are aborted.
let detailController: AbortController | undefined;
const loadDetail = async (code: string) => {
    detailController?.abort();
    const current = new AbortController();
    detailController = current;
    detailError.value = '';

    try {
        const result = await requestJson<AgentDetail>(
            `/agents/office/agents/${encodeURIComponent(code)}`,
            { signal: current.signal },
        );

        if (detailController === current && selected.value === code) {
            detail.value = result;
        }
    } catch (error) {
        if (
            (error as Error).name !== 'AbortError' &&
            detailController === current &&
            selected.value === code
        ) {
            detailError.value = 'Details are unavailable right now.';
        }
    }
};
const select = (code: string) => {
    selected.value = code;
    detail.value = null;
    void loadDetail(code);
};
const closePanel = () => {
    detailController?.abort();
    selected.value = null;
    detail.value = null;
};
// Keep the open panel current as the feed refreshes.
watch(
    () => feed.value.serverTime,
    () => {
        if (selected.value) {
            void loadDetail(selected.value);
        }
    },
);

const openInbox = () => {
    if (props.canOpenInbox) {
        router.visit('/agents/inbox');
    }
};

const lightFor = (agent: FeedAgent): Light =>
    agent.isEnabled ? agent.light : 'grey';
const statusFor = (agent: FeedAgent) =>
    agent.isEnabled ? LIGHT_LABELS[agent.light] : 'Off';
const latestMessage = computed(() => recentMessages(feed.value));
const counts = computed(() => {
    const on = feed.value.agents.filter((agent) => agent.isEnabled);

    return {
        working: on.filter((agent) => agent.light === 'green').length,
        approval: on.filter((agent) => agent.light === 'yellow').length,
        failed: on.filter((agent) => agent.light === 'red').length,
        off: feed.value.agents.length - on.length,
        waiting: feed.value.pendingProposals,
    };
});
const agentName = (code: string | null): string =>
    feed.value.agents.find((agent) => agent.code === code)?.displayName ??
    'An agent';
const waitingAgents = computed(() =>
    feed.value.agents.filter((agent) => agent.pendingProposals > 0),
);
const activity = computed(() => feed.value.messages.slice(0, 6));
const panelTitleId = 'office-panel-title';
const closeButton = ref<HTMLButtonElement | null>(null);
// On a phone the office scrolls sideways: its right edge fades while more of it is hidden there.
const sceneScroller = ref<HTMLElement | null>(null);
const { measure: measureScene, fadeStyle: sceneFade } = useScrollStrip(
    sceneScroller,
    '[data-office-start]',
);
watch(selected, async (code) => {
    if (code) {
        await nextTick();
        closeButton.value?.focus();
    }
});

// Classic (the default look) keeps its sheet, which needs the desktop test and the open flag; Version 2 uses its
// own side panel (closePanel above).
const { isV2 } = useUiVersion();

// Version 2 only: say a step in plain words ("Read stock counts for Sungai Besi"), never a tool code or branch code.
const plainStep = (summary: string): string => {
    const readMatch = /^Read ([a-z_]+)\.([a-z_]+)(?: for ([A-Z_]+))?$/.exec(
        summary,
    );

    if (readMatch === null) {
        return summary.replace(
            /\b[A-Z]{2,}(?:_[A-Z]+)+\b|\b[A-Z]{3,}(?=:)/g,
            (code) => titleCase(code),
        );
    }

    const what = readMatch[2].replace(/_/g, ' ');

    return `Read ${what}${readMatch[3] ? ` for ${titleCase(readMatch[3])}` : ''}`;
};
const titleCase = (code: string): string =>
    code
        .toLowerCase()
        .split('_')
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
const panelOpen = computed({
    get: () => selected.value !== null,
    set: (value: boolean) => {
        if (!value) {
            closePanel();
        }
    },
});
const isDesktop = ref(true);
let desktopQuery: MediaQueryList | undefined;
const desktopChanged = () => {
    isDesktop.value = desktopQuery?.matches ?? true;
};
onMounted(() => {
    desktopQuery = window.matchMedia('(min-width: 768px)');
    desktopChanged();
    desktopQuery.addEventListener('change', desktopChanged);
});
</script>

<template>
    <template v-if="isV2">
        <Head title="Agent office" />
        <main
            class="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-5 px-4 py-6 md:px-6"
        >
            <PageHeader
                title="Agent office"
                description="Where the AI agents work. Click an agent to see what it is doing."
            />
            <p
                class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm text-foreground"
                data-test="office-notice"
            >
                Everyone in this office is an AI agent, not a member of staff.
                Agents only suggest; people decide in the approval inbox.
            </p>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span
                    class="inline-flex min-h-8 items-center gap-2 rounded-full border px-3 py-1"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.green }"
                    />
                    {{ counts.working }} working
                </span>
                <span
                    v-if="counts.approval > 0"
                    class="inline-flex min-h-8 items-center gap-2 rounded-full border px-3 py-1"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.yellow }"
                    />
                    {{ counts.approval }} waiting for approval
                </span>
                <span
                    v-if="counts.failed > 0"
                    class="inline-flex min-h-8 items-center gap-2 rounded-full border px-3 py-1"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.red }"
                    />
                    {{ counts.failed }} last run failed
                </span>
                <span
                    v-if="counts.off > 0"
                    class="inline-flex min-h-8 items-center gap-2 rounded-full border px-3 py-1"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.grey }"
                    />
                    {{ counts.off }} off
                </span>
                <component
                    :is="canOpenInbox ? Link : 'span'"
                    :href="canOpenInbox ? '/agents/inbox' : undefined"
                    class="inline-flex min-h-8 items-center gap-2 rounded-full border px-3 py-1"
                    data-test="office-pending"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.yellow }"
                    />
                    {{ counts.waiting }} waiting for a decision
                </component>
                <span
                    v-if="paused"
                    class="rounded-full border border-warning px-3 py-1 text-warning"
                    data-test="office-paused"
                    role="status"
                >
                    Live updates paused. Showing the last view.
                </span>
            </div>

            <div
                class="grid items-start gap-5 xl:grid-cols-[300px_minmax(0,1fr)_260px]"
            >
                <section
                    aria-labelledby="office-agents-heading"
                    class="order-2 grid min-w-0 gap-3 xl:order-1"
                >
                    <h2
                        id="office-agents-heading"
                        class="text-lg font-semibold"
                    >
                        Agents
                    </h2>
                    <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                        <li
                            v-for="agent in feed.agents"
                            :key="agent.code"
                            class="min-w-0"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 w-full min-w-0 flex-col gap-1.5 rounded-[14px] border bg-card p-3.5 text-left hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"
                                :class="{
                                    'opacity-65': !agent.isEnabled,
                                    'border-primary': selected === agent.code,
                                }"
                                :data-test="`office-card-${agent.code}`"
                                @click="select(agent.code)"
                            >
                                <span
                                    class="flex min-w-0 flex-wrap items-center gap-2"
                                >
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{
                                            background:
                                                LIGHT_COLOURS[lightFor(agent)],
                                        }"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="min-w-0 truncate font-medium"
                                        >{{ agent.displayName }}</span
                                    >
                                    <StatusBadge
                                        status="ai_agent"
                                        label="AI agent"
                                        tone="accent"
                                    />
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ agent.jobTitle }} ·
                                    {{ statusFor(agent) }}
                                </span>
                                <span
                                    class="line-clamp-3 text-sm"
                                    :title="
                                        agent.currentTask ??
                                        agent.lastStep ??
                                        undefined
                                    "
                                >
                                    {{
                                        plainStep(
                                            agent.currentTask ??
                                                agent.lastStep ??
                                                'Nothing to do right now.',
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="latestMessage.get(agent.code)"
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    “{{
                                        latestMessage.get(agent.code)?.summary
                                    }}”
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>

                <div class="relative order-1 min-w-0 xl:order-2">
                    <div
                        class="dark overflow-hidden rounded-[18px] border border-slate-400/15 bg-[linear-gradient(165deg,#0e1526,#070b14)] p-3 text-slate-200 sm:p-4"
                        style="color-scheme: dark"
                    >
                        <div
                            class="mb-2 flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <span
                                class="text-xs tracking-[0.14em] text-[#8d9bb3] uppercase"
                            >
                                Studio floor · live
                            </span>
                            <button
                                v-if="!osReducedMotion"
                                type="button"
                                class="inline-flex min-h-11 items-center rounded-[10px] border border-slate-400/30 px-3 text-xs font-medium text-slate-200 outline-none hover:bg-slate-400/10 focus-visible:ring-2 focus-visible:ring-ring aria-pressed:border-sky-300/60 aria-pressed:bg-sky-300/15 aria-pressed:font-semibold lg:min-h-9"
                                :aria-pressed="motionOff"
                                data-test="office-motion"
                                @click="toggleMotion"
                            >
                                <span
                                    v-if="motionOff"
                                    aria-hidden="true"
                                    class="mr-1"
                                    >✓</span
                                >Pause motion
                            </button>
                            <span class="text-xs text-[#8d9bb3]">
                                <span class="md:hidden"
                                    >Scroll sideways to see the whole office. </span
                                >Click an agent for details
                            </span>
                        </div>
                        <div
                            ref="sceneScroller"
                            class="overflow-x-auto"
                            :style="sceneFade"
                            data-test="office-scroll"
                            @scroll.passive="measureScene"
                        >
                            <OfficeScene
                                :feed="feed"
                                :selected="selected"
                                :clock="clock"
                                :can-open-inbox="canOpenInbox"
                                :motion-off="motionOff"
                                @select="select"
                                @open-inbox="openInbox"
                            />
                        </div>
                        <ul
                            class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#8d9bb3]"
                            aria-label="What the lights mean"
                        >
                            <li
                                v-for="light in [
                                    'green',
                                    'yellow',
                                    'red',
                                    'grey',
                                ] as Light[]"
                                :key="light"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :style="{
                                        background: LIGHT_COLOURS[light],
                                    }"
                                    aria-hidden="true"
                                />
                                {{ LIGHT_LABELS[light] }}
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="selected"
                        class="fixed inset-0 z-40 bg-black/45 lg:hidden"
                        aria-hidden="true"
                        @click="closePanel"
                    />
                    <aside
                        v-if="selected"
                        class="fixed inset-x-0 top-40 bottom-0 z-50 overflow-y-auto rounded-t-[18px] border bg-popover pb-[env(safe-area-inset-bottom)] text-popover-foreground shadow-2xl lg:absolute lg:inset-x-auto lg:top-4 lg:right-4 lg:bottom-4 lg:z-10 lg:w-[min(380px,calc(100%-2rem))] lg:rounded-[18px] lg:pb-0"
                        role="dialog"
                        :aria-labelledby="panelTitleId"
                        data-test="office-panel"
                        @keydown.esc="closePanel"
                    >
                        <div
                            class="flex items-center justify-between px-3 pt-2.5"
                        >
                            <span
                                class="mx-auto h-1.5 w-11 rounded-full bg-border lg:hidden"
                                aria-hidden="true"
                            />
                            <button
                                ref="closeButton"
                                type="button"
                                class="ml-auto flex size-11 items-center justify-center rounded-lg text-2xl leading-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-primary lg:size-9"
                                aria-label="Close"
                                @click="closePanel"
                            >
                                ×
                            </button>
                        </div>
                        <div
                            v-if="selectedAgent"
                            class="grid gap-5 px-5 pb-5 text-sm"
                        >
                            <div class="grid gap-1">
                                <h2
                                    :id="panelTitleId"
                                    class="flex flex-wrap items-center gap-2 text-xl font-semibold"
                                >
                                    {{ selectedAgent.displayName }}
                                    <StatusBadge
                                        status="ai_agent"
                                        label="AI agent"
                                        tone="accent"
                                    />
                                </h2>
                                <p class="text-muted-foreground">
                                    {{ selectedAgent.jobTitle }}
                                    <template v-if="selectedAgent.department">
                                        · {{ selectedAgent.department }}
                                    </template>
                                </p>
                            </div>

                            <dl class="grid gap-2">
                                <div class="flex flex-wrap gap-2">
                                    <dt class="text-muted-foreground">
                                        Status:
                                    </dt>
                                    <dd class="flex items-center gap-2">
                                        <span
                                            class="size-2.5 rounded-full"
                                            :style="{
                                                background:
                                                    LIGHT_COLOURS[
                                                        lightFor(selectedAgent)
                                                    ],
                                            }"
                                            aria-hidden="true"
                                        />
                                        {{ statusFor(selectedAgent) }}
                                    </dd>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <dt class="text-muted-foreground">
                                        Doing now:
                                    </dt>
                                    <dd>
                                        {{
                                            selectedAgent.currentTask ??
                                            'Nothing right now'
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <p
                                v-if="detailError"
                                class="text-destructive"
                                role="alert"
                            >
                                {{ detailError }}
                            </p>

                            <section class="grid gap-2">
                                <h3 class="font-semibold">Last steps</h3>
                                <p
                                    v-if="detail && detail.steps.length === 0"
                                    class="text-muted-foreground"
                                >
                                    No steps recorded yet.
                                </p>
                                <ol
                                    v-else-if="detail"
                                    class="grid gap-2"
                                    data-test="office-steps"
                                >
                                    <li
                                        v-for="(step, index) in detail.steps"
                                        :key="index"
                                        class="rounded-[14px] border p-2.5"
                                    >
                                        <p
                                            class="break-words"
                                            :title="step.summary"
                                        >
                                            {{ plainStep(step.summary) }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatDateTime(step.at) }}
                                        </p>
                                    </li>
                                </ol>
                                <p
                                    v-else-if="!detailError"
                                    class="text-muted-foreground"
                                >
                                    Loading…
                                </p>
                            </section>

                            <section v-if="detail" class="grid gap-2">
                                <!-- prettier-ignore -->
                                <h3 class="font-semibold">Waiting for a decision ({{ detail.pendingTotal }})</h3>
                                <p
                                    v-if="detail.pendingTotal === 0"
                                    class="text-muted-foreground"
                                >
                                    Nothing waiting.
                                </p>
                                <p
                                    v-else-if="detail.pendingBranch === null"
                                    class="text-muted-foreground"
                                >
                                    The details are in the approval inbox, for
                                    the people who decide them.
                                </p>
                                <p
                                    v-else-if="detail.pending.length === 0"
                                    class="text-muted-foreground"
                                >
                                    None at {{ detail.pendingBranch }}; they are
                                    at other branches.
                                </p>
                                <ul
                                    v-else
                                    class="grid gap-2"
                                    data-test="office-pending-list"
                                >
                                    <li
                                        v-for="proposal in detail.pending"
                                        :key="proposal.id"
                                        class="rounded-[14px] border p-2.5"
                                    >
                                        <p class="font-medium">
                                            {{ proposal.typeLabel }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ detail.pendingBranch }} ·
                                            {{
                                                formatDateTime(
                                                    proposal.createdAt,
                                                )
                                            }}
                                        </p>
                                    </li>
                                </ul>
                                <Link
                                    v-if="
                                        canOpenInbox && detail.pendingTotal > 0
                                    "
                                    href="/agents/inbox"
                                    class="inline-flex min-h-11 items-center font-medium text-primary underline"
                                >
                                    Open the approval inbox
                                </Link>
                            </section>

                            <p class="text-xs text-muted-foreground">
                                Read only. Counts and plain wording, never
                                patient details.
                            </p>
                        </div>
                    </aside>
                </div>

                <div
                    class="order-3 grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-1"
                >
                    <section
                        class="overflow-hidden rounded-[14px] border bg-card"
                        aria-labelledby="office-waiting-heading"
                        data-test="office-waiting"
                    >
                        <div class="grid gap-0.5 p-4">
                            <h2
                                id="office-waiting-heading"
                                class="text-lg font-semibold"
                            >
                                Waiting for a decision
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Agents only suggest. A person approves each one.
                            </p>
                        </div>
                        <ul v-if="waitingAgents.length > 0">
                            <li
                                v-for="agent in waitingAgents"
                                :key="agent.code"
                                class="flex min-h-14 items-center justify-between gap-3 border-t px-4 py-2"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">
                                        {{ agent.displayName }}
                                    </span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ agent.jobTitle }}
                                    </span>
                                </span>
                                <StatusBadge
                                    status="waiting"
                                    :label="`${agent.pendingProposals} waiting`"
                                    tone="warning"
                                />
                            </li>
                        </ul>
                        <p
                            v-else
                            class="border-t px-4 py-3 text-sm text-muted-foreground"
                        >
                            Nothing waiting.
                        </p>
                        <div v-if="canOpenInbox" class="border-t px-4">
                            <Link
                                href="/agents/inbox"
                                class="inline-flex min-h-11 items-center text-sm font-medium text-primary underline"
                            >
                                Open the approval inbox
                            </Link>
                        </div>
                    </section>

                    <section
                        class="overflow-hidden rounded-[14px] border bg-card"
                        aria-labelledby="office-activity-heading"
                        data-test="office-activity"
                    >
                        <div class="p-4">
                            <h2
                                id="office-activity-heading"
                                class="text-lg font-semibold"
                            >
                                Recent activity
                            </h2>
                        </div>
                        <ul v-if="activity.length > 0">
                            <li
                                v-for="(message, index) in activity"
                                :key="index"
                                class="grid min-h-14 gap-0.5 border-t px-4 py-2 text-sm"
                            >
                                <span class="font-medium">
                                    {{ agentName(message.from)
                                    }}<template v-if="message.to">
                                        to {{ agentName(message.to) }}</template
                                    >
                                </span>
                                <span
                                    class="break-words text-muted-foreground"
                                    >{{ message.summary }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    formatDateTime(message.at)
                                }}</span>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="border-t px-4 py-3 text-sm text-muted-foreground"
                        >
                            No messages between agents yet.
                        </p>
                    </section>
                </div>
            </div>
        </main>
    </template>
    <template v-else>
        <Head title="Agent office" />
        <main
            class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-5 px-4 py-6 md:px-6"
        >
            <PageHeader
                title="Agent office"
                description="Where the AI agents work. Click an agent to see what it is doing."
            />
            <p class="text-sm text-muted-foreground" data-test="office-notice">
                Everyone in this office is an AI agent, not a member of staff.
                Agents only suggest; people decide in the approval inbox.
            </p>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.green }"
                    />
                    {{ counts.working }} working
                </span>
                <component
                    :is="canOpenInbox ? Link : 'span'"
                    :href="canOpenInbox ? '/agents/inbox' : undefined"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1"
                    data-test="office-pending"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: LIGHT_COLOURS.yellow }"
                    />
                    {{ counts.waiting }} waiting for a decision
                </component>
                <button
                    v-if="!osReducedMotion"
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-full border px-3 py-1 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring aria-pressed:border-brand aria-pressed:bg-brand/10 aria-pressed:font-semibold lg:min-h-9"
                    :aria-pressed="motionOff"
                    data-test="office-motion"
                    @click="toggleMotion"
                >
                    <span v-if="motionOff" aria-hidden="true" class="mr-1"
                        >✓</span
                    >Pause motion
                </button>
                <span
                    v-if="paused"
                    class="rounded-full border border-warning px-3 py-1 text-warning"
                    data-test="office-paused"
                    role="status"
                >
                    Live updates paused. Showing the last view.
                </span>
            </div>

            <div
                class="overflow-hidden rounded-2xl border bg-gradient-to-b from-brand/5 to-transparent p-1 sm:p-3"
            >
                <OfficeScene
                    :feed="feed"
                    :selected="selected"
                    :clock="clock"
                    :can-open-inbox="canOpenInbox"
                    :motion-off="motionOff"
                    @select="select"
                    @open-inbox="openInbox"
                />
            </div>

            <section aria-labelledby="office-agents-heading" class="grid gap-3">
                <h2 id="office-agents-heading" class="text-base font-semibold">
                    Agents
                </h2>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="agent in feed.agents"
                        :key="agent.code"
                        class="min-w-0"
                    >
                        <button
                            type="button"
                            class="flex min-h-11 w-full min-w-0 flex-col gap-1 rounded-xl border bg-card p-3 text-left hover:border-brand"
                            :class="{ 'opacity-60': !agent.isEnabled }"
                            :data-test="`office-card-${agent.code}`"
                            @click="select(agent.code)"
                        >
                            <span
                                class="flex min-w-0 flex-wrap items-center gap-2"
                            >
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{
                                        background:
                                            LIGHT_COLOURS[lightFor(agent)],
                                    }"
                                    aria-hidden="true"
                                />
                                <span class="font-medium">{{
                                    agent.displayName
                                }}</span>
                                <StatusBadge
                                    status="ai_agent"
                                    label="AI agent"
                                    tone="accent"
                                />
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ agent.jobTitle }} · {{ statusFor(agent) }}
                            </span>
                            <span class="truncate text-sm">
                                {{
                                    agent.currentTask ??
                                    agent.lastStep ??
                                    'Nothing to do right now.'
                                }}
                            </span>
                            <span
                                v-if="latestMessage.get(agent.code)"
                                class="truncate text-xs text-muted-foreground"
                            >
                                “{{ latestMessage.get(agent.code)?.summary }}”
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <Sheet v-model:open="panelOpen">
                <SheetContent
                    :side="isDesktop ? 'right' : 'bottom'"
                    class="max-h-[85vh] overflow-y-auto"
                    data-test="office-panel"
                >
                    <SheetHeader v-if="selectedAgent">
                        <SheetTitle class="flex flex-wrap items-center gap-2">
                            {{ selectedAgent.displayName }}
                            <StatusBadge
                                status="ai_agent"
                                label="AI agent"
                                tone="accent"
                            />
                        </SheetTitle>
                        <SheetDescription>
                            {{ selectedAgent.jobTitle }}
                            <template v-if="selectedAgent.department">
                                · {{ selectedAgent.department }}
                            </template>
                        </SheetDescription>
                    </SheetHeader>

                    <div
                        v-if="selectedAgent"
                        class="grid gap-5 px-4 pb-6 text-sm"
                    >
                        <dl class="grid gap-2">
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-muted-foreground">Status:</dt>
                                <dd class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 rounded-full"
                                        :style="{
                                            background:
                                                LIGHT_COLOURS[
                                                    lightFor(selectedAgent)
                                                ],
                                        }"
                                        aria-hidden="true"
                                    />
                                    {{ statusFor(selectedAgent) }}
                                </dd>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <dt class="text-muted-foreground">
                                    Doing now:
                                </dt>
                                <dd>
                                    {{
                                        selectedAgent.currentTask ??
                                        'Nothing right now'
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="detailError"
                            class="text-destructive"
                            role="alert"
                        >
                            {{ detailError }}
                        </p>

                        <section class="grid gap-2">
                            <h3 class="font-semibold">Last steps</h3>
                            <p
                                v-if="detail && detail.steps.length === 0"
                                class="text-muted-foreground"
                            >
                                No steps recorded yet.
                            </p>
                            <ol
                                v-else-if="detail"
                                class="grid gap-2"
                                data-test="office-steps"
                            >
                                <li
                                    v-for="(step, index) in detail.steps"
                                    :key="index"
                                    class="rounded-lg border p-2"
                                >
                                    <p class="break-words">
                                        {{ step.summary }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatDateTime(step.at) }}
                                    </p>
                                </li>
                            </ol>
                            <p
                                v-else-if="!detailError"
                                class="text-muted-foreground"
                            >
                                Loading…
                            </p>
                        </section>

                        <section v-if="detail" class="grid gap-2">
                            <h3 class="font-semibold">
                                Waiting for a decision ({{
                                    detail.pendingTotal
                                }})
                            </h3>
                            <p
                                v-if="detail.pendingTotal === 0"
                                class="text-muted-foreground"
                            >
                                Nothing waiting.
                            </p>
                            <p
                                v-else-if="detail.pendingBranch === null"
                                class="text-muted-foreground"
                            >
                                The details are in the approval inbox, for the
                                people who decide them.
                            </p>
                            <p
                                v-else-if="detail.pending.length === 0"
                                class="text-muted-foreground"
                            >
                                None at {{ detail.pendingBranch }}; they are at
                                other branches.
                            </p>
                            <ul
                                v-else
                                class="grid gap-2"
                                data-test="office-pending-list"
                            >
                                <li
                                    v-for="proposal in detail.pending"
                                    :key="proposal.id"
                                    class="rounded-lg border p-2"
                                >
                                    <p class="font-medium">
                                        {{ proposal.typeLabel }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ detail.pendingBranch }} ·
                                        {{ formatDateTime(proposal.createdAt) }}
                                    </p>
                                </li>
                            </ul>
                            <Link
                                v-if="canOpenInbox && detail.pendingTotal > 0"
                                href="/agents/inbox"
                                class="inline-flex min-h-11 items-center font-medium text-brand-strong underline"
                            >
                                Open the approval inbox
                            </Link>
                        </section>
                    </div>
                </SheetContent>
            </Sheet>
        </main>
    </template>
</template>

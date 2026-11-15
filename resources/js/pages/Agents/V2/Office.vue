<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type {
    Light,
    OfficeFeed,
} from '@/components/agents/office/office-layout';
import {
    LIGHT_COLOURS,
    LIGHT_LABELS,
} from '@/components/agents/office/office-layout';
import OfficeScene from '@/components/agents/office/OfficeScene.vue';
import { useAgentOffice } from '@/components/agents/office/useAgentOffice';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { useScrollStrip } from '@/composables/useScrollStrip';
import { titleCaseCode } from '@/lib/agent-plain';
import { formatDateTime } from '@/lib/presentation';

/**
 * Version 2 agent office (AG-23). Shown only in V2; Classic is `pages/Agents/Office.vue`, untouched. Same props, same
 * route (`GET /agents/office`), same permission and the same feed; the live behaviour is `useAgentOffice`, shared with
 * Classic. The scene and layout C are as the owner chose them on 12 Nov; this page only changes the frame around them
 * (the shared V2 layout and crumb, V2 status chips) and shows the agent's details in a V2 dialog.
 */
const props = defineProps<{
    feed: OfficeFeed;
    canOpenInbox: boolean;
}>();

const {
    feed,
    paused,
    osReducedMotion,
    motionOff,
    toggleMotion,
    clock,
    selected,
    detail,
    detailError,
    selectedAgent,
    select,
    closePanel,
    openInbox,
    lightFor,
    statusFor,
    latestMessage,
    counts,
    agentName,
    waitingAgents,
    activity,
} = useAgentOffice(props);

usePageCrumbs(() => [{ title: 'Agents' }, { title: 'Office' }]);

// Say a step in plain words ("Read stock counts for Sungai Besi"), never a tool code or branch code.
const plainStep = (summary: string): string => {
    const readMatch = /^Read ([a-z_]+)\.([a-z_]+)(?: for ([A-Z_]+))?$/.exec(
        summary,
    );

    if (readMatch === null) {
        return summary.replace(
            /\b[A-Z]{2,}(?:_[A-Z]+)+\b|\b[A-Z]{3,}(?=:)/g,
            (code) => titleCaseCode(code),
        );
    }

    const what = readMatch[2].replace(/_/g, ' ');

    return `Read ${what}${readMatch[3] ? ` for ${titleCaseCode(readMatch[3])}` : ''}`;
};

// The agent's details open in a V2 dialog (a bottom sheet on a phone). It stays open while the 10-second poll refreshes
// what it shows, in place.
const panelOpen = computed({
    get: () => selected.value !== null,
    set: (value: boolean) => {
        if (!value) {
            closePanel();
        }
    },
});
const panelDescription = computed(() =>
    selectedAgent.value
        ? [selectedAgent.value.jobTitle, selectedAgent.value.department]
              .filter(Boolean)
              .join(' · ')
        : undefined,
);

// On a phone the office scrolls sideways: its right edge fades while more of it is hidden there.
const sceneScroller = ref<HTMLElement | null>(null);
const { measure: measureScene, fadeStyle: sceneFade } = useScrollStrip(
    sceneScroller,
    '[data-office-start]',
);
</script>

<template>
    <Head title="Agent office" />
    <PageFrame>
        <V2WorkLayout
            title="Agent office"
            description="Where the AI agents work. Click an agent to see what it is doing."
        >
            <template #main>
                <p
                    class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm text-foreground"
                    data-test="office-notice"
                >
                    Everyone in this office is an AI agent, not a member of
                    staff. Agents only suggest; people decide in the approval
                    inbox.
                </p>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <V2Chip
                        tone="success"
                        :label="`${counts.working} working`"
                    />
                    <V2Chip
                        v-if="counts.approval > 0"
                        tone="warning"
                        :label="`${counts.approval} waiting for approval`"
                    />
                    <V2Chip
                        v-if="counts.failed > 0"
                        tone="danger"
                        :label="`${counts.failed} last run failed`"
                    />
                    <V2Chip
                        v-if="counts.off > 0"
                        tone="neutral"
                        :label="`${counts.off} off`"
                    />
                    <component
                        :is="canOpenInbox ? Link : 'span'"
                        :href="canOpenInbox ? '/agents/inbox' : undefined"
                        class="inline-flex items-center"
                        :class="canOpenInbox ? 'min-h-11 lg:min-h-9' : ''"
                        data-test="office-pending"
                    >
                        <V2Chip
                            tone="warning"
                            :label="`${counts.waiting} waiting for a decision`"
                        />
                    </component>
                    <span v-if="paused" role="status" data-test="office-paused">
                        <V2Chip
                            tone="warning"
                            label="Live updates paused. Showing the last view."
                        />
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
                                        'border-primary':
                                            selected === agent.code,
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
                                                    LIGHT_COLOURS[
                                                        lightFor(agent)
                                                    ],
                                            }"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="min-w-0 truncate font-medium"
                                            >{{ agent.displayName }}</span
                                        >
                                        <V2Chip tone="info" label="AI agent" />
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
                                            latestMessage.get(agent.code)
                                                ?.summary
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
                                        >Scroll sideways to see the whole
                                        office. </span
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
                                    Agents only suggest. A person approves each
                                    one.
                                </p>
                            </div>
                            <ul v-if="waitingAgents.length > 0">
                                <li
                                    v-for="agent in waitingAgents"
                                    :key="agent.code"
                                    class="flex min-h-14 items-center justify-between gap-3 border-t px-4 py-2"
                                >
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate font-medium"
                                        >
                                            {{ agent.displayName }}
                                        </span>
                                        <span
                                            class="block text-xs text-muted-foreground"
                                        >
                                            {{ agent.jobTitle }}
                                        </span>
                                    </span>
                                    <span class="shrink-0">
                                        <V2Chip
                                            tone="warning"
                                            :label="`${agent.pendingProposals} waiting`"
                                        />
                                    </span>
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
                                            to
                                            {{
                                                agentName(message.to)
                                            }}</template
                                        >
                                    </span>
                                    <span
                                        class="break-words text-muted-foreground"
                                        >{{ message.summary }}</span
                                    >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ formatDateTime(message.at) }}</span
                                    >
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
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        v-model:open="panelOpen"
        :title="selectedAgent?.displayName ?? 'Agent'"
        :description="panelDescription"
        confirm-label="Close"
        single-action
        @confirm="closePanel"
    >
        <div
            v-if="selectedAgent"
            class="grid gap-5 text-sm"
            data-test="office-panel"
        >
            <div class="flex flex-wrap items-center gap-2">
                <V2Chip tone="info" label="AI agent" />
            </div>

            <dl class="grid gap-2">
                <div class="flex flex-wrap gap-2">
                    <dt class="text-muted-foreground">Status:</dt>
                    <dd class="flex items-center gap-2">
                        <span
                            class="size-2.5 rounded-full"
                            :style="{
                                background:
                                    LIGHT_COLOURS[lightFor(selectedAgent)],
                            }"
                            aria-hidden="true"
                        />
                        {{ statusFor(selectedAgent) }}
                    </dd>
                </div>
                <div class="flex flex-wrap gap-2">
                    <dt class="text-muted-foreground">Doing now:</dt>
                    <dd>
                        {{ selectedAgent.currentTask ?? 'Nothing right now' }}
                    </dd>
                </div>
            </dl>

            <p v-if="detailError" class="text-destructive" role="alert">
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
                        <p class="break-words" :title="step.summary">
                            {{ plainStep(step.summary) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatDateTime(step.at) }}
                        </p>
                    </li>
                </ol>
                <p v-else-if="!detailError" class="text-muted-foreground">
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
                    The details are in the approval inbox, for the people who
                    decide them.
                </p>
                <p
                    v-else-if="detail.pending.length === 0"
                    class="text-muted-foreground"
                >
                    None at {{ detail.pendingBranch }}; they are at other
                    branches.
                </p>
                <ul v-else class="grid gap-2" data-test="office-pending-list">
                    <li
                        v-for="proposal in detail.pending"
                        :key="proposal.id"
                        class="rounded-[14px] border p-2.5"
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
                    class="inline-flex min-h-11 items-center font-medium text-primary underline"
                >
                    Open the approval inbox
                </Link>
            </section>

            <p class="text-xs text-muted-foreground">
                Read only. Counts and plain wording, never patient details.
            </p>
        </div>
    </V2Dialog>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { OfficeFeed } from '@/components/agents/office/office-layout';
import { LIGHT_COLOURS } from '@/components/agents/office/office-layout';
import OfficeScene from '@/components/agents/office/OfficeScene.vue';
import { useAgentOffice } from '@/components/agents/office/useAgentOffice';
import { PageHeader } from '@/components/ui/page-header';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status';
import { formatDateTime } from '@/lib/presentation';

/**
 * The agent office. The live behaviour (poll, selection, detail, Pause motion) is `useAgentOffice` (AG-23); the page
 * keeps its sheet.
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
} = useAgentOffice(props);

// Classic keeps its sheet, which needs the desktop test and the open flag.
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
onBeforeUnmount(() => {
    desktopQuery?.removeEventListener('change', desktopChanged);
});
</script>

<template>
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
                <span v-if="motionOff" aria-hidden="true" class="mr-1">✓</span
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
                        <span class="flex min-w-0 flex-wrap items-center gap-2">
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{
                                    background: LIGHT_COLOURS[lightFor(agent)],
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

                <div v-if="selectedAgent" class="grid gap-5 px-4 pb-6 text-sm">
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
                            <dt class="text-muted-foreground">Doing now:</dt>
                            <dd>
                                {{
                                    selectedAgent.currentTask ??
                                    'Nothing right now'
                                }}
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
                            Waiting for a decision ({{ detail.pendingTotal }})
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

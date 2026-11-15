<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { codeSentence, dataSourceLabel } from '@/lib/agent-plain';
import { formatDateTime } from '@/lib/presentation';

/**
 * Version 2 AI agents list (shown only in V2; Classic is `pages/Agents/Index.vue`, untouched). Same props, same route
 * (`POST /agents/{id}/enabled`), same permission (`canManage`), same lock version and payload.
 */
type Light = 'green' | 'yellow' | 'red' | 'grey';
type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

type AgentRow = {
    id: number;
    code: string;
    displayName: string;
    jobTitle: string;
    department: string | null;
    kind: 'build' | 'runtime';
    isEnabled: boolean;
    lockVersion: number;
    tools: string[];
    light: Light;
    currentTask: string | null;
    lastStep: string | null;
    lastStepAt: string | null;
};

type ActivityRow = {
    id: number;
    agent: string | null;
    taskType: string;
    branch: string | null;
    status: string;
    scheduled: boolean;
    startedAt: string | null;
    finishedAt: string | null;
    lastStep: string | null;
    errorCode: string | null;
};

const props = defineProps<{
    canManage: boolean;
    agents: AgentRow[];
    activity: ActivityRow[];
}>();

const light: Record<Light, { tone: Tone; label: string }> = {
    green: { tone: 'success', label: 'Working' },
    yellow: { tone: 'warning', label: 'Waiting for approval' },
    red: { tone: 'danger', label: 'Last run failed' },
    grey: { tone: 'neutral', label: 'Idle' },
};
const runStatus = (status: string): { tone: Tone; label: string } =>
    (
        ({
            working: { tone: 'info', label: 'Working' },
            awaiting_approval: {
                tone: 'warning',
                label: 'Waiting for approval',
            },
            completed: { tone: 'success', label: 'Completed' },
            failed: { tone: 'danger', label: 'Failed' },
        }) as Record<string, { tone: Tone; label: string }>
    )[status] ?? { tone: 'neutral', label: codeSentence(status) };
const sources = (agent: AgentRow) =>
    agent.tools.length ? agent.tools.map(dataSourceLabel).join(', ') : 'None';
// The title is registered as "Inventory Analyst (AI agent)"; the chip already says it, so the line says it once.
const jobTitle = (agent: AgentRow) =>
    agent.jobTitle.replace(/ \(AI agent\)$/, '');
const summary = computed(() => {
    const enabled = props.agents.filter((agent) => agent.isEnabled).length;

    return `${props.agents.length} registered, ${enabled} enabled.`;
});

const confirming = ref<AgentRow | null>(null);
const processing = ref(false);
const error = ref('');
const dialogOpen = computed({
    get: () => confirming.value !== null,
    set: (value: boolean) => {
        if (!value) {
            confirming.value = null;
        }
    },
});
const askToggle = (agent: AgentRow) => {
    error.value = '';
    confirming.value = agent;
};
const toggle = () => {
    const agent = confirming.value;

    if (!agent) {
        return;
    }

    processing.value = true;
    router.post(
        `/agents/${agent.id}/enabled`,
        { is_enabled: !agent.isEnabled, lock_version: agent.lockVersion },
        {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = null;
            },
            onError: (errors) => {
                error.value =
                    errors.lock_version ??
                    errors.is_enabled ??
                    'This change was not saved.';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
const outline =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background max-sm:w-full lg:h-9';
</script>

<template>
    <Head title="AI agents" />
    <PageFrame>
        <V2WorkLayout
            title="AI agents"
            description="The AI agents registered in KPOne and what they did recently."
        >
            <template #main>
                <p
                    class="text-[15px] leading-[22px] text-muted-foreground"
                    data-test="agents-notice"
                >
                    These are AI agents, not staff. They follow fixed rules over
                    KPOne data, propose work for people to approve and change no
                    clinic data. KPOne sends nothing to any AI service.
                </p>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="agents-heading"
                >
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 id="agents-heading" class="text-lg font-semibold">
                            Registered agents
                        </h2>
                        <p
                            class="text-[13px] leading-[18px] text-muted-foreground"
                        >
                            {{ summary }}
                        </p>
                    </div>
                    <p
                        v-if="agents.length > 0 && !canManage"
                        class="text-[13px] leading-[18px] text-muted-foreground"
                        data-test="agents-manage-note"
                    >
                        Only a Director can enable or disable an agent.
                    </p>
                    <p
                        v-if="agents.length === 0"
                        class="rounded-[14px] border border-dashed border-border p-5 text-[15px] leading-[22px] text-muted-foreground"
                    >
                        No AI agents are registered yet.
                    </p>
                    <ul v-else class="grid gap-3 lg:grid-cols-2">
                        <li
                            v-for="agent in agents"
                            :key="agent.id"
                            class="flex min-w-0 flex-col gap-2 rounded-[14px] border border-border bg-card p-3"
                            :data-test="`agent-${agent.code}`"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-semibold">{{
                                    agent.displayName
                                }}</span>
                                <V2Chip tone="info" label="AI agent" />
                                <V2Chip
                                    :tone="
                                        agent.isEnabled ? 'success' : 'neutral'
                                    "
                                    :label="
                                        agent.isEnabled ? 'Enabled' : 'Disabled'
                                    "
                                />
                            </div>
                            <p class="text-sm leading-5 text-muted-foreground">
                                {{ jobTitle(agent)
                                }}<template v-if="agent.department">
                                    · {{ agent.department }}</template
                                >
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-[18px] text-muted-foreground"
                            >
                                <V2Chip
                                    :tone="light[agent.light].tone"
                                    :label="light[agent.light].label"
                                />
                                <span>{{
                                    agent.kind === 'build'
                                        ? 'Build agent. It runs no tasks, so Disable only shows it as Off in the office.'
                                        : 'Runtime agent'
                                }}</span>
                            </p>
                            <dl
                                class="grid max-w-xl grid-cols-1 gap-y-0.5 text-sm sm:grid-cols-[7.5rem_minmax(0,1fr)] sm:gap-x-4"
                            >
                                <dt
                                    class="text-[13px] text-muted-foreground max-sm:mt-1.5 max-sm:first:mt-0 sm:text-sm"
                                >
                                    Data sources
                                </dt>
                                <dd class="min-w-0 break-words">
                                    {{ sources(agent) }}
                                </dd>
                                <template v-if="agent.currentTask">
                                    <dt
                                        class="text-[13px] text-muted-foreground max-sm:mt-1.5 max-sm:first:mt-0 sm:text-sm"
                                    >
                                        Now
                                    </dt>
                                    <dd class="min-w-0 break-words">
                                        {{ agent.currentTask }}
                                    </dd>
                                </template>
                                <template v-if="agent.lastStep">
                                    <dt
                                        class="text-[13px] text-muted-foreground max-sm:mt-1.5 max-sm:first:mt-0 sm:text-sm"
                                    >
                                        Last step
                                    </dt>
                                    <dd class="min-w-0 break-words">
                                        {{ agent.lastStep }}
                                        <span
                                            class="block text-muted-foreground"
                                            >{{
                                                formatDateTime(agent.lastStepAt)
                                            }}</span
                                        >
                                    </dd>
                                </template>
                            </dl>
                            <div v-if="canManage" class="flex">
                                <button
                                    type="button"
                                    :class="outline"
                                    :data-test="`toggle-${agent.code}`"
                                    @click="askToggle(agent)"
                                >
                                    {{
                                        agent.isEnabled
                                            ? `Disable ${agent.displayName}`
                                            : `Enable ${agent.displayName}`
                                    }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="activity-heading"
                >
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 id="activity-heading" class="text-lg font-semibold">
                            Recent activity
                        </h2>
                        <p
                            class="text-[13px] leading-[18px] text-muted-foreground"
                        >
                            The latest 25 agent tasks. Times are in Malaysia
                            time.
                        </p>
                    </div>
                    <p
                        v-if="activity.length === 0"
                        class="rounded-[14px] border border-dashed border-border p-5 text-[15px] leading-[22px] text-muted-foreground xl:hidden"
                    >
                        No agent has run yet.
                    </p>
                    <ul v-else class="flex flex-col gap-3 xl:hidden">
                        <li
                            v-for="run in activity"
                            :key="run.id"
                            class="flex min-w-0 flex-col gap-1 rounded-[14px] border border-border bg-card p-3"
                            :data-test="`run-card-${run.id}`"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                            >
                                <span class="text-base font-semibold">{{
                                    run.agent ?? '—'
                                }}</span>
                                <V2Chip
                                    :tone="runStatus(run.status).tone"
                                    :label="runStatus(run.status).label"
                                />
                            </div>
                            <p class="text-sm leading-5 break-words">
                                {{ codeSentence(run.taskType) }}
                                <span class="text-muted-foreground"
                                    >· {{ run.branch ?? 'All branches' }} ·
                                    {{
                                        run.scheduled
                                            ? 'Scheduled'
                                            : 'Asked by staff'
                                    }}</span
                                >
                            </p>
                            <p
                                v-if="run.errorCode"
                                class="text-[13px] leading-[18px] text-muted-foreground"
                            >
                                {{ codeSentence(run.errorCode) }}
                            </p>
                            <p
                                v-if="run.lastStep"
                                class="text-sm leading-5 break-words"
                            >
                                <span class="text-muted-foreground"
                                    >Last step:
                                </span>
                                {{ run.lastStep }}
                            </p>
                            <p
                                class="text-[13px] leading-[18px] text-muted-foreground"
                            >
                                {{ formatDateTime(run.startedAt) }}
                            </p>
                        </li>
                    </ul>
                    <div class="max-xl:hidden">
                        <V2Table
                            label="Recent agent activity"
                            :columns="5"
                            min-width="860px"
                            :empty="activity.length === 0"
                            empty-message="No agent has run yet."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Agent</th>
                                    <th scope="col">Task</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="whitespace-nowrap">
                                        Started
                                    </th>
                                    <th scope="col">Last step</th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="run in activity"
                                    :key="run.id"
                                    :data-test="`run-${run.id}`"
                                >
                                    <td>{{ run.agent ?? '—' }}</td>
                                    <td>
                                        {{ codeSentence(run.taskType) }}
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ run.branch ?? 'All branches' }}
                                            ·
                                            {{
                                                run.scheduled
                                                    ? 'Scheduled'
                                                    : 'Asked by staff'
                                            }}</span
                                        >
                                    </td>
                                    <td>
                                        <div class="flex flex-col items-start">
                                            <V2Chip
                                                :tone="
                                                    runStatus(run.status).tone
                                                "
                                                :label="
                                                    runStatus(run.status).label
                                                "
                                            />
                                            <span
                                                v-if="run.errorCode"
                                                class="text-xs text-muted-foreground"
                                                >{{
                                                    codeSentence(run.errorCode)
                                                }}</span
                                            >
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        {{ formatDateTime(run.startedAt) }}
                                    </td>
                                    <td>{{ run.lastStep ?? '—' }}</td>
                                </tr>
                            </template>
                        </V2Table>
                    </div>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        v-model:open="dialogOpen"
        :title="
            confirming?.isEnabled
                ? `Disable ${confirming?.displayName}?`
                : `Enable ${confirming?.displayName}?`
        "
        :description="
            confirming?.kind === 'build'
                ? confirming.isEnabled
                    ? 'This agent runs no tasks in KPOne. Disabling it only shows it as Off in the office. Its history is kept.'
                    : 'This agent runs no tasks in KPOne. Enabling it shows it as On in the office again.'
                : confirming?.isEnabled
                  ? 'A disabled agent starts no new tasks and makes no new suggestions. Suggestions already waiting stay in the approval inbox. Its history is kept.'
                  : 'The agent may start tasks again under its fixed rules.'
        "
        :confirm-label="confirming?.isEnabled ? 'Disable' : 'Enable'"
        :tone="confirming?.isEnabled ? 'danger' : 'default'"
        :busy="processing"
        @confirm="toggle"
    >
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </V2Dialog>
</template>

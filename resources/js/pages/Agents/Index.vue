<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';
import { formatDateTime } from '@/lib/presentation';

type Light = 'green' | 'yellow' | 'red' | 'grey';

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

defineProps<{
    canManage: boolean;
    agents: AgentRow[];
    activity: ActivityRow[];
}>();

const lightLabels: Record<Light, string> = {
    green: 'Working',
    yellow: 'Waiting for approval',
    red: 'Last run failed',
    grey: 'Idle',
};
const lightClasses: Record<Light, string> = {
    green: 'bg-success',
    yellow: 'bg-warning',
    red: 'bg-destructive',
    grey: 'bg-muted-foreground/40',
};
type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
const runTones: Record<string, Tone> = {
    working: 'accent',
    awaiting_approval: 'warning',
    completed: 'success',
    failed: 'danger',
};
const runTone = (status: string): Tone => runTones[status] ?? 'neutral';
const taskLabel = (taskType: string): string => {
    const words = taskType.replaceAll('_', ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
};

const confirming = ref<AgentRow | null>(null);
const processing = ref(false);
const error = ref('');
const askToggle = (agent: AgentRow) => {
    error.value = '';
    confirming.value = agent;
};
const closeDialog = (open: boolean) => {
    if (!open) {
        confirming.value = null;
    }
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
</script>

<template>
    <Head title="AI agents" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="AI agents"
            description="The AI agents registered in KPOne and what they did recently."
        />
        <p class="text-sm text-muted-foreground" data-test="agents-notice">
            These are AI agents, not staff. They follow fixed rules over KPOne
            data, propose work for people to approve and change no clinic data.
            KPOne sends nothing to any AI service.
        </p>

        <section class="rounded-xl border bg-card p-4" aria-labelledby="agents">
            <h2 id="agents" class="text-sm font-semibold">Registered agents</h2>
            <div
                v-if="agents.length === 0"
                class="mt-3 rounded-lg border border-dashed p-5 text-sm text-muted-foreground"
            >
                No AI agents are registered yet.
            </div>
            <ul v-else class="mt-3 grid gap-3 md:grid-cols-2">
                <li
                    v-for="agent in agents"
                    :key="agent.id"
                    class="flex min-w-0 flex-col gap-3 rounded-lg border p-3"
                    :data-test="`agent-${agent.code}`"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                            :class="lightClasses[agent.light]"
                            :title="lightLabels[agent.light]"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    agent.displayName
                                }}</span>
                                <StatusBadge
                                    status="ai_agent"
                                    label="AI agent"
                                    tone="accent"
                                />
                                <StatusBadge
                                    :status="
                                        agent.isEnabled ? 'enabled' : 'disabled'
                                    "
                                    :label="
                                        agent.isEnabled ? 'Enabled' : 'Disabled'
                                    "
                                    :tone="
                                        agent.isEnabled ? 'success' : 'neutral'
                                    "
                                />
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ agent.jobTitle }}
                                <template v-if="agent.department">
                                    · {{ agent.department }}
                                </template>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    agent.kind === 'build'
                                        ? 'Build agent (shown in the office only)'
                                        : 'Runtime agent'
                                }}
                                · {{ lightLabels[agent.light] }}
                            </p>
                        </div>
                    </div>
                    <dl class="grid gap-1 text-xs">
                        <div class="flex flex-wrap gap-1">
                            <dt class="text-muted-foreground">Data sources:</dt>
                            <dd
                                v-if="agent.tools.length"
                                class="min-w-0 break-words"
                            >
                                {{ agent.tools.join(', ') }}
                            </dd>
                            <dd v-else>None</dd>
                        </div>
                        <div v-if="agent.currentTask" class="flex gap-1">
                            <dt class="text-muted-foreground">Now:</dt>
                            <dd>{{ agent.currentTask }}</dd>
                        </div>
                        <div v-if="agent.lastStep" class="flex flex-wrap gap-1">
                            <dt class="text-muted-foreground">Last step:</dt>
                            <dd class="min-w-0 break-words">
                                {{ agent.lastStep }}
                                <span class="text-muted-foreground">
                                    ({{ formatDateTime(agent.lastStepAt) }})
                                </span>
                            </dd>
                        </div>
                    </dl>
                    <div v-if="canManage">
                        <Button
                            size="sm"
                            :variant="agent.isEnabled ? 'outline' : 'default'"
                            :data-test="`toggle-${agent.code}`"
                            @click="askToggle(agent)"
                        >
                            {{
                                agent.isEnabled
                                    ? `Disable ${agent.displayName}`
                                    : `Enable ${agent.displayName}`
                            }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section
            class="rounded-xl border bg-card p-4"
            aria-labelledby="activity"
        >
            <h2 id="activity" class="text-sm font-semibold">Recent activity</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                The latest 25 agent tasks. Times are shown in Malaysia time.
            </p>
            <div
                v-if="activity.length === 0"
                class="mt-3 rounded-lg border border-dashed p-5 text-sm text-muted-foreground"
            >
                No agent has run yet.
            </div>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Agent</th>
                            <th class="py-2 pr-3 font-medium">Task</th>
                            <th class="py-2 pr-3 font-medium">Branch</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2 pr-3 font-medium">Started</th>
                            <th class="py-2 font-medium">Last step</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="run in activity"
                            :key="run.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-3">{{ run.agent ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                {{ taskLabel(run.taskType) }}
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        run.scheduled
                                            ? 'Scheduled'
                                            : 'Asked by staff'
                                    }}
                                </div>
                            </td>
                            <td class="py-2 pr-3">{{ run.branch ?? 'All' }}</td>
                            <td class="py-2 pr-3">
                                <StatusBadge
                                    :status="run.status"
                                    :tone="runTone(run.status)"
                                />
                                <div
                                    v-if="run.errorCode"
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ run.errorCode }}
                                </div>
                            </td>
                            <td class="py-2 pr-3 whitespace-nowrap">
                                {{ formatDateTime(run.startedAt) }}
                            </td>
                            <td class="py-2">{{ run.lastStep ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <OperationalConfirmDialog
            :open="confirming !== null"
            :title="
                confirming?.isEnabled
                    ? `Disable ${confirming?.displayName}?`
                    : `Enable ${confirming?.displayName}?`
            "
            :description="
                confirming?.isEnabled
                    ? 'A disabled agent starts no new tasks. Its history is kept.'
                    : 'The agent may start tasks again under its fixed rules.'
            "
            :confirm-label="confirming?.isEnabled ? 'Disable' : 'Enable'"
            :processing="processing"
            :destructive="confirming?.isEnabled ?? false"
            :error="error"
            @update:open="closeDialog"
            @confirm="toggle"
        />
    </main>
</template>

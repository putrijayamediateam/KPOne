<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import OperationalTabs from '@/components/ui/tabs/OperationalTabs.vue';
import {
    refuseReasonLabels,
    returnConditionLabels,
    returnReasonLabels,
} from '@/lib/dispensary-returns';

/**
 * DSP-7b (approved by B.E.R.N.I.S.S for the owner, 2026-11-06): the branch list of medicine returns waiting for a
 * check. Read only: each row opens its case, where a CA Supervisor who did not record the return checks it.
 */
type Row = {
    publicId: string;
    recordedAt: string;
    patient: string;
    visitNumber: string;
    medicine: string;
    quantity: string;
    unit: string;
    reason: string;
    condition: string;
    controlled: boolean;
    recordedBy: string;
    recordedByMe: boolean;
    caseUrl: string;
    // DSP-28: set only on the checked list.
    outcome?: 'dispose' | 'quarantine' | 'refuse' | null;
    refuseReason?: string | null;
    checkedBy?: string | null;
    checkedByMe?: boolean;
    checkedAt?: string | null;
};

const props = defineProps<{
    returns: {
        branchName: string;
        view?: 'waiting' | 'checked';
        controlled?: 'all' | 'yes' | 'no';
        outcome?: 'all' | 'dispose' | 'quarantine' | 'refuse';
        waitingAge?: 'all' | 'today' | 'yesterday' | '2days' | '3days';
        monthLabel?: string;
        counts?: { waiting: number; checked: number };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const caseLink = (row: Row) => `${row.caseUrl}#medicine-returns`;
const reason = (row: Row) => returnReasonLabels[row.reason] ?? row.reason;
const condition = (row: Row) =>
    returnConditionLabels[row.condition] ?? row.condition;
const by = (row: Row) => (row.recordedByMe ? 'You' : row.recordedBy);
// DSP-28 (owner, 25 Nov 2026, Clean Look gap list B3 and B5): a second tab for the returns checked this month, and a
// controlled-medicine filter on both. Still read only: paging, the tab and the filter are plain GETs.
const view = computed(() => props.returns.view ?? 'waiting');
const controlled = computed(() => props.returns.controlled ?? 'all');
const checked = computed(() => view.value === 'checked');
const selectedOutcome = computed(() =>
    checked.value ? (props.returns.outcome ?? 'all') : 'all',
);
const selectedWaitingAge = computed(() =>
    checked.value ? 'all' : (props.returns.waitingAge ?? 'all'),
);
const ageOptions = [
    { value: 'all', label: 'All ages' },
    { value: '3days', label: '3 days ago' },
    { value: '2days', label: '2 days ago' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'today', label: 'Today' },
];
const filteredWaiting = computed(
    () =>
        !checked.value &&
        (selectedWaitingAge.value !== 'all' || controlled.value !== 'all'),
);
const filteredChecked = computed(
    () =>
        checked.value &&
        (selectedOutcome.value !== 'all' || controlled.value !== 'all'),
);
const go = (params: {
    page?: number;
    view?: string;
    controlled?: string;
    outcome?: string;
    waitingAge?: string;
}) =>
    router.get(
        '/dispensary/returns-to-check',
        {
            page: params.page ?? 1,
            view:
                (params.view ?? view.value) === 'checked'
                    ? 'checked'
                    : undefined,
            controlled:
                (params.controlled ?? controlled.value) === 'all'
                    ? undefined
                    : (params.controlled ?? controlled.value),
            waiting_age:
                (params.view ?? view.value) !== 'checked' &&
                (params.waitingAge ?? selectedWaitingAge.value) !== 'all'
                    ? (params.waitingAge ?? selectedWaitingAge.value)
                    : undefined,
            outcome:
                (params.view ?? view.value) === 'checked' &&
                (params.outcome ?? selectedOutcome.value) !== 'all'
                    ? (params.outcome ?? selectedOutcome.value)
                    : undefined,
        },
        { preserveScroll: false },
    );
const visit = (page: number) => go({ page });
const tabs = computed(() => [
    {
        value: 'waiting',
        label: 'Waiting for a check',
        count: props.returns.counts?.waiting,
    },
    {
        value: 'checked',
        label: 'Checked this month',
        count: props.returns.counts?.checked,
    },
]);
const filters = [
    { value: 'all', label: 'All medicines' },
    { value: 'yes', label: 'Controlled only' },
    { value: 'no', label: 'Not controlled' },
] as const;
const outcomeOptions = [
    { value: 'all', label: 'All outcomes' },
    { value: 'quarantine', label: 'Quarantined' },
    { value: 'dispose', label: 'Disposed' },
    { value: 'refuse', label: 'Refused' },
] as const;
// Daniel: the outcome as a dot-plus-word chip; Quarantined is the info tone, the rest neutral.
const outcomeClass = (row: Row) =>
    row.outcome === 'quarantine'
        ? 'border-border bg-info-wash text-info'
        : 'border-border text-foreground';
onMounted(() =>
    // DD-30: a tab chosen from the address is scrolled into view, with its count, on a phone.
    nextTick(() =>
        document
            .querySelector<HTMLElement>('[role="tab"][aria-selected="true"]')
            ?.scrollIntoView?.({ block: 'nearest', inline: 'end' }),
    ),
);
const outcome = (row: Row) =>
    row.outcome === 'dispose'
        ? 'Disposed'
        : row.outcome === 'quarantine'
          ? 'Quarantined'
          : row.outcome === 'refuse'
            ? `Refused: ${refuseReasonLabels[row.refuseReason ?? ''] ?? 'Other'}`
            : '';
const ownNote = 'You recorded this. Another CA Supervisor must check it.';
const help = computed(() =>
    checked.value
        ? `${props.returns.branchName} · Medicine returns checked in ${props.returns.monthLabel ?? 'this month'}, newest first`
        : `${props.returns.branchName} · Medicine returns waiting for a check, oldest first`,
);
</script>

<template>
    <Head title="Returns to check" />
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 px-4 py-6 md:px-6 lg:[&_a[href]]:min-h-9 lg:[&_button]:min-h-9"
    >
        <PageHeader title="Returns to check" :description="help" />
        <OperationalTabs
            :model-value="view"
            label="Medicine returns"
            :tabs="tabs"
            @update:model-value="(value: string) => go({ view: value })"
        />
        <div
            class="grid w-full grid-cols-3 gap-1 rounded-full bg-muted p-1 sm:inline-grid sm:w-fit"
            role="group"
            aria-label="Which medicines"
        >
            <button
                v-for="option in filters"
                :key="option.value"
                type="button"
                class="rounded-full px-2 text-center text-sm leading-tight transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring max-lg:min-h-11 sm:px-3 lg:h-9"
                :class="
                    controlled === option.value
                        ? 'bg-background font-medium text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-pressed="controlled === option.value"
                data-testid="returns-controlled-filter"
                @click="go({ controlled: option.value })"
            >
                {{ option.label }}
            </button>
        </div>
        <div v-if="!checked" class="flex flex-wrap items-center gap-2">
            <label for="returns-waiting-age" class="text-sm font-medium"
                >Waiting</label
            >
            <select
                id="returns-waiting-age"
                :value="selectedWaitingAge"
                class="min-h-11 max-w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:min-h-9"
                @change="
                    go({
                        waitingAge: ($event.target as HTMLSelectElement).value,
                    })
                "
            >
                <option
                    v-for="option in ageOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <span
                class="text-sm text-muted-foreground"
                data-testid="returns-waiting-count"
                >{{ returns.total }}
                {{ filteredWaiting ? 'matching waiting' : 'waiting' }}
                {{ returns.total === 1 ? 'return' : 'returns' }}</span
            >
        </div>
        <p v-if="!checked" class="text-xs text-muted-foreground">
            Ages use the branch's calendar days. Each choice matches that exact
            day; All includes older returns.
        </p>
        <div v-if="checked" class="flex flex-wrap items-center gap-2">
            <label for="returns-outcome" class="text-sm font-medium"
                >Outcome</label
            >
            <select
                id="returns-outcome"
                :value="selectedOutcome"
                class="min-h-11 max-w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:min-h-9"
                data-testid="returns-outcome-filter"
                @change="
                    go({ outcome: ($event.target as HTMLSelectElement).value })
                "
            >
                <option
                    v-for="option in outcomeOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <span
                class="text-sm text-muted-foreground"
                data-testid="returns-matching-count"
                >{{ returns.total }}
                {{ filteredChecked ? 'matching checked' : 'checked' }}
                {{ returns.total === 1 ? 'return' : 'returns' }}</span
            >
        </div>
        <p v-if="checked" class="text-xs text-muted-foreground">
            Returns checked this month. Opening a case shows what was decided.
        </p>
        <p v-else class="text-xs text-muted-foreground">
            Open the case to dispose, quarantine or refuse a return. This list
            does not check anything. You cannot check a return you recorded.
        </p>

        <EmptyState
            v-if="returns.rows.length === 0 && checked"
            :title="
                filteredChecked
                    ? 'No matching checked returns'
                    : 'No returns checked'
            "
            :description="
                filteredChecked
                    ? `No checked medicine return in ${returns.branchName} matches these filters in ${returns.monthLabel ?? 'this month'}. Choose All outcomes or All medicines to broaden the list.`
                    : `No medicine return in ${returns.branchName} was checked in ${returns.monthLabel ?? 'this month'}.`
            "
        />
        <EmptyState
            v-else-if="returns.rows.length === 0 && filteredWaiting"
            title="No matching waiting returns"
            :description="`No medicine return in ${returns.branchName} matches these waiting filters. Choose All ages or All medicines to broaden the list.`"
        />
        <EmptyState
            v-else-if="returns.rows.length === 0"
            title="No returns waiting"
            :description="`No medicine return in ${returns.branchName} is waiting for a check.`"
        />

        <template v-else>
            <div class="hidden md:block">
                <OperationalTable
                    label="Returns to check"
                    :columns="7"
                    min-width="960px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Recorded</th>
                            <th scope="col">Patient / Visit</th>
                            <th scope="col">Medicine</th>
                            <th scope="col" class="text-right">Qty returned</th>
                            <th scope="col">Reason / Condition</th>
                            <th scope="col">Recorded by</th>
                            <th scope="col" class="text-right">State</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in returns.rows"
                            :key="row.publicId"
                            data-testid="return-to-check-row"
                        >
                            <td class="whitespace-nowrap">
                                {{ row.recordedAt }}
                            </td>
                            <td>
                                <span class="block font-medium text-foreground">
                                    {{ row.patient }}
                                </span>
                                <span
                                    class="block font-mono text-xs text-muted-foreground"
                                    >{{ row.visitNumber }}</span
                                >
                            </td>
                            <td>
                                <span class="mr-2">{{ row.medicine }}</span>
                                <Badge
                                    v-if="row.controlled"
                                    variant="outline"
                                    data-testid="return-controlled"
                                    >Controlled</Badge
                                >
                            </td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ row.quantity }} {{ row.unit }}
                            </td>
                            <td>
                                <span class="block">{{ reason(row) }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ condition(row) }}</span
                                >
                            </td>
                            <td>{{ by(row) }}</td>
                            <td class="text-right">
                                <div class="flex flex-col items-end gap-1">
                                    <template v-if="checked">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                                            :class="outcomeClass(row)"
                                            data-testid="return-outcome"
                                            ><span
                                                class="size-1.5 rounded-full bg-current"
                                                aria-hidden="true"
                                            ></span
                                            >{{ outcome(row) }}</span
                                        >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >{{ row.checkedAt }} · by
                                            {{
                                                row.checkedByMe
                                                    ? 'you'
                                                    : row.checkedBy
                                            }}</span
                                        >
                                    </template>
                                    <template v-else>
                                        <StatusBadge status="waiting_check" />
                                    </template>
                                    <span
                                        v-if="row.recordedByMe && !checked"
                                        class="text-xs text-muted-foreground"
                                        >{{ ownNote }}</span
                                    >
                                    <ActionLink
                                        :href="caseLink(row)"
                                        variant="secondary"
                                        size="sm"
                                        :aria-label="`Open case for ${row.medicine}, ${row.visitNumber}`"
                                    >
                                        Open case
                                    </ActionLink>
                                </div>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>

            <ul class="space-y-3 md:hidden" aria-label="Returns to check">
                <li
                    v-for="row in returns.rows"
                    :key="row.publicId"
                    class="space-y-2 rounded-xl border bg-card p-4 text-sm"
                    data-testid="return-to-check-card"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <h2 class="text-sm font-medium">
                            {{ row.medicine }}
                            <Badge
                                v-if="row.controlled"
                                variant="outline"
                                class="ml-1"
                                >Controlled</Badge
                            >
                        </h2>
                        <span
                            v-if="checked"
                            class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                            :class="outcomeClass(row)"
                            data-testid="return-outcome-card"
                            ><span
                                class="size-1.5 rounded-full bg-current"
                                aria-hidden="true"
                            ></span
                            >{{ outcome(row) }}</span
                        >
                        <template v-else>
                            <StatusBadge status="waiting_check" />
                        </template>
                    </div>
                    <p v-if="checked" class="text-xs text-muted-foreground">
                        Checked {{ row.checkedAt }} · by
                        {{ row.checkedByMe ? 'you' : row.checkedBy }}
                    </p>
                    <p class="tabular-nums">
                        Qty returned {{ row.quantity }} {{ row.unit }} ·
                        {{ reason(row) }}
                    </p>
                    <p>
                        {{ row.patient }} ·
                        <span class="font-mono text-xs text-muted-foreground">{{
                            row.visitNumber
                        }}</span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ row.recordedAt }} · by
                        {{ row.recordedByMe ? 'you' : row.recordedBy }} ·
                        {{ condition(row) }}
                    </p>
                    <p
                        v-if="row.recordedByMe && !checked"
                        class="text-xs text-muted-foreground"
                    >
                        {{ ownNote }}
                    </p>
                    <ActionLink
                        :href="caseLink(row)"
                        variant="secondary"
                        class="min-h-11 w-full justify-center"
                        :aria-label="`Open case for ${row.medicine}, ${row.visitNumber}`"
                    >
                        Open case
                    </ActionLink>
                </li>
            </ul>

            <CompactPagination
                :current-page="returns.page"
                :last-page="returns.lastPage"
                :total="returns.total"
                @change="(page: number) => visit(page)"
            />
        </template>
    </main>
</template>

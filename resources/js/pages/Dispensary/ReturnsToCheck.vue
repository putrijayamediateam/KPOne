<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ActionLink } from '@/components/ui/action-link';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import {
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
};

const props = defineProps<{
    returns: {
        branchName: string;
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
const visit = (page: number) =>
    router.get(
        '/dispensary/returns-to-check',
        { page },
        { preserveScroll: false },
    );
const ownNote = 'You recorded this. Another CA Supervisor must check it.';
const help = `${props.returns.branchName} · Medicine returns waiting for a check, oldest first`;
</script>

<template>
    <Head title="Returns to check" />
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader title="Returns to check" :description="help" />
        <p class="text-xs text-muted-foreground">
            Open the case to dispose, quarantine or refuse a return. This list
            does not check anything. You cannot check a return you recorded.
        </p>

        <EmptyState
            v-if="returns.rows.length === 0"
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
                                    <StatusBadge status="waiting_check" />
                                    <span
                                        v-if="row.recordedByMe"
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
                        <StatusBadge status="waiting_check" />
                    </div>
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
                        v-if="row.recordedByMe"
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

<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ActionLink } from '@/components/ui/action-link';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { useUiVersion } from '@/composables/useUiVersion';
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
const { isV2 } = useUiVersion();
const help = `${props.returns.branchName} · Medicine returns waiting for a check, oldest first`;
</script>

<template>
    <Head title="Returns to check" />
    <!-- Classic keeps the original look exactly (owner decision 2026-11-12: Version 2 only for people who switch it on).
         UI-RESTYLE D1 (GUIDE section 19) applies under Version 2 only: v2: classes, a table from 1024 px and
         label-and-value cards (useUiVersion), with the same data, links and words. -->
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 px-4 py-6 md:px-6 v2:gap-5"
    >
        <PageHeader title="Returns to check" :description="help" />
        <p
            class="text-xs text-muted-foreground v2:-mt-2 v2:text-[13px] v2:leading-[inherit]"
        >
            Open the case to dispose, quarantine or refuse a return. This list
            does not check anything. You cannot check a return you recorded.
        </p>

        <EmptyState
            v-if="returns.rows.length === 0"
            title="No returns waiting"
            :description="`No medicine return in ${returns.branchName} is waiting for a check.`"
        />

        <template v-else>
            <!-- Classic: display contents, so the table switches at 768 px as before. V2: hidden below 1024 px. -->
            <div :class="isV2 ? 'hidden lg:block' : 'contents'">
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
                                <th scope="col" class="text-right">
                                    Qty returned
                                </th>
                                <th scope="col">Reason / Condition</th>
                                <th scope="col">Recorded by</th>
                                <th scope="col" class="text-right">State</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in returns.rows"
                                :key="row.publicId"
                                class="v2:align-top"
                                data-testid="return-to-check-row"
                            >
                                <td class="whitespace-nowrap v2:tabular-nums">
                                    {{ row.recordedAt }}
                                </td>
                                <td>
                                    <span
                                        class="block font-medium text-foreground"
                                    >
                                        {{ row.patient }}
                                    </span>
                                    <span
                                        class="block font-mono text-xs text-muted-foreground v2:text-[13px] v2:leading-[inherit]"
                                        >{{ row.visitNumber }}</span
                                    >
                                </td>
                                <td>
                                    <span class="mr-2 v2:font-medium">{{
                                        row.medicine
                                    }}</span>
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
                                        class="block text-xs text-muted-foreground v2:text-[13px] v2:leading-[inherit]"
                                        >{{ condition(row) }}</span
                                    >
                                </td>
                                <td>{{ by(row) }}</td>
                                <td class="text-right">
                                    <div
                                        class="flex flex-col items-end gap-1 v2:gap-1.5"
                                    >
                                        <StatusBadge status="waiting_check" />
                                        <span
                                            v-if="row.recordedByMe"
                                            class="text-xs text-muted-foreground v2:max-w-56 v2:text-[13px] v2:leading-[inherit]"
                                            >{{ ownNote }}</span
                                        >
                                        <ActionLink
                                            :href="caseLink(row)"
                                            variant="secondary"
                                            size="sm"
                                            class="v2:h-9 v2:px-3"
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
            </div>

            <template v-if="!isV2">
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
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ row.visitNumber }}</span
                            >
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
            </template>
            <ul
                v-else
                class="space-y-3 lg:hidden"
                aria-label="Returns to check"
            >
                <li
                    v-for="row in returns.rows"
                    :key="row.publicId"
                    class="space-y-3 rounded-[14px] border bg-card p-4"
                    data-testid="return-to-check-card"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <h2 class="text-base leading-snug font-semibold">
                            {{ row.medicine }}
                            <Badge
                                v-if="row.controlled"
                                variant="outline"
                                class="ml-1 align-middle"
                                >Controlled</Badge
                            >
                        </h2>
                        <StatusBadge status="waiting_check" />
                    </div>
                    <dl
                        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-[15px]"
                    >
                        <dt class="text-[13px] text-muted-foreground">
                            Qty returned
                        </dt>
                        <dd class="min-w-0 break-words tabular-nums">
                            {{ row.quantity }} {{ row.unit }}
                        </dd>
                        <dt class="text-[13px] text-muted-foreground">
                            Patient / Visit
                        </dt>
                        <dd class="min-w-0 break-words">
                            {{ row.patient }}
                            <span
                                class="block font-mono text-[13px] text-muted-foreground"
                                >{{ row.visitNumber }}</span
                            >
                        </dd>
                        <dt class="text-[13px] text-muted-foreground">
                            Reason / Condition
                        </dt>
                        <dd class="min-w-0 break-words">
                            {{ reason(row) }}
                            <span
                                class="block text-[13px] text-muted-foreground"
                                >{{ condition(row) }}</span
                            >
                        </dd>
                        <dt class="text-[13px] text-muted-foreground">
                            Recorded
                        </dt>
                        <dd class="min-w-0 break-words tabular-nums">
                            {{ row.recordedAt }}
                        </dd>
                        <dt class="text-[13px] text-muted-foreground">
                            Recorded by
                        </dt>
                        <dd class="min-w-0 break-words">{{ by(row) }}</dd>
                    </dl>
                    <p
                        v-if="row.recordedByMe"
                        class="text-[13px] text-muted-foreground"
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

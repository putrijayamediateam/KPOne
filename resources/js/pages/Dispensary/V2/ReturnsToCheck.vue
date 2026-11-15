<script setup lang="ts">
/**
 * Version 2 look for Returns to check (DSP-7b; owner: V2 only for people who switch it on, Classic kept exactly as it is).
 * Look only: the props, route and words are Classic's (`Dispensary/ReturnsToCheck.vue`), kept in step by
 * tests/Frontend/v2-dispensary-returns-to-check.test.mjs. Read only: each row opens its case, where a CA Supervisor who
 * did not record the return checks it.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { PageFrame } from '@/components/ui/page-frame';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import {
    returnConditionLabels,
    returnReasonLabels,
} from '@/lib/dispensary-returns';
import { quantityWords } from '@/pages/Dispensary/V2/Partials/quantity-words';

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

// V2 (Daniel): the crumbs above the title, the section word as the eyebrow and the branch in the description.
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Returns to check' },
]);
const help = `Medicine returns waiting for a check at ${props.returns.branchName}, oldest first.`;
const ownNote = 'You recorded this return, so someone else must check it.';
// The server pages by 25 (DispensaryReturnService::returnsToCheck).
const perPage = 25;
const amount = (row: Row) => quantityWords(row.quantity, row.unit);
const openLabel = (row: Row) =>
    `Open case for ${row.medicine}, ${row.visitNumber}`;

const outlineButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background dark:border-foreground/20';
</script>

<template>
    <Head title="Returns to check" />
    <PageFrame>
        <V2WorkLayout
            title="Returns to check"
            eyebrow="Pharmacy"
            :description="help"
        >
            <template #main>
                <p class="-mt-2 text-[13px] text-muted-foreground">
                    Open the case to dispose, quarantine or refuse a return.
                    This list does not check anything. You cannot check a return
                    you recorded.
                </p>

                <EmptyState
                    v-if="returns.rows.length === 0"
                    title="No returns waiting"
                    :description="`No medicine return in ${returns.branchName} is waiting for a check.`"
                />

                <template v-else>
                    <!-- From 1280 px: a table. Below it: label-and-value cards (the V2 sidebar leaves too little room at 1024, as V2Table cards="xl"). -->
                    <div class="hidden xl:block">
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
                                    <th scope="col" class="text-right">
                                        State
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in returns.rows"
                                    :key="row.publicId"
                                    class="align-top"
                                    data-testid="return-to-check-row"
                                >
                                    <td class="whitespace-nowrap tabular-nums">
                                        {{ row.recordedAt }}
                                    </td>
                                    <td>
                                        <span
                                            class="block font-medium text-foreground"
                                            >{{ row.patient }}</span
                                        >
                                        <span
                                            class="block font-mono text-[13px] whitespace-nowrap text-muted-foreground"
                                            >{{ row.visitNumber }}</span
                                        >
                                    </td>
                                    <td>
                                        <span class="mr-2 font-medium">{{
                                            row.medicine
                                        }}</span>
                                        <V2Chip
                                            v-if="row.controlled"
                                            tone="warning"
                                            label="Controlled"
                                            data-testid="return-controlled"
                                        />
                                    </td>
                                    <td
                                        class="text-right whitespace-nowrap tabular-nums"
                                    >
                                        {{ amount(row) }}
                                    </td>
                                    <td>
                                        <span class="block">{{
                                            reason(row)
                                        }}</span>
                                        <span
                                            class="block text-[13px] text-muted-foreground"
                                            >{{ condition(row) }}</span
                                        >
                                    </td>
                                    <td>{{ by(row) }}</td>
                                    <td class="text-right">
                                        <div
                                            class="flex flex-col items-end gap-1.5"
                                        >
                                            <V2Chip
                                                tone="warning"
                                                label="Waiting for check"
                                            />
                                            <span
                                                v-if="row.recordedByMe"
                                                class="block max-w-56 text-[13px] text-muted-foreground"
                                                data-testid="return-own-note"
                                                >{{ ownNote }}</span
                                            >
                                            <Link
                                                :href="caseLink(row)"
                                                :class="[
                                                    outlineButton,
                                                    'lg:h-9 lg:px-3',
                                                ]"
                                                :aria-label="openLabel(row)"
                                                >Open case</Link
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </OperationalTable>
                    </div>

                    <ul
                        class="space-y-3 xl:hidden"
                        aria-label="Returns to check"
                    >
                        <li
                            v-for="row in returns.rows"
                            :key="row.publicId"
                            class="space-y-3 rounded-[14px] border bg-card p-4 dark:border-foreground/20"
                            data-testid="return-to-check-card"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <h2
                                    class="text-base leading-snug font-semibold break-words"
                                >
                                    {{ row.medicine }}
                                </h2>
                                <V2Chip
                                    tone="warning"
                                    label="Waiting for check"
                                />
                            </div>
                            <V2Chip
                                v-if="row.controlled"
                                tone="warning"
                                label="Controlled"
                                data-testid="return-controlled"
                            />
                            <dl
                                class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-[15px]"
                            >
                                <dt class="text-[13px] text-muted-foreground">
                                    Qty returned
                                </dt>
                                <dd class="min-w-0 break-words tabular-nums">
                                    {{ amount(row) }}
                                </dd>
                                <dt class="text-[13px] text-muted-foreground">
                                    Patient / Visit
                                </dt>
                                <dd class="min-w-0 break-words">
                                    {{ row.patient }}
                                    <span
                                        class="block font-mono text-[13px] whitespace-nowrap text-muted-foreground"
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
                                <dd class="min-w-0 break-words">
                                    {{ by(row) }}
                                </dd>
                            </dl>
                            <p
                                v-if="row.recordedByMe"
                                class="text-[13px] text-muted-foreground"
                                data-testid="return-own-note"
                            >
                                {{ ownNote }}
                            </p>
                            <Link
                                :href="caseLink(row)"
                                :class="[outlineButton, 'w-full']"
                                :aria-label="openLabel(row)"
                                >Open case</Link
                            >
                        </li>
                    </ul>

                    <V2Pager
                        :current-page="returns.page"
                        :last-page="returns.lastPage"
                        :total="returns.total"
                        :per-page="perPage"
                        @change="(page: number) => visit(page)"
                    />
                </template>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

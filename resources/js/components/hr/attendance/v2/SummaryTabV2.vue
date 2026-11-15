<script setup lang="ts">
// Version 2 Summary tab (Director, HR Manager), HR V2 Attendance PR 2. Read-only and on screen only, the same data
// as Classic through useSummaryTab. Daniel: a table from 1280 px with its Total row; below it one card per person
// (the name as the title, label above value in two columns at 390 px) and the Total as its own card; hours never split.
import { OperationalSelect } from '@/components/ui/select';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { useSummaryTab } from '@/composables/hr/useSummaryTab';
import { glueTimes } from '@/lib/hr-attendance-v2';
import type { SummaryFigures, SummaryTabData } from '../types';
import { button } from './classes';

const props = defineProps<{ data: SummaryTabData }>();

const { visit, branchOptions, figureKeys, periods } = useSummaryTab(props);

const labels: Record<(typeof figureKeys)[number], string> = {
    rostered: 'Rostered',
    worked: 'Worked',
    late: 'Late',
    leftEarly: 'Left early',
    noClockIn: 'No clock-in',
    noClockOut: 'No clock-out',
    overtime: 'Overtime approved',
};
const figure = (row: SummaryFigures, key: (typeof figureKeys)[number]) =>
    glueTimes(String(row[key] ?? ''));
</script>

<template>
    <section
        aria-labelledby="v2-summary-title"
        class="flex flex-col gap-4"
        data-test="v2-summary-tab"
    >
        <h2 id="v2-summary-title" class="sr-only">Attendance summary</h2>
        <div
            v-if="!data.branch"
            class="rounded-[14px] border border-dashed bg-card p-4 text-sm text-muted-foreground"
        >
            <p class="font-medium text-foreground">No active branch.</p>
            <p>Add a branch first.</p>
        </div>
        <template v-else>
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex min-w-48 flex-col gap-1.5">
                    <label for="v2-summary-branch" class="text-sm font-medium"
                        >Branch</label
                    >
                    <OperationalSelect
                        id="v2-summary-branch"
                        :model-value="data.branch.id"
                        :options="branchOptions"
                        label="Branch"
                        @update:model-value="(v) => visit({ branch: v })"
                    />
                </div>
                <V2OptionGroup
                    :model-value="data.period ?? 'month'"
                    :options="periods"
                    label="Period"
                    @update:model-value="(v: string) => visit({ period: v })"
                />
                <div class="flex flex-nowrap items-end gap-2">
                    <button
                        type="button"
                        :class="[button, 'gap-1.5']"
                        @click="visit({ date: data.prevDate })"
                    >
                        <span aria-hidden="true">‹</span
                        ><span class="max-sm:sr-only">{{
                            data.period === 'week'
                                ? 'Previous week'
                                : 'Previous month'
                        }}</span>
                    </button>
                    <V2DateField
                        :model-value="data.date ?? ''"
                        required
                        :label="data.period === 'week' ? 'Week of' : 'Month of'"
                        @update:model-value="
                            (value: string) => value && visit({ date: value })
                        "
                    />
                    <button
                        type="button"
                        :class="[button, 'gap-1.5']"
                        @click="visit({ date: data.nextDate })"
                    >
                        <span class="max-sm:sr-only">{{
                            data.period === 'week' ? 'Next week' : 'Next month'
                        }}</span
                        ><span aria-hidden="true">›</span>
                    </button>
                </div>
            </div>
            <h3 class="text-base font-semibold" data-test="summary-period">
                {{ data.branch.name }} · {{ data.periodLabel }}
            </h3>
            <p v-if="!data.settingsReady" class="text-sm text-muted-foreground">
                Attendance is not set up yet, so late and early counts are not
                worked out.
            </p>
            <div
                v-if="data.rows.length === 0"
                class="rounded-[14px] border border-dashed bg-card p-4 text-sm text-muted-foreground"
            >
                <p class="font-medium text-foreground">
                    Nobody to show for this period.
                </p>
                <p>
                    Nobody is assigned to this branch or clocked in here in this
                    period. Choose another branch or period.
                </p>
            </div>
            <template v-else>
                <p
                    v-if="data.rows.some((r) => r.otherBranch)"
                    class="text-[13px] text-muted-foreground"
                >
                    Figures include time worked at other branches. The period
                    runs up to today.
                </p>

                <!-- From 1280 px: a table with its Total row -->
                <div class="hidden xl:block" data-test="summary-table">
                    <V2Table label="Attendance summary" :columns="8">
                        <template #head>
                            <tr>
                                <th scope="col">Person</th>
                                <th
                                    v-for="key in figureKeys"
                                    :key="key"
                                    scope="col"
                                    class="text-right"
                                >
                                    {{ labels[key] }}
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="r in data.rows"
                                :key="r.userId"
                                data-test="summary-row"
                            >
                                <td>
                                    <span class="font-medium">{{
                                        r.person
                                    }}</span>
                                    <span
                                        v-if="r.otherBranch"
                                        class="block text-[13px] text-muted-foreground"
                                        >Includes time at another branch</span
                                    >
                                </td>
                                <td
                                    v-for="key in figureKeys"
                                    :key="key"
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ figure(r, key) }}
                                </td>
                            </tr>
                            <tr
                                v-if="data.total"
                                class="font-semibold"
                                data-test="summary-total"
                            >
                                <td>Total</td>
                                <td
                                    v-for="key in figureKeys"
                                    :key="key"
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ figure(data.total, key) }}
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                </div>

                <!-- Below 1280 px: one card per person, label above value in two columns, the Total as its own card -->
                <ul
                    class="flex flex-col gap-3 xl:hidden"
                    data-test="summary-cards"
                >
                    <li
                        v-for="r in data.rows"
                        :key="r.userId"
                        class="rounded-[14px] border bg-card p-4 text-sm"
                    >
                        <h4 class="font-semibold">{{ r.person }}</h4>
                        <p
                            v-if="r.otherBranch"
                            class="text-[13px] text-muted-foreground"
                        >
                            Includes time at another branch
                        </p>
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">
                            <div
                                v-for="key in figureKeys"
                                :key="key"
                                class="flex min-w-0 flex-col"
                            >
                                <dt class="text-[13px] text-muted-foreground">
                                    {{ labels[key] }}
                                </dt>
                                <dd class="whitespace-nowrap tabular-nums">
                                    {{ figure(r, key) }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                    <li
                        v-if="data.total"
                        class="rounded-[14px] border bg-muted/40 p-4 text-sm"
                        data-test="summary-total-card"
                    >
                        <h4 class="font-semibold">Total</h4>
                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">
                            <div
                                v-for="key in figureKeys"
                                :key="key"
                                class="flex min-w-0 flex-col"
                            >
                                <dt class="text-[13px] text-muted-foreground">
                                    {{ labels[key] }}
                                </dt>
                                <dd
                                    class="font-semibold whitespace-nowrap tabular-nums"
                                >
                                    {{ figure(data.total, key) }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>
            </template>
        </template>
    </section>
</template>

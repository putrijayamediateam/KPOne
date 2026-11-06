<script setup lang="ts">
// HR-3b Summary tab (Director, HR Manager): one branch, one week or month, one row per person. On screen only.
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import type { SummaryTabData } from './types';

const props = defineProps<{ data: SummaryTabData }>();

const visit = (params: Record<string, string | number | undefined>) =>
    router.get(
        '/hr/attendance',
        {
            tab: 'summary',
            branch: props.data.branch?.id,
            period: props.data.period,
            date: props.data.date,
            ...params,
        },
        { preserveScroll: true },
    );
const branchOptions = computed(() =>
    props.data.branches.map((b) => ({ value: b.id, label: b.name })),
);
const figureKeys = [
    'rostered',
    'worked',
    'late',
    'leftEarly',
    'noClockIn',
    'noClockOut',
    'overtime',
] as const;
const periods = [
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
];
</script>

<template>
    <section
        aria-labelledby="summary-title"
        class="flex flex-col gap-4"
        data-test="summary-tab"
    >
        <h2 id="summary-title" class="sr-only">Attendance summary</h2>
        <EmptyState
            v-if="!data.branch"
            title="No active branch."
            description="Add a branch first."
        />
        <template v-else>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
                    <Label for="summary-branch">Branch</Label>
                    <OperationalSelect
                        id="summary-branch"
                        :model-value="data.branch.id"
                        :options="branchOptions"
                        label="Branch"
                        class="min-w-48"
                        @update:model-value="(v) => visit({ branch: v })"
                    />
                </div>
                <div class="grid gap-1">
                    <span class="text-sm font-medium">Period</span>
                    <OperationalTabs
                        :model-value="data.period ?? 'month'"
                        :tabs="periods"
                        label="Period"
                        class="max-sm:[&>button]:h-11"
                        @update:model-value="
                            (v: string) => visit({ period: v })
                        "
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="summary-date">{{
                        data.period === 'week' ? 'Week of' : 'Month of'
                    }}</Label>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            :aria-label="
                                data.period === 'week'
                                    ? 'Previous week'
                                    : 'Previous month'
                            "
                            @click="visit({ date: data.prevDate })"
                            >‹</Button
                        >
                        <Input
                            id="summary-date"
                            type="date"
                            class="min-h-11 w-40 sm:min-h-9"
                            :model-value="data.date ?? ''"
                            @change="
                                (e: Event) =>
                                    visit({
                                        date: (e.target as HTMLInputElement)
                                            .value,
                                    })
                            "
                        />
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            :aria-label="
                                data.period === 'week'
                                    ? 'Next week'
                                    : 'Next month'
                            "
                            @click="visit({ date: data.nextDate })"
                            >›</Button
                        >
                    </div>
                </div>
            </div>
            <h3 class="text-base font-semibold" data-test="summary-period">
                {{ data.branch.name }} · {{ data.periodLabel }}
            </h3>
            <p v-if="!data.settingsReady" class="text-sm text-muted-foreground">
                Attendance is not set up yet, so late and early counts are not
                worked out.
            </p>
            <EmptyState
                v-if="data.rows.length === 0"
                title="Nobody to show for this period."
                description="Nobody is assigned to this branch or clocked in here in this period. Choose another branch or period."
            />
            <template v-else>
                <p
                    v-if="data.rows.some((r) => r.otherBranch)"
                    class="text-xs text-muted-foreground"
                >
                    Figures include time worked at other branches. The period
                    runs up to today.
                </p>
                <div class="hidden lg:block">
                    <OperationalTable
                        label="Attendance summary"
                        :columns="8"
                        min-width="1040px"
                    >
                        <template #head>
                            <tr>
                                <th
                                    scope="col"
                                    class="sticky left-0 z-10 bg-card shadow-[inset_0_0_0_999px_color-mix(in_oklab,var(--muted)_60%,transparent)]"
                                >
                                    Person
                                </th>
                                <th scope="col" class="text-right">Rostered</th>
                                <th scope="col" class="text-right">Worked</th>
                                <th scope="col" class="text-right">Late</th>
                                <th scope="col" class="text-right">
                                    Left early
                                </th>
                                <th scope="col" class="text-right">
                                    No clock-in
                                </th>
                                <th scope="col" class="text-right">
                                    No clock-out
                                </th>
                                <th scope="col" class="text-right">
                                    Overtime approved
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="r in data.rows"
                                :key="r.userId"
                                data-test="summary-row"
                            >
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card text-left font-medium"
                                >
                                    {{ r.person }}
                                    <span
                                        v-if="r.otherBranch"
                                        class="block text-xs font-normal text-muted-foreground"
                                        >Includes time at another branch</span
                                    >
                                </th>
                                <td
                                    v-for="key in figureKeys"
                                    :key="key"
                                    class="text-right tabular-nums"
                                >
                                    {{ r[key] }}
                                </td>
                            </tr>
                            <tr
                                v-if="data.total"
                                class="font-semibold"
                                data-test="summary-total"
                            >
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card text-left"
                                >
                                    Total
                                </th>
                                <td
                                    v-for="key in figureKeys"
                                    :key="key"
                                    class="text-right tabular-nums"
                                >
                                    {{ data.total[key] }}
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </div>
                <ul class="flex flex-col gap-2 lg:hidden">
                    <li
                        v-for="r in data.rows"
                        :key="r.userId"
                        class="rounded-xl border bg-card p-4 text-sm"
                    >
                        <p class="font-semibold">{{ r.person }}</p>
                        <p
                            v-if="r.otherBranch"
                            class="text-xs text-muted-foreground"
                        >
                            Includes time at another branch
                        </p>
                        <dl
                            class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1"
                        >
                            <dt class="text-muted-foreground">Rostered</dt>
                            <dd class="tabular-nums">{{ r.rostered }}</dd>
                            <dt class="text-muted-foreground">Worked</dt>
                            <dd class="tabular-nums">{{ r.worked }}</dd>
                            <dt class="text-muted-foreground">Late</dt>
                            <dd class="tabular-nums">{{ r.late }}</dd>
                            <dt class="text-muted-foreground">Left early</dt>
                            <dd class="tabular-nums">{{ r.leftEarly }}</dd>
                            <dt class="text-muted-foreground">No clock-in</dt>
                            <dd class="tabular-nums">{{ r.noClockIn }}</dd>
                            <dt class="text-muted-foreground">No clock-out</dt>
                            <dd class="tabular-nums">{{ r.noClockOut }}</dd>
                            <dt class="text-muted-foreground">Overtime</dt>
                            <dd class="tabular-nums">{{ r.overtime }}</dd>
                        </dl>
                    </li>
                </ul>
            </template>
        </template>
    </section>
</template>

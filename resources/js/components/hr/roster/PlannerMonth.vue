<script setup lang="ts">
/**
 * HR-22e (HR-PLAN 29.6): the planner's month view for one branch. Each week that touches the month is a card with its
 * status and its shifts per person and day in short form; a station shows as a short tag with its full name for screen
 * readers. Read mostly: a shift is edited in the week view ("Open week"), and "Copy to the rest of the month" asks first.
 */
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { StatusBadge } from '@/components/ui/status';
import type {
    PlannerMonth,
    PlannerMonthWeek,
} from '@/composables/hr/useRosterPage';

const emit = defineEmits<{
    open: [week: string];
    copy: [week: PlannerMonthWeek, done: () => void];
}>();

const props = defineProps<{
    month: PlannerMonth;
    copying: string | null;
    copyError: string;
}>();
const asking = ref<PlannerMonthWeek | null>(null);
// The last week copied to may run into the next month (its Monday is in this month).
const inSentence = (label: string) => label.replace(/^Week/, 'week');
const lastLabel = (week: PlannerMonthWeek) =>
    props.month.weeks[props.month.weeks.length - 1]?.label ?? week.label;
const confirmOpen = ref(false);
const ask = (week: PlannerMonthWeek) => {
    asking.value = week;
    confirmOpen.value = true;
};
const confirm = () => {
    if (asking.value) {
        emit('copy', asking.value, () => {
            confirmOpen.value = false;
        });
    }
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4" data-test="planner-month">
        <section
            v-for="week in month.weeks"
            :key="week.start"
            class="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4"
            :aria-labelledby="`week-${week.start}`"
            data-test="planner-month-week"
        >
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <h3 :id="`week-${week.start}`" class="text-sm font-semibold">
                    {{ week.label }}
                </h3>
                <StatusBadge
                    v-if="week.status"
                    :status="`roster_${week.status}`"
                    :label="week.statusLabel"
                />
                <span v-else class="text-xs text-muted-foreground">{{
                    week.statusLabel
                }}</span>
                <span
                    v-if="week.approvedToo"
                    class="text-xs text-muted-foreground"
                    >The approved week stays in force until this one is
                    approved.</span
                >
                <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                    <Button
                        variant="outline"
                        size="sm"
                        class="min-h-11 sm:min-h-8"
                        :aria-label="`Open ${week.label}`"
                        @click="emit('open', week.start)"
                        >Open week</Button
                    >
                    <Button
                        v-if="week.canCopy"
                        variant="outline"
                        size="sm"
                        class="min-h-11 sm:min-h-8"
                        :aria-label="`Copy ${week.label} to the rest of the month`"
                        data-test="copy-to-month"
                        @click="ask(week)"
                        ><span class="sm:hidden">Copy to rest of month</span
                        ><span class="hidden sm:inline"
                            >Copy to the rest of the month</span
                        ></Button
                    >
                </div>
            </div>

            <p
                v-if="week.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No shifts.
            </p>
            <p
                v-if="week.rows.length > 0"
                class="text-xs text-muted-foreground lg:hidden"
            >
                Scroll sideways for the rest of the week.
            </p>
            <div v-if="week.rows.length > 0" class="relative overflow-x-auto">
                <table
                    class="w-full min-w-[60rem] table-fixed border-collapse text-sm"
                >
                    <caption class="sr-only">
                        Shifts in the
                        {{
                            week.label
                        }}
                    </caption>
                    <thead>
                        <tr class="border-b text-left text-xs">
                            <th
                                scope="col"
                                class="sticky left-0 z-10 w-32 bg-card py-2 pr-3 font-medium lg:w-44"
                            >
                                Staff
                            </th>
                            <th
                                v-for="day in week.days"
                                :key="day.date"
                                scope="col"
                                class="px-2 py-2 font-medium"
                                :class="
                                    day.inMonth ? '' : 'text-muted-foreground'
                                "
                            >
                                {{ day.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in week.rows"
                            :key="row.staffUserId"
                            class="border-b align-top last:border-b-0"
                        >
                            <th
                                scope="row"
                                class="sticky left-0 z-10 bg-card py-2 pr-3 text-left"
                            >
                                <span class="block font-medium">{{
                                    row.name
                                }}</span>
                                <span
                                    v-if="row.role"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ row.role }}</span
                                >
                            </th>
                            <td
                                v-for="(cells, i) in row.days"
                                :key="i"
                                class="px-2 py-2"
                            >
                                <span v-if="cells.length === 0" class="sr-only"
                                    >No shift</span
                                >
                                <span
                                    v-for="(cell, j) in cells"
                                    :key="j"
                                    class="flex flex-wrap items-center gap-x-1 tabular-nums"
                                    ><span class="whitespace-nowrap">{{
                                        cell.time
                                    }}</span
                                    ><template v-if="cell.station">
                                        <span
                                            class="rounded border px-1 text-xs text-muted-foreground"
                                            aria-hidden="true"
                                            :title="cell.stationLabel ?? ''"
                                            >{{ cell.station }}</span
                                        ><span class="sr-only"
                                            >, {{ cell.stationLabel }}</span
                                        ></template
                                    ><span
                                        v-if="cell.onCall"
                                        class="text-xs text-muted-foreground"
                                        >On call</span
                                    ></span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <OperationalConfirmDialog
            v-model:open="confirmOpen"
            title="Copy this week to the rest of the month?"
            :description="
                asking
                    ? `The shifts in the ${inSentence(asking.label)} are copied to each later week of ${month.label}, up to the ${inSentence(lastLabel(asking))}. A week with no roster gets a new draft; a week with a draft gets the shifts added. Weeks waiting for approval or already approved are not changed. Each week is still sent for approval on its own.`
                    : ''
            "
            confirm-label="Copy"
            processing-label="Copying…"
            :processing="copying !== null"
            :error="copyError"
            @confirm="confirm"
        />
    </div>
</template>

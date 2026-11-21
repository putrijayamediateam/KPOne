<script setup lang="ts">
/**
 * HR-15f: the month calendar on My roster (owner, via B.E.R.N.I.S.S, 18 Nov 2026; layout by Daniel). Shared by Classic
 * and Version 2. The person's own approved shifts and their own open-shift asks only. From 640 px a Monday-first table;
 * below it a list of the days that have something. A day with items is one button that opens the day sheet, which is the
 * same content as the phone list. Lines inside a cell are plain text, never interactive.
 */
import { computed, ref } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { MonthDay, RosterMonth } from '@/composables/hr/useRosterPage';

const props = defineProps<{ month: RosterMonth }>();

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const WEEKDAY_NAMES = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

const weeks = computed(() => {
    const out: MonthDay[][] = [];

    for (let i = 0; i < props.month.days.length; i += 7) {
        out.push(props.month.days.slice(i, i + 7));
    }

    return out;
});
const busyDays = computed(() =>
    props.month.days.filter(
        (d) => d.inMonth && d.shifts.length + d.asks.length > 0,
    ),
);

const count = (day: MonthDay) => day.shifts.length + day.asks.length;
// Asks still waiting, and asks HR confirmed into a week not approved yet, are counted apart (Daniel).
const waiting = (day: MonthDay) =>
    day.asks.filter((a) => a.status === 'asked').length;
const pending = (day: MonthDay) =>
    day.asks.filter((a) => a.status === 'confirmed').length;
const plural = (n: number, one: string, many: string) =>
    `${n} ${n === 1 ? one : many}`;
const countWords = (day: MonthDay) =>
    [
        day.shifts.length > 0
            ? plural(day.shifts.length, 'shift', 'shifts')
            : '',
        waiting(day) > 0
            ? plural(waiting(day), 'open shift ask', 'open shift asks')
            : '',
        pending(day) > 0
            ? plural(
                  pending(day),
                  'confirmed shift not approved yet',
                  'confirmed shifts not approved yet',
              )
            : '',
    ]
        .filter(Boolean)
        .join(', ');
// Shares the AM/PM when both ends have the same one: "8:00–10:00 AM" (Daniel), so the branch name fits.
const compact = (start: string, end: string) => {
    const s = start.split(' ');
    const e = end.split(' ');

    return s[1] && s[1] === e[1] ? `${s[0]}–${end}` : `${start}–${end}`;
};
// The short count in a narrow cell: "2 shifts", "1 shift, 1 ask", "1 not approved" (Daniel).
const shortCount = (day: MonthDay) =>
    [
        day.shifts.length > 0
            ? plural(day.shifts.length, 'shift', 'shifts')
            : '',
        waiting(day) > 0 ? plural(waiting(day), 'ask', 'asks') : '',
        pending(day) > 0 ? `${pending(day)} not approved` : '',
    ]
        .filter(Boolean)
        .join(', ');
const time = (item: {
    startLabel: string;
    endLabel: string;
    nextDay: boolean;
}) => `${item.startLabel}–${item.endLabel}${item.nextDay ? ' (next day)' : ''}`;
// Each cell line: shifts first, then asks, as plain text.
const lines = (day: MonthDay) => [
    ...day.shifts.map((s) => ({
        key: s.id,
        ask: false,
        text: `${compact(s.startLabel, s.endLabel)} ${s.branch}`,
    })),
    ...day.asks.map((a) => ({
        key: a.id,
        ask: true,
        text: `${a.shortLabel} ${a.startLabel}`,
    })),
];

const open = ref<MonthDay | null>(null);
</script>

<template>
    <div class="flex flex-col gap-3" data-test="month-calendar">
        <p class="text-sm text-muted-foreground" data-test="month-hours">
            {{ month.hoursLabel }} approved this month
        </p>

        <!-- Phones: the days that have something, as cards. -->
        <div class="sm:hidden">
            <p
                v-if="busyDays.length === 0"
                class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                data-test="month-empty"
            >
                No shifts this month.
            </p>
            <ul v-else class="flex flex-col gap-3" data-test="month-list">
                <li
                    v-for="day in busyDays"
                    :key="day.date"
                    class="rounded-xl border bg-card p-4"
                >
                    <h3 class="text-sm font-semibold">
                        {{ day.label
                        }}<span v-if="day.today" class="text-muted-foreground">
                            · Today</span
                        >
                    </h3>
                    <ul class="mt-2 flex flex-col gap-2 text-sm">
                        <li
                            v-for="shift in day.shifts"
                            :key="shift.id"
                            class="flex flex-wrap gap-x-3 gap-y-1"
                        >
                            <span class="font-medium tabular-nums">{{
                                time(shift)
                            }}</span>
                            <span>{{ shift.branch }}</span>
                            <span
                                v-if="shift.roomName"
                                class="text-muted-foreground"
                                >{{ shift.roomName }}</span
                            >
                            <span class="text-muted-foreground">{{
                                shift.hours
                            }}</span>
                        </li>
                        <li
                            v-for="ask in day.asks"
                            :key="ask.id"
                            class="flex flex-wrap gap-x-3 gap-y-1 rounded-md border border-dashed px-2 py-1"
                        >
                            <span class="font-medium tabular-nums">{{
                                time(ask)
                            }}</span>
                            <span>{{ ask.branch }}</span>
                            <span class="text-muted-foreground">{{
                                ask.label
                            }}</span>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- From 640 px: a Monday-first month table. -->
        <table
            class="hidden w-full table-fixed border-collapse sm:table"
            data-test="month-grid"
        >
            <caption class="sr-only">
                {{
                    month.label
                }}
            </caption>
            <thead>
                <tr>
                    <th
                        v-for="(d, i) in WEEKDAYS"
                        :key="d"
                        scope="col"
                        class="pb-1 text-left text-xs font-medium text-muted-foreground"
                    >
                        <abbr :title="WEEKDAY_NAMES[i]" class="no-underline">{{
                            d
                        }}</abbr>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(week, w) in weeks" :key="w">
                    <td
                        v-for="day in week"
                        :key="day.date"
                        class="h-20 border p-0 align-top lg:h-28"
                        :class="day.inMonth ? 'bg-card' : 'bg-muted/40'"
                    >
                        <button
                            v-if="day.inMonth && count(day) > 0"
                            type="button"
                            class="flex h-full min-h-11 w-full flex-col gap-1 p-1.5 text-left hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                            :aria-label="`${day.label}${day.today ? ', today' : ''}, ${countWords(day)}`"
                            data-test="month-day"
                            @click="open = day"
                        >
                            <span
                                class="inline-flex size-6 items-center justify-center rounded-full text-xs font-medium tabular-nums"
                                :class="day.today ? 'ring-2 ring-primary' : ''"
                                >{{ day.day }}</span
                            >
                            <!-- 640 to 1023 px: a count and a dot; details in the sheet. -->
                            <span
                                class="flex items-start gap-1 text-xs leading-tight lg:hidden"
                            >
                                <span
                                    class="mt-1 size-1.5 shrink-0 rounded-full bg-foreground"
                                    aria-hidden="true"
                                />
                                <!-- Wraps rather than cut "not approved" (Daniel). -->
                                <span class="min-w-0 break-words">{{
                                    shortCount(day)
                                }}</span>
                            </span>
                            <!-- From 1024 px: 2 lines (3 from 1280), then "+N more". -->
                            <span
                                v-for="(line, i) in lines(day)"
                                :key="line.key"
                                class="hidden text-xs"
                                :class="[
                                    i < 2
                                        ? 'lg:block'
                                        : i === 2
                                          ? 'xl:block'
                                          : '',
                                    // A shift line is cut at the end, never the time; an ask wraps so its word and time stay whole.
                                    line.ask
                                        ? 'rounded border border-dashed px-1 leading-tight break-words'
                                        : 'truncate',
                                ]"
                                >{{ line.text }}</span
                            >
                            <span
                                v-if="lines(day).length > 2"
                                class="hidden text-xs text-muted-foreground lg:block xl:hidden"
                                >+{{ lines(day).length - 2 }} more</span
                            >
                            <span
                                v-if="lines(day).length > 3"
                                class="hidden text-xs text-muted-foreground xl:block"
                                >+{{ lines(day).length - 3 }} more</span
                            >
                        </button>
                        <div
                            v-else
                            class="p-1.5"
                            :class="day.inMonth ? '' : 'text-muted-foreground'"
                        >
                            <span
                                class="inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums"
                                :class="day.today ? 'ring-2 ring-primary' : ''"
                                >{{ day.day }}</span
                            >
                            <span v-if="day.today" class="sr-only">Today</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <Sheet
            :open="open !== null"
            @update:open="(value) => !value && (open = null)"
        >
            <SheetContent class="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>{{ open?.label }}</SheetTitle>
                    <SheetDescription
                        >Hours count approved shifts only. Times are in each
                        branch's time.</SheetDescription
                    >
                </SheetHeader>
                <ul
                    v-if="open"
                    class="flex flex-col gap-3 px-4 pb-6 text-sm"
                    data-test="month-day-sheet"
                >
                    <li
                        v-for="shift in open.shifts"
                        :key="shift.id"
                        class="flex flex-col gap-0.5 rounded-xl border p-3"
                    >
                        <span class="font-medium tabular-nums">{{
                            time(shift)
                        }}</span>
                        <span
                            >{{ shift.branch }} · {{ shift.sessionLabel }}</span
                        >
                        <span
                            v-if="shift.roomName"
                            class="text-muted-foreground"
                            >{{ shift.roomName }}</span
                        >
                        <span class="text-muted-foreground"
                            >{{ shift.hours
                            }}<template v-if="shift.breakMinutes > 0">
                                after a {{ shift.breakMinutes }} min
                                break</template
                            ></span
                        >
                    </li>
                    <li
                        v-for="ask in open.asks"
                        :key="ask.id"
                        class="flex flex-col gap-0.5 rounded-xl border border-dashed p-3"
                    >
                        <span class="font-medium tabular-nums">{{
                            time(ask)
                        }}</span>
                        <span>{{ ask.branch }}</span>
                        <span class="text-muted-foreground">{{
                            ask.label
                        }}</span>
                    </li>
                </ul>
            </SheetContent>
        </Sheet>
    </div>
</template>

<script setup lang="ts">
import { nextTick, ref } from 'vue';

/**
 * Version 2 week grid: people as rows and the seven days as columns from 1280 px, one card per day below 1280 px.
 * The first column and the day row stay in view while the page scrolls (the day row sits under the V2 top bar).
 * A time never breaks inside itself; an empty editable cell shows "+ Add" on hover and focus. Every entry is text (for a shift, its times), a note such as
 * "On leave" is a word, and a day warning shows an icon and a word ("Short"), never colour alone. Arrow keys move
 * between the cells of the grid; Home and End go to the first and last day of the row; Enter or Space on a cell
 * opens its first entry, or asks to add one when the cell is empty. The block only shows and
 * emits: `open` for an entry and `add` for an empty slot. The page opens its own V2Dialog, sends the request and
 * decides who may edit (`editable`). V2 views only.
 */
export type WeekGridDay = { key: string; label: string; long: string };
export type WeekGridEntry = {
    key: string;
    /** The main line, for example "8:00 AM – 2:00 PM". */
    label: string;
    /** A muted second line, for example "Morning · Room 2". */
    detail?: string;
    /** The spoken name of the entry's button, for example "Edit shift: Aisyah, Mon 16 Nov, 8:00 AM to 2:00 PM". */
    actionLabel?: string;
};
export type WeekGridRow = {
    key: string | number;
    title: string;
    subtitle?: string;
    entries: Record<string, WeekGridEntry[]>;
    /** A word for a day, for example "On leave" or "On leave (half day)". */
    notes?: Record<string, string>;
    /** A warning for one person on one day, shown with an icon and the words, for example "Rest under 11 h". */
    warnings?: Record<string, string>;
};
export type WeekGridTotal = { text: string; warning?: string };

const props = withDefaults(
    defineProps<{
        label: string;
        days: WeekGridDay[];
        rows: WeekGridRow[];
        /** The column head of the first column, for example "Staff". */
        rowHeader?: string;
        totals?: Record<string, WeekGridTotal>;
        totalsLabel?: string;
        editable?: boolean;
        addLabel?: string;
        emptyMessage?: string;
    }>(),
    {
        rowHeader: 'Staff',
        totals: undefined,
        totalsLabel: 'Total',
        editable: false,
        addLabel: 'Add',
        emptyMessage: 'Nothing planned yet.',
    },
);

const emit = defineEmits<{
    open: [entryKey: string, rowKey: string | number, dayKey: string];
    add: [dayKey: string, rowKey: string | number | null];
}>();

// Roving focus: one cell of the grid is in the tab order; arrows move it.
const focusRow = ref(0);
const focusCol = ref(0);
const grid = ref<HTMLElement | null>(null);

const isFocusCell = (r: number, c: number) =>
    focusRow.value === r && focusCol.value === c;

const moveTo = async (r: number, c: number) => {
    focusRow.value = Math.max(0, Math.min(props.rows.length - 1, r));
    focusCol.value = Math.max(0, Math.min(props.days.length - 1, c));
    await nextTick();
    grid.value
        ?.querySelector<HTMLElement>(
            `[data-cell="${focusRow.value}-${focusCol.value}"]`,
        )
        ?.focus();
};

const onKey = (event: KeyboardEvent, r: number, c: number) => {
    const moves: Record<string, [number, number]> = {
        ArrowRight: [r, c + 1],
        ArrowLeft: [r, c - 1],
        ArrowDown: [r + 1, c],
        ArrowUp: [r - 1, c],
        Home: [r, 0],
        End: [r, props.days.length - 1],
    };

    // Only act on the cell itself, not on a button inside it.
    if ((event.target as HTMLElement).dataset.cell === undefined) {
        return;
    }

    // Enter or Space on a cell opens its first entry, or asks to add one when it is empty.
    if (event.key === 'Enter' || event.key === ' ') {
        if (!props.editable) {
            return;
        }

        event.preventDefault();
        const row = props.rows[r];
        const day = props.days[c];
        const first = row?.entries[day?.key ?? '']?.[0];

        if (row && day && first) {
            emit('open', first.key, row.key, day.key);
        } else if (row && day) {
            emit('add', day.key, row.key);
        }

        return;
    }

    const to = moves[event.key];

    if (!to) {
        return;
    }

    event.preventDefault();
    moveTo(to[0], to[1]);
};

// A time never splits inside itself ("2:00 PM"); the line may only break after the en dash.
const noBreak = (text: string) =>
    text
        .split(' – ')
        .map((part) => part.replace(/ /g, '\u00a0'))
        .join(' – ');

// What a screen reader hears on a cell, for example "Aisyah, Tue 17 Nov, 8:00 AM to 2:00 PM, Morning. Press Enter to
// edit." or "Add shift for Aisyah on Wed 18 Nov".
const cellName = (row: WeekGridRow, day: WeekGridDay) => {
    const entries = row.entries[day.key] ?? [];
    const parts = [
        row.notes?.[day.key],
        row.warnings?.[day.key],
        ...entries.map((e) =>
            [e.label.replace(' – ', ' to '), e.detail]
                .filter(Boolean)
                .join(', '),
        ),
    ].filter(Boolean);

    if (parts.length === 0) {
        return props.editable
            ? `${props.addLabel} for ${row.title} on ${day.long}`
            : `${row.title}, ${day.long}, nothing planned`;
    }

    const said = `${row.title}, ${day.long}, ${parts.join('; ')}`;

    if (!props.editable) {
        return said;
    }

    return entries.length > 0
        ? `${said}. Press Enter to edit.`
        : `${said}. Press Enter to ${props.addLabel.toLowerCase()}.`;
};

const dayItems = (dayKey: string) =>
    props.rows.flatMap((row) =>
        (row.entries[dayKey] ?? []).map((entry) => ({ row, entry })),
    );
const dayWarnings = (dayKey: string) =>
    props.rows
        .filter((row) => row.warnings?.[dayKey])
        .map((row) => `${row.title}: ${row.warnings?.[dayKey]}`);
const dayNotes = (dayKey: string) =>
    props.rows
        .filter((row) => row.notes?.[dayKey])
        .map((row) => `${row.title}: ${row.notes?.[dayKey]}`);

const entryButton =
    'flex w-full min-w-0 flex-col items-start rounded-[10px] border border-border bg-card px-2 py-1.5 text-left hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
const textLink =
    'inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
</script>

<template>
    <div data-slot="v2-week-grid" class="min-w-0">
        <!-- From 1280 px: people as rows, days as columns -->
        <div
            ref="grid"
            class="max-w-full rounded-[14px] border bg-card max-xl:hidden"
            role="region"
            :aria-label="label"
        >
            <p
                v-if="rows.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                {{ emptyMessage }}
            </p>
            <table
                v-else
                role="grid"
                :aria-label="label"
                class="w-full table-fixed border-collapse text-left text-sm"
            >
                <colgroup>
                    <col class="w-40" />
                    <col v-for="day in days" :key="day.key" />
                </colgroup>
                <thead>
                    <tr class="border-b">
                        <th
                            scope="col"
                            class="sticky top-16 left-0 z-[3] bg-card px-3 py-2 text-[13px] font-medium text-muted-foreground"
                        >
                            {{ rowHeader }}
                        </th>
                        <th
                            v-for="day in days"
                            :key="day.key"
                            scope="col"
                            class="sticky top-16 z-[2] bg-card px-2 py-2 text-[13px] font-medium text-muted-foreground"
                        >
                            <span class="sr-only">{{ day.long }}</span
                            ><span aria-hidden="true">{{ day.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, r) in rows"
                        :key="row.key"
                        class="border-b align-top last:border-b-0"
                    >
                        <th
                            scope="row"
                            class="sticky left-0 z-[1] bg-card px-3 py-2 font-normal"
                        >
                            <span class="block font-medium break-words">{{
                                row.title
                            }}</span>
                            <span
                                v-if="row.subtitle"
                                class="block text-[13px] text-muted-foreground"
                                >{{ row.subtitle }}</span
                            >
                        </th>
                        <td
                            v-for="(day, c) in days"
                            :key="day.key"
                            role="gridcell"
                            :data-cell="`${r}-${c}`"
                            :tabindex="isFocusCell(r, c) ? 0 : -1"
                            :aria-label="cellName(row, day)"
                            class="group/cell relative px-1.5 py-1.5 outline-none focus-visible:z-[1] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid"
                            @keydown="onKey($event, r, c)"
                            @focus="
                                focusRow = r;
                                focusCol = c;
                            "
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <span
                                    v-if="row.notes?.[day.key]"
                                    class="text-[13px] font-medium"
                                    >{{ row.notes[day.key] }}</span
                                >
                                <span
                                    v-if="row.warnings?.[day.key]"
                                    class="inline-flex items-start gap-1 text-[13px] font-medium"
                                >
                                    <svg
                                        class="mt-0.5 size-3.5 shrink-0 text-warning"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.25"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.01"
                                        />
                                    </svg>
                                    {{ row.warnings[day.key] }}
                                </span>
                                <button
                                    v-if="
                                        editable &&
                                        (row.entries[day.key] ?? []).length ===
                                            0
                                    "
                                    type="button"
                                    tabindex="-1"
                                    aria-hidden="true"
                                    class="flex min-h-9 w-full items-center justify-center rounded-[10px] border border-dashed border-border text-[13px] font-medium text-muted-foreground opacity-0 group-hover/cell:opacity-100 group-focus-visible/cell:opacity-100 hover:bg-muted"
                                    @click="emit('add', day.key, row.key)"
                                >
                                    + {{ addLabel }}
                                </button>
                                <template
                                    v-for="entry in row.entries[day.key] ?? []"
                                    :key="entry.key"
                                >
                                    <button
                                        v-if="editable"
                                        type="button"
                                        tabindex="-1"
                                        :class="entryButton"
                                        :aria-label="entry.actionLabel"
                                        @click="
                                            emit(
                                                'open',
                                                entry.key,
                                                row.key,
                                                day.key,
                                            )
                                        "
                                    >
                                        <span
                                            class="font-medium tabular-nums"
                                            >{{ noBreak(entry.label) }}</span
                                        >
                                        <span
                                            v-if="entry.detail"
                                            class="text-[12px] text-muted-foreground"
                                            >{{ entry.detail }}</span
                                        >
                                    </button>
                                    <span
                                        v-else
                                        class="flex flex-col px-2 py-1.5"
                                    >
                                        <span
                                            class="font-medium tabular-nums"
                                            >{{ noBreak(entry.label) }}</span
                                        >
                                        <span
                                            v-if="entry.detail"
                                            class="text-[12px] text-muted-foreground"
                                            >{{ entry.detail }}</span
                                        >
                                    </span>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="totals">
                    <tr class="border-t">
                        <th
                            scope="row"
                            class="sticky left-0 z-[1] bg-card px-3 py-2 font-medium"
                        >
                            {{ totalsLabel }}
                        </th>
                        <td
                            v-for="day in days"
                            :key="day.key"
                            class="px-2 py-2"
                        >
                            <span class="flex flex-col gap-1">
                                <span class="tabular-nums">{{
                                    totals[day.key]?.text
                                }}</span>
                                <span
                                    v-if="totals[day.key]?.warning"
                                    class="inline-flex items-center gap-1 text-[13px] font-medium text-foreground"
                                >
                                    <svg
                                        class="size-3.5 shrink-0 text-warning"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.25"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.01"
                                        />
                                    </svg>
                                    {{ totals[day.key]?.warning }}
                                </span>
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Below 1280 px: one card per day -->
        <ul class="grid gap-3 md:grid-cols-2 xl:hidden">
            <li
                v-for="day in days"
                :key="day.key"
                class="flex min-w-0 flex-col gap-2 rounded-[14px] border bg-card p-4"
            >
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">{{ day.long }}</h3>
                    <span
                        v-if="totals?.[day.key]"
                        class="flex items-center gap-2 text-[13px] text-muted-foreground"
                    >
                        {{ totals[day.key].text }}
                        <span
                            v-if="totals[day.key].warning"
                            class="inline-flex items-center gap-1 font-medium text-foreground"
                        >
                            <svg
                                class="size-3.5 shrink-0 text-warning"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.25"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.01" />
                            </svg>
                            {{ totals[day.key].warning }}
                        </span>
                    </span>
                </div>
                <p
                    v-for="warning in dayWarnings(day.key)"
                    :key="warning"
                    class="inline-flex items-start gap-1 text-[13px] font-medium"
                >
                    <svg
                        class="mt-0.5 size-3.5 shrink-0 text-warning"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.25"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 3 2 20h20L12 3Zm0 6v5m0 3v.01" />
                    </svg>
                    {{ warning }}
                </p>
                <p
                    v-for="note in dayNotes(day.key)"
                    :key="note"
                    class="text-[13px] text-muted-foreground"
                >
                    {{ note }}
                </p>
                <p
                    v-if="dayItems(day.key).length === 0"
                    class="text-[13px] text-muted-foreground"
                >
                    {{ emptyMessage }}
                </p>
                <ul v-else class="flex flex-col divide-y">
                    <li
                        v-for="item in dayItems(day.key)"
                        :key="item.entry.key"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <div class="min-w-0 text-sm">
                            <p class="font-medium break-words">
                                {{ item.row.title }}
                            </p>
                            <p class="text-[13px] text-muted-foreground">
                                <template v-if="item.row.subtitle"
                                    >{{ item.row.subtitle }} · </template
                                ><span class="tabular-nums">{{
                                    noBreak(item.entry.label)
                                }}</span
                                ><template v-if="item.entry.detail">
                                    · {{ item.entry.detail }}</template
                                >
                            </p>
                        </div>
                        <button
                            v-if="editable"
                            type="button"
                            :class="button"
                            :aria-label="item.entry.actionLabel"
                            @click="
                                emit(
                                    'open',
                                    item.entry.key,
                                    item.row.key,
                                    day.key,
                                )
                            "
                        >
                            Edit
                        </button>
                    </li>
                </ul>
                <button
                    v-if="editable"
                    type="button"
                    :class="[textLink, 'self-start']"
                    @click="emit('add', day.key, null)"
                >
                    {{ addLabel
                    }}<span class="sr-only"> on {{ day.long }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>

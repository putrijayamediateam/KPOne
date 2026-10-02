<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';

type DateRange = { from: string; to: string };
type PresetId =
    | 'yesterday'
    | 'last-7-days'
    | 'last-30-days'
    | 'last-90-days'
    | 'this-week'
    | 'this-month'
    | 'this-year'
    | 'last-week'
    | 'last-month'
    | 'custom';

const props = defineProps<DateRange>();
const emit = defineEmits<{
    change: [range: DateRange];
}>();

const presets: { id: PresetId; label: string }[] = [
    { id: 'yesterday', label: 'Yesterday' },
    { id: 'last-7-days', label: 'Last 7 days' },
    { id: 'last-30-days', label: 'Last 30 days' },
    { id: 'last-90-days', label: 'Last 90 days' },
    { id: 'this-week', label: 'This week' },
    { id: 'this-month', label: 'This month' },
    { id: 'this-year', label: 'This year' },
    { id: 'last-week', label: 'Last week' },
    { id: 'last-month', label: 'Last month' },
    { id: 'custom', label: 'Custom' },
];
const weekdayLabels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const open = ref(false);
const draftFrom = ref(props.from);
const draftTo = ref(props.to);
const activePreset = ref<PresetId>(findPreset(props.from, props.to));
const selectingEnd = ref(false);
const visibleMonth = ref(firstOfMonth(parseDate(props.from)));

watch(
    () => [props.from, props.to] as const,
    ([from, to]) => {
        draftFrom.value = from;
        draftTo.value = to;
        activePreset.value = findPreset(from, to);
        visibleMonth.value = firstOfMonth(parseDate(from));
        selectingEnd.value = false;
    },
);

const monthLabel = computed(() =>
    visibleMonth.value.toLocaleDateString('en-MY', {
        month: 'long',
        year: 'numeric',
    }),
);
const calendarDays = computed(() => {
    const year = visibleMonth.value.getFullYear();
    const month = visibleMonth.value.getMonth();
    const firstWeekday = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cellCount = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

    return Array.from({ length: cellCount }, (_, index) => {
        const day = index - firstWeekday + 1;

        return day > 0 && day <= daysInMonth
            ? formatDate(new Date(year, month, day))
            : null;
    });
});
const displayLabel = computed(() => {
    const range =
        draftFrom.value && draftTo.value
            ? `${formatDisplayDate(draftFrom.value)} – ${formatDisplayDate(draftTo.value)}`
            : 'Select date range';
    const preset = presets.find((item) => item.id === activePreset.value);

    return activePreset.value === 'custom' || !preset
        ? range
        : `${preset.label}: ${range}`;
});
const hasValidDraft = computed(
    () =>
        draftFrom.value !== '' &&
        draftTo.value !== '' &&
        draftTo.value >= draftFrom.value &&
        daysBetween(draftFrom.value, draftTo.value) <= 365,
);
const rangeError = computed(() =>
    draftFrom.value !== '' &&
    draftTo.value !== '' &&
    daysBetween(draftFrom.value, draftTo.value) > 365
        ? 'Choose a date range of one year or less.'
        : '',
);

function parseDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function formatDate(value: Date): string {
    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function firstOfMonth(value: Date): Date {
    return new Date(value.getFullYear(), value.getMonth(), 1);
}

function todayInKualaLumpur(): Date {
    const value = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date());

    return parseDate(value);
}

function addDays(value: Date, amount: number): Date {
    const result = new Date(value);
    result.setDate(result.getDate() + amount);

    return result;
}

function daysBetween(from: string, to: string): number {
    return Math.round(
        (parseDate(to).getTime() - parseDate(from).getTime()) / 86_400_000,
    );
}

function rangesForToday(
    today: Date,
): Record<Exclude<PresetId, 'custom'>, DateRange> {
    const currentWeekStart = addDays(today, -((today.getDay() + 6) % 7));

    return {
        yesterday: {
            from: formatDate(addDays(today, -1)),
            to: formatDate(addDays(today, -1)),
        },
        'last-7-days': {
            from: formatDate(addDays(today, -6)),
            to: formatDate(today),
        },
        'last-30-days': {
            from: formatDate(addDays(today, -29)),
            to: formatDate(today),
        },
        'last-90-days': {
            from: formatDate(addDays(today, -89)),
            to: formatDate(today),
        },
        'this-week': {
            from: formatDate(currentWeekStart),
            to: formatDate(addDays(currentWeekStart, 6)),
        },
        'this-month': {
            from: formatDate(
                new Date(today.getFullYear(), today.getMonth(), 1),
            ),
            to: formatDate(
                new Date(today.getFullYear(), today.getMonth() + 1, 0),
            ),
        },
        'this-year': {
            from: formatDate(new Date(today.getFullYear(), 0, 1)),
            to: formatDate(new Date(today.getFullYear(), 11, 31)),
        },
        'last-week': {
            from: formatDate(addDays(currentWeekStart, -7)),
            to: formatDate(addDays(currentWeekStart, -1)),
        },
        'last-month': {
            from: formatDate(
                new Date(today.getFullYear(), today.getMonth() - 1, 1),
            ),
            to: formatDate(new Date(today.getFullYear(), today.getMonth(), 0)),
        },
    };
}

function findPreset(from: string, to: string): PresetId {
    const today = todayInKualaLumpur();
    const ranges = rangesForToday(today);

    return (
        presets.find(
            (preset) =>
                preset.id !== 'custom' &&
                ranges[preset.id].from === from &&
                ranges[preset.id].to === to,
        )?.id ?? 'custom'
    );
}

function formatDisplayDate(value: string): string {
    return parseDate(value).toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function selectPreset(id: PresetId): void {
    activePreset.value = id;

    if (id === 'custom') {
        draftFrom.value = '';
        draftTo.value = '';
        selectingEnd.value = false;

        return;
    }

    const range = rangesForToday(todayInKualaLumpur())[id];

    draftFrom.value = range.from;
    draftTo.value = range.to;
    visibleMonth.value = firstOfMonth(parseDate(range.from));
    selectingEnd.value = false;
    emit('change', range);
    open.value = false;
}

function moveMonth(amount: number): void {
    visibleMonth.value = new Date(
        visibleMonth.value.getFullYear(),
        visibleMonth.value.getMonth() + amount,
        1,
    );
}

function selectDay(value: string): void {
    activePreset.value = 'custom';

    if (!selectingEnd.value || value < draftFrom.value) {
        draftFrom.value = value;
        draftTo.value = '';
        selectingEnd.value = true;

        return;
    }

    draftTo.value = value;
    selectingEnd.value = false;

    if (hasValidDraft.value) {
        emit('change', { from: draftFrom.value, to: draftTo.value });
        open.value = false;
    }
}

function isInRange(value: string): boolean {
    return (
        draftFrom.value !== '' &&
        draftTo.value !== '' &&
        value > draftFrom.value &&
        value < draftTo.value
    );
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex h-10 min-w-64 items-center gap-2 rounded-md border border-input bg-card px-3 text-left text-sm shadow-xs transition-colors hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none"
                aria-label="Choose date range"
            >
                <CalendarDays class="size-4 shrink-0 text-muted-foreground" />
                <span class="truncate">{{ displayLabel }}</span>
            </button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="8"
                class="z-50 max-h-[min(85vh,var(--reka-popper-available-height))] w-[min(94vw,780px)] overflow-y-auto rounded-xl border bg-popover text-popover-foreground shadow-xl"
                aria-label="Date range picker"
            >
                <div class="grid md:grid-cols-[250px_minmax(0,1fr)]">
                    <div class="border-b md:border-r md:border-b-0">
                        <div class="grid grid-cols-2 md:grid-cols-1">
                            <button
                                v-for="preset in presets"
                                :key="preset.id"
                                type="button"
                                class="flex min-h-11 items-center gap-3 border-b px-4 py-2 text-left text-sm transition-colors last:border-b-0 hover:bg-muted/60 md:min-h-[58px]"
                                :class="
                                    activePreset === preset.id
                                        ? 'bg-muted/50 font-medium'
                                        : ''
                                "
                                @click="selectPreset(preset.id)"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full border-2"
                                    :class="
                                        activePreset === preset.id
                                            ? 'border-primary'
                                            : 'border-muted-foreground/60'
                                    "
                                    aria-hidden="true"
                                >
                                    <span
                                        v-if="activePreset === preset.id"
                                        class="size-2.5 rounded-full bg-primary"
                                    />
                                </span>
                                {{ preset.label }}
                            </button>
                        </div>
                    </div>
                    <div class="p-4 md:p-5">
                        <div
                            class="border-b pb-4 text-sm font-semibold tabular-nums"
                            aria-live="polite"
                        >
                            {{
                                draftFrom && draftTo
                                    ? `${formatDisplayDate(draftFrom)} – ${formatDisplayDate(draftTo)}`
                                    : draftFrom
                                      ? `${formatDisplayDate(draftFrom)} – Select end date`
                                      : 'Select a start and end date'
                            }}
                        </div>
                        <div
                            class="flex items-center justify-between border-b py-3"
                        >
                            <h2 class="text-sm font-semibold">
                                {{ monthLabel }}
                            </h2>
                            <div class="flex gap-1">
                                <button
                                    type="button"
                                    class="rounded-md p-2 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-label="Previous month"
                                    @click="moveMonth(-1)"
                                >
                                    <ChevronLeft class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md p-2 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-label="Next month"
                                    @click="moveMonth(1)"
                                >
                                    <ChevronRight class="size-4" />
                                </button>
                            </div>
                        </div>
                        <div
                            class="grid grid-cols-7 border-b text-center text-xs font-medium text-muted-foreground"
                        >
                            <span
                                v-for="(day, index) in weekdayLabels"
                                :key="index"
                                class="py-3"
                                >{{ day }}</span
                            >
                        </div>
                        <div
                            class="mx-auto grid max-w-[440px] grid-cols-7 gap-y-1 pt-2"
                        >
                            <span
                                v-for="(day, index) in calendarDays"
                                :key="`${visibleMonth.getTime()}-${index}`"
                                class="grid h-11 place-items-center"
                            >
                                <button
                                    v-if="day"
                                    type="button"
                                    class="grid size-10 place-items-center rounded-lg text-sm tabular-nums transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :class="{
                                        'bg-muted text-foreground':
                                            isInRange(day),
                                        'bg-primary text-primary-foreground hover:bg-primary':
                                            day === draftFrom ||
                                            day === draftTo,
                                    }"
                                    :aria-label="formatDisplayDate(day)"
                                    :aria-pressed="
                                        day === draftFrom || day === draftTo
                                    "
                                    @click="selectDay(day)"
                                >
                                    {{ Number(day.slice(-2)) }}
                                </button>
                            </span>
                        </div>
                        <p
                            v-if="rangeError"
                            class="mt-3 text-sm text-destructive"
                            role="alert"
                        >
                            {{ rangeError }}
                        </p>
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

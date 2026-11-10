<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import {
    DATE_FIELD_TEXT,
    MONTH_NAMES,
    MONTH_NAMES_MS,
    addDaysIso,
    addMonthsIso,
    calendarWeeks,
    normaliseIso,
    todayIso,
    withinRange,
} from '@/lib/date-field';
import type { DateFieldLocale } from '@/lib/date-field';

// The month grid behind the calendar button of DateField (UX-17). Sunday first, like the
// Insights picker. Arrow keys move by day or week, Page Up and Page Down by month.
const props = withDefaults(
    defineProps<{
        modelValue?: string;
        min?: string;
        max?: string;
        locale?: DateFieldLocale;
    }>(),
    { modelValue: '', min: undefined, max: undefined, locale: 'en' },
);
const emit = defineEmits<{ select: [iso: string]; clear: [] }>();

const text = computed(() => DATE_FIELD_TEXT[props.locale]);
const months = computed(() =>
    props.locale === 'ms' ? MONTH_NAMES_MS : MONTH_NAMES,
);
const selected = computed(() => normaliseIso(props.modelValue));
const startIso = (): string => {
    const base = selected.value ?? todayIso();

    if (!withinRange(base, props.min, props.max)) {
        return props.min && base < props.min ? props.min : (props.max ?? base);
    }

    return base;
};
const focused = ref(startIso());
const grid = ref<HTMLElement | null>(null);
const viewYear = computed(() => Number(focused.value.slice(0, 4)));
const viewMonth = computed(() => Number(focused.value.slice(5, 7)));
const weeks = computed(() => calendarWeeks(viewYear.value, viewMonth.value));
const today = computed(() => todayIso());
const monthLabel = computed(
    () => `${months.value[viewMonth.value - 1]} ${viewYear.value}`,
);

watch(
    () => props.modelValue,
    () => {
        focused.value = startIso();
    },
);

const longLabel = (iso: string): string =>
    `${Number(iso.slice(8, 10))} ${months.value[Number(iso.slice(5, 7)) - 1]} ${iso.slice(0, 4)}`;
const allowed = (iso: string): boolean => withinRange(iso, props.min, props.max);

const moveTo = async (iso: string, focusCell = true): Promise<void> => {
    focused.value = iso;

    if (focusCell) {
        await nextTick();
        grid.value
            ?.querySelector<HTMLButtonElement>(`[data-iso="${iso}"]`)
            ?.focus();
    }
};

const onKeydown = (event: KeyboardEvent): void => {
    const moves: Record<string, string> = {
        ArrowLeft: addDaysIso(focused.value, -1),
        ArrowRight: addDaysIso(focused.value, 1),
        ArrowUp: addDaysIso(focused.value, -7),
        ArrowDown: addDaysIso(focused.value, 7),
        PageUp: addMonthsIso(focused.value, -1),
        PageDown: addMonthsIso(focused.value, 1),
        Home: addDaysIso(focused.value, -new Date(`${focused.value}T12:00:00`).getDay()),
        End: addDaysIso(focused.value, 6 - new Date(`${focused.value}T12:00:00`).getDay()),
    };

    if (moves[event.key]) {
        event.preventDefault();
        void moveTo(moves[event.key]);
    }
};

const focusDay = async (): Promise<void> => {
    await moveTo(focused.value);
};
defineExpose({ focusDay });

const pick = (iso: string): void => {
    if (allowed(iso)) {
        emit('select', iso);
    }
};
const todayAllowed = computed(() => allowed(today.value));
</script>

<template>
    <div class="w-[19rem] max-w-full space-y-2 text-sm" data-testid="date-calendar">
        <div class="flex items-center justify-between gap-2">
            <button
                type="button"
                class="grid size-11 place-items-center rounded-md border border-input hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none sm:size-9"
                :aria-label="text.previousMonth"
                @click="moveTo(addMonthsIso(focused, -1), false)"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
            </button>
            <p class="font-medium" aria-live="polite">{{ monthLabel }}</p>
            <button
                type="button"
                class="grid size-11 place-items-center rounded-md border border-input hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none sm:size-9"
                :aria-label="text.nextMonth"
                @click="moveTo(addMonthsIso(focused, 1), false)"
            >
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </div>
        <div
            ref="grid"
            role="grid"
            :aria-label="monthLabel"
            @keydown="onKeydown"
        >
            <div class="grid grid-cols-7 text-center text-xs text-muted-foreground" role="row">
                <span
                    v-for="day in text.weekdays"
                    :key="day"
                    role="columnheader"
                    class="py-1"
                    >{{ day }}</span
                >
            </div>
            <div
                v-for="(week, index) in weeks"
                :key="index"
                class="grid grid-cols-7"
                role="row"
            >
                <span v-for="cell in week" :key="cell.iso" role="gridcell">
                    <button
                        type="button"
                        :data-iso="cell.iso"
                        :tabindex="cell.iso === focused ? 0 : -1"
                        :disabled="!allowed(cell.iso)"
                        :aria-label="longLabel(cell.iso)"
                        :aria-selected="cell.iso === selected"
                        :aria-current="cell.iso === today ? 'date' : undefined"
                        class="grid h-10 w-full place-items-center rounded-md tabular-nums hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent sm:h-9"
                        :class="[
                            cell.inMonth ? '' : 'text-muted-foreground/60',
                            cell.iso === selected
                                ? 'bg-brand text-brand-foreground hover:bg-brand/90'
                                : '',
                            cell.iso === today && cell.iso !== selected
                                ? 'border border-brand text-brand-strong'
                                : '',
                        ]"
                        @click="pick(cell.iso)"
                    >
                        {{ cell.day }}
                    </button>
                </span>
            </div>
        </div>
        <div class="flex items-center justify-between gap-2 border-t pt-2">
            <button
                type="button"
                class="min-h-11 rounded-md px-3 text-brand-strong hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none disabled:opacity-40 sm:min-h-9"
                :disabled="!todayAllowed"
                @click="pick(today)"
            >
                {{ text.today }}
            </button>
            <button
                type="button"
                class="min-h-11 rounded-md px-3 text-muted-foreground hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none sm:min-h-9"
                @click="emit('clear')"
            >
                {{ text.clear }}
            </button>
        </div>
    </div>
</template>

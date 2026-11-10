<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref, useAttrs, useId, watch } from 'vue';
import DateCalendar from './DateCalendar.vue';
import {
    DATE_FIELD_TEXT,
    dateProblem,
    formatDisplayDate,
    formatEchoDate,
    maskDateInput,
    normaliseIso,
    parseDisplayDate,
    withinRange,
} from '@/lib/date-field';
import type { DateFieldLocale } from '@/lib/date-field';
import { cn } from '@/lib/utils';

// UX-17: a date box that always shows and accepts dd/mm/yyyy, whatever the browser or operating
// system language is (a native date input follows those, so an English (US) computer shows
// mm/dd/yyyy). The model is the same yyyy-mm-dd string a native date input gives: '' until the
// text is a complete, real date inside min and max. id, name, required, disabled and aria-*
// go to the text box so labels and error links keep working; class goes to the wrapper.
// The events carry the new yyyy-mm-dd string, not a DOM Event: read the argument, not $event.target.
// echo shows "= 10 Nov 2026" under the box (off in tight table filters).
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        min?: string;
        max?: string;
        locale?: DateFieldLocale;
        placeholder?: string;
        calendarLabel?: string;
        echo?: boolean;
    }>(),
    {
        modelValue: '',
        min: undefined,
        max: undefined,
        locale: 'en',
        placeholder: undefined,
        calendarLabel: undefined,
        echo: true,
    },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    input: [value: string];
    change: [value: string];
}>();
const attrs = useAttrs();
const words = computed(() => DATE_FIELD_TEXT[props.locale]);
const errorId = useId();
const hintId = useId();
const input = ref<HTMLInputElement | null>(null);
const text = ref(formatDisplayDate(props.modelValue));
const open = ref(false);
// change fires only when the value differs from the last one announced, as a native input does.
let lastChange = props.modelValue ?? '';
const calendar = ref<InstanceType<typeof DateCalendar> | null>(null);
// The arrow keys work as soon as the calendar opens: focus lands on the selected day.
const onOpenFocus = (event: Event): void => {
    event.preventDefault();
    void calendar.value?.focusDay();
};
const problem = ref('');
const disabled = computed(
    () => attrs.disabled !== undefined && attrs.disabled !== false,
);

watch(
    () => props.modelValue,
    (value) => {
        // A value that came from outside (a reset, a saved record) replaces the text; the echo of
        // our own typing is already what the box shows.
        if ((value ?? '') !== (parseDisplayDate(text.value) ?? '')) {
            text.value = formatDisplayDate(value);
            problem.value = '';
        }
    },
);

const commit = (value: string): void => {
    emit('update:modelValue', value);
    emit('input', value);
};
const check = (typed: string): { iso: string; error: string } => {
    const error = dateProblem(typed, props.min, props.max, props.locale);

    return error ? { iso: '', error } : { iso: parseDisplayDate(typed) ?? '', error: '' };
};

const onInput = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const caretAtEnd = target.selectionStart === target.value.length;
    const next = maskDateInput(target.value, text.value);

    text.value = next;

    if (target.value !== next) {
        target.value = next;

        if (!caretAtEnd) {
            // Typing in the middle: keep the caret where the person was typing.
            const place = Math.min(target.selectionStart ?? next.length, next.length);

            target.setSelectionRange(place, place);
        }
    }

    problem.value = '';
    commit(check(next).iso);
};
const onBlur = (): void => {
    const { iso, error } = check(text.value);

    problem.value = error;

    if (iso !== '') {
        text.value = formatDisplayDate(iso);
    }

    if (iso !== lastChange) {
        lastChange = iso;
        emit('change', iso);
    }
};
const onPick = (iso: string): void => {
    text.value = formatDisplayDate(iso);
    problem.value = '';
    open.value = false;
    commit(iso);
    lastChange = iso;
    emit('change', iso);
};
const onClear = (): void => {
    text.value = '';
    problem.value = '';
    open.value = false;
    commit('');
    lastChange = '';
    emit('change', '');
};

const inputAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    const described = [rest['aria-describedby'], hintId, problem.value ? errorId : '']
        .filter(Boolean)
        .join(' ');

    return {
        ...rest,
        'aria-describedby': described || undefined,
        'aria-invalid': problem.value
            ? ('true' as const)
            : (rest['aria-invalid'] as boolean | 'true' | 'false' | undefined),
    };
});
const echoText = computed(() => (props.echo ? formatEchoDate(parseDisplayDate(text.value), props.locale) : ''));
const onCloseFocus = (event: Event): void => {
    event.preventDefault();
    input.value?.focus();
};
const calendarValue = computed(() => normaliseIso(parseDisplayDate(text.value)) ?? '');
</script>

<template>
    <div :class="cn('w-full', attrs.class as string)">
        <div class="relative flex h-9 w-full items-center max-sm:h-11">
        <input
            ref="input"
            v-bind="inputAttrs"
            :value="text"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :placeholder="placeholder ?? words.placeholder"
            data-slot="input"
            data-kpone-field="date"
            class="h-full w-full min-w-0 rounded-md border border-input bg-card px-3 py-1 pr-11 text-base shadow-xs transition-[background-color,border-color,box-shadow] outline-none selection:bg-brand selection:text-brand-foreground placeholder:text-muted-foreground hover:border-foreground/25 read-only:bg-muted/70 read-only:text-muted-foreground disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground disabled:opacity-70 aria-invalid:border-destructive aria-invalid:ring-destructive/20 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
            @input="onInput"
            @blur="onBlur"
        />
        <PopoverRoot v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :disabled="disabled"
                    :aria-label="calendarLabel ?? words.choose"
                    class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                >
                    <CalendarDays class="size-4" aria-hidden="true" />
                </button>
            </PopoverTrigger>
            <PopoverPortal>
                <PopoverContent
                    align="end"
                    :side-offset="6"
                    class="z-50 rounded-lg border bg-popover p-3 text-popover-foreground shadow-lg"
                    role="dialog"
                    :aria-label="calendarLabel ?? words.choose"
                    @open-auto-focus="onOpenFocus"
                    @close-auto-focus="onCloseFocus"
                >
                    <DateCalendar
                        ref="calendar"
                        :model-value="calendarValue"
                        :min="min"
                        :max="max"
                        :locale="locale"
                        @select="onPick"
                        @clear="onClear"
                    />
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>
        </div>
        <span :id="hintId" class="sr-only">{{ words.formatHint }}</span>
        <p
            v-if="echoText && !problem"
            class="mt-1 text-sm text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            = {{ echoText }}
        </p>
        <p
            v-if="problem"
            :id="errorId"
            class="mt-1 text-sm text-destructive"
            role="alert"
        >
            {{ problem }}
        </p>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, useAttrs, useId, watch } from 'vue';
import DateField from './DateField.vue';
import {
    DATE_FIELD_TEXT,
    formatEchoDate,
    formatEchoTime,
    from12Hour,
    timeProblem as describeTime,
    splitDateTime,
    to12Hour,
} from '@/lib/date-field';
import type { DateFieldLocale, Meridiem } from '@/lib/date-field';
import { cn } from '@/lib/utils';

// UX-17: date and time in one value, yyyy-mm-ddTHH:mm like a native datetime-local input. The
// date shows as dd/mm/yyyy; the time is 12-hour with AM or PM, as KPOne shows times on screen.
// The model stays '' until both parts are complete and real. min and max are dates (yyyy-mm-dd).
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        min?: string;
        max?: string;
        locale?: DateFieldLocale;
        echo?: boolean;
    }>(),
    { modelValue: '', min: undefined, max: undefined, locale: 'en', echo: true },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    input: [value: string];
    change: [value: string];
}>();
const attrs = useAttrs();
const words = computed(() => DATE_FIELD_TEXT[props.locale]);
const timeId = useId();
const initial = splitDateTime(props.modelValue);
const parts = to12Hour(initial.time);
const date = ref(initial.date);
const hour = ref(parts?.hour ?? '');
const minute = ref(parts?.minute ?? '');
const meridiem = ref<Meridiem>(parts?.meridiem ?? 'AM');
const timeProblem = ref(false);

const current = (): string => {
    const time = from12Hour(hour.value, minute.value, meridiem.value);

    return date.value && time ? `${date.value}T${time}` : '';
};
const push = (): void => {
    const value = current();

    emit('update:modelValue', value);
    emit('input', value);
};

watch(
    () => props.modelValue,
    (value) => {
        if ((value ?? '') !== current()) {
            const next = splitDateTime(value);
            const time = to12Hour(next.time);

            date.value = next.date;
            hour.value = time?.hour ?? '';
            minute.value = time?.minute ?? '';
            meridiem.value = time?.meridiem ?? 'AM';
            timeProblem.value = false;
        }
    },
);

const onDate = (value: string): void => {
    date.value = value;
    push();
};
const onTimeInput = (): void => {
    timeProblem.value = false;
    push();
};
const onTimeBlur = (): void => {
    const typed = hour.value !== '' || minute.value !== '';

    if (typed && minute.value !== '' && minute.value.length === 1) {
        minute.value = `0${minute.value}`;
    }

    timeProblem.value =
        typed && from12Hour(hour.value, minute.value, meridiem.value) === null;
    emit('change', current());
};
const dateAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
const selectClass =
    'h-full rounded-md border border-input bg-card px-2 text-base shadow-xs outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-70 md:text-sm dark:bg-input/30';
const timeClass =
    'h-full w-12 rounded-md border border-input bg-card px-2 text-center text-base shadow-xs outline-none placeholder:text-muted-foreground hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-70 aria-invalid:border-destructive md:text-sm dark:bg-input/30';
const echoText = computed(() => {
    if (!props.echo || !current()) {
        return '';
    }

    const [d, t] = current().split('T');

    return `${formatEchoDate(d, props.locale)}, ${formatEchoTime(t)}`;
});
const timeMessage = computed(
    () =>
        describeTime(hour.value, minute.value, props.locale) ||
        words.value.invalidTime,
);
const disabled = computed(() => attrs.disabled !== undefined && attrs.disabled !== false);
</script>

<template>
    <div :class="cn('w-full', attrs.class as string)">
        <div class="flex flex-wrap items-start gap-2">
            <DateField
                v-bind="dateAttrs"
                :model-value="date"
                :min="min"
                :max="max"
                :locale="locale"
                :echo="false"
                class="min-w-[11rem] flex-1"
                @update:model-value="onDate"
            />
            <div
                class="flex h-9 items-center gap-1 max-sm:h-11"
                role="group"
                :aria-label="words.hour"
            >
                <input
                    v-model="hour"
                    type="text"
                    inputmode="numeric"
                    maxlength="2"
                    placeholder="5"
                    :disabled="disabled"
                    :aria-label="words.hourOnly"
                    :aria-invalid="timeProblem ? 'true' : undefined"
                    :aria-describedby="timeProblem ? `${timeId}-error` : undefined"
                    :class="timeClass"
                    @input="onTimeInput"
                    @blur="onTimeBlur"
                />
                <span aria-hidden="true">:</span>
                <input
                    v-model="minute"
                    type="text"
                    inputmode="numeric"
                    maxlength="2"
                    placeholder="30"
                    :disabled="disabled"
                    :aria-label="words.minute"
                    :aria-invalid="timeProblem ? 'true' : undefined"
                    :aria-describedby="timeProblem ? `${timeId}-error` : undefined"
                    :class="timeClass"
                    @input="onTimeInput"
                    @blur="onTimeBlur"
                />
                <select
                    v-model="meridiem"
                    :disabled="disabled"
                    :aria-label="words.meridiem"
                    :class="selectClass"
                    @change="
                        onTimeInput();
                        onTimeBlur();
                    "
                >
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                </select>
            </div>
        </div>
        <p v-if="echoText && !timeProblem" class="mt-1 text-sm text-muted-foreground tabular-nums" aria-hidden="true">
            = {{ echoText }}
        </p>
        <p
            v-if="timeProblem"
            :id="`${timeId}-error`"
            class="mt-1 text-sm text-destructive"
            role="alert"
        >
            {{ timeMessage }}
        </p>
    </div>
</template>

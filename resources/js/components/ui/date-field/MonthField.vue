<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight } from '@lucide/vue';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref, useAttrs, useId, watch } from 'vue';
import {
    DATE_FIELD_TEXT,
    MONTH_NAMES,
    MONTH_NAMES_MS,
    formatDisplayMonth,
    maskMonthInput,
    monthProblem,
    parseDisplayMonth,
    todayIso,
} from '@/lib/date-field';
import type { DateFieldLocale } from '@/lib/date-field';
import { cn } from '@/lib/utils';

// UX-17: the month version of DateField. Shows and accepts mm/yyyy (11/2026); the model is the
// yyyy-mm string a native month input gives. min and max are yyyy-mm.
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        min?: string;
        max?: string;
        locale?: DateFieldLocale;
    }>(),
    { modelValue: '', min: undefined, max: undefined, locale: 'en' },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    input: [value: string];
    change: [value: string];
}>();
const attrs = useAttrs();
const words = computed(() => DATE_FIELD_TEXT[props.locale]);
const names = computed(() => (props.locale === 'ms' ? MONTH_NAMES_MS : MONTH_NAMES));
const errorId = useId();
const hintId = useId();
const text = ref(formatDisplayMonth(props.modelValue));
const open = ref(false);
const problem = ref('');
const year = ref(Number((props.modelValue || todayIso()).slice(0, 4)));
const disabled = computed(() => attrs.disabled !== undefined && attrs.disabled !== false);
const inRange = (value: string): boolean =>
    (!props.min || value >= props.min) && (!props.max || value <= props.max);

watch(
    () => props.modelValue,
    (value) => {
        if ((value ?? '') !== (parseDisplayMonth(text.value) ?? '')) {
            text.value = formatDisplayMonth(value);
            problem.value = '';
        }
    },
);

const commit = (value: string): void => {
    emit('update:modelValue', value);
    emit('input', value);
};
const check = (typed: string): { value: string; error: string } => {
    const error = monthProblem(typed, props.min, props.max, props.locale);

    return error ? { value: '', error } : { value: parseDisplayMonth(typed) ?? '', error: '' };
};
const onInput = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const next = maskMonthInput(target.value, text.value);

    text.value = next;
    target.value = next;
    problem.value = '';
    commit(check(next).value);
};
const onBlur = (): void => {
    const { value, error } = check(text.value);

    problem.value = error;

    if (value !== '') {
        text.value = formatDisplayMonth(value);
    }

    emit('change', value);
};
const pick = (month: number): void => {
    const value = `${String(year.value).padStart(4, '0')}-${String(month).padStart(2, '0')}`;

    if (!inRange(value)) {
        return;
    }

    text.value = formatDisplayMonth(value);
    problem.value = '';
    open.value = false;
    commit(value);
    emit('change', value);
};
const thisMonthValue = (): string => todayIso().slice(0, 7);
const thisMonthAllowed = computed(() => inRange(thisMonthValue()));
const pickThisMonth = (): void => {
    const value = thisMonthValue();

    if (!inRange(value)) {
        return;
    }

    text.value = formatDisplayMonth(value);
    problem.value = '';
    open.value = false;
    commit(value);
    emit('change', value);
};
const onClear = (): void => {
    text.value = '';
    problem.value = '';
    open.value = false;
    commit('');
    emit('change', '');
};
const selected = computed(() => parseDisplayMonth(text.value) ?? '');
const monthValue = (month: number): string =>
    `${String(year.value).padStart(4, '0')}-${String(month).padStart(2, '0')}`;

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
</script>

<template>
    <div :class="cn('w-full', attrs.class as string)">
        <div class="relative flex h-9 w-full items-center max-sm:h-11">
            <input
                v-bind="inputAttrs"
                :value="text"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                :placeholder="words.monthPlaceholder"
                data-slot="input"
                data-kpone-field="month"
                class="h-full w-full min-w-0 rounded-md border border-input bg-card px-3 py-1 pr-11 text-base shadow-xs transition-[background-color,border-color,box-shadow] outline-none selection:bg-brand selection:text-brand-foreground placeholder:text-muted-foreground hover:border-foreground/25 read-only:bg-muted/70 read-only:text-muted-foreground disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground disabled:opacity-70 aria-invalid:border-destructive aria-invalid:ring-destructive/20 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                @input="onInput"
                @blur="onBlur"
            />
            <PopoverRoot v-model:open="open">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        :disabled="disabled"
                        :aria-label="words.chooseMonth"
                        class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                    >
                        <CalendarDays class="size-4" aria-hidden="true" />
                    </button>
                </PopoverTrigger>
                <PopoverPortal>
                    <PopoverContent
                        align="end"
                        :side-offset="6"
                        role="dialog"
                    :aria-label="words.chooseMonth"
                    class="z-50 rounded-lg border bg-popover p-3 text-popover-foreground shadow-lg"
                    >
                        <div class="w-[19rem] max-w-full space-y-2 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <button
                                    type="button"
                                    class="grid size-11 place-items-center rounded-md border border-input hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:size-9"
                                    :aria-label="words.previousYear"
                                    @click="year -= 1"
                                >
                                    <ChevronLeft class="size-4" aria-hidden="true" />
                                </button>
                                <p class="font-medium" aria-live="polite">{{ year }}</p>
                                <button
                                    type="button"
                                    class="grid size-11 place-items-center rounded-md border border-input hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:size-9"
                                    :aria-label="words.nextYear"
                                    @click="year += 1"
                                >
                                    <ChevronRight class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                            <div class="grid grid-cols-3 gap-1">
                                <button
                                    v-for="(name, index) in names"
                                    :key="name"
                                    type="button"
                                    :disabled="!inRange(monthValue(index + 1))"
                                    :aria-pressed="selected === monthValue(index + 1)"
                                    class="min-h-11 rounded-md hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent sm:min-h-9"
                                    :class="
                                        selected === monthValue(index + 1)
                                            ? 'bg-brand text-brand-foreground hover:bg-brand/90'
                                            : ''
                                    "
                                    @click="pick(index + 1)"
                                >
                                    {{ name.slice(0, 3) }}
                                </button>
                            </div>
                            <div class="flex items-center justify-between gap-2 border-t pt-2">
                                <button
                                    type="button"
                                    class="min-h-11 rounded-md px-3 text-brand-strong hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-40 sm:min-h-9"
                                    :disabled="!thisMonthAllowed"
                                    @click="pickThisMonth"
                                >
                                    {{ words.thisMonth }}
                                </button>
                                <button
                                    type="button"
                                    class="min-h-11 rounded-md px-3 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:min-h-9"
                                    @click="onClear"
                                >
                                    {{ words.clear }}
                                </button>
                            </div>
                        </div>
                    </PopoverContent>
                </PopoverPortal>
            </PopoverRoot>
        </div>
        <span :id="hintId" class="sr-only">{{ words.monthFormatHint }}</span>
        <p v-if="problem" :id="errorId" class="mt-1 text-sm text-destructive" role="alert">
            {{ problem }}
        </p>
    </div>
</template>

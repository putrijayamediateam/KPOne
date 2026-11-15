<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Version 2 date range: a From and To pair for filters (native date inputs, 44px). When both are set and From is after
 * To, "From must be on or before To." is announced. What the dates filter is the page's rule.
 *
 * Plain-date echo: under the inputs the range is written out in words ("1 Nov 2026 to 13 Nov 2026", "From 1 Nov 2026",
 * "Up to 13 Nov 2026"), because a native date box shows the browser's own format. It is on by default for every page and
 * announced politely, but never on every digit typed: the spoken text updates once the dates have been still for a moment (or
 * when focus leaves the range), and never while a date is partial; `echo={false}` turns it off (for a place that already
 * shows the date in words). The dates are formatted from the YYYY-MM-DD text itself, with no time
 * zone, so a date never shifts a day.
 */
const props = withDefaults(
    defineProps<{
        from: string;
        to: string;
        label: string;
        fromLabel?: string;
        toLabel?: string;
        error?: string;
        echo?: boolean;
    }>(),
    { fromLabel: 'From', toLabel: 'To', error: undefined, echo: true },
);

const emit = defineEmits<{
    'update:from': [value: string];
    'update:to': [value: string];
}>();

const base = `v2-range-${Math.random().toString(36).slice(2, 8)}`;
const message = computed(
    () =>
        props.error ??
        (props.from && props.to && props.from > props.to
            ? 'From must be on or before To.'
            : undefined),
);

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
const plain = (value: string): string | null => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const month = MONTHS[Number(match[2]) - 1];

    return month ? `${Number(match[3])} ${month} ${match[1]}` : null;
};
const echoText = computed(() => {
    const from = plain(props.from);
    const to = plain(props.to);

    if (message.value || (!from && !to)) {
        return '';
    }

    if (from && to) {
        return `${from} to ${to}`;
    }

    return from ? `From ${from}` : `Up to ${to}`;
});

// The visible echo follows at once; the live region follows after the dates stop changing, or on blur.
const spoken = ref('');
let timer: ReturnType<typeof setTimeout> | null = null;
const speakNow = () => {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }

    spoken.value = echoText.value;
};

watch(echoText, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(speakNow, 1200);
});
onBeforeUnmount(() => timer && clearTimeout(timer));

const input =
    'h-11 w-full rounded-[10px] border bg-card px-3 text-base outline-none selection:bg-primary/25 hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <fieldset
        data-slot="v2-date-range"
        class="flex min-w-0 flex-col gap-1.5"
        @focusout="speakNow"
    >
        <legend class="mb-1.5 text-sm font-medium text-foreground">
            {{ label }}
        </legend>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label
                class="flex min-w-0 flex-col gap-1 text-[13px] text-muted-foreground"
            >
                {{ fromLabel }}
                <input
                    type="date"
                    :value="from"
                    :max="to || undefined"
                    :aria-invalid="!!message"
                    :aria-describedby="message ? `${base}-error` : undefined"
                    :class="[
                        input,
                        message ? 'border-destructive' : 'border-input',
                        'text-foreground',
                    ]"
                    @input="
                        emit(
                            'update:from',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
            <label
                class="flex min-w-0 flex-col gap-1 text-[13px] text-muted-foreground"
            >
                {{ toLabel }}
                <input
                    type="date"
                    :value="to"
                    :min="from || undefined"
                    :aria-invalid="!!message"
                    :aria-describedby="message ? `${base}-error` : undefined"
                    :class="[
                        input,
                        message ? 'border-destructive' : 'border-input',
                        'text-foreground',
                    ]"
                    @input="
                        emit(
                            'update:to',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
        </div>
        <p
            v-if="echo"
            data-slot="v2-date-range-echo"
            class="min-h-5 text-[13px] leading-5 text-muted-foreground"
            aria-hidden="true"
        >
            {{ echoText }}
        </p>
        <p
            v-if="echo"
            class="sr-only"
            role="status"
            aria-live="polite"
            data-slot="v2-date-range-spoken"
        >
            {{ spoken }}
        </p>
        <p
            v-if="message"
            :id="`${base}-error`"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ message }}
        </p>
    </fieldset>
</template>

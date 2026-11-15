<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { displayFromIso, fromIso, rangeProblem, readDate } from '@/lib/v2-date';
import V2Field from './V2Field.vue';

/**
 * Version 2 date field: a text box that replaces the browser's own date input, whose box shows "11/13/2026" (US order)
 * and can be misread in Malaysia ("13/11" or "11/13"?). Type or pick a date; when the person leaves the box it becomes
 * "13 Nov 2026", and while they type a plain echo shows what will be saved in words ("= 11 Dec 2026" for "11/12/2026").
 * The box and the echo always show the English month, even when a Bahasa Melayu month was typed.
 *
 * `weekday` (off by default) adds the weekday to the echo ("= Fri 13 Nov 2026"): turn it on for dates people plan around
 * (roster, leave, appointments); leave it off for a date of birth or an expiry, where it is noise.
 *
 * DAY FIRST, always: the box NEVER reads month-first. When both numbers are 12 or less ("11/12/2026") it is the 11th of
 * December, and the echo says so in words. Accepted: "13 Nov 2026", "13/11/2026", "13-11-2026", "13.11.2026", "13112026" and month names in
 * English and Bahasa Melayu ("5 Mei 2027", "25 Dis 2026"). Nothing is guessed: the year must have four digits (a
 * two-digit year is refused: "Type the full year, such as 2026."); a date that only works month-first ("12/13/2026") is
 * refused: "Type the date day first, such as 13 Dec 2026."; an impossible date is refused by name ("There is no 31 Nov.",
 * "There is no 29 Feb in 2027."). Outside the optional `min` and `max` (inclusive, ISO) the box says why with the limit as a
 * date: "Choose a date on or after 1 Jan 2026."; pass `subject` ("Expiry") and it reads "Expiry must be on or after
 * 13 Nov 2026." The block never guesses the subject. For a message of its own (for example "Expiry must be after 13 Nov 2026." with `min` set to the next day), the page passes `minMessage` or `maxMessage`; the limit itself stays inclusive.
 *
 * `modelValue` is ISO "YYYY-MM-DD" or '' (the same value as a native date input, so a page only swaps the tag). It is
 * updated when the person leaves the box, presses Enter, picks from the calendar or presses Clear. If the text is not a
 * usable date, the model becomes '' (never a stale date), the box keeps what was typed and says why. **An empty model after
 * bad text means the PAGE must block Save and show the field's error**; do not fall back to the old date. The page's own
 * `error` wins. Whether a date is allowed beyond min and max (for example an end after a start) is the page's job.
 *
 * A date that is not required can be emptied: "Clear <label>" (44px) appears beside the box while it holds a date. The
 * "Choose date" button opens the browser's own calendar (a hidden date input and `showPicker()`); the calendar only picks,
 * the box is never the US one. Where the browser cannot open it the button is not shown.
 *
 * `@update:invalid` is true while the box holds text it could not use, so a page can block Save (an empty end date would otherwise read as "no end date").
 *
 * The echo is visual only; one polite announcement says the saved date once the person leaves the box. V2 views only.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        disabled?: boolean;
        min?: string;
        max?: string;
        placeholder?: string;
        weekday?: boolean;
        subject?: string;
        minMessage?: string;
        maxMessage?: string;
    }>(),
    {
        hint: undefined,
        error: undefined,
        required: false,
        disabled: false,
        min: undefined,
        max: undefined,
        placeholder: 'e.g. 13 Nov 2026',
        weekday: false,
        subject: undefined,
        minMessage: undefined,
        maxMessage: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    // true while the box holds text it could not use (the model is '' then); a page uses it to block Save.
    'update:invalid': [value: boolean];
}>();

const text = ref(displayFromIso(props.modelValue));
const typedProblem = ref<string | null>(null);
watch(typedProblem, (problem) => emit('update:invalid', problem !== null));
const announce = ref('');
const input = ref<HTMLInputElement | null>(null);
const picker = ref<HTMLInputElement | null>(null);
let lastEmitted: string | null = null;

const canPick =
    typeof HTMLInputElement !== 'undefined' &&
    'showPicker' in HTMLInputElement.prototype;

const send = (value: string) => {
    lastEmitted = value;
    emit('update:modelValue', value);
    // The page's watcher has run by the next tick; never let an old value hide a later change from the page.
    nextTick(() => (lastEmitted = null));
};

// A change from the page (reset, loaded record) replaces the text, unless it is the value this box just emitted.
watch(
    () => props.modelValue,
    (value) => {
        if (value === lastEmitted) {
            return;
        }

        text.value = displayFromIso(value);
        typedProblem.value = null;
    },
);

const reading = computed(() =>
    text.value.trim() === '' ? null : readDate(text.value),
);

const echo = computed(() => {
    const r = reading.value;

    if (!r || !r.ok) {
        return '';
    }

    return r.date.display === text.value.trim()
        ? ''
        : `= ${props.weekday ? `${r.date.weekday} ` : ''}${r.date.display}`;
});

const shownError = computed(
    () => props.error ?? typedProblem.value ?? undefined,
);

const commit = () => {
    announce.value = '';

    if (text.value.trim() === '') {
        typedProblem.value = null;
        send('');

        return;
    }

    const r = readDate(text.value);

    if (!r.ok) {
        typedProblem.value = r.problem.message;
        send('');

        return;
    }

    const range = rangeProblem(r.date.iso, props.min, props.max, props.subject);

    if (range) {
        // The page may say it its own way ("Expiry must be after 13 Nov 2026.") when its rule is stricter than the limit.
        typedProblem.value =
            (r.date.iso < (props.min ?? '')
                ? props.minMessage
                : props.maxMessage) ?? range;
        send('');

        return;
    }

    typedProblem.value = null;
    text.value = r.date.display;
    send(r.date.iso);
    // A separate tick, so the announcement is a change even if the same date is committed twice.
    setTimeout(() => (announce.value = text.value), 50);
};

const clear = () => {
    text.value = '';
    typedProblem.value = null;
    send('');
    announce.value = '';
    nextTick(() => input.value?.focus());
};

const openCalendar = () => {
    const native = picker.value;

    if (!native) {
        return;
    }

    native.value = fromIso(props.modelValue) ? props.modelValue : '';
    native.showPicker();
};

const picked = () => {
    const value = picker.value?.value ?? '';
    const date = fromIso(value);

    if (!date) {
        return;
    }

    text.value = date.display;
    commit();
};
</script>

<template>
    <V2Field
        v-slot="{ id, describedBy }"
        :label="label"
        :hint="hint"
        :error="shownError"
        :required="required"
    >
        <div class="flex flex-wrap items-center gap-2">
            <input
                :id="id"
                ref="input"
                v-model="text"
                type="text"
                inputmode="text"
                autocomplete="off"
                enterkeyhint="done"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                :aria-invalid="!!shownError"
                :aria-describedby="describedBy"
                class="h-11 w-44 rounded-[10px] border bg-card px-3 text-base tabular-nums outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50 md:text-[15px] lg:h-9"
                :class="
                    shownError
                        ? 'border-destructive'
                        : 'border-input hover:border-foreground/60'
                "
                @input="typedProblem = null"
                @blur="commit"
                @keydown.enter="commit"
            />
            <button
                v-if="canPick && !disabled"
                type="button"
                :aria-label="`Choose a date for ${label}`"
                class="inline-flex size-11 shrink-0 items-center justify-center rounded-[10px] border border-input bg-card outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:size-9"
                @click="openCalendar"
            >
                <svg
                    class="size-4"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <rect x="3" y="5" width="18" height="16" rx="2" />
                    <path d="M3 10h18M8 3v4M16 3v4" />
                </svg>
            </button>
            <button
                v-if="!required && !disabled && modelValue !== ''"
                type="button"
                :aria-label="`Clear ${label}`"
                class="inline-flex h-11 shrink-0 items-center justify-center rounded-[10px] border border-input bg-card px-3 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:h-9"
                @click="clear"
            >
                Clear
            </button>
            <span
                v-if="echo"
                class="text-sm text-muted-foreground tabular-nums"
                aria-hidden="true"
                data-slot="v2-date-echo"
                >{{ echo }}</span
            >
            <input
                ref="picker"
                type="date"
                class="sr-only"
                tabindex="-1"
                aria-hidden="true"
                :min="min"
                :max="max"
                @change="picked"
            />
        </div>
        <p class="sr-only" role="status" aria-live="polite">{{ announce }}</p>
    </V2Field>
</template>

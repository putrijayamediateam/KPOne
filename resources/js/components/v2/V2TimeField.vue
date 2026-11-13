<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import {
    TIME_ERROR,
    displayFromCanonical,
    parseTime,
    toCanonical,
    toDisplay,
} from '@/lib/v2-time';
import V2Field from './V2Field.vue';

/**
 * Version 2 time field: a text box that replaces the browser's own time input (whose wheels and 24-hour or 12-hour
 * choice differ on every device). Type "8am", "8:30 pm", "0830" or "14:30"; when the person leaves the box it becomes
 * "8:00 AM", and while they type a plain echo shows what will be saved ("= 8:00 AM"), so nobody has to guess whether
 * "8" meant morning or evening. A number with no AM or PM is read as a 24-hour time ("14" is 2:00 PM, "8" is 8:00 AM).
 *
 * `modelValue` is the CANONICAL 24-hour time "HH:MM" ("08:00"), or '' when empty. It is updated when the person leaves
 * the box or presses Enter. If the text is not a time, the model becomes '' (never a stale time), the box keeps what was
 * typed and says "Enter a time such as 8:00 AM." The page's own `error` wins. The block knows nothing about dates, time
 * zones, shifts or ranges: whether a time is allowed, and whether an end is after a start, is the page's job.
 *
 * The echo is visual only and is not read on every key press; a polite announcement says the saved time once the person
 * leaves the box ("8:00 AM"). V2 views only.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    { hint: undefined, error: undefined, required: false, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const text = ref(displayFromCanonical(props.modelValue));
const typedInvalid = ref(false);
const announce = ref('');
let lastEmitted: string | null = null;

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
        // The value this box just emitted (even '' for text that is not a time) never rewrites what was typed.
        if (value === lastEmitted) {
            return;
        }

        text.value = displayFromCanonical(value);
        typedInvalid.value = false;
    },
);

const echo = computed(() => {
    const parsed = parseTime(text.value);

    if (!parsed) {
        return '';
    }

    const shown = toDisplay(parsed);

    return shown === text.value.trim() ? '' : `= ${shown}`;
});

const shownError = computed(
    () => props.error ?? (typedInvalid.value ? TIME_ERROR : undefined),
);

const commit = () => {
    announce.value = '';

    if (text.value.trim() === '') {
        typedInvalid.value = false;
        send('');

        return;
    }

    const parsed = parseTime(text.value);

    if (!parsed) {
        typedInvalid.value = true;
        send('');

        return;
    }

    typedInvalid.value = false;
    text.value = toDisplay(parsed);
    send(toCanonical(parsed));
    // A separate tick, so the announcement is a change even if the same time is committed twice.
    setTimeout(() => (announce.value = text.value), 50);
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
        <div class="flex items-center gap-3">
            <input
                :id="id"
                v-model="text"
                type="text"
                inputmode="text"
                autocomplete="off"
                enterkeyhint="done"
                placeholder="8:00 AM"
                :disabled="disabled"
                :required="required"
                :aria-invalid="!!shownError"
                :aria-describedby="describedBy"
                class="h-11 w-36 rounded-[10px] border bg-card px-3 text-base tabular-nums outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50 md:text-[15px] lg:h-9"
                :class="
                    shownError
                        ? 'border-destructive'
                        : 'border-input hover:border-foreground/60'
                "
                @input="typedInvalid = false"
                @blur="commit"
                @keydown.enter="commit"
            />
            <span
                v-if="echo"
                class="text-sm text-muted-foreground tabular-nums"
                aria-hidden="true"
                data-slot="v2-time-echo"
                >{{ echo }}</span
            >
        </div>
        <p class="sr-only" role="status" aria-live="polite">{{ announce }}</p>
    </V2Field>
</template>

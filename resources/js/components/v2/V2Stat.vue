<script setup lang="ts">
import { computed, watch } from 'vue';
import V2Chip from './V2Chip.vue';

/**
 * Version 2 stat: a label, one big number and a muted line under it (for example "Leave balance" / "12.5 days" /
 * "Resets 1 Jan 2027"). `value` is text the page has already formatted (the block does no maths and no formatting).
 *
 * Colour is never the only signal: a `tone` other than `neutral` needs a `status` word, shown as a chip beside the number
 * and dropped below it when the number is long. The caller words the chip the way the clinic says it: "Short", not
 * "Negative". A negative number is written with a real minus sign right before it ("−RM 20.00", not "-RM 20.00");
 * the caller words the rest ("RM 20.00 short").
 *
 * An unavailable value is passed in words, "Not available" (or "To confirm"), never an empty string or a bare dash; an
 * empty `value` warns in development.
 *
 * The muted line adds information; it does not repeat the status ("Matches" beside "Matches expected" is one too many).
 * Reading: the card is one group named "<label>, <value>, <status>, <detail>" (for example "Overtime, 44 h, Near limit,
 * Limit is 45 h"); the status is skipped when the detail already says it ("Cash counted, RM 1,240.00, Matches
 * expected"). Numbers use tabular figures and never wrap. V2 views only.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        detail?: string;
        tone?: 'neutral' | 'success' | 'warning' | 'danger';
        status?: string;
    }>(),
    { detail: undefined, tone: 'neutral', status: undefined },
);

// The status word is left out of the spoken group name when the muted line already says it ("Matches" and "Matches
// expected"), so it is not read twice.
const reading = computed(() => {
    const detailSaysStatus =
        !!props.status &&
        !!props.detail &&
        props.detail.toLowerCase().includes(props.status.toLowerCase());

    return [
        props.label,
        props.value,
        detailSaysStatus ? undefined : props.status,
        props.detail,
    ]
        .filter((part) => part && part.trim() !== '')
        .join(', ');
});

if (import.meta.env?.DEV) {
    watch(
        () => [props.tone, props.status, props.value],
        ([tone, status, value]) => {
            if (tone !== 'neutral' && !status) {
                console.warn(
                    'V2Stat: a tone other than neutral needs a `status` word.',
                );
            }

            if (!String(value ?? '').trim()) {
                console.warn(
                    'V2Stat: `value` is empty; pass "Not available" in words.',
                );
            }
        },
        { immediate: true },
    );
}
</script>

<template>
    <div
        data-slot="v2-stat"
        role="group"
        :aria-label="reading"
        class="flex min-w-0 flex-col gap-1 rounded-[14px] border bg-card p-4"
    >
        <p class="text-[13px] leading-[18px] font-medium text-muted-foreground">
            {{ label }}
        </p>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span
                class="text-2xl leading-8 font-semibold whitespace-nowrap tabular-nums"
                >{{ value }}</span
            >
            <V2Chip
                v-if="tone !== 'neutral' && status"
                :tone="tone"
                :label="status"
            />
        </p>
        <p
            v-if="detail"
            class="text-[13px] leading-[18px] text-muted-foreground"
        >
            {{ detail }}
        </p>
    </div>
</template>

<script setup lang="ts">
import { watch } from 'vue';
import V2Chip from './V2Chip.vue';

/**
 * Version 2 stat: a label, one big number and a muted line under it (for example "Leave balance" / "12.5 days" /
 * "Resets 1 Jan 2027"). Colour is never the only signal: a `tone` other than `neutral` needs a `status` word that is shown
 * beside the number as a chip ("Negative", "To confirm", "Over limit"), and a negative or unconfirmed value is also
 * written in words by the page ("-RM 20.00 (owed)", "To confirm"). `value` is text the page has already formatted
 * (the block does no maths and no formatting). Numbers use tabular figures and never wrap. V2 views only.
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

if (import.meta.env?.DEV) {
    watch(
        () => [props.tone, props.status],
        ([tone, status]) => {
            if (tone !== 'neutral' && !status) {
                console.warn(
                    'V2Stat: a tone other than neutral needs a `status` word.',
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

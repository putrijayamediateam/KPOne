<script setup lang="ts">
import { computed } from 'vue';

/**
 * Version 2 waiting-time ring: a ring that fills towards the danger time, read as one run beside it ("72 min · very long wait").
 * Neutral under `warnAt`, amber from `warnAt`, red from `dangerAt` (30 and 60 minutes, as the Classic chip).
 * The ring is a fixed-size item (it never shrinks); the words sit beside it and wrap by word when the column is narrow, so the ring can never sit on top of its own text.
 * The minutes and "min" sit beside the ring for sighted users, with "long wait" or "very long wait" from the warning time (turn off with `words=false` where there is no room). The state is also spoken ("long wait"), never colour alone. Used by V2 views only.
 */
const props = withDefaults(
    defineProps<{
        minutes: number;
        warnAt?: number;
        dangerAt?: number;
        words?: boolean;
    }>(),
    { warnAt: 30, dangerAt: 60, words: true },
);

const RADIUS = 14;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const level = computed<'ok' | 'long' | 'very-long'>(() =>
    props.minutes >= props.dangerAt
        ? 'very-long'
        : props.minutes >= props.warnAt
          ? 'long'
          : 'ok',
);
const stroke = computed(
    () =>
        ({
            ok: 'stroke-muted-foreground',
            long: 'stroke-warning',
            'very-long': 'stroke-destructive',
        })[level.value],
);
const filled = computed(
    () =>
        CIRCUMFERENCE *
        Math.min(Math.max(props.minutes, 0) / props.dangerAt, 1),
);
const spoken = computed(
    () =>
        `${props.minutes} minutes waiting${
            level.value === 'ok'
                ? ''
                : level.value === 'long'
                  ? ', long wait'
                  : ', very long wait'
        }`,
);
</script>

<template>
    <span
        data-slot="wait-ring"
        class="inline-flex max-w-full items-center gap-1.5"
        role="img"
        :aria-label="spoken"
    >
        <span
            class="relative inline-flex size-9 shrink-0 items-center justify-center"
        >
            <svg
                viewBox="0 0 36 36"
                class="absolute inset-0 size-9 -rotate-90"
                aria-hidden="true"
            >
                <circle
                    cx="18"
                    cy="18"
                    :r="RADIUS"
                    fill="none"
                    stroke-width="3"
                    class="stroke-border"
                />
                <circle
                    cx="18"
                    cy="18"
                    :r="RADIUS"
                    fill="none"
                    stroke-width="3"
                    stroke-linecap="round"
                    :class="stroke"
                    :stroke-dasharray="`${filled} ${CIRCUMFERENCE}`"
                />
            </svg>
        </span>
        <span
            class="min-w-0 text-[13px] text-foreground tabular-nums"
            aria-hidden="true"
            >{{ minutes }} min<template v-if="words && level !== 'ok'">
                ·
                {{
                    level === 'long' ? 'long wait' : 'very long wait'
                }}</template
            ></span
        >
    </span>
</template>

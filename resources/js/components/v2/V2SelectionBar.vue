<script setup lang="ts">
import { computed } from 'vue';

/**
 * Version 2 selection bar: a bar stuck to the foot of the main column whenever something is ticked and the page's
 * right rail is not showing. It carries the one summary line ("3 claims selected · Panel X · RM 1,240.00"), the one
 * filled action and an optional outline secondary (for example "Clear"). `railFrom` is the width from which the page's
 * rail shows the summary and actions instead; it MUST match the `railFrom` of the page's V2WorkLayout (`lg`, `xl` or
 * `2xl`), and from that width up the bar is hidden. `visible` is the page's own condition (usually "something is
 * ticked"); when it turns false the bar is gone. A polite live region announces `announce` (default: the summary) when
 * the bar appears or the text changes. No selection or action logic lives here: the page handles `action` and
 * `secondary`. The wrapper is `display: contents` on purpose: the bar is `sticky`, and it can only stick inside the page's
 * column, not inside a wrapper as tall as itself. Place the block as the last child of that column. 44px targets below 1024px (36px from 1024px), safe-area padding, state in words.
 */
const props = withDefaults(
    defineProps<{
        visible: boolean;
        summary: string;
        actionLabel: string;
        railFrom?: 'lg' | 'xl' | '2xl';
        secondaryLabel?: string;
        announce?: string;
        actionDisabled?: boolean;
        busy?: boolean;
    }>(),
    {
        railFrom: '2xl',
        secondaryLabel: undefined,
        announce: undefined,
        actionDisabled: false,
        busy: false,
    },
);

const emit = defineEmits<{ action: []; secondary: [] }>();

const hideFrom = computed(
    () =>
        ({ lg: 'lg:hidden', xl: 'xl:hidden', '2xl': '2xl:hidden' })[
            props.railFrom
        ],
);
const spoken = computed(() =>
    props.visible ? (props.announce ?? props.summary) : '',
);
</script>

<template>
    <div data-slot="v2-selection-bar-region" class="contents">
        <div class="sr-only" role="status" aria-live="polite">
            {{ spoken }}
        </div>
        <div
            v-if="visible"
            data-slot="v2-selection-bar"
            class="sticky bottom-0 z-10 -mx-4 flex flex-col gap-3 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:flex-row sm:items-center sm:justify-between"
            :class="hideFrom"
        >
            <p class="min-w-0 text-[15px] leading-[22px] font-medium">
                <slot name="summary">{{ summary }}</slot>
            </p>
            <div class="flex shrink-0 gap-2 max-sm:w-full [&>*]:max-sm:flex-1">
                <button
                    v-if="secondaryLabel"
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                    @click="emit('secondary')"
                >
                    {{ secondaryLabel }}
                </button>
                <button
                    type="button"
                    :disabled="actionDisabled || busy"
                    :aria-busy="busy"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground outline-none hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 lg:h-9"
                    @click="emit('action')"
                >
                    {{ busy ? 'Working…' : actionLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

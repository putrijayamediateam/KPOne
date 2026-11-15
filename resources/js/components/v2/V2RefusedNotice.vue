<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { refused } from '@/lib/v2-refused-request';

const props = defineProps<{
    /** The page-level notice: bring it into view when it appears (a person presses Save at the bottom of a long form). */
    scrollIntoView?: boolean;
}>();

const box = ref<HTMLElement | null>(null);

// Smooth, unless the person asked for less motion; focus is NOT moved (the alert is announced instead).
watch(refused, (value) => {
    if (!value || !props.scrollIntoView) {
        return;
    }

    nextTick(() => {
        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        box.value?.scrollIntoView({
            block: 'center',
            behavior: reduced ? 'auto' : 'smooth',
        });
    });
});

const reload = () => window.location.reload();

/**
 * Version 2 refused-request notice (DD-84): one plain line when the server refused an action (403) or the page was open too
 * long (419). Warning tokens with an icon AND words, never colour alone; `role="alert"` so it is announced; focus does not
 * move. Only a 419 has the outline "Reload page" button: reloading does not fix a 403. Shown by V2AppShell above the page
 * and, while a sheet is open, inside that sheet (V2Dialog); see `lib/v2-refused-request.ts`. The page-level one passes
 * `scroll-into-view`: it scrolls itself into view (smooth, or instant with reduced motion) without moving focus.
 */
</script>

<template>
    <div
        v-if="refused"
        ref="box"
        role="alert"
        data-slot="v2-refused-notice"
        class="flex scroll-mt-20 flex-col gap-2 rounded-[10px] border border-warning/40 bg-warning-soft px-3 py-2.5 text-sm text-foreground sm:flex-row sm:items-center sm:justify-between"
    >
        <p class="flex items-start gap-2">
            <svg
                class="mt-0.5 size-4 shrink-0 text-warning-strong"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M12 3 2 21h20L12 3Z" />
                <path d="M12 10v4M12 17.5v.01" />
            </svg>
            <span>{{ refused.message }}</span>
        </p>
        <button
            v-if="refused.status === 419"
            type="button"
            class="inline-flex h-11 shrink-0 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
            @click="reload"
        >
            Reload page
        </button>
    </div>
</template>

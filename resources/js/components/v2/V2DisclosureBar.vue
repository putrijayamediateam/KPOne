<script setup lang="ts">
import { ref } from 'vue';

/**
 * Version 2 disclosure bar: a full-width bar with a title (for example "3 need attention") that opens a panel under
 * it. 44px target below 1024px; `aria-expanded` and `aria-controls` tie the button to the panel. It is closed by
 * default; `defaultOpen` opens it.
 */
const props = withDefaults(
    defineProps<{ title: string; defaultOpen?: boolean }>(),
    { defaultOpen: false },
);

const open = ref(props.defaultOpen);
const id = `v2-bar-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <section
        data-slot="v2-disclosure-bar"
        class="rounded-[14px] border bg-card"
    >
        <button
            type="button"
            :aria-expanded="open"
            :aria-controls="id"
            class="flex min-h-11 w-full items-center justify-between gap-3 rounded-[14px] px-4 py-2 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
            @click="open = !open"
        >
            {{ title }}
            <svg
                class="size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none"
                :class="open ? 'rotate-180' : ''"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="m6 9 6 6 6-6" />
            </svg>
        </button>
        <div v-if="open" :id="id" class="border-t p-4">
            <slot />
        </div>
    </section>
</template>

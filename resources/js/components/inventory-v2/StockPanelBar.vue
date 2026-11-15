<script setup lang="ts">
import { ref } from 'vue';

/**
 * Inventory V2 only: a disclosure bar like `V2DisclosureBar`, for the Classic stock panels on the V2 Inventory home
 * (receiving, transfers, stock requests, stock setup). Time-boxed exception (Daniel, 2026-11-13) until each panel is
 * rebuilt as its own V2 page or dialog, with a full review because they write stock.
 *
 * - The panel stays mounted when the bar closes (`v-show`), so a half-filled form is never lost. Replace with
 *   `V2DisclosureBar keepMounted` once that option exists.
 * - Closed, the panel is hidden and `inert`, so none of its fields can take focus.
 * - The Classic panel heading ("Inventory Operations", "Stock Setup") repeats the bar title, so it is kept for screen
 *   readers only.
 * - V2 scope for the Classic forms inside: 44 px fields and buttons below 1024 px, and no filled button
 *   (the page has no main action): every default (filled) button reads as outline, submits included.
 */
const props = defineProps<{ title: string }>();

const open = ref(false);
const id = `inv-v2-panel-${Math.random().toString(36).slice(2, 8)}`;

void props;
</script>

<template>
    <section
        data-slot="inventory-v2-stock-panel"
        class="rounded-[14px] border bg-card"
    >
        <button
            type="button"
            :aria-expanded="open"
            :aria-controls="id"
            class="flex min-h-11 w-full items-center justify-between gap-3 rounded-[14px] px-4 py-2 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:min-h-9"
            @click="open = !open"
        >
            <span class="min-w-0">{{ title }}</span>
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
        <div
            v-show="open"
            :id="id"
            :inert="!open"
            class="stock-panel border-t p-4"
        >
            <slot />
        </div>
    </section>
</template>

<style scoped>
.stock-panel :deep(> section > h2:first-child),
.stock-panel :deep(> section > div:first-child > h2:first-child) {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.stock-panel :deep(button[data-slot='button']:not([data-variant])) {
    border: 1px solid var(--border);
    background: var(--card);
    color: var(--foreground);
}

.stock-panel :deep(button[data-slot='button']:not([data-variant]):hover) {
    background: var(--muted);
}

@media (max-width: 1023.98px) {
    .stock-panel :deep(button),
    .stock-panel :deep(select),
    .stock-panel :deep(textarea),
    .stock-panel :deep([role='combobox']),
    .stock-panel :deep(input:not([type='checkbox']):not([type='radio'])) {
        min-height: 2.75rem;
    }
}
</style>

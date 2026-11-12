<script setup lang="ts">
// One list row: 56 px, title with an optional muted line, trailing slot.
// `link` adds a trailing chevron, for a row that opens something.
defineProps<{ title: string; description?: string; link?: boolean }>();
</script>

<template>
    <div
        data-slot="list-row"
        class="flex min-h-14 items-center gap-3 border-b border-border/70 px-4 py-2 last:border-0"
    >
        <div class="min-w-0 flex-1">
            <p class="truncate text-[15px] font-medium">{{ title }}</p>
            <p v-if="description" class="truncate text-[13px] text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <slot />
        <svg
            v-if="link"
            class="size-5 shrink-0 text-muted-foreground"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="m9 18 6-6-6-6" />
        </svg>
    </div>
</template>

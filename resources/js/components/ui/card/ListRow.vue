<script setup lang="ts">
// One list row: 56 px, title with an optional muted line, trailing slot.
defineProps<{ title: string; description?: string }>();
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
    </div>
</template>

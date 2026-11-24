<script setup lang="ts">
/**
 * DSP-25 (shared with the consultation review): the banner at the top of a Director's read-only review page. Neutral
 * info tone (Daniel), with Back as the only control, passed in the slot.
 */
import { Eye } from '@lucide/vue';

// DSP-26: the Resident Doctor's Dispensary pages pass their own line; the Director's default is unchanged.
withDefaults(defineProps<{ message?: string }>(), {
    message: 'Read only. Every view is recorded.',
});
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-muted/60 px-4 py-3 text-sm text-foreground dark:border-foreground/20"
        role="note"
        data-testid="read-only-banner"
    >
        <p class="flex items-center gap-2 font-medium">
            <Eye
                class="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            {{ message }}
        </p>
        <slot />
    </div>
</template>

<script setup lang="ts">
/**
 * Version 2 status chip: a coloured dot and the word. Colour is never the only signal. Used by V2 views only.
 */
defineProps<{
    tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
    label: string;
}>();

const dots = {
    neutral: 'bg-muted-foreground',
    info: 'bg-primary',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
} as const;
</script>

<template>
    <span
        data-slot="v2-chip"
        class="inline-flex min-h-6 items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-foreground"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :class="dots[tone]"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>

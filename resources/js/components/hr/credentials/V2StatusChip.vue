<script setup lang="ts">
// Version 2 credential status chip. Same look as V2Chip; Expired carries a small alert icon inside the chip, before the
// words, and bold text, so it never reads the same as Expiring. The tone stays warning, never red (HR-5a rule).
import { TriangleAlert } from '@lucide/vue';
import V2Chip from '@/components/v2/V2Chip.vue';

defineProps<{
    tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
    label: string;
    expired: boolean;
}>();
</script>

<template>
    <span
        v-if="expired"
        data-slot="v2-chip"
        class="inline-flex min-h-6 items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-0.5 text-xs leading-snug font-semibold text-balance text-foreground"
    >
        <TriangleAlert
            class="size-3.5 shrink-0 text-warning-strong"
            aria-hidden="true"
        />
        {{ label }}
    </span>
    <V2Chip v-else :tone="tone" :label="label" />
</template>

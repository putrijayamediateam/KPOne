<script setup lang="ts">
import { computed } from 'vue';
import { formatStatusLabel, statusTone } from '@/lib/presentation';

const props = defineProps<{
    status: string;
    label?: string;
    tone?: 'neutral' | 'accent' | 'info' | 'success' | 'warning' | 'danger';
}>();

// A coloured dot plus the word: colour is never the only signal.
const dot = computed(() => {
    const tone = props.tone ?? statusTone(props.status);

    return {
        neutral: 'bg-muted-foreground',
        accent: 'bg-brand',
        info: 'bg-primary',
        success: 'bg-success',
        warning: 'bg-warning',
        danger: 'bg-destructive',
    }[tone];
});
const text = computed(() =>
    (props.tone ?? statusTone(props.status)) === 'danger'
        ? 'text-destructive'
        : 'text-foreground',
);
</script>

<template>
    <span
        data-slot="status-badge"
        class="inline-flex min-h-6 items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="text"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :class="dot"
            aria-hidden="true"
        />
        {{ label ?? formatStatusLabel(status) }}
    </span>
</template>

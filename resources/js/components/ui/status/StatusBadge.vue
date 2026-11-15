<script setup lang="ts">
import { computed } from 'vue';
import { formatStatusLabel, statusTone } from '@/lib/presentation';

const props = defineProps<{
    status: string;
    label?: string;
    tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
}>();

const classes = computed(() => {
    const tone = props.tone ?? statusTone(props.status);

    return {
        neutral: 'border-border bg-muted text-foreground',
        accent: 'border-brand/25 bg-brand/10 text-brand-strong',
        success: 'border-success/25 bg-success/10 text-success',
        warning: 'border-warning/25 bg-warning/10 text-warning-strong',
        danger: 'border-destructive/25 bg-destructive/10 text-destructive',
    }[tone];
});
</script>

<template>
    <span
        data-slot="status-badge"
        class="inline-flex min-h-5 items-center rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="classes"
    >
        {{ label ?? formatStatusLabel(status) }}
    </span>
</template>

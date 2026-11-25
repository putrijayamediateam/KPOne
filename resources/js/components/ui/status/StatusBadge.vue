<script setup lang="ts">
import { computed } from 'vue';
import { formatStatusLabel, statusTone } from '@/lib/presentation';

const props = withDefaults(
    defineProps<{
        status: string;
        label?: string;
        tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
        dot?: boolean;
    }>(),
    // Clean Look: a status is always a dot plus a word, never a bare coloured pill.
    { dot: true },
);

const classes = computed(() => {
    const tone = props.tone ?? statusTone(props.status);

    return {
        neutral: 'bg-muted text-foreground',
        accent: 'bg-info-wash text-info',
        success: 'bg-success-wash text-success',
        warning: 'bg-warning-wash text-warning',
        danger: 'bg-destructive/10 text-destructive',
    }[tone];
});
</script>

<template>
    <span
        data-slot="status-badge"
        class="inline-flex min-h-5 items-center rounded-lg px-2 py-0.5 text-[12.5px] font-semibold whitespace-nowrap"
        :class="classes"
    >
        <span
            v-if="dot"
            aria-hidden="true"
            class="mr-1.5 size-1.5 rounded-full bg-current"
        />
        {{ label ?? formatStatusLabel(status) }}
    </span>
</template>

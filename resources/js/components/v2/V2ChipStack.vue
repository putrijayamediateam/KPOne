<script setup lang="ts">
import V2Chip from './V2Chip.vue';

/**
 * Version 2 chip stack: several status chips under the main status chip (for example "Not ready to call"). They wrap,
 * and stay in one column inside a phone card. The tone and the words are the page's data.
 */
defineProps<{
    chips: {
        tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
        label: string;
    }[];
}>();
</script>

<template>
    <div
        data-slot="v2-chip-stack"
        class="flex min-w-0 flex-wrap items-start gap-1.5 max-sm:flex-col"
    >
        <V2Chip
            v-for="chip in chips"
            :key="chip.label"
            :tone="chip.tone"
            :label="chip.label"
        />
    </div>
</template>

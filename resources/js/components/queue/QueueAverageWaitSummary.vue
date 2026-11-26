<script setup lang="ts">
import type { QueueAverageWait } from '@/composables/useQueueAverageWait';

defineProps<{
    stats: QueueAverageWait | null;
    unavailable: boolean;
    scope: 'own' | 'branch';
}>();
</script>

<template>
    <div
        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs text-muted-foreground"
    >
        <span
            >Average wait today ·
            {{ scope === 'own' ? 'Your patients' : 'Whole branch' }}</span
        >
        <span class="font-medium text-foreground tabular-nums">
            <template v-if="unavailable">Temporarily unavailable</template>
            <template v-else-if="!stats">Loading…</template>
            <template v-else-if="stats.averageWaitMinutes === null"
                >Not enough calls yet</template
            >
            <template v-else>{{ stats.averageWaitMinutes }} min</template>
        </span>
        <span v-if="stats"
            >{{ stats.samples }} called today ·
            {{ stats.operationalDate }}</span
        >
    </div>
</template>

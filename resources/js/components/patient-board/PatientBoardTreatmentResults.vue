<script setup lang="ts">
import { computed } from 'vue';
import type { TreatmentProgress, TreatmentRoomOutcomeSummary } from '@/types';
const props = defineProps<{ progress?: TreatmentProgress | null }>();
const entries = computed(() => {
    const progress = props.progress;
    const entries: Array<{
        step: string;
        outcome?: TreatmentRoomOutcomeSummary;
    }> = [];

    if (!progress) {
        return entries;
    }

    if (progress.label || progress.outcome) {
        entries.push({
            step: progress.label ?? 'Treatment step',
            outcome: progress.outcome,
        });
    }

    for (const step of progress.waiting ?? []) {
        entries.push({ step: step.label, outcome: step.outcome });
    }

    return entries;
});
</script>
<template>
    <ul
        v-if="entries.length"
        class="space-y-2 text-xs"
        aria-label="Recorded treatment outcomes"
    >
        <li
            v-for="(entry, index) in entries"
            :key="index"
            class="space-y-1 break-words"
        >
            <p class="font-medium">Step: {{ entry.step }}</p>
            <template v-if="entry.outcome">
                <p>Recorded procedure: {{ entry.outcome.procedureLabel }}</p>
                <p
                    :class="
                        entry.outcome.outcomeLabel === 'Not successful'
                            ? 'font-medium text-warning-strong'
                            : ''
                    "
                >
                    {{ entry.outcome.outcomeLabel
                    }}<span v-if="entry.outcome.reasonLabel"
                        >: {{ entry.outcome.reasonLabel }}</span
                    >
                </p>
            </template>
            <p v-else class="text-muted-foreground">Outcome not available</p>
        </li>
    </ul>
    <span v-else class="text-xs text-muted-foreground"
        >Outcome not available</span
    >
</template>

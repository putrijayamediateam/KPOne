<script setup lang="ts">
import { computed } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import {
    treatmentAriaLabel,
    treatmentBadgeText,
    treatmentChipLines,
    treatmentWaitingAriaLabel,
} from '@/lib/treatment-progress';
import type { TreatmentProgress } from '@/types';

/**
 * Version 2 treatment step chip (Classic: `components/queue/TreatmentProgressBadge.vue`, untouched). Same words,
 * lines and spoken label; a dot and a word instead of the green and grey boxes. The board passes server-anchored
 * minutes.
 */
const props = defineProps<{
    progress?: TreatmentProgress | null;
    minutes?: number | null;
}>();
const text = computed(() =>
    treatmentBadgeText(props.progress, props.minutes ?? null),
);
const lines = computed(() =>
    treatmentChipLines(
        props.progress,
        props.minutes ?? null,
        props.progress?.waiting,
        Date.now(),
    ),
);
const label = computed(
    () =>
        treatmentWaitingAriaLabel(
            props.progress,
            props.minutes ?? null,
            props.progress?.waiting,
            Date.now(),
        ) ?? treatmentAriaLabel(props.progress, props.minutes ?? null),
);
</script>

<template>
    <div
        v-if="text"
        role="status"
        :aria-label="label ?? undefined"
        :data-treatment-state="progress?.state"
        data-testid="treatment-progress-badge"
        class="flex w-full flex-col items-start gap-1"
    >
        <V2Chip
            :tone="
                progress?.state === 'done' || progress?.waiting?.length
                    ? 'success'
                    : 'info'
            "
            :label="lines ? lines[0] : text"
            aria-hidden="true"
        />
        <p
            v-for="(line, index) in lines?.slice(1) ?? []"
            :key="index"
            class="text-xs text-muted-foreground"
            aria-hidden="true"
            data-testid="treatment-progress-waiting"
        >
            {{ line }}
        </p>
    </div>
</template>

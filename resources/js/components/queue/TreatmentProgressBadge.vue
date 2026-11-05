<script setup lang="ts">
import { Activity, CheckCircle2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { statusToneClasses, treatmentProgressTone } from '@/lib/presentation';
import {
    treatmentAriaLabel,
    treatmentBadgeText,
    treatmentMinutes,
} from '@/lib/treatment-progress';
import type { TreatmentProgress } from '@/types';

// Q-2: neutral while a step runs (icon and word carry it), success once done. Tokens only (DARK-1 lesson).
// The board passes server-anchored minutes; with `live` the badge ticks its own clock (consultation page).
const props = defineProps<{
    progress?: TreatmentProgress | null;
    minutes?: number | null;
    live?: boolean;
}>();
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (props.live) {
        timer = setInterval(() => (now.value = Date.now()), 30_000);
    }
});
onBeforeUnmount(() => clearInterval(timer));
const shownMinutes = computed(() =>
    props.live
        ? treatmentMinutes(props.progress, now.value)
        : (props.minutes ?? null),
);
const text = computed(() =>
    treatmentBadgeText(props.progress, shownMinutes.value),
);
const label = computed(() =>
    treatmentAriaLabel(props.progress, shownMinutes.value),
);
</script>
<template>
    <span
        v-if="text"
        role="status"
        :aria-label="label ?? undefined"
        :data-treatment-state="progress?.state"
        data-testid="treatment-progress-badge"
        class="flex w-full items-start gap-1.5 rounded-md border p-2 text-xs break-words"
        :class="statusToneClasses[treatmentProgressTone(progress?.state)]"
    >
        <CheckCircle2
            v-if="progress?.state === 'done'"
            class="mt-0.5 size-3.5 shrink-0"
            aria-hidden="true"
        />
        <Activity v-else class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        <span aria-hidden="true">{{ text }}</span>
    </span>
</template>

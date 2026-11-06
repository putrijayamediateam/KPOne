<script setup lang="ts">
import { Activity, CheckCircle2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { statusToneClasses, treatmentProgressTone } from '@/lib/presentation';
import {
    treatmentAriaLabel,
    treatmentBadgeText,
    treatmentChipLines,
    treatmentMinutes,
    treatmentWaitingAriaLabel,
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
    // Q-2c: waiting lines count their own minutes, so every badge ticks (the board's main line keeps its
    // server-anchored minutes unless `live`).
    timer = setInterval(() => (now.value = Date.now()), 30_000);
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
// Q-2c (Yasmin): finished steps waiting beside the main step become extra lines in the same chip.
const lines = computed(() =>
    treatmentChipLines(
        props.progress,
        shownMinutes.value,
        props.progress?.waiting,
        now.value,
    ),
);
const label = computed(
    () =>
        treatmentWaitingAriaLabel(
            props.progress,
            shownMinutes.value,
            props.progress?.waiting,
            now.value,
        ) ?? treatmentAriaLabel(props.progress, shownMinutes.value),
);
const tone = computed(() =>
    treatmentProgressTone(
        props.progress?.waiting?.length ? 'done' : props.progress?.state,
    ),
);
</script>
<template>
    <span
        v-if="text"
        role="status"
        :aria-label="label ?? undefined"
        :data-treatment-state="progress?.state"
        data-testid="treatment-progress-badge"
        class="flex w-full flex-col gap-1 rounded-md border p-2 text-xs break-words"
        :class="statusToneClasses[tone]"
    >
        <span class="flex items-start gap-1.5">
            <CheckCircle2
                v-if="progress?.state === 'done'"
                class="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
            <Activity
                v-else
                class="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
            <span aria-hidden="true">{{ lines ? lines[0] : text }}</span>
        </span>
        <span
            v-for="(line, index) in lines?.slice(1) ?? []"
            :key="index"
            class="flex items-start gap-1.5 border-t border-current/20 pt-1"
            data-testid="treatment-progress-waiting"
        >
            <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span aria-hidden="true">{{ line }}</span>
        </span>
    </span>
</template>

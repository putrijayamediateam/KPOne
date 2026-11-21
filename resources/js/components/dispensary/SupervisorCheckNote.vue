<script setup lang="ts">
// DSP-23 (Daniel, change 4): says plainly that a CA Supervisor checked instead of the doctor, with who, when and why.
// A dot and words, never colour alone.
import { computed } from 'vue';
import { supervisorCheckSentence } from '@/lib/dispensary-doctor-check';
import type { DispensarySupervisorCheck } from '@/types';

const props = defineProps<{ check: DispensarySupervisorCheck }>();
const sentence = computed(() => supervisorCheckSentence(props.check));
</script>

<template>
    <p
        class="flex items-start gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
        role="note"
        data-testid="supervisor-check-note"
    >
        <span
            class="mt-1.5 size-1.5 shrink-0 rounded-full bg-foreground/70"
            aria-hidden="true"
        />
        <span class="min-w-0 break-words">{{ sentence }}</span>
    </p>
</template>

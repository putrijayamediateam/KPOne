<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';

// DSP-1: shown when a medicine's name or generic name matches the patient's recorded allergy text.
// The server repeats the check and refuses the save until the CA ticks the box.
defineProps<{
    matches: Array<{ publicId: string; allergen: string }>;
    fieldId: string;
}>();
const confirmed = defineModel<boolean>({ default: false });
</script>

<template>
    <div
        role="region"
        aria-label="Possible allergy match"
        class="rounded-md border border-red-300 bg-red-50 p-3 text-xs text-red-900 dark:border-red-500/60 dark:bg-red-950/60 dark:text-red-100"
        data-testid="allergy-match-warning"
    >
        <p class="flex items-start gap-2 text-sm font-semibold">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Possible allergy match
        </p>
        <p class="mt-1">
            This medicine may match the patient's recorded allergy:
            <strong
                v-for="(match, index) in matches"
                :key="match.publicId"
                data-testid="allergy-match-allergen"
                >{{ match.allergen
                }}{{ index < matches.length - 1 ? ', ' : '' }}</strong
            >.
        </p>
        <label
            :for="fieldId"
            class="mt-2 flex min-h-11 cursor-pointer items-center gap-2 font-medium"
        >
            <input
                :id="fieldId"
                v-model="confirmed"
                type="checkbox"
                class="size-4 accent-red-700 dark:accent-red-400"
                data-field="allergy-confirmation"
            />
            I have checked with the patient/doctor
        </label>
        <p class="mt-1 text-red-800 dark:text-red-200">
            This check only compares the allergy text with the medicine name and
            generic name. It can miss an allergy (for example a drug group, a
            brand name or a different spelling), so always read the full allergy
            list.
        </p>
    </div>
</template>

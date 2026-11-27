<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

// DSP-29 (Iman's sketch): the read-only twin of the CA's AllergyMatchWarning, for people who cannot change the line
// (the doctor's check panel; the view-only case page follows in DSP-29 part A). Same red box, no tick box, and it never says that anyone
// checked the match. The server's own allergy checks at save and complete are unchanged.
const props = defineProps<{
    allergens: string[];
    medicine: string;
    compact?: boolean;
}>();
const names = computed(() => [
    ...new Set(props.allergens.map((name) => name.trim()).filter(Boolean)),
]);
</script>

<template>
    <div
        v-if="names.length"
        role="note"
        :aria-label="`Possible allergy match for ${medicine}`"
        class="rounded-md border border-red-300 bg-red-50 text-xs break-words text-red-900 dark:border-red-500/60 dark:bg-red-950/60 dark:text-red-100"
        :class="compact ? 'p-2' : 'p-3'"
        data-testid="allergy-match-mark"
    >
        <p class="flex items-start gap-2 text-sm font-semibold">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Possible allergy match
        </p>
        <p class="mt-1">
            This medicine may match the patient's recorded
            {{ names.length === 1 ? 'allergy' : 'allergies' }}:
            <template v-for="(name, index) in names" :key="name"
                ><strong data-testid="allergy-match-mark-allergen">{{
                    name
                }}</strong
                >{{ index < names.length - 1 ? ', ' : '' }}</template
            >.
        </p>
        <p v-if="!compact" class="mt-1 text-red-800 dark:text-red-200">
            It can miss an allergy, so always read the full allergy list.
        </p>
    </div>
</template>

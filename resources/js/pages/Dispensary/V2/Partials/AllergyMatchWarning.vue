<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

/**
 * Version 2 allergy match warning (Classic is `../../Partials/AllergyMatchWarning.vue`, untouched). Same model and
 * the same server rule (the save is refused until the box is ticked). Daniel's conditions: an icon and words
 * ("Allergy match: penicillin") in the destructive tone; the tick's whole row is a 44 px target with a 24 px box on
 * phones; it is announced only when a new match appears after an add or edit (`announce`), not on page load.
 */
const props = withDefaults(
    defineProps<{
        matches: Array<{ publicId: string; allergen: string }>;
        fieldId: string;
        announce?: boolean;
    }>(),
    { announce: false },
);
const confirmed = defineModel<boolean>({ default: false });
const names = computed(() =>
    props.matches.map((match) => match.allergen).join(', '),
);
const spoken = ref('');

onMounted(() => {
    if (props.announce) {
        spoken.value = `Allergy match: ${names.value}. Check with the patient or doctor before you save.`;
    }
});
</script>

<template>
    <div
        role="region"
        :aria-label="`Allergy match: ${names}`"
        class="rounded-[10px] border border-destructive/40 bg-destructive/10 p-3 text-sm text-foreground"
        data-testid="allergy-match-warning"
    >
        <p class="sr-only" role="status">{{ spoken }}</p>
        <p
            class="flex items-start gap-2 text-[15px] font-semibold text-destructive"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span
                >Allergy match:
                <span
                    v-for="(match, index) in matches"
                    :key="match.publicId"
                    data-testid="allergy-match-allergen"
                    >{{ match.allergen
                    }}{{ index < matches.length - 1 ? ', ' : '' }}</span
                ></span
            >
        </p>
        <p class="mt-1">
            This medicine may match the patient's recorded allergy.
        </p>
        <label
            :for="fieldId"
            class="-mx-2 mt-2 flex min-h-11 w-[calc(100%+1rem)] cursor-pointer items-center gap-3 rounded-md px-2 font-medium hover:bg-destructive/10 lg:gap-2"
            data-testid="allergy-confirmation-row"
        >
            <input
                :id="fieldId"
                v-model="confirmed"
                type="checkbox"
                class="size-6 shrink-0 accent-destructive"
                data-field="allergy-confirmation"
            />
            I have checked with the patient or doctor
        </label>
        <p class="mt-1 text-[13px] text-muted-foreground">
            This check compares the allergy text with the medicine name, generic
            name, catalogue other names and catalogue drug groups only. It can
            miss an allergy (a drug group, brand name or spelling not listed in
            the catalogue),
            <span class="font-medium text-foreground"
                >so always read the full allergy list.</span
            >
        </p>
    </div>
</template>

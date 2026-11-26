<script setup lang="ts">
import { computed } from 'vue';
import { knownMinor } from '@/lib/guardian-contact';
import type { RegistrationRequirements } from '@/types/registration-settings';

const props = defineProps<{
    requirements: RegistrationRequirements;
    dateOfBirth: string;
    guardianToday?: string;
}>();
const minor = computed(() =>
    knownMinor(props.dateOfBirth, props.guardianToday),
);
const policyFields = computed(() => [
    { label: 'Date of birth', required: props.requirements.requireDateOfBirth },
    { label: 'Nationality', required: props.requirements.requireNationality },
    { label: 'Email', required: props.requirements.requireEmail },
    { label: 'Address', required: props.requirements.requireAddress },
]);
</script>

<template>
    <aside
        aria-label="New patient requirements"
        class="min-w-0 space-y-4 rounded-lg border bg-card p-4 text-sm shadow-card"
    >
        <div>
            <h2 class="font-semibold">Before creating a patient</h2>
            <p class="mt-2 text-muted-foreground">
                Search for an existing patient first. A full name and an IC or
                passport are required.
            </p>
        </div>
        <section aria-label="Organisation requirements" class="border-t pt-3">
            <h3 class="font-medium">Organisation requirements</h3>
            <dl class="mt-2 divide-y">
                <div
                    v-for="field in policyFields"
                    :key="field.label"
                    class="flex flex-wrap justify-between gap-2 py-2"
                >
                    <dt>{{ field.label }}</dt>
                    <dd class="text-muted-foreground">
                        {{ field.required ? 'Required' : 'Optional' }}
                    </dd>
                </div>
            </dl>
            <p
                v-if="requirements.requireAddress"
                class="mt-2 text-xs text-muted-foreground"
            >
                Address line 1, postcode, city, state and country are required.
                Address line 2 is optional.
            </p>
            <p v-else class="mt-2 text-xs text-muted-foreground">
                Address details are optional, including address line 2.
            </p>
        </section>
        <section
            aria-label="Contact and guardian guidance"
            class="border-t pt-3"
        >
            <h3 class="font-medium">Contact and guardian</h3>
            <p v-if="minor" class="mt-2 text-muted-foreground">
                For this minor, guardian name, relationship and phone are
                required. The patient's own phone is optional.
            </p>
            <p v-else class="mt-2 text-muted-foreground">
                A patient phone is required unless a valid date of birth
                confirms a minor. Guardian fields follow the date of birth
                entered in the form.
            </p>
            <p
                v-if="!dateOfBirth && !requirements.requireDateOfBirth"
                class="mt-2 text-muted-foreground"
            >
                If the date of birth is not recorded, complete the form's adult
                confirmation.
            </p>
        </section>
        <p class="border-t pt-3 text-muted-foreground">
            Creating a patient saves the patient record. It does not register a
            visit.
        </p>
    </aside>
</template>

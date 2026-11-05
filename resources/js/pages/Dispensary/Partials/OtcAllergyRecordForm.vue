<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import {
    allergyCategoryOptions,
    allergySeverityOptions,
} from '@/lib/allergy-options';

// DSP-5: the CA adds what an OTC walk-in is allergic to, saved to the patient's allergy profile (add only).
// The server repeats every check and refuses a stale page.
const props = defineProps<{
    casePublicId: string;
    branchId: number;
    caseLockVersion: number;
    profileVersion: number | null;
    optional: boolean;
}>();
const emit = defineEmits<{ cancel: []; saved: [] }>();

const form = useForm({
    allergen_text: '',
    category: '',
    severity: '',
    reaction_text: '',
});
const allergenInput = ref<HTMLInputElement | null>(null);
const fieldClass =
    'mt-1 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40';

const submit = () => {
    if (form.allergen_text.trim() === '') {
        form.setError('allergen_text', 'Enter the allergen.');
        allergenInput.value?.focus();

        return;
    }

    form.clearErrors();
    form.transform((data) => ({
        ...data,
        expected_branch_id: props.branchId,
        case_lock_version: props.caseLockVersion,
        profile_lock_version: props.profileVersion,
        category: data.category || null,
        severity: data.severity || null,
        reaction_text: data.reaction_text.trim() || null,
    })).post(`/dispensary/${props.casePublicId}/otc-allergy-records`, {
        preserveScroll: true,
        // Keep the CA's "has an allergy" choice on the page while the new record loads.
        preserveState: true,
        onSuccess: () => {
            form.reset();
            emit('saved');
        },
        onError: async () => {
            await nextTick();
            allergenInput.value?.focus();
        },
    });
};
</script>

<template>
    <form
        class="mt-3 grid gap-3 rounded-md border border-warning/50 bg-card p-3"
        novalidate
        data-testid="otc-allergy-form"
        :aria-busy="form.processing"
        @submit.prevent="submit"
    >
        <p class="text-sm font-semibold">Allergy details</p>
        <label class="text-sm" for="otc-allergy-allergen">
            Allergen
            <span class="text-muted-foreground">(required)</span>
            <input
                id="otc-allergy-allergen"
                ref="allergenInput"
                v-model="form.allergen_text"
                maxlength="500"
                required
                :disabled="form.processing"
                :aria-invalid="form.errors.allergen_text ? 'true' : undefined"
                :aria-describedby="
                    form.errors.allergen_text
                        ? 'otc-allergy-allergen-help otc-allergy-allergen-error'
                        : 'otc-allergy-allergen-help'
                "
                :class="[fieldClass, 'h-11 sm:h-9']"
                data-field="otc-allergy-allergen"
            />
            <span
                id="otc-allergy-allergen-help"
                class="mt-1 block text-xs text-muted-foreground"
                >Medicine, food or substance, e.g. penicillin.</span
            >
            <span
                v-if="form.errors.allergen_text"
                id="otc-allergy-allergen-error"
                class="mt-1 block text-xs text-destructive"
                >{{ form.errors.allergen_text }}</span
            >
        </label>
        <label class="text-sm" for="otc-allergy-category">
            Category <span class="text-muted-foreground">(optional)</span>
            <OperationalSelect
                id="otc-allergy-category"
                v-model="form.category"
                class="mt-1"
                trigger-class="h-11 sm:h-9"
                :disabled="form.processing"
                label="Allergy category"
                :options="allergyCategoryOptions"
                data-field="otc-allergy-category"
            />
        </label>
        <label class="text-sm" for="otc-allergy-severity">
            Severity <span class="text-muted-foreground">(optional)</span>
            <OperationalSelect
                id="otc-allergy-severity"
                v-model="form.severity"
                class="mt-1"
                trigger-class="h-11 sm:h-9"
                :disabled="form.processing"
                label="Allergy severity"
                :options="allergySeverityOptions"
                data-field="otc-allergy-severity"
            />
        </label>
        <label class="text-sm" for="otc-allergy-reaction">
            Reaction <span class="text-muted-foreground">(optional)</span>
            <textarea
                id="otc-allergy-reaction"
                v-model="form.reaction_text"
                maxlength="1000"
                rows="2"
                :disabled="form.processing"
                :aria-invalid="form.errors.reaction_text ? 'true' : undefined"
                :aria-describedby="
                    form.errors.reaction_text
                        ? 'otc-allergy-reaction-help otc-allergy-reaction-error'
                        : 'otc-allergy-reaction-help'
                "
                :class="[fieldClass, 'py-2']"
                data-field="otc-allergy-reaction"
            />
            <span
                id="otc-allergy-reaction-help"
                class="mt-1 block text-xs text-muted-foreground"
                >What happened, e.g. rash, swelling.</span
            >
            <span
                v-if="form.errors.reaction_text"
                id="otc-allergy-reaction-error"
                class="mt-1 block text-xs text-destructive"
                >{{ form.errors.reaction_text }}</span
            >
        </label>
        <p
            v-if="
                Object.keys(form.errors).some(
                    (key) => !['allergen_text', 'reaction_text'].includes(key),
                )
            "
            role="alert"
            class="text-xs text-destructive"
        >
            {{
                Object.entries(form.errors).find(
                    ([key]) =>
                        !['allergen_text', 'reaction_text'].includes(key),
                )?.[1]
            }}
        </p>
        <p class="text-xs text-muted-foreground">
            Saved to the patient's allergy profile. Only a doctor can change or
            remove it.
        </p>
        <div class="flex flex-col gap-2 sm:flex-row">
            <Button
                type="submit"
                class="h-11 w-full sm:h-9 sm:w-auto"
                :disabled="form.processing"
                data-action="add-otc-allergy-record"
            >
                {{ form.processing ? 'Saving…' : 'Add allergy' }}
            </Button>
            <Button
                v-if="optional"
                type="button"
                variant="outline"
                class="h-11 w-full sm:h-9 sm:w-auto"
                :disabled="form.processing"
                data-action="cancel-otc-allergy-form"
                @click="emit('cancel')"
            >
                Cancel
            </Button>
        </div>
    </form>
</template>

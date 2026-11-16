<script setup lang="ts">
/**
 * Version 2 look for the OTC allergy record form on the V2 Dispensary case page (DSP-5; owner: "start the next Dispensary
 * V2 page", 2026-11-16; Daniel's OK). Classic stays `pages/Dispensary/Partials/OtcAllergyRecordForm.vue`, untouched.
 * Same props, emits, POST, lock versions and client check; tests/Frontend/v2-dispensary-otc-allergy-form.test.mjs keeps
 * the request in step with Classic. The CA adds what an OTC walk-in is allergic to, saved to the patient's allergy
 * profile (add only). The server repeats every check and refuses a stale page.
 */
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import V2Field from '@/components/v2/V2Field.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import {
    allergyCategoryOptions,
    allergySeverityOptions,
} from '@/lib/allergy-options';

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
const guard = ref<InstanceType<typeof V2LeaveGuard> | null>(null);
const reactionMax = 1000;

// V2 (Daniel): the guard counts typed text only, so an empty form never asks.
const typed = computed(
    () =>
        form.allergen_text.trim() !== '' ||
        form.category !== '' ||
        form.severity !== '' ||
        form.reaction_text.trim() !== '',
);
// V2 (Daniel): "Add allergy" stays faded, with the reason, until the allergen is typed.
const addBlocked = computed(() => form.allergen_text.trim() === '');
// A refusal that is not about a field (for example a stale page) shows once, above the buttons.
const otherError = computed(
    () =>
        Object.entries(form.errors).find(
            ([key]) => !['allergen_text', 'reaction_text'].includes(key),
        )?.[1],
);

const submit = () => {
    if (form.processing) {
        return;
    }

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
        // V2: the inputs are disabled until the request finishes, so focus Allergen once it has (in Classic the focus
        // runs while the field is still disabled and is lost).
        onFinish: async () => {
            if (!form.hasErrors) {
                return;
            }

            await nextTick();
            allergenInput.value?.focus();
        },
    });
};

const cancel = () => {
    if (typed.value) {
        guard.value?.ask(() => emit('cancel'));

        return;
    }

    emit('cancel');
};

const fieldClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-[15px] outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40 disabled:opacity-60 lg:h-9 dark:border-foreground/20';
const outlineButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-60 aria-disabled:cursor-not-allowed aria-disabled:opacity-60 max-sm:w-full lg:h-9 dark:border-foreground/20';
</script>

<template>
    <form
        class="mt-3 grid gap-4 rounded-[14px] border bg-card p-4 dark:border-foreground/20"
        novalidate
        data-testid="otc-allergy-form"
        :aria-busy="form.processing"
        aria-labelledby="otc-allergy-heading"
        @submit.prevent="submit"
    >
        <V2LeaveGuard ref="guard" :dirty="typed" @discard="form.reset()" />
        <h3 id="otc-allergy-heading" class="text-[15px] font-semibold">
            Allergy details
        </h3>

        <V2Field
            v-slot="{ id, describedBy, invalid }"
            label="Allergen"
            required
            hint="Medicine, food or substance, e.g. penicillin."
            :error="form.errors.allergen_text"
        >
            <input
                :id="id"
                ref="allergenInput"
                v-model="form.allergen_text"
                maxlength="500"
                required
                :disabled="form.processing"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
                :class="fieldClass"
                data-field="otc-allergy-allergen"
            />
        </V2Field>

        <div class="grid gap-4 sm:grid-cols-2">
            <V2Field v-slot="{ id }" label="Category (optional)">
                <OperationalSelect
                    :id="id"
                    v-model="form.category"
                    trigger-class="h-11 lg:h-9"
                    :disabled="form.processing"
                    label="Allergy category"
                    :options="allergyCategoryOptions"
                    data-field="otc-allergy-category"
                />
            </V2Field>
            <V2Field v-slot="{ id }" label="Severity (optional)">
                <OperationalSelect
                    :id="id"
                    v-model="form.severity"
                    trigger-class="h-11 lg:h-9"
                    :disabled="form.processing"
                    label="Allergy severity"
                    :options="allergySeverityOptions"
                    data-field="otc-allergy-severity"
                />
            </V2Field>
        </div>

        <V2Field
            v-slot="{ id, describedBy, invalid }"
            label="Reaction (optional)"
            hint="What happened, e.g. rash, swelling."
            :error="form.errors.reaction_text"
        >
            <textarea
                :id="id"
                v-model="form.reaction_text"
                :maxlength="reactionMax"
                rows="2"
                :disabled="form.processing"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="
                    `${describedBy ?? ''} otc-allergy-reaction-count`.trim()
                "
                :class="[fieldClass, 'h-auto py-2 lg:h-auto']"
                data-field="otc-allergy-reaction"
            />
            <p
                id="otc-allergy-reaction-count"
                class="text-right text-[13px] text-muted-foreground tabular-nums"
                data-testid="otc-allergy-reaction-count"
            >
                {{ form.reaction_text.length }} / {{ reactionMax }}
            </p>
        </V2Field>

        <p
            v-if="otherError"
            role="alert"
            class="text-sm text-destructive"
            data-testid="otc-allergy-refusal"
        >
            {{ otherError }}
        </p>

        <!-- Daniel: the note sits beside "Add allergy", in view at 390, and says plainly it cannot be edited here. -->
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p
                id="otc-allergy-note"
                class="text-[13px] text-muted-foreground sm:max-w-80"
            >
                Saved to the patient's allergy profile. Only a doctor can change
                or remove it.
                <span class="font-medium text-foreground"
                    >You can't edit it afterwards.</span
                >
            </p>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <button
                    v-if="optional"
                    type="button"
                    :class="outlineButton"
                    :disabled="form.processing"
                    data-action="cancel-otc-allergy-form"
                    @click="cancel"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="outlineButton"
                    :disabled="form.processing"
                    :aria-disabled="addBlocked ? 'true' : undefined"
                    :aria-busy="form.processing"
                    :aria-describedby="
                        addBlocked
                            ? 'otc-allergy-note otc-allergy-add-reason'
                            : 'otc-allergy-note'
                    "
                    data-action="add-otc-allergy-record"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    {{ form.processing ? 'Saving…' : 'Add allergy' }}
                </button>
            </div>
        </div>
        <p
            v-if="addBlocked"
            id="otc-allergy-add-reason"
            class="-mt-2 text-[13px] text-muted-foreground sm:text-right"
            data-testid="otc-allergy-add-reason"
        >
            Enter the allergen to continue.
        </p>
    </form>
</template>

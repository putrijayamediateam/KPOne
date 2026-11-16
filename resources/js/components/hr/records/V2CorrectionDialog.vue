<script setup lang="ts">
// Version 2 "Ask for a correction" (HR-PLAN 11.3 rule 6): the same request and key as the Classic sheet, from
// `useCorrectionSheet`. "Recorded: … · New: …", a reason (empty at the start), a note and each error under its field.
import { computed } from 'vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import { useCorrectionSheet } from '@/composables/hr/useCorrectionSheet';
import type { CorrectionSheetProps } from '@/composables/hr/useCorrectionSheet';

const props = defineProps<CorrectionSheetProps>();
const open = defineModel<boolean>('open', { required: true });

const { errors, form, kindLabel, send } = useCorrectionSheet(props, open);

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';
const identityKinds = [
    { value: 'ic', label: 'IC (MyKad)' },
    { value: 'passport', label: 'Passport' },
];
const missing = computed(() => {
    if (['legal_name', 'nationality', 'date_of_birth'].includes(props.kind)) {
        if (form.value.trim() === '') {
            return `Enter the new ${kindLabel.value.toLowerCase()} to continue.`;
        }
    }

    if (props.kind === 'identity' && form.identityNumber.trim() === '') {
        return 'Enter the new identity number to continue.';
    }

    if (
        props.kind === 'bank' &&
        (form.bankName.trim() === '' ||
            form.holder.trim() === '' ||
            form.account.trim() === '')
    ) {
        return 'Fill in the bank name, holder and account number to continue.';
    }

    return form.reason === '' ? 'Pick a reason to continue.' : '';
});
</script>

<template>
    <V2Dialog
        :open="open"
        title="Ask for a correction"
        :description="`${kindLabel}. Someone in HR checks it and applies it.`"
        size="tall"
        :confirm-label="form.processing ? 'Sending…' : 'Send to HR'"
        :busy="form.processing"
        :confirm-disabled="missing !== ''"
        :confirm-disabled-reason="missing"
        data-test="correction-sheet"
        @update:open="(v: boolean) => (open = v)"
        @confirm="send"
    >
        <p v-if="errors.records" role="alert" class="text-sm text-destructive">
            {{ errors.records }}
        </p>
        <p class="text-sm" data-test="correction-compare">
            Recorded: {{ recorded }}
        </p>

        <V2Field
            v-if="['legal_name', 'nationality'].includes(kind)"
            v-slot="{ id, describedBy }"
            :label="`New ${kindLabel.toLowerCase()}`"
            required
            :error="errors.value"
        >
            <input
                :id="id"
                v-model="form.value"
                autocomplete="off"
                :class="inputClass"
                :aria-describedby="describedBy"
            />
        </V2Field>
        <V2DateField
            v-else-if="kind === 'date_of_birth'"
            v-model="form.value"
            label="New date of birth"
            required
            :error="errors.date_of_birth ?? errors.value"
        />
        <template v-else-if="kind === 'identity'">
            <V2OptionGroup
                v-model="form.identityKind"
                :options="identityKinds"
                label="Identity document"
                required
                :error="errors.identity_kind"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                :label="
                    form.identityKind === 'ic'
                        ? 'New IC number'
                        : 'New passport number'
                "
                required
                :error="errors.identity_number"
            >
                <input
                    :id="id"
                    v-model="form.identityNumber"
                    autocomplete="off"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-if="form.identityKind === 'passport'"
                v-slot="{ id, describedBy }"
                label="Issuing country (2-letter code)"
                :error="errors.passport_country"
            >
                <input
                    :id="id"
                    v-model="form.passportCountry"
                    maxlength="2"
                    :class="`${inputClass} w-24 uppercase`"
                    :aria-describedby="describedBy"
                />
            </V2Field>
        </template>
        <template v-else-if="kind === 'bank'">
            <V2Field
                v-slot="{ id, describedBy }"
                label="Bank name"
                required
                :error="errors.bank_name"
            >
                <input
                    :id="id"
                    v-model="form.bankName"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Account holder"
                required
                :error="errors.holder"
            >
                <input
                    :id="id"
                    v-model="form.holder"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Account number"
                required
                :error="errors.account"
            >
                <input
                    :id="id"
                    v-model="form.account"
                    inputmode="numeric"
                    autocomplete="off"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
        </template>

        <V2OptionGroup
            :model-value="form.reason || null"
            :options="reasons"
            label="Reason"
            required
            :error="errors.reason"
            @update:model-value="form.reason = $event ?? ''"
        />
        <V2Field
            v-slot="{ id, describedBy }"
            label="Note (optional)"
            hint="Do not write medical details here."
            :error="errors.note"
        >
            <textarea
                :id="id"
                v-model="form.note"
                maxlength="200"
                rows="3"
                :class="textareaClass"
                :aria-describedby="describedBy"
            ></textarea>
        </V2Field>
        <p v-if="errors.kind" role="alert" class="text-sm text-destructive">
            {{ errors.kind }}
        </p>
    </V2Dialog>
</template>

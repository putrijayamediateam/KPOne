<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PatientFormV2 from '@/components/patient-v2/PatientFormV2.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { knownMinor } from '@/lib/guardian-contact';
import {
    focusInvalidField,
    identifierIssuer,
    identityError,
    phoneError,
} from '@/lib/patient-registration';
import type { PatientFormValues } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

/**
 * Version 2 Create patient (shown only in V2; Classic is `pages/Patient/Create.vue`, untouched). Same props, same
 * `POST /patients`, same payload keys, identifier check, phone check, adult-confirmation key and duplicate override.
 * No backend change.
 */
const props = defineProps<{
    requirements: RegistrationRequirements;
    guardianToday?: string;
}>();

usePageCrumbs(() => [
    { title: 'Patients', href: '/patients' },
    { title: 'Create' },
]);

type IdentifierInput = {
    identifier_type: 'nric' | 'passport';
    issuing_country_code: string;
    value: string;
};
const identityTypeOptions = [
    { value: 'nric', label: 'Malaysian IC' },
    { value: 'passport', label: 'Passport' },
];
const form = useForm<
    PatientFormValues & {
        identifiers: IdentifierInput[];
        duplicate_override: boolean;
    }
>({
    adult_confirmed: false,
    adult_confirmation_key: crypto.randomUUID(),
    guardian: null,
    expected_branch_id: (
        usePage().props.branchContext as { active?: { id: number } } | undefined
    )?.active?.id,
    full_name: '',
    date_of_birth: '',
    sex: 'unknown',
    nationality_code: '',
    mobile_phone: '',
    phone_country: 'MY',
    email: '',
    address_line_1: '',
    address_line_2: '',
    postcode: '',
    city: '',
    state: '',
    country_code: 'MY',
    identifiers: [
        { identifier_type: 'nric', issuing_country_code: 'MY', value: '' },
    ],
    duplicate_override: false,
});
const initial = JSON.parse(JSON.stringify(form.data())) as Record<
    string,
    unknown
>;

const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
const identifierMessage = computed(
    () =>
        errorFor('identifiers.0.value') ||
        errorFor('identifiers.0.issuing_country_code') ||
        errorFor('identifiers'),
);
const updateIdentifierType = (value: string | number) => {
    const identifier = form.identifiers[0];
    identifier.identifier_type = String(value) as 'nric' | 'passport';
    identifier.issuing_country_code = identifierIssuer(
        identifier.identifier_type,
        identifier.issuing_country_code,
    );
    form.clearErrors('identifiers' as never);
};

// The date of birth box could not read what was typed: it says why, and nothing is sent.
const dobInvalid = ref(false);
const submit = () => {
    form.clearErrors();

    if (dobInvalid.value) {
        return focusInvalidField();
    }

    const missingName = !form.full_name.trim();

    if (missingName) {
        form.setError('full_name', 'Please enter the Patient name.');
    }

    const phone = phoneError(
        form.mobile_phone,
        form.phone_country,
        !knownMinor(form.date_of_birth, props.guardianToday),
    );
    const identity = form.identifiers[0];
    identity.issuing_country_code = identifierIssuer(
        identity.identifier_type,
        identity.issuing_country_code,
    );
    const identifier = identityError(
        identity.identifier_type,
        identity.value,
        identity.issuing_country_code,
    );

    if (phone) {
        form.setError('mobile_phone', phone);
    }

    if (identifier) {
        form.setError('identifiers', identifier);
    }

    if (missingName || phone || identifier) {
        return focusInvalidField();
    }

    form.transform((data) => {
        const { adult_confirmation_key, adult_confirmed, ...rest } = data;

        return !data.date_of_birth
            ? { ...rest, adult_confirmation_key, adult_confirmed }
            : rest;
    });

    form.post('/patients', { preserveState: true, onError: focusInvalidField });
};

const FIELD_LABELS: Record<string, string> = {
    full_name: 'Name',
    date_of_birth: 'Date of birth',
    sex: 'Gender',
    nationality_code: 'Nationality',
    guardian: 'Guardian contact',
    mobile_phone: 'Phone',
    email: 'Email',
    address_line_1: 'Address',
    address_line_2: 'Address',
    postcode: 'Address',
    city: 'Address',
    state: 'Address',
    identifiers: 'IC / Passport',
};
// A fresh retry key is made on every guardian edit, so it is not a change on its own.
const stripKey = (value: unknown) => {
    if (!value || typeof value !== 'object') {
        return value;
    }

    const rest = { ...(value as Record<string, unknown>) };
    Reflect.deleteProperty(rest, 'retry_key');

    return rest;
};
const changedFields = computed(() => {
    const current = form.data() as unknown as Record<string, unknown>;
    const labels = Object.keys(FIELD_LABELS)
        .filter((key) => {
            const a =
                key === 'guardian' ? stripKey(current[key]) : current[key];
            const b =
                key === 'guardian' ? stripKey(initial[key]) : initial[key];

            return JSON.stringify(a) !== JSON.stringify(b);
        })
        .map((key) => FIELD_LABELS[key]);

    return [...new Set(labels)];
});
const dirty = computed(() => changedFields.value.length > 0);
const summary = computed(() => [
    {
        label: 'Name',
        value: form.full_name.trim() || 'Not entered yet',
    },
    {
        label: 'IC / Passport',
        value: form.identifiers[0].value.trim() ? 'Entered' : 'Not entered yet',
    },
    ...(dirty.value
        ? [{ label: 'Unsaved entries', value: changedFields.value.join(', ') }]
        : []),
]);
const saveClass = computed(() => [
    'inline-flex items-center justify-center rounded-[10px] px-4 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50',
    'bg-primary text-primary-foreground hover:bg-primary/90',
]);
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
</script>

<template>
    <Head title="Create patient" />
    <PageFrame>
        <V2WorkLayout
            title="Create patient"
            description="Organisation-level canonical identity. This is not a registration or encounter."
            rail-from="xl"
            rail-width="narrow"
        >
            <template #actions>
                <Link
                    href="/patients"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:justify-start max-sm:border-0 max-sm:bg-transparent max-sm:px-0 max-sm:text-primary lg:h-9"
                    ><span class="max-sm:hidden">Back to patients</span
                    ><span class="sm:hidden">← Patients</span></Link
                >
            </template>

            <template #main>
                <form
                    id="v2-create-patient-form"
                    class="flex min-w-0 flex-col gap-4"
                    novalidate
                    @submit.prevent="submit"
                >
                    <V2FormCard
                        title="Identifier"
                        description="Required Malaysian IC or Passport. Values remain reserved if later retired."
                    >
                        <div class="grid min-w-0 gap-4 md:grid-cols-3">
                            <V2Field label="Identification type" required>
                                <template #default>
                                    <OperationalSelect
                                        :model-value="
                                            form.identifiers[0].identifier_type
                                        "
                                        label="Identification type"
                                        :options="identityTypeOptions"
                                        @update:model-value="
                                            updateIdentifierType
                                        "
                                    />
                                </template>
                            </V2Field>
                            <V2Field
                                label="Issuing country (2-letter code)"
                                required
                            >
                                <template #default="{ id }">
                                    <input
                                        :id="id"
                                        v-model="
                                            form.identifiers[0]
                                                .issuing_country_code
                                        "
                                        maxlength="2"
                                        :disabled="
                                            form.identifiers[0]
                                                .identifier_type === 'nric'
                                        "
                                        :class="[inputClass, 'uppercase']"
                                    />
                                </template>
                            </V2Field>
                            <V2Field
                                label="IC / Passport number"
                                required
                                :error="identifierMessage"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <input
                                        :id="id"
                                        v-model="form.identifiers[0].value"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        autocomplete="off"
                                        :class="inputClass"
                                        @input="
                                            form.clearErrors(
                                                'identifiers' as never,
                                            )
                                        "
                                    />
                                </template>
                            </V2Field>
                        </div>
                    </V2FormCard>

                    <PatientFormV2
                        :model="form"
                        :guardian-today="guardianToday"
                        :requirements="requirements"
                        :errors="form.errors"
                        new-patient
                        @dob-invalid="(value: boolean) => (dobInvalid = value)"
                        @clear="(key: string) => form.clearErrors(key as never)"
                    />

                    <label
                        v-if="form.errors.duplicate_override"
                        class="flex min-h-11 items-start gap-3 rounded-[10px] border border-warning/40 bg-warning/10 p-4 text-sm"
                    >
                        <input
                            v-model="form.duplicate_override"
                            type="checkbox"
                            class="mt-0.5 size-5"
                        />
                        <span
                            ><strong>Possible duplicate:</strong>
                            {{ form.errors.duplicate_override }} Confirm only
                            after reviewing the Patient directory.</span
                        >
                    </label>

                    <V2StickyBar>
                        <button
                            type="submit"
                            :class="saveClass"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing ? 'Creating…' : 'Create patient'
                            }}
                        </button>
                    </V2StickyBar>
                </form>
            </template>

            <template #rail>
                <V2StickySummary title="Summary" :items="summary">
                    <button
                        type="submit"
                        form="v2-create-patient-form"
                        :class="saveClass"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Creating…' : 'Create patient' }}
                    </button>
                </V2StickySummary>
            </template>
        </V2WorkLayout>
        <V2LeaveGuard :dirty="dirty && !form.processing" />
    </PageFrame>
</template>

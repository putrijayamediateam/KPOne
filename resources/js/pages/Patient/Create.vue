<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, UserPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PatientFormFields from '@/components/patient/PatientFormFields.vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import {
    phoneError,
    identityError,
    identifierIssuer,
    focusInvalidField,
} from '@/lib/patient-registration';
import type { PatientFormValues } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/patients' },
            { title: 'Create', href: '/patients/create' },
        ],
    },
});
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
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
const updateIdentifierType = (
    identifier: IdentifierInput,
    value: string | number,
) => {
    identifier.identifier_type = String(value) as 'nric' | 'passport';
    identifier.issuing_country_code = identifierIssuer(
        identifier.identifier_type,
        identifier.issuing_country_code,
    );
};
const submit = () => {
    form.clearErrors();
    const missingName = !form.full_name.trim();

    if (missingName) {
        form.setError('full_name', 'Please enter the Patient name.');
    }

    const phone = phoneError(form.mobile_phone, form.phone_country);
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

    form.post('/patients', { preserveState: true, onError: focusInvalidField });
};
</script>

<template>
    <Head title="Create patient" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <UserPlus class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Create Patient Master record
                </h1>
                <p class="text-sm text-muted-foreground">
                    Organisation-level canonical identity. This is not a
                    registration or encounter.
                </p>
            </div>
        </div>
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <PatientFormFields
                :model="form"
                :errors="form.errors"
                new-patient
            />
            <section class="rounded-lg border bg-card">
                <div
                    class="flex items-start justify-between gap-4 border-b px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold">Identifiers</h2>
                        <p class="text-sm text-muted-foreground">
                            Required Malaysian IC or Passport. Values remain
                            reserved if later retired.
                        </p>
                    </div>
                </div>
                <div class="space-y-3 p-5">
                    <div
                        v-if="!form.identifiers.length"
                        class="text-sm text-muted-foreground"
                    >
                        No identifier supplied.
                    </div>
                    <div
                        v-for="(identifier, index) in form.identifiers"
                        :key="index"
                        class="grid gap-3 rounded-md border p-4 md:grid-cols-3"
                    >
                        <div class="grid gap-2">
                            <span
                                :id="'patient-identifier-type-label-' + index"
                                class="text-sm font-medium"
                                >Identification type *</span
                            >
                            <OperationalSelect
                                :id="'patient-identifier-type-' + index"
                                :model-value="identifier.identifier_type"
                                label="Identification type"
                                :labelledby="
                                    'patient-identifier-type-label-' + index
                                "
                                :options="identityTypeOptions"
                                trigger-class="h-10"
                                @update:model-value="
                                    updateIdentifierType(identifier, $event)
                                "
                            />
                        </div>
                        <label class="grid gap-2"
                            ><span class="text-sm font-medium"
                                >Issuing country *</span
                            >
                            <input
                                v-model="identifier.issuing_country_code"
                                aria-label="Passport issuing country"
                                maxlength="2"
                                :disabled="
                                    identifier.identifier_type === 'nric'
                                "
                                class="h-10 rounded-md border bg-background px-3 text-sm uppercase"
                            />
                        </label>
                        <label class="grid gap-2"
                            ><span class="text-sm font-medium"
                                >IC / Passport number *</span
                            >
                            <input
                                v-model="identifier.value"
                                aria-label="IC or Passport number"
                                required
                                :aria-invalid="
                                    !!(
                                        errorFor(
                                            'identifiers.' + index + '.value',
                                        ) ||
                                        errorFor(
                                            'identifiers.' +
                                                index +
                                                '.issuing_country_code',
                                        ) ||
                                        errorFor('identifiers')
                                    )
                                "
                                :aria-describedby="'identity-error-' + index"
                                autocomplete="off"
                                class="h-10 rounded-md border bg-background px-3 text-sm"
                                placeholder="Identifier value"
                            />
                        </label>
                        <InputError
                            :id="'identity-error-' + index"
                            role="alert"
                            class="md:col-span-3"
                            :message="
                                errorFor('identifiers.' + index + '.value') ||
                                errorFor(
                                    'identifiers.' +
                                        index +
                                        '.issuing_country_code',
                                ) ||
                                errorFor('identifiers')
                            "
                        />
                    </div>
                </div>
            </section>
            <label
                v-if="form.errors.duplicate_override"
                class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm"
            >
                <input
                    v-model="form.duplicate_override"
                    type="checkbox"
                    class="mt-1"
                />
                <span
                    ><strong>Possible duplicate:</strong>
                    {{ form.errors.duplicate_override }} Confirm only after
                    reviewing the Patient Directory.</span
                >
            </label>
            <div class="flex justify-end gap-3">
                <Button variant="outline" as-child
                    ><Link href="/patients"
                        ><ArrowLeft class="size-4" /> Cancel</Link
                    ></Button
                >
                <Button type="submit" :disabled="form.processing"
                    >Create patient</Button
                >
            </div>
        </form>
    </main>
</template>

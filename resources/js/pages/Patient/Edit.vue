<script setup lang="ts">
import { usePage, Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from '@lucide/vue';
import PatientFormFields from '@/components/patient/PatientFormFields.vue';
import { Button } from '@/components/ui/button';
import { knownMinor } from '@/lib/guardian-contact';
import { phoneError, focusInvalidField } from '@/lib/patient-registration';
import type { PatientDetail, PatientFormValues } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/patients' },
            { title: 'Edit' },
        ],
    },
});
const props = defineProps<{
    patient: PatientDetail;
    guardianToday?: string;
    requirements: RegistrationRequirements;
}>();
const form = useForm<PatientFormValues & { lock_version: number }>({
    adult_confirmed: false,
    adult_confirmation_key: crypto.randomUUID(),
    guardian: props.patient.guardian
        ? { ...props.patient.guardian, retry_key: crypto.randomUUID() }
        : null,
    expected_branch_id: (
        usePage().props.branchContext as { active?: { id: number } } | undefined
    )?.active?.id,
    full_name: props.patient.identity.fullName,
    date_of_birth: props.patient.identity.dateOfBirth ?? '',
    sex: props.patient.identity.sex,
    nationality_code: props.patient.identity.nationalityCode ?? '',
    mobile_phone: props.patient.contact.mobilePhone ?? '',
    phone_country: 'MY',
    email: props.patient.contact.email ?? '',
    address_line_1: props.patient.address.line1 ?? '',
    address_line_2: props.patient.address.line2 ?? '',
    postcode: props.patient.address.postcode ?? '',
    city: props.patient.address.city ?? '',
    state: props.patient.address.state ?? '',
    country_code: props.patient.address.countryCode ?? '',
    lock_version: props.patient.administrative.lockVersion,
});
const submit = () => {
    form.clearErrors();

    if (form.mobile_phone !== (props.patient.contact.mobilePhone ?? '')) {
        const error = phoneError(
            form.mobile_phone,
            form.phone_country,
            !knownMinor(form.date_of_birth, props.guardianToday),
        );

        if (error) {
            form.setError('mobile_phone', error);

            return focusInvalidField();
        }
    }

    form.transform((data) => {
        const { adult_confirmation_key, adult_confirmed, ...rest } = data;

        return !data.date_of_birth && !!props.patient.identity.dateOfBirth
            ? { ...rest, adult_confirmation_key, adult_confirmed }
            : rest;
    });

    form.patch('/patients/' + encodeURIComponent(props.patient.patientNumber), {
        preserveState: true,
        onError: focusInvalidField,
    });
};
</script>

<template>
    <Head :title="'Edit ' + patient.patientNumber" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Edit Patient Master
            </h1>
            <p class="font-mono text-sm text-muted-foreground">
                {{ patient.patientNumber }} · identifiers are managed separately
            </p>
        </div>
        <form class="space-y-5" @submit.prevent="submit">
            <PatientFormFields
                :model="form"
                :guardian-today="guardianToday"
                :requirements="requirements"
                :errors="form.errors"
                :original-phone="patient.contact.mobilePhone ?? ''"
                :original-dob="patient.identity.dateOfBirth ?? ''"
                :can-whatsapp="patient.canWhatsapp"
                :patient-number="patient.patientNumber"
            />
            <div
                v-if="form.errors.lock_version"
                class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm"
            >
                {{ form.errors.lock_version }}
            </div>
            <div class="flex justify-end gap-3">
                <Button variant="outline" as-child
                    ><Link
                        :href="
                            '/patients/' +
                            encodeURIComponent(patient.patientNumber)
                        "
                        ><ArrowLeft class="size-4" /> Cancel</Link
                    ></Button
                >
                <Button type="submit" :disabled="form.processing"
                    ><Save class="size-4" /> Save changes</Button
                >
            </div>
        </form>
    </main>
</template>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from '@lucide/vue';
import PatientFormFields from '@/components/patient/PatientFormFields.vue';
import { Button } from '@/components/ui/button';
import { phoneError, focusInvalidField } from '@/lib/patient-registration';
import type { PatientDetail, PatientFormValues } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/patients' },
            { title: 'Edit' },
        ],
    },
});
const props = defineProps<{ patient: PatientDetail }>();
const form = useForm<PatientFormValues & { lock_version: number }>({
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
        const error = phoneError(form.mobile_phone, form.phone_country);

        if (error) {
            form.setError('mobile_phone', error);

            return focusInvalidField();
        }
    }

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
                :errors="form.errors"
                :original-phone="patient.contact.mobilePhone ?? ''"
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

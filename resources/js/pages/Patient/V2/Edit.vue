<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PatientFormV2 from '@/components/patient-v2/PatientFormV2.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { knownMinor } from '@/lib/guardian-contact';
import { focusInvalidField, phoneError } from '@/lib/patient-registration';
import type { PatientDetail, PatientFormValues } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

/**
 * Version 2 Edit patient (shown only in V2; Classic is `pages/Patient/Edit.vue`, untouched). Same props, same
 * `PATCH /patients/{number}`, same payload keys, lock version, adult-confirmation key, guardian retry key and phone
 * check. No backend change.
 */
const props = defineProps<{
    patient: PatientDetail;
    guardianToday?: string;
    requirements: RegistrationRequirements;
}>();

usePageCrumbs(() => [
    {
        title: props.patient.patientNumber,
        href: '/patients/' + encodeURIComponent(props.patient.patientNumber),
    },
    { title: 'Edit' },
]);

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
// The values the page opened with, to say which fields have unsaved changes (names only, never values).
const initial = JSON.parse(JSON.stringify(form.data())) as Record<
    string,
    unknown
>;

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
    country_code: 'Address',
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
// A fresh retry key is made on every guardian edit, so it is not a change on its own.
const stripKey = (value: unknown) => {
    if (!value || typeof value !== 'object') {
        return value;
    }

    const rest = { ...(value as Record<string, unknown>) };
    Reflect.deleteProperty(rest, 'retry_key');

    return rest;
};
const dirty = computed(() => changedFields.value.length > 0);
const summary = computed(() => [
    { label: 'Patient', value: props.patient.identity.fullName },
    { label: 'Patient number', value: props.patient.patientNumber },
    ...(dirty.value
        ? [{ label: 'Unsaved changes', value: changedFields.value.join(', ') }]
        : []),
]);
const formError = computed(() => form.errors.lock_version);
const saveClass = computed(() => [
    'inline-flex items-center justify-center rounded-[10px] px-4 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50',
    dirty.value
        ? 'bg-primary text-primary-foreground hover:bg-primary/90'
        : 'border border-border bg-card text-foreground hover:bg-muted',
]);
const hasAddress = !!props.patient.address.line1;
const patientUrl =
    '/patients/' + encodeURIComponent(props.patient.patientNumber);
</script>

<template>
    <Head :title="'Edit ' + patient.patientNumber" />
    <PageFrame>
        <V2WorkLayout
            title="Edit patient"
            :description="`${patient.identity.fullName} · identifiers are managed separately`"
            rail-from="xl"
            rail-width="narrow"
        >
            <template #actions>
                <Link
                    :href="patientUrl"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:justify-start max-sm:border-0 max-sm:bg-transparent max-sm:px-0 max-sm:text-primary lg:h-9"
                    ><span class="max-sm:hidden">Back to patient</span
                    ><span class="sm:hidden">← Patient</span></Link
                >
            </template>

            <template #main>
                <form
                    id="v2-edit-patient-form"
                    class="flex min-w-0 flex-col gap-4"
                    @submit.prevent="submit"
                >
                    <PatientFormV2
                        :model="form"
                        :guardian-today="guardianToday"
                        :requirements="requirements"
                        :errors="form.errors"
                        :original-phone="patient.contact.mobilePhone ?? ''"
                        :original-dob="patient.identity.dateOfBirth ?? ''"
                        :has-address="hasAddress"
                        @clear="(key: string) => form.clearErrors(key as never)"
                    />
                    <p
                        v-if="formError"
                        role="alert"
                        class="rounded-[10px] border border-warning/40 p-4 text-sm"
                    >
                        {{ formError }}
                    </p>
                    <V2StickyBar>
                        <button
                            type="submit"
                            :class="saveClass"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </button>
                    </V2StickyBar>
                </form>
            </template>

            <template #rail>
                <V2StickySummary title="Summary" :items="summary">
                    <button
                        type="submit"
                        form="v2-edit-patient-form"
                        :class="saveClass"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Saving…' : 'Save changes' }}
                    </button>
                </V2StickySummary>
            </template>
        </V2WorkLayout>
        <V2LeaveGuard :dirty="dirty && !form.processing" />
    </PageFrame>
</template>

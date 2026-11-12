<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdultConfirmation from '@/components/patient/AdultConfirmation.vue';
import GuardianFields from '@/components/patient/GuardianFields.vue';
import PhoneInput from '@/components/patient/PhoneInput.vue';
import RegistrationContactFields from '@/components/patient/RegistrationContactFields.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import { DateField } from '@/components/ui/date-field';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2PatientPickList from '@/components/v2/V2PatientPickList.vue';
import V2SearchCombobox from '@/components/v2/V2SearchCombobox.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import VisitReasonPicker from '@/components/visit/VisitReasonPicker.vue';
import { useRegistrationCreate } from '@/composables/registration/useRegistrationCreate';
import { knownMinor } from '@/lib/guardian-contact';
import { formatDate } from '@/lib/presentation';
import type { PatientRegistrationSummary, VisitOptions } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

/**
 * Version 2 Register visit (shown only in V2; Classic is `pages/Registration/Create.vue`, untouched). Same props, same
 * `POST /registration` payload (through `useRegistrationCreate`, a copy of the Classic logic), same patient search,
 * same field rules. The search tries Malay-style input as Classic does: KP- numbers, a 12-digit IC, a phone or a name.
 */
const props = defineProps<{
    options: VisitOptions & { idempotencyKey: string };
    recentPatients: PatientRegistrationSummary[];
    requirements: RegistrationRequirements;
    guardianToday?: string;
}>();
const c = useRegistrationCreate(props);
const {
    form,
    quick,
    selectedPatient,
    quickMode,
    visitReady,
    patientQuery,
    patientResults,
    searching,
    showingRecent,
} = c;

const patientOptions = computed(() =>
    patientResults.value.map((patient) => ({
        id: patient.patientNumber,
        label: `${patient.fullName} · ${patient.patientNumber}`,
        detail: `${
            patient.dateOfBirth
                ? formatDate(patient.dateOfBirth)
                : 'DOB unknown'
        } · ${
            patient.identifier?.maskedValue ??
            patient.maskedPhone ??
            'No identifier'
        }`,
    })),
);
const pickList = computed(() =>
    props.recentPatients.map((patient) => ({
        value: patient.patientNumber,
        name: patient.fullName,
        kpNumber: patient.patientNumber,
        maskedIdentifier:
            patient.identifier?.maskedValue ?? patient.maskedPhone ?? undefined,
        dateOfBirth: patient.dateOfBirth
            ? formatDate(patient.dateOfBirth)
            : undefined,
    })),
);
const findPatient = (number: string) =>
    [...patientResults.value, ...props.recentPatients].find(
        (patient) => patient.patientNumber === number,
    );
const pick = (number: string) => {
    const patient = findPatient(number);

    if (patient) {
        c.selectPatient(patient);
    }
};
const visitTypeOptions = [
    { value: 'consultation', label: 'Consultation' },
    { value: 'otc', label: 'OTC' },
];
const coverageOptions = [
    { value: 'self_pay', label: 'Self-pay' },
    { value: 'panel', label: 'Panel' },
];
const urgencyOptions = [
    { value: 'normal', label: 'Normal' },
    { value: 'urgent', label: 'Urgent' },
];
const summary = computed(() => [
    { label: 'Patient', value: c.patientSummaryName.value },
    {
        label: 'Visit type',
        value: form.visit_type === 'consultation' ? 'Consultation' : 'OTC',
    },
    {
        label: 'Coverage',
        value:
            form.coverage_type === 'panel'
                ? (props.options.panels.find((p) => p.id === form.panel_id)
                      ?.name ?? 'Panel')
                : 'Self-pay',
    },
    {
        label: 'Urgency',
        value: form.priority === 'urgent' ? 'Urgent' : 'Normal',
    },
]);
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <Head title="Register visit" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Register visit"
            :description="`${options.branch.name} · current branch. Pick the patient, then the visit details.`"
            rail-width="narrow"
        >
            <template #actions>
                <Link
                    href="/registration"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >Back to registration</Link
                >
            </template>

            <template #main>
                <V2FormCard
                    :step="1"
                    title="Patient"
                    description="Select an existing patient or create a new one quickly. Search first; Quick create appears when nothing matches."
                >
                    <div
                        v-if="selectedPatient"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border bg-muted/40 p-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">
                                {{ selectedPatient.fullName }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ selectedPatient.patientNumber }} ·
                                {{
                                    selectedPatient.dateOfBirth
                                        ? formatDate(
                                              selectedPatient.dateOfBirth,
                                          )
                                        : 'DOB not recorded'
                                }}
                                ·
                                {{
                                    selectedPatient.identifier?.maskedValue ??
                                    selectedPatient.maskedPhone ??
                                    'No identifier recorded'
                                }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <WhatsAppLink
                                v-if="!selectedPatient.identifier"
                                :can-whatsapp="selectedPatient.canWhatsapp"
                                :route-key="selectedPatient.patientNumber"
                            />
                            <button
                                type="button"
                                class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                @click="c.changePatient"
                            >
                                Change patient
                            </button>
                        </div>
                    </div>

                    <template v-else-if="!quickMode">
                        <!-- The "no match" row and Quick create sit right under the box, so an empty result list stays hidden. -->
                        <V2SearchCombobox
                            class="[&_ul[role=listbox]:not(:has([role=option],[role=status]))]:hidden"
                            v-model="patientQuery"
                            label="Search patient"
                            placeholder="Name, IC number, phone or Patient No."
                            :options="patientOptions"
                            :loading="searching"
                            empty-text="No matching Patient found."
                            @select="(option) => pick(option.id)"
                        />
                        <p
                            v-if="
                                patientQuery.trim().length > 0 &&
                                patientQuery.trim().length < 3
                            "
                            class="text-[13px] text-muted-foreground"
                        >
                            Enter at least 3 characters.
                        </p>
                        <p
                            v-if="c.patientSearchError.value"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ c.patientSearchError.value }}
                        </p>
                        <p
                            v-else-if="c.errorFor('patient_number')"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ c.errorFor('patient_number') }}
                        </p>

                        <div v-if="showingRecent && pickList.length">
                            <h3 class="mb-2 text-[13px] font-semibold">
                                Recent patients
                            </h3>
                            <V2PatientPickList
                                label="Recent patients"
                                :model-value="null"
                                :patients="pickList"
                                @update:model-value="pick"
                            />
                        </div>
                        <div
                            v-if="
                                (!searching &&
                                    patientQuery.trim().length >= 3 &&
                                    patientResults.length === 0) ||
                                (showingRecent && !pickList.length)
                            "
                            class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] bg-muted/40 p-3 text-sm"
                        >
                            <span>{{
                                showingRecent
                                    ? 'No patients yet.'
                                    : 'No matching Patient found.'
                            }}</span>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                @click="c.startQuickCreate"
                            >
                                Quick create
                            </button>
                        </div>
                    </template>

                    <div v-else class="grid gap-4 md:grid-cols-2">
                        <h3 class="text-[13px] font-semibold md:col-span-2">
                            New patient (Quick create)
                        </h3>
                        <div class="md:col-span-2">
                            <V2Field
                                v-slot="{ describedBy, invalid }"
                                label="Name"
                                required
                                :error="c.errorFor('quick_patient.full_name')"
                            >
                                <input
                                    id="quick-patient-name"
                                    aria-label="Name"
                                    v-model="quick.full_name"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    autocomplete="off"
                                    :class="inputClass"
                                />
                            </V2Field>
                        </div>
                        <V2Field
                            v-slot="{ describedBy, invalid }"
                            label="Identity document"
                            required
                            :error="
                                c.errorFor('quick_patient.identifiers') ||
                                c.errorFor(
                                    'quick_patient.identifiers.0.value',
                                ) ||
                                c.errorFor(
                                    'quick_patient.identifiers.0.issuing_country_code',
                                )
                            "
                        >
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <OperationalSelect
                                    v-model="c.quickIdentifierType.value"
                                    label="Identification type"
                                    :options="c.identityTypeOptions"
                                    trigger-class="h-11 w-full sm:w-52 lg:h-9"
                                />
                                <input
                                    v-model="c.quickIdentifierValue.value"
                                    aria-label="IC or Passport number"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    autocomplete="off"
                                    :class="inputClass"
                                />
                            </div>
                        </V2Field>
                        <V2Field
                            v-if="c.quickIdentifierType.value === 'passport'"
                            v-slot="{ id, describedBy }"
                            label="Passport issuer"
                            required
                        >
                            <input
                                :id="id"
                                v-model="c.quickIssuer.value"
                                :aria-describedby="describedBy"
                                maxlength="2"
                                :class="`${inputClass} uppercase`"
                            />
                        </V2Field>
                        <span v-else class="max-md:hidden" />
                        <PhoneInput
                            id="quick-phone"
                            v-model="quick.mobile_phone"
                            v-model:country="quick.phone_country"
                            :required="
                                !knownMinor(quick.date_of_birth, guardianToday)
                            "
                            :error="
                                c.errorFor('quick_patient.mobile_phone') ||
                                c.errorFor('quick_patient.phone_country')
                            "
                        />
                        <V2Field
                            label="Date of birth"
                            :required="requirements.requireDateOfBirth"
                            :error="c.errorFor('quick_patient.date_of_birth')"
                        >
                            <DateField
                                v-model="quick.date_of_birth"
                                :required="requirements.requireDateOfBirth"
                                :aria-invalid="
                                    !!c.errorFor('quick_patient.date_of_birth')
                                "
                            />
                        </V2Field>
                        <div
                            v-if="
                                !quick.date_of_birth &&
                                !requirements.requireDateOfBirth
                            "
                            class="md:col-span-2"
                        >
                            <AdultConfirmation
                                v-model="quick.adult_confirmed"
                                :error="
                                    c.errorFor(
                                        'quick_patient.adult_confirmed',
                                    ) ||
                                    c.errorFor(
                                        'quick_patient.adult_confirmation_key',
                                    )
                                "
                            />
                        </div>
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="Nationality country code"
                            :required="requirements.requireNationality"
                            :error="
                                c.errorFor('quick_patient.nationality_code')
                            "
                        >
                            <input
                                :id="id"
                                v-model="quick.nationality_code"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :required="requirements.requireNationality"
                                maxlength="2"
                                placeholder="MY"
                                :class="`${inputClass} uppercase`"
                            />
                        </V2Field>
                        <V2Field label="Gender">
                            <OperationalSelect
                                v-model="quick.sex"
                                label="Gender"
                                :invalid="!!c.errorFor('quick_patient.sex')"
                                :options="c.genderOptions"
                                trigger-class="h-11 lg:h-9"
                            />
                        </V2Field>
                        <div class="md:col-span-2">
                            <GuardianFields
                                v-model="quick.guardian"
                                :dob="quick.date_of_birth"
                                :today="guardianToday"
                                new-patient
                                :errors="c.contactErrors.value"
                            />
                        </div>
                        <RegistrationContactFields
                            class="md:col-span-2"
                            :model="quick"
                            :require-email="requirements.requireEmail"
                            :require-address="requirements.requireAddress"
                            :errors="c.contactErrors.value"
                            id-prefix="quick-contact"
                        />
                        <label
                            v-if="
                                c.errorFor('quick_patient.duplicate_override')
                            "
                            class="flex items-start gap-2 rounded-[10px] border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong md:col-span-2"
                            ><input
                                v-model="quick.duplicate_override"
                                type="checkbox"
                                class="mt-1"
                            /><span
                                >{{
                                    c.errorFor(
                                        'quick_patient.duplicate_override',
                                    )
                                }}
                                Confirm only after review.</span
                            ></label
                        >
                        <div class="md:col-span-2">
                            <button
                                type="button"
                                class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                @click="c.changePatient"
                            >
                                Back to search
                            </button>
                        </div>
                    </div>
                </V2FormCard>

                <form
                    v-if="visitReady"
                    id="v2-register-visit-form"
                    class="flex flex-col gap-5"
                    @submit.prevent="c.submit"
                >
                    <V2FormCard
                        :step="2"
                        title="Visit details"
                        description="Visit type, doctor, reason, coverage and urgency."
                    >
                        <div class="grid gap-4 md:grid-cols-2">
                            <V2OptionGroup
                                v-model="form.visit_type"
                                label="Visit type"
                                :options="visitTypeOptions"
                                @update:model-value="
                                    (value) =>
                                        (form.visit_type = value as
                                            'consultation' | 'otc')
                                "
                            />
                            <V2Field
                                label="Doctor"
                                :hint="
                                    form.visit_type === 'otc'
                                        ? 'Optional for OTC.'
                                        : undefined
                                "
                                :error="c.errorFor('assigned_doctor_user_id')"
                            >
                                <OperationalSelect
                                    id="assigned-doctor"
                                    v-model="form.assigned_doctor_user_id"
                                    label="Doctor"
                                    :invalid="
                                        !!form.errors.assigned_doctor_user_id
                                    "
                                    :options="c.doctorOptions.value"
                                    trigger-class="h-11 lg:h-9"
                                />
                            </V2Field>
                            <div class="md:col-span-2">
                                <p class="mb-1.5 text-sm font-medium">
                                    Visit reason (up to 5, first is primary)
                                    <span
                                        v-if="form.visit_type === 'otc'"
                                        class="font-normal text-muted-foreground"
                                        >(optional)</span
                                    >
                                </p>
                                <VisitReasonPicker
                                    v-model="form.visit_reason_public_ids"
                                    v-model:selected="
                                        c.selectedVisitReasons.value
                                    "
                                    :error="
                                        c.errorFor('visit_reason_public_ids')
                                    "
                                />
                            </div>
                            <V2OptionGroup
                                v-model="form.coverage_type"
                                label="Coverage"
                                :options="coverageOptions"
                                @update:model-value="
                                    (value) =>
                                        (form.coverage_type = value as
                                            'self_pay' | 'panel')
                                "
                            />
                            <V2OptionGroup
                                v-model="form.priority"
                                label="Urgency"
                                :options="urgencyOptions"
                                @update:model-value="
                                    (value) =>
                                        (form.priority = value as
                                            'normal' | 'urgent')
                                "
                            />
                            <template v-if="form.coverage_type === 'panel'">
                                <V2Field
                                    label="Panel"
                                    :error="c.errorFor('panel_id')"
                                >
                                    <OperationalSelect
                                        id="panel-id"
                                        v-model="form.panel_id"
                                        label="Panel"
                                        :invalid="!!form.errors.panel_id"
                                        :options="c.panelOptions.value"
                                        trigger-class="h-11 lg:h-9"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Member or staff reference"
                                    hint="Optional."
                                    :error="
                                        c.errorFor('coverage_member_reference')
                                    "
                                >
                                    <input
                                        :id="id"
                                        v-model="form.coverage_member_reference"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        autocomplete="off"
                                        :class="inputClass"
                                    />
                                </V2Field>
                                <div class="md:col-span-2">
                                    <V2Field
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Panel note"
                                        :hint="`Optional. ${form.coverage_panel_note.length} / 500`"
                                        :error="
                                            c.errorFor('coverage_panel_note') ||
                                            c.errorFor(
                                                'panel_note_idempotency_key',
                                            )
                                        "
                                    >
                                        <textarea
                                            :id="id"
                                            v-model="form.coverage_panel_note"
                                            :aria-describedby="describedBy"
                                            :aria-invalid="invalid"
                                            rows="2"
                                            maxlength="500"
                                            data-testid="registration-panel-note"
                                            class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                                        />
                                    </V2Field>
                                </div>
                            </template>
                        </div>
                    </V2FormCard>

                    <label
                        v-if="c.errorFor('confirm_repeat')"
                        class="flex items-start gap-3 rounded-[14px] border border-warning/25 bg-warning/10 p-4 text-sm text-warning-strong"
                        ><input
                            v-model="form.confirm_repeat"
                            type="checkbox"
                            class="mt-1"
                        /><span
                            ><strong>Repeat visit warning:</strong>
                            {{ c.errorFor('confirm_repeat') }}</span
                        ></label
                    >
                    <p
                        v-if="
                            c.errorFor('expected_branch_id') ||
                            c.errorFor('quick_patient')
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{
                            c.errorFor('expected_branch_id') ||
                            c.errorFor('quick_patient')
                        }}
                    </p>

                    <V2StickyBar>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing
                                    ? 'Registering…'
                                    : 'Register visit'
                            }}
                        </button>
                    </V2StickyBar>
                </form>
            </template>

            <template #rail>
                <V2StickySummary title="Summary" :items="summary">
                    <button
                        type="submit"
                        form="v2-register-visit-form"
                        class="inline-flex items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50"
                        :disabled="!visitReady || form.processing"
                    >
                        {{
                            form.processing ? 'Registering…' : 'Register visit'
                        }}
                    </button>
                    <p class="text-[13px] text-muted-foreground">
                        {{
                            visitReady
                                ? 'Registers the visit now. The patient joins the list on the Registration board.'
                                : 'Choose or create a patient first.'
                        }}
                    </p>
                </V2StickySummary>
                <V2Chip
                    v-if="form.priority === 'urgent'"
                    tone="danger"
                    label="Urgent visit"
                />
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import V2PhoneField from '@/components/registration-v2/V2PhoneField.vue';
import VisitReasonField from '@/components/registration-v2/VisitReasonField.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import type { GuardianInput } from '@/lib/guardian-contact';
import { requestJson } from '@/lib/json-client';
import { focusInvalidField } from '@/lib/patient-registration';
import { ageOn } from '@/lib/patient-v2';
import { formatDate } from '@/lib/presentation';

/**
 * Version 2 QR intake review (shown only in V2; Classic is `pages/RegistrationReview/Show.vue`, untouched). Same props,
 * same routes (`PATCH /correct`, `POST /accept`, `/correction-required`, `/reject`, `GET /guardian-contact`), same payload
 * keys, same lock-version watchers and coverage rules. No backend change. Reject asks for a confirmation first.
 */
type Intake = {
    publicId: string;
    status: string;
    submissionType: string;
    privacyNoticeVersion: string;
    collectContactDetails: boolean;
    consentedAt: string;
    submittedAt: string;
    lockVersion: number;
    fields: {
        submission_type: string;
        full_name: string;
        date_of_birth: string;
        sex: string;
        nationality_code: string | null;
        email: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        city: string | null;
        state: string | null;
        postcode: string | null;
        country_code: string | null;
        mobile_phone: string;
        phone_country: string;
        identifier_type: string;
        identifier_value: string;
        identifier_issuing_country_code: string | null;
        guardian_name: string | null;
        guardian_relationship: string | null;
        guardian_contact_number: string | null;
        guardian_attestation: boolean;
        visit_kind?: string;
        visit_purpose: string;
        chief_complaint: string;
        complaint_duration: string | null;
        coverage_type: string;
        panel_id: number | null;
        coverage_member_reference: string | null;
        consent_confirmed: boolean;
        privacy_notice_version: string;
    };
    duplicateCandidates: Array<{
        patientNumber: string;
        fullName: string;
        dateOfBirth: string | null;
        maskedPhone: string | null;
        canWhatsapp: boolean;
        maskedIdentifier: string | null;
    }>;
};
const props = defineProps<{
    intake: Intake;
    visitOptions: {
        branch: { id: number; name: string };
        idempotencyKey: string;
        doctors: Array<{ id: number; name: string }>;
        panels: Array<{ id: number; name: string }>;
    };
    rejectionCategories: string[];
}>();
const selectedReasons = ref<VisitReasonOption[]>([]);
const actionProcessing = ref(false);
const actionError = ref('');
const correction = useForm({
    ...props.intake.fields,
    lock_version: props.intake.lockVersion,
});
const acceptance = useForm({
    lock_version: props.intake.lockVersion,
    idempotency_key: props.visitOptions.idempotencyKey,
    // With no duplicate candidate there is nothing to match, so the review starts on creating a new patient.
    resolution:
        props.intake.duplicateCandidates.length === 0 ? 'create' : 'match',
    guardian_choice: '',
    guardian_relationship: [
        'mother',
        'father',
        'legal_guardian',
        'other_family_member',
    ].includes(props.intake.fields.guardian_relationship ?? '')
        ? (props.intake.fields.guardian_relationship ?? '')
        : '',
    guardian_lock_version: 0,
    patient_number: '',
    duplicate_override: false,
    assigned_doctor_user_id: '' as string | number,
    visit_reason_public_ids: [] as string[],
    priority: 'normal',
    coverage_type: '',
    panel_id: '' as string | number,
    coverage_member_reference: '',
    coverage_verified: false,
    confirm_repeat: false,
    status: '',
});
const matchedGuardian = ref<Omit<GuardianInput, 'retry_key'> | null>(null);
const guardianLookupBusy = ref(false);
const guardianLookupError = ref('');
let guardianLookup: AbortController | null = null;
watch(
    () => acceptance.patient_number,
    async (patientNumber) => {
        guardianLookup?.abort();
        matchedGuardian.value = null;
        acceptance.guardian_choice = '';
        acceptance.guardian_lock_version = 0;
        guardianLookupError.value = '';
        guardianLookupBusy.value = false;

        if (!patientNumber || props.intake.submissionType !== 'guardian') {
            return;
        }

        const controller = new AbortController();
        guardianLookup = controller;
        guardianLookupBusy.value = true;

        try {
            const data = await requestJson<{
                patientNumber: string;
                guardian: Omit<GuardianInput, 'retry_key'> | null;
            }>(
                `/registration-review/${props.intake.publicId}/guardian-contact?patient_number=${encodeURIComponent(patientNumber)}`,
                { signal: controller.signal },
            );

            if (
                controller.signal.aborted ||
                acceptance.patient_number !== patientNumber
            ) {
                return;
            }

            matchedGuardian.value = data.guardian;
            acceptance.guardian_lock_version = data.guardian?.lock_version ?? 0;
        } catch {
            if (!controller.signal.aborted) {
                guardianLookupError.value =
                    'Unable to load the selected guardian contact. Reload before accepting.';
            }
        } finally {
            if (!controller.signal.aborted) {
                guardianLookupBusy.value = false;
            }
        }
    },
);
watch(
    () => [props.intake.publicId, props.visitOptions.branch.id],
    () => {
        guardianLookup?.abort();
        matchedGuardian.value = null;
        guardianLookupBusy.value = false;
        acceptance.guardian_choice = '';
        acceptance.guardian_lock_version = 0;
    },
);
onBeforeUnmount(() => guardianLookup?.abort());
const guardianAcceptError = computed(() =>
    Object.entries(acceptance.errors)
        .filter(([key]) => key === 'guardian' || key.startsWith('guardian.'))
        .map(([, message]) => message)
        .join(' '),
);
const rejectionCategory = ref('insufficient_information');
const medicineOnly = computed(() => props.intake.fields.visit_kind === 'otc');
const doctorOptions = computed(() =>
    props.visitOptions.doctors.map((d) => ({ value: d.id, label: d.name })),
);
const panelOptions = computed(() =>
    props.visitOptions.panels.map((p) => ({ value: p.id, label: p.name })),
);
const intakeStatus = computed(
    () =>
        ({
            pending: 'Pending',
            under_review: 'Under review',
            correction_required: 'Correction required',
            accepted: 'Accepted',
            rejected: 'Rejected',
            expired: 'Expired',
        })[props.intake.status] ?? props.intake.status.replaceAll('_', ' '),
);
const canMutate = computed(() =>
    ['pending', 'under_review', 'correction_required'].includes(
        props.intake.status,
    ),
);
const postAction = (path: string, data: Parameters<typeof router.post>[1]) => {
    actionProcessing.value = true;
    actionError.value = '';
    router.post(path, data, {
        preserveScroll: true,
        onError: () =>
            (actionError.value =
                'Action failed. Reload and review the current record.'),
        onFinish: () => (actionProcessing.value = false),
    });
};
const requireCorrection = () =>
    postAction(
        `/registration-review/${props.intake.publicId}/correction-required`,
        { lock_version: props.intake.lockVersion },
    );
const reject = () =>
    postAction(`/registration-review/${props.intake.publicId}/reject`, {
        lock_version: props.intake.lockVersion,
        category: rejectionCategory.value,
    });
const saveCorrection = () => {
    if (dobInvalid.value) {
        return;
    }

    correction.patch(`/registration-review/${props.intake.publicId}/correct`, {
        preserveScroll: true,
        onSuccess: afterSave,
    });
};
const accept = () => {
    if (guardianLookupBusy.value || guardianLookupError.value) {
        return;
    }

    acceptance.post(`/registration-review/${props.intake.publicId}/accept`, {
        preserveScroll: true,
        onError: (errors) => {
            // Someone may have registered this person after the page loaded: reload the candidates so they show.
            if (errors.duplicate_override) {
                router.reload({ only: ['intake'] });
            }

            focusInvalidField();
        },
    });
};
const selectCandidate = (patientNumber: string) => {
    acceptance.resolution = 'match';
    acceptance.patient_number = patientNumber;
};
// A saved correction or a started review bumps the intake's lock version and the page re-renders in place with
// the new props. Both forms captured the version once, so keep them on the current one or the next save or
// accept is refused as stale. Only the version moves; anything the reviewer has typed is left alone.
watch(
    () => props.intake.lockVersion,
    (lockVersion) => {
        correction.lock_version = lockVersion;
        acceptance.lock_version = lockVersion;
    },
);
watch(
    () => correction.coverage_type,
    (coverageType) => {
        if (coverageType !== 'panel') {
            correction.panel_id = null;
            correction.coverage_member_reference = null;
        }
    },
);
watch(
    () => correction.panel_id,
    (panelId, previousPanelId) => {
        if (panelId !== previousPanelId) {
            correction.coverage_member_reference = null;
        }
    },
);
watch(
    () => acceptance.coverage_type,
    (coverageType) => {
        acceptance.coverage_verified = false;

        if (coverageType !== 'panel') {
            acceptance.panel_id = '';
            acceptance.coverage_member_reference = '';
        }
    },
);
watch(
    () => acceptance.panel_id,
    (panelId, previousPanelId) => {
        if (panelId !== previousPanelId) {
            acceptance.coverage_member_reference = '';
            acceptance.coverage_verified = false;
        }
    },
);
watch(
    () => acceptance.coverage_member_reference,
    () => {
        acceptance.coverage_verified = false;
    },
);

usePageCrumbs(() => [
    { title: 'QR intake', href: '/registration?tab=qr-intake' },
    { title: 'Review' },
]);

const sexOptions = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: 'indeterminate', label: 'Indeterminate' },
    { value: 'unknown', label: 'Unknown' },
];
const purposeOptions = [
    { value: 'doctor_illness', label: 'See a doctor / illness' },
    { value: 'pregnancy_check', label: 'Pregnancy check' },
    { value: 'scan', label: 'Scan' },
    { value: 'vaccination', label: 'Vaccination' },
    { value: 'medical_checkup', label: 'Medical check-up' },
    { value: 'procedure', label: 'Procedure' },
    { value: 'other', label: 'Other' },
];
const relationshipChoices = [
    { value: 'mother', label: 'Mother' },
    { value: 'father', label: 'Father' },
    { value: 'legal_guardian', label: 'Legal guardian' },
    { value: 'other_family_member', label: 'Other family member' },
];
const identifierTypeOptions = [
    { value: 'nric', label: 'Malaysian IC' },
    { value: 'passport', label: 'Passport' },
];
const coverageChoices = [
    { value: 'self_pay', label: 'Self-pay' },
    { value: 'panel', label: 'Panel' },
];
const contactKeys = [
    ['email', 'Email', 255],
    ['address_line_1', 'Address line 1', 255],
    ['address_line_2', 'Address line 2', 255],
    ['city', 'City', 100],
    ['state', 'State', 100],
    ['postcode', 'Postcode', 20],
    ['country_code', 'Country code (2 letters)', 2],
] as const;
const inputClass =
    'h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
const textareaClass =
    'min-h-24 w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';
const cardButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';

const statusTone = computed(
    () =>
        (
            ({
                pending: 'warning',
                under_review: 'info',
                correction_required: 'warning',
                accepted: 'success',
                rejected: 'danger',
                expired: 'neutral',
            }) as const
        )[props.intake.status as 'pending'] ?? 'neutral',
);
const resolutionChoices = [
    { value: 'match', label: 'Match existing patient' },
    { value: 'create', label: 'Create new patient' },
];
const priorityChoices = [
    { value: 'normal', label: 'Normal' },
    { value: 'urgent', label: 'Urgent' },
];
const guardianChoiceOptions = [
    { value: 'keep', label: 'Keep the existing guardian' },
    { value: 'use_incoming', label: 'Use the incoming guardian' },
];

// Field names (never values) of the corrections not yet saved.
const FIELD_LABELS: Record<string, string> = {
    full_name: 'Name',
    date_of_birth: 'Date of birth',
    sex: 'Gender',
    mobile_phone: 'Phone',
    phone_country: 'Phone',
    nationality_code: 'Nationality',
    identifier_type: 'IC / Passport',
    identifier_value: 'IC / Passport',
    email: 'Email',
    address_line_1: 'Address',
    address_line_2: 'Address',
    city: 'Address',
    state: 'Address',
    postcode: 'Address',
    country_code: 'Address',
    guardian_name: 'Guardian',
    guardian_contact_number: 'Guardian',
    visit_purpose: 'Visit purpose',
    chief_complaint: 'Complaint',
    complaint_duration: 'Duration',
    coverage_type: 'Reported coverage',
    panel_id: 'Reported coverage',
    coverage_member_reference: 'Reported coverage',
};
const initialCorrection = ref(
    JSON.parse(JSON.stringify(correction.data())) as Record<string, unknown>,
);
const changedFields = computed(() => {
    const current = correction.data() as unknown as Record<string, unknown>;
    const labels = Object.keys(FIELD_LABELS)
        .filter(
            (key) =>
                JSON.stringify(current[key] ?? null) !==
                JSON.stringify(initialCorrection.value[key] ?? null),
        )
        .map((key) => FIELD_LABELS[key]);

    return [...new Set(labels)];
});
const dirty = computed(() => changedFields.value.length > 0);
const afterSave = () => {
    initialCorrection.value = JSON.parse(
        JSON.stringify(correction.data()),
    ) as Record<string, unknown>;
};

const resolutionWords = computed(() =>
    acceptance.resolution === 'match'
        ? acceptance.patient_number
            ? 'Match a patient'
            : 'Choose a patient to match'
        : 'Create a new patient',
);
const summary = computed(() => [
    { label: 'Status', value: intakeStatus.value },
    {
        label: 'Duplicate check',
        value: props.intake.duplicateCandidates.length
            ? `${props.intake.duplicateCandidates.length} possible`
            : 'No match found',
    },
    { label: 'Resolution', value: resolutionWords.value },
    {
        label: 'Coverage verified',
        value: acceptance.coverage_verified ? 'Yes' : 'Not yet',
    },
    ...(dirty.value
        ? [
              {
                  label: 'Unsaved corrections',
                  value: changedFields.value.join(', '),
              },
          ]
        : []),
]);

// A plain sentence for the "required" refusal; the server's own words for anything else.
const patientNumberError = computed(() => {
    const message = acceptance.errors.patient_number;

    return message && /is required/i.test(message)
        ? 'Enter the patient number to match, or choose Create new patient.'
        : message;
});
const dobInvalid = ref(false);
const dobWords = computed(() => {
    const age = ageOn(correction.date_of_birth);

    if (age === null) {
        return 'Date of birth not entered.';
    }

    return `Born ${formatDate(correction.date_of_birth)} · age ${age}${
        age < 18
            ? ' · a guardian contact is required'
            : ' · no guardian contact is needed'
    }`;
});
const relationshipNotChosen = computed(() => !acceptance.guardian_relationship);

// The first thing still missing before Accept can work. The server stays the authority; this only says what to do next.
const acceptBlocker = computed(() => {
    if (!canMutate.value) {
        return 'This intake can no longer be accepted.';
    }

    if (acceptance.resolution === 'match' && !acceptance.patient_number) {
        return 'Choose Match or Create for the duplicate to continue.';
    }

    if (!acceptance.coverage_type) {
        return 'Choose the verified coverage to continue.';
    }

    if (acceptance.coverage_type === 'panel' && !acceptance.panel_id) {
        return 'Choose the verified Panel to continue.';
    }

    if (!acceptance.coverage_verified) {
        return 'Tick the coverage confirmation to continue.';
    }

    if (
        !medicineOnly.value &&
        (!acceptance.assigned_doctor_user_id ||
            acceptance.visit_reason_public_ids.length === 0)
    ) {
        return 'Choose the doctor and at least one visit reason to continue.';
    }

    return '';
});
const acceptRefusal = computed(
    () =>
        acceptance.errors.idempotency_key ||
        acceptance.errors.lock_version ||
        acceptance.errors.status ||
        '',
);
const rejectOpen = ref(false);
const rejectLabel = (category: string) =>
    category.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const rejectOptions = computed(() =>
    props.rejectionCategories.map((value) => ({
        value,
        label: rejectLabel(value),
    })),
);
const confirmReject = () => {
    rejectOpen.value = false;
    reject();
};
</script>

<template>
    <Head title="Review QR intake" />
    <PageFrame>
        <V2LeaveGuard :dirty="dirty && !correction.processing" />
        <V2WorkLayout
            title="Review QR intake"
            :description="`${visitOptions.branch.name} · review once before a patient, visit and queue entry are created`"
            rail-from="xl"
            rail-width="narrow"
        >
            <template #actions>
                <div class="flex items-center gap-3">
                    <V2Chip :tone="statusTone" :label="intakeStatus" />
                    <Link
                        href="/registration?tab=qr-intake"
                        :class="cardButton"
                        class="max-sm:hidden"
                        >Back to QR intake</Link
                    >
                </div>
            </template>

            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <p class="text-[13px] text-muted-foreground xl:hidden">
                        No patient, visit or queue record is created until
                        acceptance commits.
                    </p>
                    <p
                        v-if="actionError"
                        role="alert"
                        class="rounded-[10px] border border-destructive/40 p-4 text-sm text-destructive"
                    >
                        {{ actionError }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Reference:
                        <span class="font-mono break-all">{{
                            intake.publicId
                        }}</span>
                    </p>

                    <form
                        id="v2-review-correction"
                        class="flex min-w-0 flex-col gap-4"
                        novalidate
                        @submit.prevent="saveCorrection"
                    >
                        <V2FormCard
                            title="What the patient entered"
                            description="Encrypted public payload, visible only in this authorised branch workspace. Correct anything that is wrong, then save."
                        >
                            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <V2Field
                                        label="Full name"
                                        :error="correction.errors.full_name"
                                    >
                                        <template
                                            #default="{
                                                id,
                                                describedBy,
                                                invalid,
                                            }"
                                        >
                                            <input
                                                :id="id"
                                                v-model="correction.full_name"
                                                :aria-describedby="describedBy"
                                                :aria-invalid="invalid"
                                                autocomplete="off"
                                                :class="inputClass"
                                            />
                                        </template>
                                    </V2Field>
                                </div>
                                <V2DateField
                                    v-model="correction.date_of_birth"
                                    label="Date of birth"
                                    :hint="dobWords"
                                    :error="correction.errors.date_of_birth"
                                    @update:invalid="
                                        (value: boolean) => (dobInvalid = value)
                                    "
                                />
                                <V2Field
                                    label="Nationality (2-letter country code)"
                                    hint="For example MY"
                                    :error="correction.errors.nationality_code"
                                >
                                    <template
                                        #default="{ id, describedBy, invalid }"
                                    >
                                        <input
                                            :id="id"
                                            v-model="
                                                correction.nationality_code
                                            "
                                            :aria-describedby="describedBy"
                                            :aria-invalid="invalid"
                                            maxlength="2"
                                            autocomplete="off"
                                            :class="[inputClass, 'uppercase']"
                                        />
                                    </template>
                                </V2Field>
                                <div class="md:col-span-2">
                                    <V2OptionGroup
                                        v-model="correction.sex"
                                        label="Gender"
                                        :options="sexOptions"
                                    />
                                </div>
                                <div class="md:col-span-2">
                                    <V2PhoneField
                                        id="review-phone"
                                        v-model="correction.mobile_phone"
                                        v-model:country="
                                            correction.phone_country
                                        "
                                        :error="correction.errors.mobile_phone"
                                    />
                                </div>
                                <V2Field label="Identification type">
                                    <template #default>
                                        <OperationalSelect
                                            v-model="correction.identifier_type"
                                            label="Identification type"
                                            :options="identifierTypeOptions"
                                        />
                                    </template>
                                </V2Field>
                                <V2Field label="IC / Passport number">
                                    <template #default="{ id }">
                                        <input
                                            :id="id"
                                            v-model="
                                                correction.identifier_value
                                            "
                                            autocomplete="off"
                                            :class="inputClass"
                                        />
                                    </template>
                                </V2Field>
                                <template v-if="intake.collectContactDetails">
                                    <div
                                        v-for="[
                                            key,
                                            label,
                                            limit,
                                        ] in contactKeys"
                                        :key="key"
                                        :class="
                                            key === 'email' ||
                                            key === 'address_line_1' ||
                                            key === 'address_line_2'
                                                ? 'md:col-span-2'
                                                : ''
                                        "
                                    >
                                        <V2Field
                                            :label="label"
                                            :error="correction.errors[key]"
                                        >
                                            <template
                                                #default="{
                                                    id,
                                                    describedBy,
                                                    invalid,
                                                }"
                                            >
                                                <input
                                                    :id="id"
                                                    v-model="correction[key]"
                                                    :aria-describedby="
                                                        describedBy
                                                    "
                                                    :aria-invalid="invalid"
                                                    :maxlength="limit"
                                                    autocomplete="off"
                                                    :class="inputClass"
                                                />
                                            </template>
                                        </V2Field>
                                    </div>
                                </template>
                                <template
                                    v-if="
                                        correction.submission_type ===
                                        'guardian'
                                    "
                                >
                                    <V2Field label="Guardian name">
                                        <template #default="{ id }">
                                            <input
                                                :id="id"
                                                v-model="
                                                    correction.guardian_name
                                                "
                                                autocomplete="off"
                                                :class="inputClass"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field label="Guardian contact">
                                        <template #default="{ id }">
                                            <input
                                                :id="id"
                                                v-model="
                                                    correction.guardian_contact_number
                                                "
                                                type="tel"
                                                autocomplete="off"
                                                :class="inputClass"
                                            />
                                        </template>
                                    </V2Field>
                                </template>
                            </div>
                        </V2FormCard>

                        <V2FormCard
                            title="Reason for the visit"
                            :description="
                                medicineOnly
                                    ? 'The patient chose “Buy medicine only”. Accepting adds them to the OTC waiting list with a B number.'
                                    : undefined
                            "
                        >
                            <div class="flex min-w-0 flex-col gap-4">
                                <V2Field
                                    v-if="!medicineOnly"
                                    label="Visit purpose"
                                >
                                    <template #default>
                                        <OperationalSelect
                                            v-model="correction.visit_purpose"
                                            label="Visit purpose"
                                            :options="purposeOptions"
                                        />
                                    </template>
                                </V2Field>
                                <V2Field
                                    :label="
                                        medicineOnly
                                            ? 'Medicine needed (optional)'
                                            : 'Chief complaint / main reason'
                                    "
                                >
                                    <template #default="{ id }">
                                        <textarea
                                            :id="id"
                                            v-model="correction.chief_complaint"
                                            rows="3"
                                            maxlength="500"
                                            :class="textareaClass"
                                        ></textarea>
                                    </template>
                                </V2Field>
                                <V2Field v-if="!medicineOnly" label="Duration">
                                    <template #default="{ id }">
                                        <input
                                            :id="id"
                                            v-model="
                                                correction.complaint_duration
                                            "
                                            maxlength="120"
                                            :class="inputClass"
                                        />
                                    </template>
                                </V2Field>
                            </div>
                        </V2FormCard>

                        <V2FormCard
                            title="Coverage"
                            description="The patient's own answer is not verified and is not used for the visit until you verify it below."
                        >
                            <div class="flex min-w-0 flex-col gap-4">
                                <V2OptionGroup
                                    v-model="correction.coverage_type"
                                    label="Patient-reported coverage"
                                    :options="coverageChoices"
                                    :error="correction.errors.coverage_type"
                                />
                                <template
                                    v-if="correction.coverage_type === 'panel'"
                                >
                                    <V2Field
                                        label="Patient-reported Panel"
                                        :error="correction.errors.panel_id"
                                    >
                                        <template #default>
                                            <OperationalSelect
                                                :model-value="
                                                    correction.panel_id ?? ''
                                                "
                                                label="Patient-reported Panel"
                                                @update:model-value="
                                                    (value) =>
                                                        (correction.panel_id =
                                                            value === ''
                                                                ? null
                                                                : Number(value))
                                                "
                                                :options="panelOptions"
                                            />
                                        </template>
                                    </V2Field>
                                    <V2Field
                                        label="Patient-reported member reference"
                                        :error="
                                            correction.errors
                                                .coverage_member_reference
                                        "
                                    >
                                        <template #default="{ id }">
                                            <input
                                                :id="id"
                                                v-model="
                                                    correction.coverage_member_reference
                                                "
                                                maxlength="100"
                                                :class="inputClass"
                                            />
                                        </template>
                                    </V2Field>
                                </template>
                                <p class="text-[13px] text-muted-foreground">
                                    Consent
                                    {{ intake.privacyNoticeVersion }} recorded
                                    {{ intake.consentedAt }}. Consent details
                                    cannot be edited here.
                                </p>
                                <p class="text-[13px] text-muted-foreground">
                                    Saves the details, reason and coverage
                                    above. The verification below is saved when
                                    you accept.
                                </p>
                                <div>
                                    <button
                                        type="submit"
                                        :class="cardButton"
                                        :disabled="
                                            !canMutate || correction.processing
                                        "
                                    >
                                        {{
                                            correction.processing
                                                ? 'Saving…'
                                                : 'Save corrections'
                                        }}
                                    </button>
                                </div>
                            </div>
                        </V2FormCard>
                    </form>

                    <V2FormCard
                        title="CA coverage verification"
                        description="Choose the verified coverage and Panel, if any. Do not copy an unverified member reference."
                    >
                        <div class="flex min-w-0 flex-col gap-4">
                            <V2OptionGroup
                                v-model="acceptance.coverage_type"
                                label="Verified coverage"
                                required
                                :options="coverageChoices"
                                :error="acceptance.errors.coverage_type"
                            />
                            <template
                                v-if="acceptance.coverage_type === 'panel'"
                            >
                                <V2Field
                                    label="Verified Panel"
                                    required
                                    :error="acceptance.errors.panel_id"
                                >
                                    <template #default>
                                        <OperationalSelect
                                            v-model="acceptance.panel_id"
                                            :options="panelOptions"
                                            label="Verified Panel"
                                        />
                                    </template>
                                </V2Field>
                                <V2Field
                                    label="Verified member reference (if available)"
                                    :error="
                                        acceptance.errors
                                            .coverage_member_reference
                                    "
                                >
                                    <template #default="{ id }">
                                        <input
                                            :id="id"
                                            v-model="
                                                acceptance.coverage_member_reference
                                            "
                                            maxlength="100"
                                            autocomplete="off"
                                            :class="inputClass"
                                        />
                                    </template>
                                </V2Field>
                            </template>
                            <label
                                class="flex min-h-11 cursor-pointer items-start gap-3 rounded-[10px] border p-3 text-sm"
                            >
                                <input
                                    v-model="acceptance.coverage_verified"
                                    type="checkbox"
                                    class="mt-0.5 size-5 shrink-0"
                                />
                                <span
                                    >I confirmed the coverage above. Any member
                                    reference entered here has been checked;
                                    unverified patient-reported references have
                                    been omitted.</span
                                >
                            </label>
                            <p
                                v-if="acceptance.errors.coverage_verified"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ acceptance.errors.coverage_verified }}
                            </p>
                        </div>
                    </V2FormCard>

                    <V2FormCard
                        title="Duplicate resolution"
                        description="Candidates are private to staff and never appear on the public status page."
                    >
                        <div class="flex min-w-0 flex-col gap-4">
                            <section
                                v-if="
                                    intake.fields.submission_type === 'guardian'
                                "
                                class="flex min-w-0 flex-col gap-3 rounded-[10px] border p-3"
                            >
                                <h3 class="text-sm font-semibold">
                                    Guardian contact review
                                </h3>
                                <p
                                    v-if="
                                        guardianAcceptError ||
                                        guardianLookupError
                                    "
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{
                                        guardianAcceptError ||
                                        guardianLookupError
                                    }}
                                </p>
                                <p
                                    v-if="guardianLookupBusy"
                                    class="text-sm text-muted-foreground"
                                >
                                    Loading the selected guardian contact…
                                </p>
                                <p class="text-sm break-words">
                                    Incoming:
                                    {{ intake.fields.guardian_name }} ·
                                    {{ intake.fields.guardian_relationship }} ·
                                    {{ intake.fields.guardian_contact_number }}
                                </p>
                                <p
                                    v-if="matchedGuardian"
                                    class="text-sm break-words"
                                >
                                    Existing: {{ matchedGuardian.full_name }} ·
                                    {{ matchedGuardian.relationship }} ·
                                    {{ matchedGuardian.phone }}
                                </p>
                                <V2OptionGroup
                                    v-if="matchedGuardian"
                                    v-model="acceptance.guardian_choice"
                                    label="Contact decision"
                                    :options="guardianChoiceOptions"
                                    :error="acceptance.errors.guardian_choice"
                                />
                                <V2OptionGroup
                                    v-model="acceptance.guardian_relationship"
                                    label="Incoming relationship (confirm before saving)"
                                    :options="relationshipChoices"
                                    :error="
                                        acceptance.errors
                                            .guardian_relationship ||
                                        acceptance.errors.guardian_lock_version
                                    "
                                />
                                <p
                                    v-if="relationshipNotChosen"
                                    class="text-[13px] text-muted-foreground"
                                >
                                    Relationship: not chosen yet.
                                </p>
                                <p class="text-[13px] text-muted-foreground">
                                    Guardian details never replace the patient's
                                    own contact and are not used for WhatsApp.
                                </p>
                            </section>

                            <ul
                                v-if="intake.duplicateCandidates.length"
                                class="flex min-w-0 flex-col gap-2"
                            >
                                <li
                                    v-for="candidate in intake.duplicateCandidates"
                                    :key="candidate.patientNumber"
                                    class="flex min-w-0 flex-wrap items-start justify-between gap-3 rounded-[10px] border p-3 text-sm"
                                >
                                    <div class="min-w-0">
                                        <p class="font-semibold break-words">
                                            {{ candidate.fullName }}
                                        </p>
                                        <p class="text-muted-foreground">
                                            {{ candidate.patientNumber }} ·
                                            {{
                                                candidate.dateOfBirth ||
                                                'Date of birth unavailable'
                                            }}
                                        </p>
                                        <p
                                            class="flex flex-wrap items-center gap-1 text-[13px] text-muted-foreground"
                                        >
                                            {{ candidate.maskedIdentifier }} ·
                                            {{ candidate.maskedPhone }}
                                            <WhatsAppLink
                                                :can-whatsapp="
                                                    candidate.canWhatsapp
                                                "
                                                :route-key="
                                                    candidate.patientNumber
                                                "
                                            />
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        :class="cardButton"
                                        :aria-label="`Match ${candidate.fullName}`"
                                        @click="
                                            selectCandidate(
                                                candidate.patientNumber,
                                            )
                                        "
                                    >
                                        Match
                                    </button>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="rounded-[10px] bg-muted p-3 text-sm text-muted-foreground"
                            >
                                {{
                                    acceptance.errors.duplicate_override
                                        ? 'The check at accept found a possible match that is not listed here.'
                                        : 'No soft or exact duplicate candidate was found.'
                                }}
                            </p>

                            <V2OptionGroup
                                v-model="acceptance.resolution"
                                label="Resolution"
                                :options="resolutionChoices"
                            />
                            <p
                                v-if="intake.duplicateCandidates.length"
                                class="text-[13px] text-muted-foreground"
                            >
                                Match on a candidate above fills the patient
                                number below.
                            </p>
                            <V2Field
                                v-if="acceptance.resolution === 'match'"
                                label="Patient number"
                                :error="patientNumberError"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <input
                                        :id="id"
                                        v-model="acceptance.patient_number"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        autocomplete="off"
                                        :class="inputClass"
                                    />
                                </template>
                            </V2Field>
                            <template v-else>
                                <label
                                    v-if="
                                        intake.duplicateCandidates.length ||
                                        acceptance.errors.duplicate_override
                                    "
                                    class="flex min-h-11 cursor-pointer items-start gap-3 rounded-[10px] border p-3 text-sm"
                                >
                                    <input
                                        v-model="acceptance.duplicate_override"
                                        type="checkbox"
                                        class="mt-0.5 size-5 shrink-0"
                                    />
                                    <span
                                        >I reviewed the possible duplicates
                                        above and this is a different
                                        person.</span
                                    >
                                </label>
                                <p
                                    v-if="acceptance.errors.duplicate_override"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    A possible duplicate was found when you
                                    pressed accept. Review it above, tick the
                                    box if this is a different person, then
                                    accept again.
                                </p>
                            </template>
                        </div>
                    </V2FormCard>

                    <form
                        id="v2-review-accept"
                        class="flex min-w-0 flex-col gap-4"
                        novalidate
                        @submit.prevent="accept"
                    >
                        <V2FormCard
                            title="Visit and queue"
                            description="One transaction creates or matches a patient, creates the visit and queue entry, then accepts the intake."
                        >
                            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                                <V2Field
                                    v-if="!medicineOnly"
                                    label="Doctor"
                                    :error="
                                        acceptance.errors
                                            .assigned_doctor_user_id
                                    "
                                >
                                    <template #default>
                                        <OperationalSelect
                                            v-model="
                                                acceptance.assigned_doctor_user_id
                                            "
                                            :options="doctorOptions"
                                            label="Doctor"
                                        />
                                    </template>
                                </V2Field>
                                <V2OptionGroup
                                    v-model="acceptance.priority"
                                    label="Priority"
                                    :options="priorityChoices"
                                />
                                <div v-if="!medicineOnly" class="md:col-span-2">
                                    <p class="mb-1.5 text-sm font-medium">
                                        Visit reasons
                                    </p>
                                    <VisitReasonField
                                        v-model="
                                            acceptance.visit_reason_public_ids
                                        "
                                        v-model:selected="selectedReasons"
                                        :error="
                                            acceptance.errors
                                                .visit_reason_public_ids
                                        "
                                    />
                                </div>
                                <label
                                    v-if="acceptance.errors.confirm_repeat"
                                    class="flex min-h-11 cursor-pointer items-start gap-3 rounded-[10px] border border-warning/40 bg-warning/10 p-3 text-sm md:col-span-2"
                                >
                                    <input
                                        v-model="acceptance.confirm_repeat"
                                        type="checkbox"
                                        class="mt-0.5 size-5 shrink-0"
                                    />
                                    <span>{{
                                        acceptance.errors.confirm_repeat
                                    }}</span>
                                </label>
                            </div>
                        </V2FormCard>
                    </form>

                    <V2FormCard v-if="canMutate" title="Other outcomes">
                        <div class="flex min-w-0 flex-col gap-4">
                            <div>
                                <button
                                    type="button"
                                    :class="cardButton"
                                    :disabled="actionProcessing"
                                    @click="requireCorrection"
                                >
                                    Correction required
                                </button>
                            </div>
                            <V2Field label="Rejection category">
                                <template #default>
                                    <OperationalSelect
                                        v-model="rejectionCategory"
                                        label="Rejection category"
                                        :options="rejectOptions"
                                    />
                                </template>
                            </V2Field>
                            <div>
                                <button
                                    type="button"
                                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                    :disabled="actionProcessing"
                                    @click="rejectOpen = true"
                                >
                                    Reject intake
                                </button>
                            </div>
                        </div>
                    </V2FormCard>

                    <V2StickyBar>
                        <button
                            type="submit"
                            form="v2-review-accept"
                            :class="primaryButton"
                            aria-describedby="v2-accept-reason-bar"
                            :disabled="!canMutate || acceptance.processing"
                        >
                            {{
                                acceptance.processing
                                    ? 'Accepting…'
                                    : 'Accept and add to queue'
                            }}
                        </button>
                        <p
                            v-if="acceptRefusal"
                            role="alert"
                            class="text-center text-sm text-destructive sm:text-right"
                        >
                            {{ acceptRefusal }}
                        </p>
                        <p
                            v-if="acceptBlocker"
                            id="v2-accept-reason-bar"
                            class="text-center text-[13px] text-muted-foreground sm:text-right"
                        >
                            Still to do: {{ acceptBlocker }}
                        </p>
                    </V2StickyBar>
                </div>
            </template>

            <template #rail>
                <V2StickySummary title="Summary" :items="summary">
                    <button
                        type="submit"
                        form="v2-review-accept"
                        :class="primaryButton"
                        aria-describedby="v2-accept-reason-rail"
                        :disabled="!canMutate || acceptance.processing"
                    >
                        {{
                            acceptance.processing
                                ? 'Accepting…'
                                : 'Accept and add to queue'
                        }}
                    </button>
                    <p
                        v-if="acceptRefusal"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ acceptRefusal }}
                    </p>
                    <p
                        v-if="acceptBlocker"
                        id="v2-accept-reason-rail"
                        class="text-[13px] text-muted-foreground"
                    >
                        Still to do: {{ acceptBlocker }}
                    </p>
                    <p class="text-[13px] text-muted-foreground">
                        No patient, visit or queue record is created until
                        acceptance commits.
                    </p>
                </V2StickySummary>
            </template>
        </V2WorkLayout>
        <V2Dialog
            :open="rejectOpen"
            title="Reject this intake?"
            :description="`Category: ${rejectLabel(rejectionCategory)}. No patient, visit or queue record will be created.`"
            confirm-label="Reject intake"
            cancel-label="Keep reviewing"
            tone="danger"
            :busy="actionProcessing"
            @update:open="(open: boolean) => (rejectOpen = open)"
            @confirm="confirmReject"
        />
    </PageFrame>
</template>

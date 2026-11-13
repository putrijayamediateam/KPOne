<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PatientChangeHistory from '@/components/patient/PatientChangeHistory.vue';
import type { PatientChangeHistoryData } from '@/components/patient/PatientChangeHistory.vue';
import type { PatientMergePanelData } from '@/components/patient/PatientMergePanel.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import PatientMergeCards from '@/components/patient-v2/PatientMergeCards.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import {
    identifierName,
    maskedIdentifier,
    spokenIdentifier,
    titleCase,
} from '@/lib/patient-v2';
import { formatDate, formatDateTime } from '@/lib/presentation';
import type { PatientDetail, PatientIdentifier } from '@/types';

/**
 * Version 2 Patient details (shown only in V2; Classic is `pages/Patient/Show.vue`, untouched). Same props, same
 * routes (identifier add, correct and retire), same permission flags. No backend change. Add and Correct open in a
 * dialog; the typed number is cleared when the dialog closes and is never shown again. Retire asks in a dialog
 * instead of the browser's confirm().
 */
const props = defineProps<{
    patient: PatientDetail;
    merge?: PatientMergePanelData;
    history?: PatientChangeHistoryData | null;
    mergedGuardian?: {
        full_name: string | null;
        relationship: string | null;
        phone: string | null;
        email: string | null;
        fromPatientNumber: string;
    } | null;
}>();

usePageCrumbs(() => [
    { title: 'Patients', href: '/patients' },
    { title: props.patient.patientNumber },
]);

const patientUrl = () =>
    '/patients/' + encodeURIComponent(props.patient.patientNumber);

// ---- Add and Correct identifier (same endpoints and fields as Classic) ----
const dialogOpen = ref(false);
const correcting = ref<PatientIdentifier | null>(null);
const identifierForm = useForm({
    identifier_type: 'nric' as 'nric' | 'passport',
    issuing_country_code: 'MY',
    value: '',
});
// Only the two messages Classic shows; nothing else the server sends is displayed (it could mention the number).
const identifierError = () =>
    (identifierForm.errors as Record<string, string>).value ??
    (identifierForm.errors as Record<string, string>).identifiers;
const closeIdentifierDialog = () => {
    dialogOpen.value = false;
    correcting.value = null;
    // Clear the typed number and any message the server sent about it.
    identifierForm.reset();
    identifierForm.clearErrors();
};
const startAdd = () => {
    correcting.value = null;
    identifierForm.reset();
    identifierForm.clearErrors();
    dialogOpen.value = true;
};
const startCorrection = (identifier: PatientIdentifier) => {
    correcting.value = identifier;
    identifierForm.identifier_type = identifier.type;
    identifierForm.issuing_country_code = identifier.issuingCountryCode;
    identifierForm.value = '';
    identifierForm.clearErrors();
    dialogOpen.value = true;
};
const chooseType = (value: string[]) => {
    const picked = value[value.length - 1];

    if (picked === 'nric' || picked === 'passport') {
        identifierForm.identifier_type = picked;
        identifierForm.issuing_country_code =
            picked === 'nric' ? 'MY' : identifierForm.issuing_country_code;
    }
};
const submitIdentifier = () => {
    const url = correcting.value
        ? patientUrl() + '/identifiers/' + correcting.value.id
        : patientUrl() + '/identifiers';
    const options = {
        preserveScroll: true,
        onSuccess: () => closeIdentifierDialog(),
    };

    if (correcting.value) {
        identifierForm.patch(url, options);
    } else {
        identifierForm.post(url, options);
    }
};

// ---- Retire identifier ----
const retiring = ref<PatientIdentifier | null>(null);
const retireBusy = ref(false);
const retireFailed = ref(false);
const retire = () => {
    const identifier = retiring.value;

    if (!identifier) {
        return;
    }

    if (retireBusy.value) {
        return;
    }

    router.patch(
        patientUrl() + '/identifiers/' + identifier.id + '/retire',
        {},
        {
            preserveScroll: true,
            onStart: () => {
                retireBusy.value = true;
                retireFailed.value = false;
            },
            onSuccess: () => {
                retiring.value = null;
            },
            onError: () => {
                retireFailed.value = true;
            },
            onFinish: () => {
                retireBusy.value = false;
            },
        },
    );
};

const identifierMenu = () => [
    {
        label: 'Change',
        items: [
            { id: 'correct', label: 'Correct value…', allowed: true },
            {
                id: 'retire',
                label: 'Retire…',
                allowed: true,
                tone: 'danger' as const,
            },
        ],
    },
];
const menuSelect = (identifier: PatientIdentifier, id: string) => {
    if (id === 'correct') {
        startCorrection(identifier);
    } else if (id === 'retire') {
        retiring.value = identifier;
    }
};

const dob = computed(() =>
    props.patient.identity.dateOfBirth
        ? formatDate(props.patient.identity.dateOfBirth)
        : 'Date of birth not recorded',
);
const addressLines = computed(() => {
    const a = props.patient.address;

    if (!a.line1) {
        return [];
    }

    return [
        a.line1,
        a.line2,
        [a.postcode, a.city].filter(Boolean).join(' '),
        [a.state, a.countryCode].filter(Boolean).join(', '),
    ].filter(Boolean) as string[];
});
const guardianLine = (g: {
    full_name: string | null;
    relationship: string | null;
}) =>
    [g.full_name, g.relationship ? titleCase(g.relationship) : null]
        .filter(Boolean)
        .join(' · ');

const typeOptions = [
    { value: 'nric', label: 'NRIC' },
    { value: 'passport', label: 'Passport' },
];
const card =
    'flex min-w-0 flex-col gap-3 rounded-[14px] border border-border bg-card p-5';
const fieldClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <Head :title="patient.patientNumber" />
    <PageFrame width="wide">
        <V2WorkLayout
            :title="patient.identity.fullName"
            rail-from="xl"
            rail-label="Record details"
            description="Patient record, shared by every branch."
        >
            <template #actions>
                <Link
                    v-if="patient.can.update"
                    :href="patientUrl() + '/edit'"
                    class="inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none sm:w-auto lg:h-9"
                    >Edit patient</Link
                >
            </template>

            <template #main>
                <h2 class="sr-only">Patient record</h2>

                <div class="grid gap-5 md:grid-cols-2">
                    <section :class="card" aria-labelledby="pd-identity">
                        <h2 id="pd-identity" class="text-base font-semibold">
                            Identity
                        </h2>
                        <dl class="grid gap-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">
                                    Date of birth
                                </dt>
                                <dd class="text-right font-medium">
                                    {{ dob }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Gender</dt>
                                <dd class="text-right font-medium">
                                    {{ titleCase(patient.identity.sex) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">
                                    Nationality
                                </dt>
                                <dd class="text-right font-medium">
                                    {{
                                        patient.identity.nationalityCode ??
                                        'Nationality not recorded'
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section :class="card" aria-labelledby="pd-contact">
                        <h2 id="pd-contact" class="text-base font-semibold">
                            Contact
                        </h2>
                        <dl class="grid gap-3 text-sm">
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <dt class="text-muted-foreground">Mobile</dt>
                                <dd
                                    class="flex min-w-0 items-center gap-1 text-right font-medium"
                                >
                                    <span class="break-words">{{
                                        patient.contact.mobilePhone ??
                                        'Phone not recorded'
                                    }}</span>
                                    <WhatsAppLink
                                        :can-whatsapp="patient.canWhatsapp"
                                        :route-key="patient.patientNumber"
                                        :label="`Open WhatsApp chat with ${patient.identity.fullName}`"
                                    />
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Email</dt>
                                <dd
                                    class="min-w-0 text-right font-medium break-words"
                                >
                                    {{ patient.contact.email ?? 'No email' }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section :class="card" aria-labelledby="pd-address">
                        <h2 id="pd-address" class="text-base font-semibold">
                            Address
                        </h2>
                        <div v-if="addressLines.length" class="text-sm">
                            <p v-for="line in addressLines" :key="line">
                                {{ line }}
                            </p>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            No address recorded.
                        </p>
                    </section>

                    <section
                        :class="card"
                        aria-labelledby="pd-guardian"
                        :data-test="
                            mergedGuardian && !patient.guardian
                                ? 'merged-guardian'
                                : undefined
                        "
                    >
                        <h2 id="pd-guardian" class="text-base font-semibold">
                            Guardian contact
                        </h2>
                        <template v-if="patient.guardian">
                            <p class="text-sm font-medium break-words">
                                {{ guardianLine(patient.guardian) }}
                            </p>
                            <p class="text-sm break-words">
                                {{ patient.guardian.phone }}
                            </p>
                            <p
                                v-if="patient.guardian.email"
                                class="text-sm break-words"
                            >
                                {{ patient.guardian.email }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                This guardian number is not used by WhatsApp or
                                patient search.
                            </p>
                        </template>
                        <template v-else-if="mergedGuardian">
                            <p class="text-xs text-muted-foreground">
                                From merged record
                                <span class="font-mono">{{
                                    mergedGuardian.fromPatientNumber
                                }}</span
                                >. Read-only.
                            </p>
                            <p class="text-sm font-medium break-words">
                                {{ guardianLine(mergedGuardian) }}
                            </p>
                            <p
                                v-if="mergedGuardian.phone"
                                class="text-sm break-words"
                            >
                                {{ mergedGuardian.phone }}
                            </p>
                            <p
                                v-if="mergedGuardian.email"
                                class="text-sm break-words"
                            >
                                {{ mergedGuardian.email }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                This guardian number is not used by WhatsApp or
                                patient search. To keep this contact on this
                                record, use Edit patient and save the guardian
                                details.
                            </p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            No guardian contact.
                        </p>
                    </section>
                </div>

                <section :class="card" aria-labelledby="pd-identifiers">
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                id="pd-identifiers"
                                class="text-base font-semibold"
                            >
                                Identifier history
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Retired values stay reserved and are never
                                overwritten.
                            </p>
                        </div>
                        <button
                            v-if="patient.can.update"
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background max-sm:w-full lg:h-9"
                            @click="startAdd"
                        >
                            Add identifier
                        </button>
                    </div>
                    <ul v-if="patient.identifiers.length" class="divide-y">
                        <li
                            v-for="identifier in patient.identifiers"
                            :key="identifier.id"
                            class="flex items-center justify-between gap-3 py-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium break-words">
                                    <span aria-hidden="true"
                                        >{{ identifierName(identifier.type) }} ·
                                        {{ identifier.displayValue }}</span
                                    ><span class="sr-only">{{
                                        spokenIdentifier(identifier)
                                    }}</span>
                                </p>
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <V2Chip
                                        :tone="
                                            identifier.isCurrent
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            identifier.isCurrent
                                                ? 'Current'
                                                : 'Retired'
                                        "
                                    />
                                    <span
                                        >Issued in
                                        {{ identifier.issuingCountryCode
                                        }}<template v-if="identifier.retiredAt">
                                            · retired
                                            {{
                                                formatDateTime(
                                                    identifier.retiredAt,
                                                )
                                            }}</template
                                        ></span
                                    >
                                </p>
                            </div>
                            <V2RowMenu
                                v-if="
                                    patient.can.manageIdentifiers &&
                                    identifier.isCurrent
                                "
                                :label="`Actions for ${identifierName(identifier.type)} ending in ${identifier.displayValue.slice(-4)}`"
                                :groups="identifierMenu()"
                                @select="menuSelect(identifier, $event)"
                            />
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No identifier history recorded.
                    </p>
                </section>

                <section
                    :class="[card, 'xl:hidden']"
                    aria-labelledby="pd-record"
                >
                    <h2 id="pd-record" class="text-base font-semibold">
                        Record details
                    </h2>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="flex justify-between gap-4 sm:block">
                            <dt class="text-muted-foreground">Created</dt>
                            <dd class="font-medium">
                                {{
                                    formatDateTime(
                                        patient.administrative.createdAt,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 sm:block">
                            <dt class="text-muted-foreground">Last updated</dt>
                            <dd class="font-medium">
                                {{
                                    formatDateTime(
                                        patient.administrative.updatedAt,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <div v-if="history" class="min-w-0 [&>section]:rounded-[14px]">
                    <PatientChangeHistory :history="history" />
                </div>

                <PatientMergeCards
                    v-if="merge"
                    :patient-number="patient.patientNumber"
                    :merge="merge"
                />
            </template>

            <template #rail>
                <section :class="card" aria-labelledby="pd-record-rail">
                    <h2 id="pd-record-rail" class="text-base font-semibold">
                        Record details
                    </h2>
                    <dl class="grid gap-3 text-sm">
                        <div class="flex justify-between gap-4 sm:block">
                            <dt class="text-muted-foreground">Created</dt>
                            <dd class="font-medium">
                                {{
                                    formatDateTime(
                                        patient.administrative.createdAt,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 sm:block">
                            <dt class="text-muted-foreground">Last updated</dt>
                            <dd class="font-medium">
                                {{
                                    formatDateTime(
                                        patient.administrative.updatedAt,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="dialogOpen"
            :title="correcting ? 'Correct identifier' : 'Add identifier'"
            :description="
                correcting
                    ? `Replace the ${identifierName(correcting.type)} ${maskedIdentifier(correcting)} with a corrected value. The old value stays reserved in history.`
                    : 'Add an NRIC or passport number to this record.'
            "
            :confirm-label="
                correcting ? 'Replace identifier' : 'Add identifier'
            "
            :busy="identifierForm.processing"
            @update:open="(open: boolean) => !open && closeIdentifierDialog()"
            @confirm="submitIdentifier"
        >
            <div class="flex flex-col gap-4">
                <fieldset class="flex flex-col gap-2">
                    <legend class="text-sm font-medium">Identifier type</legend>
                    <div class="flex gap-2">
                        <button
                            v-for="option in typeOptions"
                            :key="option.value"
                            type="button"
                            :aria-pressed="
                                identifierForm.identifier_type === option.value
                            "
                            :disabled="!!correcting"
                            class="inline-flex h-11 items-center rounded-full border px-4 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60 lg:h-9"
                            :class="
                                identifierForm.identifier_type === option.value
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border bg-card hover:bg-muted'
                            "
                            @click="chooseType([option.value])"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </fieldset>
                <label
                    v-if="identifierForm.identifier_type === 'passport'"
                    class="grid gap-1.5 text-sm"
                >
                    <span class="font-medium"
                        >Issuing country (2-letter code)</span
                    >
                    <input
                        v-model="identifierForm.issuing_country_code"
                        maxlength="2"
                        autocomplete="off"
                        :class="[fieldClass, 'uppercase']"
                        @keydown.enter.prevent="submitIdentifier"
                    />
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium"
                        >{{ correcting ? 'Corrected' : 'New' }} identifier
                        number</span
                    >
                    <input
                        v-model="identifierForm.value"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        :class="fieldClass"
                        @keydown.enter.prevent="submitIdentifier"
                    />
                </label>
                <p class="-mt-2 text-xs text-muted-foreground">
                    {{
                        identifierForm.identifier_type === 'nric'
                            ? 'For example 900101-01-5555'
                            : 'Passport number'
                    }}
                </p>
                <InputError :message="identifierError()" />
            </div>
        </V2Dialog>

        <V2Dialog
            :open="!!retiring"
            title="Retire this identifier?"
            :description="
                retiring
                    ? `The ${identifierName(retiring.type)} ending in ${retiring.displayValue.slice(-4)} stops being current. It stays in the history and its value stays reserved, so no other patient can use it.`
                    : undefined
            "
            confirm-label="Retire identifier"
            tone="danger"
            :busy="retireBusy"
            @update:open="(open: boolean) => !open && (retiring = null)"
            @confirm="retire"
        >
            <p
                v-if="retireFailed"
                role="alert"
                class="text-sm text-destructive"
            >
                The identifier could not be retired. Check the record and try
                again.
            </p>
        </V2Dialog>
    </PageFrame>
</template>

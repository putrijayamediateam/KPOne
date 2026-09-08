<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    LoaderCircle,
    MapPin,
    Search,
    UserPlus,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/patient/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import VisitReasonPicker from '@/components/visit/VisitReasonPicker.vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';
import {
    phoneError,
    identityError,
    focusInvalidField,
} from '@/lib/patient-registration';
import type { PatientRegistrationSummary, VisitOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Registration', href: '/registration' },
            { title: 'Register Visit', href: '/registration/create' },
        ],
    },
});
const props = defineProps<{
    options: VisitOptions & { idempotencyKey: string };
    recentPatients: PatientRegistrationSummary[];
}>();
const identityTypeOptions = [
    { value: 'nric', label: 'Malaysian IC' },
    { value: 'passport', label: 'Passport' },
];
const genderOptions = [
    { value: 'unknown', label: 'Unknown' },
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: 'indeterminate', label: 'Indeterminate' },
];
const doctorOptions = computed(() => [
    { value: '', label: 'No doctor' },
    ...props.options.doctors.map((doctor) => ({
        value: doctor.id,
        label: doctor.name,
    })),
]);
const panelOptions = computed(() => [
    { value: '', label: 'Select Panel' },
    ...props.options.panels.map((panel) => ({
        value: panel.id,
        label: panel.name,
    })),
]);
const patientQuery = ref('');
const patientResults = ref<PatientRegistrationSummary[]>([
    ...props.recentPatients,
]);
const activeResultIndex = ref(props.recentPatients.length ? 0 : -1);
const patientSearchError = ref('');
const searching = ref(false);
const selectedPatient = ref<PatientRegistrationSummary | null>(null);
const quickMode = ref(false);
const quickIdentifierType = ref<'nric' | 'passport'>('nric');
const quickIdentifierValue = ref('');
const quickIssuer = ref('MY');
type QuickPatientInput = {
    full_name: string;
    date_of_birth: string;
    sex: string;
    mobile_phone: string;
    phone_country: string;
    duplicate_override: boolean;
    identifiers: Array<{
        identifier_type: 'nric' | 'passport';
        issuing_country_code: string;
        value: string;
    }>;
};
type RegistrationForm = {
    idempotency_key: string;
    expected_branch_id: number;
    patient_number: string;
    quick_patient: QuickPatientInput | null;
    visit_type: 'consultation' | 'otc';
    assigned_doctor_user_id: number | '';
    visit_reason_public_ids: string[];
    coverage_type: 'self_pay' | 'panel';
    panel_id: number | '';
    coverage_member_reference: string;
    priority: 'normal' | 'urgent';
    confirm_repeat: boolean;
};
const form = useForm<RegistrationForm>({
    idempotency_key: props.options.idempotencyKey,
    expected_branch_id: props.options.branch.id,
    patient_number: '',
    quick_patient: null,
    visit_type: 'consultation' as 'consultation' | 'otc',
    assigned_doctor_user_id:
        props.options.doctors.length === 1 ? props.options.doctors[0].id : '',
    visit_reason_public_ids: [],
    coverage_type: 'self_pay' as 'self_pay' | 'panel',
    panel_id: '',
    coverage_member_reference: '',
    priority: 'normal' as 'normal' | 'urgent',
    confirm_repeat: false,
});
const quick = ref({
    full_name: '',
    date_of_birth: '',
    sex: 'unknown',
    mobile_phone: '',
    phone_country: 'MY',
    duplicate_override: false,
});
const selectedVisitReasons = ref<VisitReasonOption[]>([]);
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
let patientSearchTimer: ReturnType<typeof setTimeout> | undefined;
let patientSearchController: AbortController | undefined;
const showingRecent = computed(() => patientQuery.value.trim() === '');
const patientSearchType = (query: string) => {
    const trimmed = query.trim();
    const compact = trimmed.replace(/[\s-]/g, '');

    if (trimmed.toUpperCase().startsWith('KP-')) {
        return 'patient_number';
    }

    if (/^\d{12}$/.test(compact)) {
        return 'nric';
    }

    if (/^\+?[()\d][()\d\s-]{6,}$/.test(trimmed)) {
        return 'phone';
    }

    return 'name';
};
const searchPatients = async () => {
    const query = patientQuery.value.trim();

    if (query.length < 3) {
        patientResults.value = query === '' ? [...props.recentPatients] : [];
        activeResultIndex.value = patientResults.value.length ? 0 : -1;

        return;
    }

    searching.value = true;
    patientSearchError.value = '';
    patientSearchController?.abort();
    const controller = new AbortController();
    patientSearchController = controller;

    try {
        const response = await fetch('/patients/search', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            signal: controller.signal,
            body: JSON.stringify({
                query,
                search_type: patientSearchType(query),
            }),
        });
        const payload = await response.json();

        if (!response.ok) {
            patientSearchError.value =
                payload.message ?? 'Patient search could not be completed.';

            return;
        }

        patientResults.value = payload.data;
        activeResultIndex.value = patientResults.value.length ? 0 : -1;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        patientSearchError.value = 'Patient search could not be completed.';
    } finally {
        if (patientSearchController === controller) {
            searching.value = false;
            patientSearchController = undefined;
        }
    }
};
watch(patientQuery, (query) => {
    if (patientSearchTimer) {
        clearTimeout(patientSearchTimer);
    }

    patientSearchController?.abort();
    patientSearchController = undefined;
    searching.value = false;

    patientSearchError.value = '';
    const trimmed = query.trim();

    if (trimmed === '') {
        patientResults.value = [...props.recentPatients];
        activeResultIndex.value = patientResults.value.length ? 0 : -1;

        return;
    }

    patientResults.value = [];
    activeResultIndex.value = -1;

    if (trimmed.length >= 3) {
        patientSearchTimer = setTimeout(searchPatients, 150);
    }
});
watch(
    () => form.coverage_type,
    (coverage) => {
        if (coverage === 'self_pay') {
            form.panel_id = '';
            form.coverage_member_reference = '';
        }
    },
);
onBeforeUnmount(() => {
    if (patientSearchTimer) {
        clearTimeout(patientSearchTimer);
    }

    patientSearchController?.abort();
});
const handlePatientSearchKeydown = (event: KeyboardEvent) => {
    if (!patientResults.value.length) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeResultIndex.value = Math.min(
            activeResultIndex.value + 1,
            patientResults.value.length - 1,
        );
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeResultIndex.value = Math.max(activeResultIndex.value - 1, 0);
    } else if (event.key === 'Enter' && activeResultIndex.value >= 0) {
        event.preventDefault();
        selectPatient(patientResults.value[activeResultIndex.value]);
    }
};
const selectPatient = (patient: PatientRegistrationSummary) => {
    selectedPatient.value = patient;
    form.patient_number = patient.patientNumber;
    quickMode.value = false;
    form.quick_patient = null;
    form.clearErrors('patient_number', 'quick_patient');
};
const changePatient = () => {
    selectedPatient.value = null;
    form.patient_number = '';
    quickMode.value = false;
    form.quick_patient = null;
    patientQuery.value = '';
    patientResults.value = [...props.recentPatients];
    activeResultIndex.value = patientResults.value.length ? 0 : -1;
    nextTick(() =>
        document.querySelector<HTMLInputElement>('#patient-search')?.focus(),
    );
};
const startQuickCreate = () => {
    selectedPatient.value = null;
    form.patient_number = '';
    quickMode.value = true;
    form.clearErrors('patient_number', 'quick_patient');
    nextTick(() =>
        document
            .querySelector<HTMLInputElement>('#quick-patient-name')
            ?.focus(),
    );
};
const visitReady = computed(
    () => selectedPatient.value !== null || quickMode.value,
);
const patientSummaryName = computed(
    () =>
        selectedPatient.value?.fullName ||
        quick.value.full_name.trim() ||
        'New Patient',
);
const submit = () => {
    form.clearErrors();

    if (quickMode.value) {
        const missingName = !quick.value.full_name.trim();

        if (missingName) {
            form.setError(
                'quick_patient.full_name',
                'Please enter the Patient name.',
            );
        }

        const phone = phoneError(
            quick.value.mobile_phone,
            quick.value.phone_country,
        );
        const identity = identityError(
            quickIdentifierType.value,
            quickIdentifierValue.value,
            quickIssuer.value,
        );

        if (phone) {
            form.setError('quick_patient.mobile_phone', phone);
        }

        if (identity) {
            form.setError('quick_patient.identifiers', identity);
        }

        if (missingName || phone || identity) {
            return focusInvalidField();
        }

        form.quick_patient = {
            ...quick.value,
            identifiers: quickIdentifierValue.value.trim()
                ? [
                      {
                          identifier_type: quickIdentifierType.value,
                          issuing_country_code:
                              quickIdentifierType.value === 'nric'
                                  ? 'MY'
                                  : quickIssuer.value,
                          value: quickIdentifierValue.value,
                      },
                  ]
                : [],
        };
        form.patient_number = '';
    }

    form.post('/registration', {
        preserveState: true,
        onError: focusInvalidField,
    });
};
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
</script>

<template>
    <Head title="Register Visit" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4 md:p-6"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Register Visit
                </h1>
                <div
                    class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
                >
                    <MapPin class="size-3.5" />
                    {{ options.branch.name }} · current branch
                </div>
            </div>
            <Button variant="outline" as-child
                ><Link href="/registration"
                    ><ArrowLeft class="size-4" /> Console</Link
                ></Button
            >
        </div>

        <section class="overflow-hidden rounded-lg border bg-card">
            <div class="flex items-center justify-between px-4 pt-4 pb-2">
                <div>
                    <h2 class="font-semibold">1. Patient</h2>
                    <p class="text-xs text-muted-foreground">
                        Select an existing Patient or create a new one quickly.
                    </p>
                </div>
                <Button
                    v-if="visitReady"
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="changePatient"
                    >Change patient</Button
                >
            </div>
            <div
                v-if="selectedPatient"
                class="mx-4 mb-4 flex items-center gap-3 rounded-md border border-emerald-200/70 bg-emerald-50/70 px-3 py-3 dark:border-brand/40 dark:bg-zinc-900/85"
            >
                <div
                    class="rounded-full bg-emerald-100 p-2 text-emerald-800 dark:bg-brand/15 dark:text-brand"
                >
                    <Check class="size-4" />
                </div>
                <div class="min-w-0">
                    <div class="font-medium text-foreground">
                        {{ selectedPatient.fullName }}
                    </div>
                    <div
                        class="font-mono text-xs text-slate-600 dark:text-slate-300"
                    >
                        {{ selectedPatient.patientNumber }} ·
                        {{ selectedPatient.dateOfBirth ?? 'DOB not recorded' }}
                    </div>
                    <div
                        class="mt-0.5 text-xs text-slate-600 dark:text-slate-300"
                    >
                        {{
                            selectedPatient.identifier?.maskedValue ??
                            selectedPatient.maskedPhone ??
                            'No identifier recorded'
                        }}
                    </div>
                </div>
            </div>
            <div v-else-if="!quickMode" class="space-y-3 px-4 pb-4">
                <form class="relative" @submit.prevent="searchPatients">
                    <label class="relative block"
                        ><Search
                            class="absolute top-3 left-3 size-4 text-muted-foreground" /><input
                            id="patient-search"
                            v-model="patientQuery"
                            autofocus
                            autocomplete="off"
                            aria-controls="patient-results"
                            aria-label="Search Patient"
                            class="h-10 w-full rounded-md border bg-background pr-10 pl-9 text-sm focus-visible:ring-2 focus-visible:ring-emerald-600/30 focus-visible:outline-none"
                            placeholder="Name / NRIC / Phone / Patient No."
                            @keydown="handlePatientSearchKeydown" /></label
                    ><LoaderCircle
                        v-if="searching"
                        class="absolute top-3 right-3 size-4 animate-spin text-muted-foreground"
                    />
                </form>
                <InputError
                    :message="patientSearchError || errorFor('patient_number')"
                />
                <div class="flex items-center justify-between pt-1">
                    <p
                        class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{
                            showingRecent ? 'Recent patients' : 'Search results'
                        }}
                    </p>
                    <span class="text-xs text-muted-foreground">
                        {{ patientResults.length }} shown
                    </span>
                </div>
                <div
                    id="patient-results"
                    v-if="patientResults.length"
                    role="listbox"
                >
                    <button
                        v-for="(patient, index) in patientResults"
                        :key="patient.patientNumber"
                        type="button"
                        role="option"
                        :aria-selected="activeResultIndex === index"
                        :class="
                            activeResultIndex === index
                                ? 'bg-emerald-50/80'
                                : 'hover:bg-muted/35'
                        "
                        class="flex w-full items-center justify-between gap-4 border-b border-border/50 px-3 py-2.5 text-left last:border-0"
                        @mouseenter="activeResultIndex = index"
                        @click="selectPatient(patient)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{
                                patient.fullName
                            }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ patient.patientNumber }}</span
                            >
                        </span>
                        <span
                            class="shrink-0 text-right text-xs text-muted-foreground"
                        >
                            <span class="block">{{
                                patient.dateOfBirth ?? 'DOB unknown'
                            }}</span>
                            <span>{{
                                patient.identifier?.maskedValue ??
                                patient.maskedPhone ??
                                'No identifier'
                            }}</span>
                        </span>
                    </button>
                </div>
                <div
                    v-if="
                        !searching &&
                        patientResults.length === 0 &&
                        patientQuery.trim().length > 0
                    "
                    class="flex items-center justify-between rounded-md bg-muted/35 p-3 text-sm"
                >
                    <span>{{
                        patientQuery.trim().length < 3
                            ? 'Enter at least 3 characters.'
                            : 'No matching Patient found.'
                    }}</span
                    ><Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="startQuickCreate"
                        ><UserPlus class="size-4" /> Quick create</Button
                    >
                </div>
                <div
                    v-else-if="showingRecent && !patientResults.length"
                    class="flex items-center justify-between rounded-md bg-muted/35 p-3 text-sm"
                >
                    <span>No Patients yet.</span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="startQuickCreate"
                    >
                        <UserPlus class="size-4" /> Quick create
                    </Button>
                </div>
            </div>
            <div v-else class="grid gap-3 p-4 md:grid-cols-2">
                <label class="grid gap-1 md:col-span-2"
                    ><span class="text-sm font-medium">Name</span
                    ><input
                        id="quick-patient-name"
                        :aria-invalid="!!errorFor('quick_patient.full_name')"
                        aria-describedby="quick-name-error"
                        v-model="quick.full_name"
                        autocomplete="off"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        id="quick-name-error"
                        role="alert"
                        :message="errorFor('quick_patient.full_name')"
                /></label>
                <label class="grid gap-1"
                    ><span class="text-sm font-medium"
                        >Identity document *</span
                    >
                    <div class="flex">
                        <OperationalSelect
                            v-model="quickIdentifierType"
                            label="Identification type"
                            :options="identityTypeOptions"
                            trigger-class="h-10 w-40 rounded-r-none"
                        /><input
                            v-model="quickIdentifierValue"
                            aria-label="IC or Passport number"
                            :aria-invalid="
                                !!(
                                    errorFor('quick_patient.identifiers') ||
                                    errorFor(
                                        'quick_patient.identifiers.0.value',
                                    )
                                )
                            "
                            aria-describedby="quick-identity-error"
                            autocomplete="off"
                            class="h-10 min-w-0 flex-1 rounded-r-md border border-l-0 bg-background px-3 text-sm"
                        />
                    </div>
                    <InputError
                        id="quick-identity-error"
                        role="alert"
                        :message="
                            errorFor('quick_patient.identifiers') ||
                            errorFor('quick_patient.identifiers.0.value') ||
                            errorFor(
                                'quick_patient.identifiers.0.issuing_country_code',
                            )
                        "
                /></label>
                <label
                    v-if="quickIdentifierType === 'passport'"
                    class="grid gap-1"
                    ><span class="text-sm font-medium">Passport issuer *</span
                    ><input
                        v-model="quickIssuer"
                        :aria-invalid="
                            !!errorFor(
                                'quick_patient.identifiers.0.issuing_country_code',
                            )
                        "
                        aria-describedby="quick-identity-error"
                        maxlength="2"
                        class="h-10 rounded-md border bg-background px-3 text-sm uppercase" /></label
                ><span v-else />
                <PhoneInput
                    id="quick-phone"
                    v-model="quick.mobile_phone"
                    v-model:country="quick.phone_country"
                    required
                    :error="
                        errorFor('quick_patient.mobile_phone') ||
                        errorFor('quick_patient.phone_country')
                    "
                />
                <label class="grid gap-1"
                    ><span class="text-sm font-medium">DOB (optional)</span
                    ><input
                        v-model="quick.date_of_birth"
                        :aria-invalid="
                            !!errorFor('quick_patient.date_of_birth')
                        "
                        type="date"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errorFor('quick_patient.date_of_birth')"
                /></label>
                <label class="grid gap-1" for="quick-gender"
                    ><span class="text-sm font-medium">Gender</span
                    ><OperationalSelect
                        id="quick-gender"
                        v-model="quick.sex"
                        label="Gender"
                        :invalid="!!errorFor('quick_patient.sex')"
                        :options="genderOptions"
                /></label>
                <label
                    v-if="errorFor('quick_patient.duplicate_override')"
                    class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm md:col-span-2"
                    ><input
                        v-model="quick.duplicate_override"
                        type="checkbox"
                        class="mt-1"
                    /><span
                        >{{
                            errorFor('quick_patient.duplicate_override')
                        }}
                        Confirm only after review.</span
                    ></label
                >
            </div>
        </section>

        <form v-if="visitReady" class="space-y-3" @submit.prevent="submit">
            <section class="rounded-lg border bg-card">
                <div class="px-4 pt-4 pb-2">
                    <h2 class="font-semibold">Visit details</h2>
                    <p class="text-xs text-muted-foreground">
                        Consultation / OTC · Doctor · Reason · Coverage ·
                        Urgency
                    </p>
                </div>
                <div class="grid gap-4 px-4 pb-4 md:grid-cols-2">
                    <fieldset class="grid gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            2. Visit type
                        </legend>
                        <div
                            class="grid grid-cols-2 gap-1 rounded-md bg-muted/70 p-1"
                        >
                            <button
                                type="button"
                                :aria-pressed="
                                    form.visit_type === 'consultation'
                                "
                                :class="
                                    form.visit_type === 'consultation'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                class="h-9 rounded-sm px-3 text-sm font-medium transition-colors"
                                @click="form.visit_type = 'consultation'"
                            >
                                Consultation
                            </button>
                            <button
                                type="button"
                                :aria-pressed="form.visit_type === 'otc'"
                                :class="
                                    form.visit_type === 'otc'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                class="h-9 rounded-sm px-3 text-sm font-medium transition-colors"
                                @click="form.visit_type = 'otc'"
                            >
                                OTC
                            </button>
                        </div>
                    </fieldset>
                    <label class="grid gap-1" for="assigned-doctor"
                        ><span class="text-sm font-medium"
                            >3. Doctor
                            <span
                                v-if="form.visit_type === 'otc'"
                                class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></span
                        ><OperationalSelect
                            id="assigned-doctor"
                            v-model="form.assigned_doctor_user_id"
                            label="Doctor"
                            :invalid="!!form.errors.assigned_doctor_user_id"
                            :options="doctorOptions" />
                        ><InputError
                            :message="errorFor('assigned_doctor_user_id')"
                    /></label>
                    <div class="grid gap-1 md:col-span-2">
                        <span class="text-sm font-medium"
                            >4. Visit Reason
                            <span
                                v-if="form.visit_type === 'otc'"
                                class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></span
                        >
                        <VisitReasonPicker
                            v-model="form.visit_reason_public_ids"
                            v-model:selected="selectedVisitReasons"
                            :error="errorFor('visit_reason_public_ids')"
                        />
                    </div>
                    <fieldset class="grid gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            5. Coverage
                        </legend>
                        <div
                            class="grid grid-cols-2 gap-1 rounded-md bg-muted/70 p-1"
                        >
                            <button
                                type="button"
                                :aria-pressed="
                                    form.coverage_type === 'self_pay'
                                "
                                :class="
                                    form.coverage_type === 'self_pay'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                class="h-9 rounded-sm px-3 text-sm font-medium transition-colors"
                                @click="form.coverage_type = 'self_pay'"
                            >
                                Self-pay
                            </button>
                            <button
                                type="button"
                                :aria-pressed="form.coverage_type === 'panel'"
                                :class="
                                    form.coverage_type === 'panel'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                class="h-9 rounded-sm px-3 text-sm font-medium transition-colors"
                                @click="form.coverage_type = 'panel'"
                            >
                                Panel
                            </button>
                        </div>
                    </fieldset>
                    <fieldset class="grid gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            6. Urgency
                        </legend>
                        <div
                            class="grid grid-cols-2 gap-1 rounded-md bg-muted/70 p-1"
                        >
                            <button
                                type="button"
                                :aria-pressed="form.priority === 'normal'"
                                :class="
                                    form.priority === 'normal'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                class="h-9 rounded-sm px-3 text-sm font-medium transition-colors"
                                @click="form.priority = 'normal'"
                            >
                                Normal
                            </button>
                            <button
                                type="button"
                                :aria-pressed="form.priority === 'urgent'"
                                :class="
                                    form.priority === 'urgent'
                                        ? 'bg-amber-50 text-amber-800 ring-1 ring-amber-300'
                                        : 'text-muted-foreground hover:text-amber-800'
                                "
                                class="h-9 rounded-sm px-3 text-sm font-semibold transition-colors"
                                @click="form.priority = 'urgent'"
                            >
                                Urgent
                            </button>
                        </div>
                    </fieldset>
                    <template v-if="form.coverage_type === 'panel'"
                        ><label class="grid gap-1" for="panel-id"
                            ><span class="text-sm font-medium">Panel</span
                            ><OperationalSelect
                                id="panel-id"
                                v-model="form.panel_id"
                                label="Panel"
                                :invalid="!!form.errors.panel_id"
                                :options="panelOptions" />
                            ><InputError
                                :message="errorFor('panel_id')" /></label
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium"
                                >Member/staff reference (optional)</span
                            ><input
                                v-model="form.coverage_member_reference"
                                :aria-invalid="
                                    !!form.errors.coverage_member_reference
                                "
                                autocomplete="off"
                                class="h-10 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-emerald-600/30 focus-visible:outline-none" /></label
                    ></template>
                </div>
            </section>
            <label
                v-if="errorFor('confirm_repeat')"
                class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm"
                ><input
                    v-model="form.confirm_repeat"
                    type="checkbox"
                    class="mt-1"
                /><span
                    ><strong>Repeat Visit warning:</strong>
                    {{ errorFor('confirm_repeat') }}</span
                ></label
            >
            <InputError
                :message="
                    errorFor('expected_branch_id') || errorFor('quick_patient')
                "
            />
            <div
                class="sticky bottom-3 z-10 flex items-center justify-between gap-4 rounded-lg border bg-background/95 p-3 shadow-sm backdrop-blur"
            >
                <div class="hidden min-w-0 sm:block">
                    <p class="truncate text-sm font-medium">
                        {{ patientSummaryName }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ options.branch.name }} ·
                        {{
                            form.visit_type === 'consultation'
                                ? 'Consultation'
                                : 'OTC'
                        }}
                    </p>
                </div>
                <Button
                    type="submit"
                    size="lg"
                    class="ml-auto min-w-40"
                    :disabled="form.processing"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    {{ form.processing ? 'Registering…' : 'Register Visit' }}
                </Button>
            </div>
        </form>
    </main>
</template>

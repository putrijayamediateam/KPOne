<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ClipboardCheck,
    LoaderCircle,
    Megaphone,
    Plus,
    PauseCircle,
    PlayCircle,
    UserRound,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DiagnosisPresetPicker from '@/components/clinical/DiagnosisPresetPicker.vue';
import UnsavedClinicalWorkDialog from '@/components/clinical/UnsavedClinicalWorkDialog.vue';
import WaitingPatientsButton from '@/components/clinical/WaitingPatientsButton.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { OperationalSelect } from '@/components/ui/select';
import UserMenuContent from '@/components/UserMenuContent.vue';
import WorkspaceBranchSwitcher from '@/components/workspace/WorkspaceBranchSwitcher.vue';
import { getInitials } from '@/composables/useInitials';
import { appendPresetDiagnosis } from '@/lib/diagnosis-draft';
import { waitedDurationLabel } from '@/lib/r1c2-presentation';
import AllergyProblemPanel from '@/pages/Clinical/Partials/AllergyProblemPanel.vue';
import ClinicalHistoryPanel from '@/pages/Clinical/Partials/ClinicalHistoryPanel.vue';
import DispensaryAttentionPanel from '@/pages/Clinical/Partials/DispensaryAttentionPanel.vue';
import TreatmentPlanPanel from '@/pages/Clinical/Partials/TreatmentPlanPanel.vue';
import type { ClinicalEncounterPage } from '@/types';

defineOptions({
    layout: {
        focusMode: true,
        breadcrumbs: [{ title: 'Queue', href: '/queue' }],
    },
});

const props = defineProps<{
    clinical: ClinicalEncounterPage;
    treatmentRooms?: Array<{ id: number; name: string }>;
}>();
const page = usePage();
const user = computed(() => page.props.auth.user!);
// Show the authorized visit note only when non-empty.
const panelNote = computed(() => props.clinical.visit.panelNote);
const canViewPatient = computed(() =>
    page.props.auth.permissions.includes('patients.view.organisation'),
);
const workspaceTab = ref<'treatment' | 'history'>('treatment');
const patientAge = computed(() => {
    const value = props.clinical.patient.dateOfBirth;

    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return 'Age unknown';
    }

    const birth = new Date(`${value}T00:00:00Z`);

    if (
        Number.isNaN(birth.getTime()) ||
        birth.toISOString().slice(0, 10) !== value
    ) {
        return 'Age unknown';
    }

    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: props.clinical.branch.timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());

    if (value > today) {
        return 'Age unknown';
    }

    const years =
        Number(today.slice(0, 4)) -
        Number(value.slice(0, 4)) -
        (today.slice(5) < value.slice(5) ? 1 : 0);

    return `${years} years`;
});
const holdProcessing = ref(false);
const holdError = ref('');
type NumericInput = number | string | null;
type DiagnosisInput = {
    diagnosis_text: string;
    diagnosis_code: string;
    code_system: string;
    is_primary: boolean;
};

// Shared with the initial useForm() seed below and with the prop-resync
// watcher (OH-06), so the two can never drift into different shapes.
const vitalsExpanded = ref(false);
const hasVitals = computed(() =>
    Object.values(form.vitals).some((value) => value !== null && value !== ''),
);
const vitalsFromProps = () => ({
    systolic_bp: props.clinical.vitals.systolicBp as NumericInput,
    diastolic_bp: props.clinical.vitals.diastolicBp as NumericInput,
    pulse_bpm: props.clinical.vitals.pulseBpm as NumericInput,
    temperature_celsius: props.clinical.vitals
        .temperatureCelsius as NumericInput,
    spo2_percent: props.clinical.vitals.spo2Percent as NumericInput,
    weight_kg: props.clinical.vitals.weightKg as NumericInput,
    height_cm: props.clinical.vitals.heightCm as NumericInput,
});
const diagnosesFromProps = (): DiagnosisInput[] =>
    props.clinical.diagnoses.map((diagnosis): DiagnosisInput => ({
        diagnosis_text: diagnosis.diagnosisText,
        diagnosis_code: diagnosis.diagnosisCode ?? '',
        code_system: diagnosis.codeSystem ?? '',
        is_primary: diagnosis.isPrimary,
    }));

const form = useForm({
    expected_branch_id: props.clinical.branch.id,
    lock_version: props.clinical.encounter.lockVersion,
    clinical_note: props.clinical.encounter.clinicalNote ?? '',
    vitals: vitalsFromProps(),
    diagnoses: diagnosesFromProps(),
});

// OH-06: Hold and Resume intentionally use `preserveState: true` (see
// changeHoldState below) so a doctor's in-progress edits are never disturbed
// by a hold/resume click. That means this component is never remounted by
// those actions, so nothing else re-seeds `form` from the fresh `clinical`
// prop that DOES arrive with the hold/resume response - `form` would
// silently drift from what the server (and the database) actually hold.
//
// Safety rule: lock_version must only ever move together with the content it
// guards, in the same assignment, never on its own - an update sent with a
// lock_version that no longer matches its content would defeat the backend's
// optimistic-concurrency check.
//
// - Clean form (no unsaved edits): safe to adopt the fresh values wholesale.
// - Dirty form (doctor mid-edit): never touch content or lock_version. The
//   next Save will be sent with the OLD lock_version and the backend will
//   reject it (ValidationException on `lock_version`) rather than silently
//   overwriting whatever changed elsewhere - surfaced here as a clear banner
//   with an explicit Reload action instead of a silent failure.
const recordDrifted = ref(false);

const adoptLatestRecord = () => {
    form.clinical_note = props.clinical.encounter.clinicalNote ?? '';
    form.vitals = vitalsFromProps();
    form.diagnoses = diagnosesFromProps();
    form.lock_version = props.clinical.encounter.lockVersion;
    form.clearErrors();
    form.defaults();
    recordDrifted.value = false;
};

watch(
    () => props.clinical.encounter.lockVersion,
    () => {
        if (form.isDirty) {
            recordDrifted.value = true;

            return;
        }

        adoptLatestRecord();
    },
);

// OH-06c: pressing On Hold while there is unsaved clinical work (this form,
// or the sibling Treatment Plan draft) must never silently strand that work
// - clinical save is disabled for the rest of the time this consultation is
// held, so Reload (the drift banner's own recovery path) is the only way
// back, and it discards exactly what the doctor was trying to protect. The
// drift banner above stays for the separate, genuine concurrent-edit case;
// this is a distinct guard that runs before a hold is ever requested.
const treatmentPlanPanelRef = ref<InstanceType<
    typeof TreatmentPlanPanel
> | null>(null);
const noteDirty = computed(
    () => form.clinical_note !== (props.clinical.encounter.clinicalNote ?? ''),
);
const vitalsDirty = computed(
    () => JSON.stringify(form.vitals) !== JSON.stringify(vitalsFromProps()),
);
const diagnosesDirty = computed(
    () =>
        JSON.stringify(form.diagnoses) !== JSON.stringify(diagnosesFromProps()),
);
const treatmentPlanDirty = computed(
    () => treatmentPlanPanelRef.value?.isDirty() ?? false,
);
const unsavedItems = computed(() => {
    const items: string[] = [];

    if (noteDirty.value) {
        items.push('clinical note');
    }

    if (vitalsDirty.value) {
        items.push('vitals');
    }

    if (diagnosesDirty.value) {
        items.push('diagnoses');
    }

    if (treatmentPlanDirty.value) {
        items.push('treatment plan');
    }

    return items;
});
const hasUnsavedWork = computed(() => form.isDirty || treatmentPlanDirty.value);
const unsavedWorkOpen = ref(false);
const unsavedWorkProcessing = ref(false);
const unsavedWorkAction = ref<'confirm' | 'secondary'>('confirm');
const unsavedWorkError = ref('');

const recallProcessing = ref(false);
const recallError = ref('');
const recallPatient = () => {
    recallProcessing.value = true;
    recallError.value = '';
    router.patch(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/queue/recall`,
        {
            expected_branch_id: props.clinical.branch.id,
            queue_lock_version: props.clinical.queue.lockVersion,
            from: 'consultation',
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                recallError.value =
                    Object.values(errors)[0] ??
                    'Pesakit tidak dapat dipanggil semula.';
            },
            onFinish: () => (recallProcessing.value = false),
        },
    );
};
// Calls the patient to a treatment room on the TV; the consultation stays with this doctor.
const treatmentRoomId = ref<string | number>(
    props.treatmentRooms?.length === 1 ? props.treatmentRooms[0].id : '',
);
const callToTreatment = () => {
    if (treatmentRoomId.value === '') {
        recallError.value = 'Pilih bilik rawatan dahulu.';

        return;
    }

    recallProcessing.value = true;
    recallError.value = '';
    router.patch(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/queue/treatment-call`,
        {
            expected_branch_id: props.clinical.branch.id,
            queue_lock_version: props.clinical.queue.lockVersion,
            branch_room_id: treatmentRoomId.value,
            from: 'consultation',
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                recallError.value =
                    Object.values(errors)[0] ??
                    'Pesakit tidak dapat dipanggil ke bilik rawatan.';
            },
            onFinish: () => (recallProcessing.value = false),
        },
    );
};
const requestHold = () => {
    if (!hasUnsavedWork.value) {
        changeHoldState('hold');

        return;
    }

    unsavedWorkError.value = '';
    unsavedWorkOpen.value = true;
};
const saveAndHold = () => {
    unsavedWorkAction.value = 'confirm';
    unsavedWorkProcessing.value = true;
    unsavedWorkError.value = '';

    const proceedToHold = () => {
        unsavedWorkOpen.value = false;
        unsavedWorkProcessing.value = false;
        changeHoldState('hold');
    };
    // A dirty Treatment Plan draft would become unsavable the moment this
    // encounter is held (its own `canSave` excludes a held consultation), so
    // it must be saved here too - not just the clinical note/vitals/
    // diagnoses form - or "Save and hold" would strand exactly the work it
    // is meant to protect.
    const saveTreatmentPlanThenHold = () => {
        const panel = treatmentPlanPanelRef.value;

        if (!panel) {
            proceedToHold();

            return;
        }

        panel.saveForHold(proceedToHold, (message) => {
            unsavedWorkError.value = message;
            unsavedWorkProcessing.value = false;
        });
    };

    if (!form.isDirty) {
        saveTreatmentPlanThenHold();

        return;
    }

    form.patch(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/encounter`,
        {
            preserveScroll: true,
            onSuccess: () => {
                adoptLatestRecord();
                saveTreatmentPlanThenHold();
            },
            onError: () => {
                unsavedWorkError.value =
                    Object.values(form.errors)[0] ??
                    'The clinical record could not be saved. Review the latest information and try again.';
                unsavedWorkProcessing.value = false;
            },
        },
    );
};
const holdWithoutSaving = () => {
    unsavedWorkAction.value = 'secondary';
    form.reset();
    form.clearErrors();
    treatmentPlanPanelRef.value?.discardDraft();
    unsavedWorkOpen.value = false;
    changeHoldState('hold');
};

const bmi = computed(() => {
    const weight = Number(form.vitals.weight_kg);
    const height = Number(form.vitals.height_cm);

    return weight > 0 && height > 0
        ? (weight / (height / 100) ** 2).toFixed(1)
        : '—';
});
const stateError = computed(
    () =>
        form.errors.lock_version ||
        (form.errors as Record<string, string>).encounter ||
        form.errors.expected_branch_id,
);
const waitedMinutes = computed(() => {
    const queued = props.clinical.queue.queuedAt
        ? Date.parse(props.clinical.queue.queuedAt)
        : Number.NaN;
    const called = props.clinical.queue.calledAt
        ? Date.parse(props.clinical.queue.calledAt)
        : Number.NaN;

    return Number.isFinite(queued) && Number.isFinite(called)
        ? Math.max(0, Math.floor((called - queued) / 60_000))
        : null;
});

const addDiagnosis = () => {
    form.diagnoses.push({
        diagnosis_text: '',
        diagnosis_code: '',
        code_system: '',
        is_primary: form.diagnoses.length === 0,
    });
};
const choosePreset = (name: string) => {
    form.diagnoses = appendPresetDiagnosis(form.diagnoses, name);
};
const removeDiagnosis = (index: number) => {
    form.diagnoses.splice(index, 1);

    if (
        form.diagnoses.length &&
        !form.diagnoses.some((row) => row.is_primary)
    ) {
        form.diagnoses[0].is_primary = true;
    }
};
const setPrimary = (index: number) => {
    form.diagnoses.forEach((row, rowIndex) => {
        row.is_primary = rowIndex === index;
    });
};
const save = () => {
    form.patch(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/encounter`,
        {
            preserveScroll: true,
            onSuccess: () => {
                // The content just saved now matches the server; adopt the
                // fresh props (including the bumped lock_version) as one
                // atomic operation and re-baseline the form as clean.
                adoptLatestRecord();
            },
        },
    );
};
const changeHoldState = (action: 'hold' | 'resume') => {
    holdProcessing.value = true;
    holdError.value = '';
    router.post(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/encounter/${action}`,
        {
            expected_branch_id: props.clinical.branch.id,
            visit_lock_version: props.clinical.visit.lockVersion,
            queue_lock_version: props.clinical.queue.lockVersion,
            encounter_lock_version: props.clinical.encounter.lockVersion,
            idempotency_key:
                action === 'hold'
                    ? props.clinical.hold.holdIdempotencyKey
                    : props.clinical.hold.resumeIdempotencyKey,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                holdError.value =
                    Object.values(errors)[0] ??
                    'The consultation state could not be changed.';
            },
            onFinish: () => (holdProcessing.value = false),
        },
    );
};
</script>

<template>
    <Head title="Clinical Encounter" />
    <main
        class="consultation-workspace mx-auto flex w-full max-w-[1800px] flex-1 flex-col gap-3 bg-neutral-100 px-3 py-3 md:px-5 dark:bg-background"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 px-1 py-1"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    href="/queue"
                    class="inline-flex items-center gap-1 text-xs font-medium text-emerald-800 hover:underline"
                >
                    <ArrowLeft class="size-3.5" /> My Queue
                </Link>
                <span class="rounded-md border bg-card px-2 py-1 text-xs">{{
                    clinical.encounter.attendingClinician
                }}</span>
                <span class="text-[11px] text-muted-foreground"
                    >{{ clinical.visit.visitNumber }} ·
                    {{ clinical.hold.isHeld ? 'On Hold' : 'In progress' }}</span
                >
                <span class="text-[11px] text-muted-foreground"
                    >{{ waitedDurationLabel(waitedMinutes) }} · Active
                    {{ clinical.hold.activeMinutes }} min · Held
                    {{ clinical.hold.heldMinutes }} min</span
                >
            </div>
            <div
                class="flex flex-wrap items-center justify-end gap-2 text-right text-xs text-muted-foreground"
            >
                <a
                    v-if="
                        page.props.auth.permissions.includes(
                            'medical_certificates.view.own',
                        )
                    "
                    :href="`/visits/${clinical.visit.visitNumber}/medical-certificates`"
                    target="_blank"
                    rel="noopener"
                    class="rounded-md border bg-card px-3 py-2 text-xs"
                    >Medical certificates (new tab)</a
                >
                <a
                    v-if="
                        page.props.auth.permissions.includes(
                            'referrals.view.own',
                        )
                    "
                    :href="`/visits/${clinical.visit.visitNumber}/referrals`"
                    target="_blank"
                    rel="noopener"
                    class="rounded-md border bg-card px-3 py-2 text-xs"
                    >Referral letters (new tab)</a
                >
                <WaitingPatientsButton
                    v-if="
                        page.props.auth.permissions.some((permission) =>
                            ['queue.view.own', 'queue.view.branch'].includes(
                                permission,
                            ),
                        )
                    "
                    :branch-id="clinical.branch.id"
                    :can-view-branch="
                        page.props.auth.permissions.includes(
                            'queue.view.branch',
                        )
                    "
                />
                <Button
                    v-if="clinical.queue.canRecall"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="recallProcessing"
                    data-testid="recall-patient"
                    @click="recallPatient"
                >
                    <Megaphone class="size-4" /> Panggil semula
                </Button>
                <div
                    v-if="
                        clinical.queue.canRecall &&
                        (treatmentRooms?.length ?? 0) > 0
                    "
                    class="flex items-center gap-2"
                >
                    <OperationalSelect
                        v-if="(treatmentRooms?.length ?? 0) > 1"
                        v-model="treatmentRoomId"
                        label="Treatment room"
                        placeholder="Bilik rawatan"
                        trigger-class="h-8 w-40"
                        :options="
                            (treatmentRooms ?? []).map((room) => ({
                                value: room.id,
                                label: room.name,
                            }))
                        "
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="recallProcessing"
                        data-testid="call-treatment"
                        @click="callToTreatment"
                    >
                        <Megaphone class="size-4" /> Ke bilik rawatan
                    </Button>
                </div>
                <InputError
                    v-if="recallError"
                    class="max-w-56 text-right"
                    :message="recallError"
                />
                <Button
                    v-if="clinical.hold.canHold"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="holdProcessing"
                    @click="requestHold"
                >
                    <PauseCircle class="size-4" /> On Hold
                </Button>
                <Button
                    v-if="clinical.hold.canResume"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="holdProcessing"
                    @click="changeHoldState('resume')"
                >
                    <PlayCircle class="size-4" /> Resume Consultation
                </Button>
                <div
                    class="flex shrink-0 items-center gap-2"
                    data-testid="consultation-focus-controls"
                >
                    <WorkspaceBranchSwitcher />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="w-8 rounded-full px-0"
                                aria-label="Open user menu"
                            >
                                <Avatar class="size-7"
                                    ><AvatarFallback
                                        class="bg-muted text-xs font-semibold"
                                        >{{
                                            getInitials(user.name)
                                        }}</AvatarFallback
                                    ></Avatar
                                >
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            class="w-60 rounded-xl border-border/70 p-1.5"
                        >
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>
        <!-- Panel notes are read-only in the consultation. -->
        <div
            class="grid min-h-0 gap-5 lg:grid-cols-[minmax(0,55fr)_minmax(0,45fr)]"
        >
            <div class="min-w-0 space-y-3" data-testid="consultation-left">
                <section class="overflow-hidden rounded-lg border bg-card">
                    <div
                        class="grid sm:grid-cols-[minmax(0,11fr)_minmax(0,9fr)]"
                        data-testid="consultation-patient-grid"
                    >
                        <div class="flex min-w-0 items-start gap-3 p-4">
                            <div
                                class="rounded-lg bg-muted p-2 text-muted-foreground"
                            >
                                <UserRound class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1 space-y-1">
                                <h1
                                    class="truncate text-[14px] font-semibold"
                                    :title="clinical.patient.name"
                                >
                                    {{ clinical.patient.name }}
                                </h1>
                                <p class="text-xs text-muted-foreground">
                                    {{ patientAge }} ·
                                    {{ clinical.patient.sex }}
                                </p>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ clinical.patient.patientNumber }}
                                </p>
                                <span
                                    v-if="clinical.visit.priority === 'urgent'"
                                    class="inline-flex rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300"
                                    >Urgent</span
                                >
                            </div>
                            <div
                                class="flex shrink-0 flex-col items-end gap-2 text-right text-xs text-muted-foreground"
                            >
                                <Link
                                    v-if="canViewPatient"
                                    :href="`/patients/${encodeURIComponent(clinical.patient.patientNumber)}`"
                                    class="inline-flex h-7 items-center rounded-md border px-2 text-[12px] text-foreground"
                                    >View profile</Link
                                >
                                <span
                                    >Queue No:
                                    {{ clinical.queue.queueNumber }}</span
                                >
                            </div>
                        </div>
                        <div
                            class="min-w-0 space-y-2 border-t p-4 sm:border-t-0 sm:border-l"
                            data-testid="consultation-payment-method"
                        >
                            <div class="text-xs font-medium">
                                Payment method
                            </div>
                            <span
                                class="inline-flex max-w-full rounded-md bg-muted px-2 py-1 text-xs break-words"
                                >{{ clinical.visit.paymentMethodLabel }}</span
                            >
                            <div
                                v-if="clinical.visit.isPanel && panelNote"
                                data-testid="consultation-panel-note"
                                class="space-y-1 text-xs text-muted-foreground"
                            >
                                <div>Panel note</div>
                                <div class="break-words whitespace-pre-wrap">
                                    {{ panelNote }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="border-t">
                        <AllergyProblemPanel
                            :visit-number="clinical.visit.visitNumber"
                            :branch-id="clinical.branch.id"
                            :time-zone="clinical.branch.timezone"
                            :allergies="clinical.allergies"
                            :problems="clinical.problems"
                        />
                    </div>
                </section>

                <DispensaryAttentionPanel
                    :items="clinical.dispensaryAttention"
                />

                <div
                    v-if="stateError"
                    role="alert"
                    class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ stateError }}
                </div>
                <div
                    v-if="holdError"
                    role="alert"
                    class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"
                >
                    {{ holdError }}
                </div>
                <div
                    v-if="clinical.hold.isHeld"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
                >
                    This patient remains in Serving Now. Resume the consultation
                    before saving or completing clinical work.
                </div>
                <div
                    v-if="recordDrifted"
                    role="alert"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
                >
                    <span class="flex items-start gap-2">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        This record has changed elsewhere. Reload to see the
                        latest version - your edits have not been saved.
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="adoptLatestRecord"
                    >
                        Reload
                    </Button>
                </div>

                <form
                    class="space-y-4 rounded-lg border bg-card p-4"
                    data-testid="consultation-record-card"
                    @submit.prevent="save"
                >
                    <div
                        class="flex min-w-0 items-baseline gap-2 rounded-md border bg-muted/30 px-3 py-2"
                        data-testid="consultation-visit-bar"
                    >
                        <span
                            class="shrink-0 text-xs font-medium text-muted-foreground"
                            >Visit note</span
                        >
                        <span>{{
                            clinical.visit.registrationReason ??
                            'No reason recorded'
                        }}</span>
                    </div>
                    <section
                        class="space-y-1 rounded-md border bg-muted/15 px-3 py-2"
                        data-testid="consultation-vitals-bar"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-2"
                        >
                            <h2
                                class="shrink-0 font-semibold"
                                title="Enter the measurements available for this consultation."
                            >
                                Vital signs
                            </h2>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="ml-auto shrink-0"
                                title="Record vital signs"
                                :aria-expanded="vitalsExpanded"
                                @click="vitalsExpanded = !vitalsExpanded"
                                >Record new</Button
                            >
                        </div>
                        <div
                            v-if="hasVitals"
                            class="flex min-w-0 flex-wrap gap-x-2 gap-y-1 text-xs"
                            data-testid="vitals-summary"
                        >
                            <span class="inline-flex items-baseline gap-1"
                                ><span
                                    class="text-muted-foreground"
                                    title="Blood pressure"
                                    >BP</span
                                ><strong class="font-medium"
                                    >{{ form.vitals.systolic_bp || '—' }}/{{
                                        form.vitals.diastolic_bp || '—'
                                    }}</strong
                                ></span
                            >
                            <span class="inline-flex items-baseline gap-1"
                                ><span class="text-muted-foreground">Pulse</span
                                ><strong class="font-medium">{{
                                    form.vitals.pulse_bpm || '—'
                                }}</strong></span
                            >
                            <span class="inline-flex items-baseline gap-1"
                                ><span
                                    class="text-muted-foreground"
                                    title="Temperature"
                                    >Temp</span
                                ><strong class="font-medium"
                                    >{{
                                        form.vitals.temperature_celsius || '—'
                                    }}
                                    °C</strong
                                ></span
                            >
                            <span class="inline-flex items-baseline gap-1"
                                ><span class="text-muted-foreground">SpO₂</span
                                ><strong class="font-medium"
                                    >{{
                                        form.vitals.spo2_percent || '—'
                                    }}%</strong
                                ></span
                            >
                            <span class="inline-flex items-baseline gap-1"
                                ><span class="text-muted-foreground"
                                    >Weight</span
                                ><strong class="font-medium"
                                    >{{
                                        form.vitals.weight_kg || '—'
                                    }}
                                    kg</strong
                                ></span
                            >
                            <span class="inline-flex items-baseline gap-1"
                                ><span class="text-muted-foreground"
                                    >Height</span
                                ><strong class="font-medium"
                                    >{{
                                        form.vitals.height_cm || '—'
                                    }}
                                    cm</strong
                                ></span
                            >
                            <span class="inline-flex items-baseline gap-1"
                                ><span
                                    class="text-muted-foreground"
                                    title="Derived BMI"
                                    >BMI</span
                                ><strong class="font-medium">{{
                                    bmi
                                }}</strong></span
                            >
                        </div>
                        <div
                            v-if="!hasVitals"
                            class="min-w-0 flex-1 text-xs text-muted-foreground"
                        >
                            No vital signs recorded.
                        </div>

                        <div
                            v-show="
                                vitalsExpanded ||
                                Object.keys(form.errors).some((key) =>
                                    key.startsWith('vitals.'),
                                )
                            "
                            class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <label class="text-sm"
                                >Systolic BP
                                <input
                                    v-model="form.vitals.systolic_bp"
                                    inputmode="numeric"
                                    type="number"
                                    min="1"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="form.errors['vitals.systolic_bp']"
                                />
                            </label>
                            <label class="text-sm"
                                >Diastolic BP
                                <input
                                    v-model="form.vitals.diastolic_bp"
                                    inputmode="numeric"
                                    type="number"
                                    min="1"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="
                                        form.errors['vitals.diastolic_bp']
                                    "
                                />
                            </label>
                            <label class="text-sm"
                                >Pulse (bpm)
                                <input
                                    v-model="form.vitals.pulse_bpm"
                                    inputmode="numeric"
                                    type="number"
                                    min="1"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="form.errors['vitals.pulse_bpm']"
                                />
                            </label>
                            <label class="text-sm"
                                >Temperature (°C)
                                <input
                                    v-model="form.vitals.temperature_celsius"
                                    inputmode="decimal"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            'vitals.temperature_celsius'
                                        ]
                                    "
                                />
                            </label>
                            <label class="text-sm"
                                >SpO₂ (%)
                                <input
                                    v-model="form.vitals.spo2_percent"
                                    inputmode="decimal"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="
                                        form.errors['vitals.spo2_percent']
                                    "
                                />
                            </label>
                            <label class="text-sm"
                                >Weight (kg)
                                <input
                                    v-model="form.vitals.weight_kg"
                                    inputmode="decimal"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="form.errors['vitals.weight_kg']"
                                />
                            </label>
                            <label class="text-sm"
                                >Height (cm)
                                <input
                                    v-model="form.vitals.height_cm"
                                    inputmode="decimal"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="form.errors['vitals.height_cm']"
                                />
                            </label>
                            <div class="rounded-md bg-muted/35 p-3 text-sm">
                                <div class="text-xs text-muted-foreground">
                                    Derived BMI
                                </div>
                                <div class="mt-1 text-lg font-semibold">
                                    {{ bmi }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-2">
                        <div>
                            <h2
                                class="font-semibold"
                                title="Consultation findings only. This is separate from the Visit Reason."
                            >
                                Clinical Note
                            </h2>
                        </div>
                        <div
                            class="overflow-hidden rounded-md border border-input"
                        >
                            <textarea
                                v-model="form.clinical_note"
                                maxlength="20000"
                                rows="3"
                                class="block min-h-20 w-full resize-y rounded-t-md border-0 bg-card p-3 text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
                                placeholder="Record the clinical consultation note…"
                            />
                            <InputError :message="form.errors.clinical_note" />
                            <div class="border-t px-3 py-2">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2"
                                >
                                    <span
                                        >{{
                                            form.clinical_note.length.toLocaleString()
                                        }}
                                        / 20,000</span
                                    >
                                    <span class="text-xs text-muted-foreground">
                                        <template v-if="form.processing"
                                            >Saving clinical record…</template
                                        >
                                        <template
                                            v-else-if="form.recentlySuccessful"
                                            >Saved.</template
                                        >
                                        <template v-else-if="form.isDirty"
                                            >Unsaved changes</template
                                        >
                                        <template v-else
                                            >Clinical record is up to
                                            date.</template
                                        >
                                    </span>
                                    <Button
                                        type="submit"
                                        :disabled="
                                            form.processing ||
                                            clinical.hold.isHeld
                                        "
                                    >
                                        <LoaderCircle
                                            v-if="form.processing"
                                            class="size-4 animate-spin"
                                        />
                                        <ClipboardCheck v-else class="size-4" />
                                        {{
                                            form.processing
                                                ? 'Saving…'
                                                : 'Save clinical record'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-2">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div>
                                <h2
                                    class="font-semibold"
                                    title="Structured codes are optional in Phase 2A."
                                >
                                    Diagnoses
                                </h2>
                            </div>
                            <div class="flex flex-wrap justify-end gap-2">
                                <DiagnosisPresetPicker
                                    :visit-number="clinical.visit.visitNumber"
                                    :branch-id="clinical.branch.id"
                                    :disabled="
                                        !clinical.encounter
                                            .canChooseDiagnosisPreset ||
                                        clinical.hold.isHeld ||
                                        form.processing ||
                                        recordDrifted ||
                                        form.diagnoses.length >= 20
                                    "
                                    @select="choosePreset"
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="addDiagnosis"
                                >
                                    <Plus class="size-4" /> Add diagnosis
                                </Button>
                            </div>
                        </div>
                        <InputError :message="form.errors.diagnoses" />
                        <div
                            v-for="(diagnosis, index) in form.diagnoses"
                            :key="index"
                            class="grid gap-2 rounded-md border p-2 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)]"
                        >
                            <label class="text-xs"
                                >Diagnosis
                                <input
                                    v-model="diagnosis.diagnosis_text"
                                    maxlength="500"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            `diagnoses.${index}.diagnosis_text`
                                        ]
                                    "
                                />
                            </label>
                            <label class="text-xs"
                                >Code
                                <input
                                    v-model="diagnosis.diagnosis_code"
                                    maxlength="50"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            `diagnoses.${index}.diagnosis_code`
                                        ]
                                    "
                                />
                            </label>
                            <label class="text-xs"
                                >Code system
                                <input
                                    v-model="diagnosis.code_system"
                                    maxlength="50"
                                    class="mt-1 h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            `diagnoses.${index}.code_system`
                                        ]
                                    "
                                />
                            </label>
                            <label
                                class="flex items-center gap-2 self-center text-sm"
                            >
                                <input
                                    type="radio"
                                    :checked="diagnosis.is_primary"
                                    @change="setPrimary(index)"
                                />
                                Primary
                            </label>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                class="self-center"
                                aria-label="Remove diagnosis"
                                @click="removeDiagnosis(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                        <p
                            v-if="!form.diagnoses.length"
                            class="text-xs text-muted-foreground"
                        >
                            No diagnosis recorded yet.
                        </p>
                    </section>
                </form>
            </div>
            <aside
                id="consultation-treatment"
                data-testid="consultation-right"
                class="min-w-0 space-y-3 lg:sticky lg:top-3 lg:self-start"
            >
                <nav
                    aria-label="Consultation panels"
                    class="flex gap-2 rounded-md border bg-card p-1"
                >
                    <button
                        type="button"
                        :aria-current="
                            workspaceTab === 'treatment' ? 'page' : undefined
                        "
                        :class="[
                            'rounded px-3 py-2 text-sm font-medium',
                            workspaceTab === 'treatment'
                                ? 'bg-muted'
                                : 'text-muted-foreground',
                        ]"
                        @click="workspaceTab = 'treatment'"
                    >
                        Treatment plan
                    </button>
                    <button
                        type="button"
                        :aria-current="
                            workspaceTab === 'history' ? 'page' : undefined
                        "
                        :class="[
                            'rounded px-3 py-2 text-sm font-medium',
                            workspaceTab === 'history'
                                ? 'bg-muted'
                                : 'text-muted-foreground',
                        ]"
                        @click="workspaceTab = 'history'"
                    >
                        Patient history
                    </button>
                </nav>
                <div
                    v-show="workspaceTab === 'treatment'"
                    data-testid="consultation-treatment-pane"
                >
                    <TreatmentPlanPanel
                        ref="treatmentPlanPanelRef"
                        :visit-number="clinical.visit.visitNumber"
                        :branch-id="clinical.branch.id"
                        :plan="clinical.treatmentPlan"
                        :visit-version="clinical.visit.lockVersion"
                        :queue-version="clinical.queue.lockVersion"
                        :encounter-version="clinical.encounter.lockVersion"
                        :allergies="clinical.allergies"
                    />
                </div>
                <div
                    v-show="workspaceTab === 'history'"
                    data-testid="consultation-history-pane"
                    class="min-h-0 rounded-lg border bg-card p-4 lg:h-[calc(100dvh-12rem)] lg:[&>div]:h-full"
                >
                    <ClinicalHistoryPanel
                        :history="clinical.history"
                        :time-zone="clinical.branch.timezone"
                    />
                </div>
            </aside>
        </div>

        <UnsavedClinicalWorkDialog
            :open="unsavedWorkOpen"
            :unsaved-items="unsavedItems"
            :processing="unsavedWorkProcessing"
            :processing-action="unsavedWorkAction"
            :error="unsavedWorkError"
            @update:open="unsavedWorkOpen = $event"
            @save-and-hold="saveAndHold"
            @hold-without-saving="holdWithoutSaving"
        />
    </main>
</template>

<style scoped>
.consultation-workspace {
    font-size: 12.5px;
    line-height: 1.5;
}
.consultation-workspace :deep(.text-sm) {
    font-size: 12.5px;
}
.consultation-workspace :deep(.text-xs) {
    font-size: 11.5px;
}
.consultation-workspace :deep(h2),
.consultation-workspace :deep(h3) {
    font-size: 13px;
}
.consultation-workspace :deep(button),
.consultation-workspace :deep(button[class]) {
    height: 30px;
    font-size: 12px;
    padding-inline: 8px;
}
.consultation-workspace :deep(#clinical-safety button) {
    padding-inline: 4px;
}
.consultation-workspace :deep(button[aria-label='Open user menu']) {
    width: 30px;
    padding-inline: 0;
}
.consultation-workspace :deep(button:not(:focus-visible)) {
    box-shadow: none;
}
.consultation-workspace :deep(button[aria-label^='Move ']),
.consultation-workspace :deep(button[aria-label^='Withdraw ']),
.consultation-workspace :deep(button[aria-label='Remove diagnosis']) {
    width: 28px;
    padding-inline: 4px;
}
.consultation-workspace
    :deep(input:not([type='radio']):not([type='checkbox'])) {
    height: 30px;
    font-size: 12px;
}
.consultation-workspace :deep(textarea) {
    font-size: 12.5px;
}
</style>

<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ClipboardCheck,
    ExternalLink,
    LoaderCircle,
    Megaphone,
    Plus,
    PauseCircle,
    PlayCircle,
    UserRound,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DiagnosisPresetPicker from '@/components/clinical/DiagnosisPresetPicker.vue';
import FamilyWaitingCard from '@/components/clinical/FamilyWaitingCard.vue';
import UnsavedClinicalWorkContinueDialog from '@/components/clinical/UnsavedClinicalWorkContinueDialog.vue';
import UnsavedClinicalWorkDialog from '@/components/clinical/UnsavedClinicalWorkDialog.vue';
import WaitingPatientsButton from '@/components/clinical/WaitingPatientsButton.vue';
import SupervisorCheckNote from '@/components/dispensary/SupervisorCheckNote.vue';
import InputError from '@/components/InputError.vue';
import HeldCallNext from '@/components/queue/HeldCallNext.vue';
import RedZoneAlertBanner from '@/components/queue/RedZoneAlertBanner.vue';
import TreatmentProgressActions from '@/components/queue/TreatmentProgressActions.vue';
import TreatmentProgressBadge from '@/components/queue/TreatmentProgressBadge.vue';
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
import {
    crossesRedLimit,
    parseVitalsFromNote,
    planFill,
} from '@/lib/vitals-from-note';
import type { NoteReading } from '@/lib/vitals-from-note';
import AllergyProblemPanel from '@/pages/Clinical/Partials/AllergyProblemPanel.vue';
import ClinicalHistoryPanel from '@/pages/Clinical/Partials/ClinicalHistoryPanel.vue';
import DispensaryAttentionPanel from '@/pages/Clinical/Partials/DispensaryAttentionPanel.vue';
import DispensaryCheckPanel from '@/pages/Clinical/Partials/DispensaryCheckPanel.vue';
import TreatmentPlanPanel from '@/pages/Clinical/Partials/TreatmentPlanPanel.vue';
import type { ClinicalEncounterPage, TreatmentProgress } from '@/types';
import { myrNoBreak } from '@/types/billing';

defineOptions({
    layout: {
        focusMode: true,
        breadcrumbs: [{ title: 'Queue', href: '/queue' }],
    },
});

const props = defineProps<{
    clinical: ClinicalEncounterPage;
    treatmentRooms?: Array<{ id: number; name: string }>;
    // Q-2: the Serving consultation's treatment step (board only; never the TV).
    treatmentProgress?: TreatmentProgress | null;
    // BIL-37: the doctor's read-only bill of this visit (only once a finalised invoice exists).
    billViewUrl?: string | null;
}>();
const page = usePage();
const user = computed(() => page.props.auth.user!);
// Show the authorized visit note only when non-empty.
const panelNote = computed(() => props.clinical.visit.panelNote);
const consultationCompleted = computed(
    () => props.clinical.treatmentPlan.checkout !== null,
);
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

// Q-11 (owner decisions 2026-11-21): vitals the doctor typed into the note are filled straight into the EMPTY vitals boxes (rule-based, in the
// browser; no AI service). The note is never edited, a box that already has a value is never overwritten, and nothing is saved until the
// doctor presses Save clinical record. Undo clears what was auto-filled.
const noteForVitals = ref(form.clinical_note);
let noteVitalsTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => form.clinical_note,
    (value) => {
        clearTimeout(noteVitalsTimer);
        noteVitalsTimer = setTimeout(() => {
            noteForVitals.value = value;
        }, 800);
    },
);
onBeforeUnmount(() => clearTimeout(noteVitalsTimer));
const noteVitals = computed(() => parseVitalsFromNote(noteForVitals.value));
const filledReadings = ref<NoteReading[]>([]);
// Only readings whose boxes still hold what KPOne put there: once the doctor edits a box it drops off this line.
const stillAutoFilled = computed(() =>
    filledReadings.value.filter((reading) =>
        Object.entries(reading.values).every(
            ([field, value]) =>
                form.vitals[field as keyof typeof form.vitals] === value,
        ),
    ),
);
const filledFromNote = computed(() =>
    stillAutoFilled.value.map((reading) => reading.label),
);
// Filled readings at or past the guideline's red limit: still filled, but flagged "Check" so a typo in the note is not missed.
const filledChecks = computed(() =>
    stillAutoFilled.value
        .filter((reading) =>
            crossesRedLimit(reading.values, props.clinical.vitalLimits),
        )
        .map((reading) => reading.label),
);
// What KPOne itself put in each box. A box still holding that value counts as empty, so "temp 38" then "38.1" updates it;
// a box the doctor changed is theirs and is never touched.
const autoValues: Partial<Record<keyof typeof form.vitals, string>> = {};
// Field and value pairs already applied once, so Undo (or clearing a box) is not undone by the next typing pause.
const appliedFromNote = new Set<string>();
const boxesForPlan = () => {
    const boxes = { ...form.vitals } as Record<string, string>;

    for (const [field, value] of Object.entries(autoValues)) {
        if (boxes[field] === value) {
            boxes[field] = '';
        }
    }

    return boxes as typeof form.vitals;
};
const noteVitalsPlan = computed(() =>
    planFill(noteVitals.value, boxesForPlan()),
);
const showNoteVitals = computed(
    () =>
        noteVitalsPlan.value.skipped.length > 0 ||
        noteVitals.value.notRead.length > 0,
);
const autoFillVitalsFromNote = () => {
    const plan = noteVitalsPlan.value;
    const touched: (keyof typeof form.vitals)[] = [];

    for (const [field, value] of Object.entries(plan.fill)) {
        const key = `${field}:${value}`;

        if (appliedFromNote.has(key)) {
            continue;
        }

        appliedFromNote.add(key);
        form.vitals[field as keyof typeof form.vitals] = value;
        autoValues[field as keyof typeof form.vitals] = value;
        touched.push(field as keyof typeof form.vitals);
    }

    if (touched.length === 0) {
        return;
    }

    // Everything KPOne has put in the boxes and the doctor has not changed (so a later update never drops an earlier fill).
    const autoRead = noteVitals.value.readings.filter((reading) =>
        Object.entries(reading.values).every(
            ([field, value]) =>
                autoValues[field as keyof typeof form.vitals] === value &&
                form.vitals[field as keyof typeof form.vitals] === value,
        ),
    );
    filledReadings.value = autoRead;
    // Focus is never moved: the doctor is usually still typing in the note.
    vitalsExpanded.value = true;
};
// Fill only after a typing pause, never on load: a saved record that is reopened is not refilled.
watch(noteForVitals, () => {
    autoFillVitalsFromNote();
});
const undoNoteVitals = () => {
    for (const [field, value] of Object.entries(autoValues)) {
        if (form.vitals[field as keyof typeof form.vitals] === value) {
            form.vitals[field as keyof typeof form.vitals] = '';
        }

        delete autoValues[field as keyof typeof form.vitals];
    }

    filledReadings.value = [];
};
const vitalsUnsaved = computed(
    () => JSON.stringify(form.vitals) !== JSON.stringify(vitalsFromProps()),
);
// Once the record is saved the "check them" line has done its job.
watch(
    () => form.recentlySuccessful,
    (saved) => {
        if (saved) {
            filledReadings.value = [];
        }
    },
);

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
/** Q-4b: Call next can be pressed now (the held notice only offers it then). */
const callNextAvailable = ref(false);
/** Q-4b: a Call next request is being sent, so Hold, Resume and the room calls wait. */
const callNextProcessing = ref(false);
// Q-4b: the doctor's own queue only; a branch caller uses the queue board.
const canCallNextWhileHeld = computed(
    () =>
        page.props.auth.permissions.includes('queue.call.own') &&
        !page.props.auth.permissions.includes('queue.view.branch'),
);
const recallError = ref('');
// Which call button the error belongs to, so a "Call again" error is not shown under the treatment room controls.
const recallErrorFor = ref<'recall' | 'treatment'>('recall');
const recallPatient = () => {
    recallProcessing.value = true;
    recallErrorFor.value = 'recall';
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
                    'This patient cannot be called again.';
            },
            onFinish: () => (recallProcessing.value = false),
        },
    );
};
// Calls the patient to a treatment room on the TV; the consultation stays with this doctor.
const treatmentRoomId = ref<string | number>(
    props.treatmentRooms?.length === 1 ? props.treatmentRooms[0].id : '',
);
// Choosing a room clears a treatment room error, so the red state does not outlive the fix.
watch(treatmentRoomId, () => {
    if (recallErrorFor.value === 'treatment') {
        recallError.value = '';
    }
});
// The error sits under the treatment room controls only when it came from the treatment room button and they are shown.
const treatmentError = computed(
    () =>
        recallError.value !== '' &&
        recallErrorFor.value === 'treatment' &&
        props.clinical.queue.canRecall &&
        (props.treatmentRooms?.length ?? 0) > 0,
);
const callToTreatment = () => {
    recallErrorFor.value = 'treatment';

    if (treatmentRoomId.value === '') {
        recallError.value = 'Choose a treatment room first.';

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
                    'This patient cannot be called to the treatment room.';
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

// Q-14 (owner decision 21 Nov 2026): Complete Consultation never goes ahead with unsaved clinical work. The panel's button only
// asks (request-complete); with nothing unsaved the existing "Complete consultation?" dialog opens as before, otherwise the doctor
// first chooses. Either choice ends in that same confirm dialog (so the service dispositions are still confirmed), never in a
// silent completion.
const continueOpen = ref(false);
const continueProcessing = ref(false);
const continueAction = ref<'confirm' | 'secondary'>('confirm');
const continueError = ref('');
const requestComplete = () => {
    if (form.processing || continueProcessing.value) {
        return;
    }

    if (!hasUnsavedWork.value) {
        treatmentPlanPanelRef.value?.openComplete();

        return;
    }

    continueError.value = '';
    continueOpen.value = true;
};
const saveAndContinue = () => {
    continueAction.value = 'confirm';
    continueProcessing.value = true;
    continueError.value = '';

    const proceed = () => {
        continueOpen.value = false;
        continueProcessing.value = false;
        treatmentPlanPanelRef.value?.openComplete();
    };
    const saveTreatmentPlanThenContinue = () => {
        const panel = treatmentPlanPanelRef.value;

        if (!panel) {
            proceed();

            return;
        }

        panel.saveDraft(proceed, (message) => {
            continueError.value = message;
            continueProcessing.value = false;
        });
    };

    if (!form.isDirty) {
        saveTreatmentPlanThenContinue();

        return;
    }

    form.patch(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/encounter`,
        {
            preserveScroll: true,
            onSuccess: () => {
                adoptLatestRecord();
                saveTreatmentPlanThenContinue();
            },
            onError: () => {
                continueError.value =
                    Object.values(form.errors)[0] ??
                    'The clinical record could not be saved. Review the latest information and try again.';
                continueProcessing.value = false;
            },
        },
    );
};
const continueWithoutSaving = () => {
    continueAction.value = 'secondary';
    form.reset();
    form.clearErrors();
    treatmentPlanPanelRef.value?.discardDraft();
    continueOpen.value = false;
    treatmentPlanPanelRef.value?.openComplete();
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
    if (consultationCompleted.value) {
        return;
    }

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
        <RedZoneAlertBanner
            v-if="
                page.props.auth.permissions.includes('queue.call.own') ||
                page.props.auth.permissions.includes(
                    'visits.triage.record.branch',
                )
            "
            :branch-id="clinical.branch.id"
            :from-visit="clinical.visit.visitNumber"
        />
        <header
            class="flex flex-wrap items-center justify-between gap-3 px-1 py-1"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    href="/queue"
                    class="inline-flex items-center gap-1 text-xs font-medium text-success hover:underline"
                >
                    <ArrowLeft class="size-3.5" /> My Queue
                </Link>
                <span class="rounded-md border bg-card px-2 py-1 text-xs">{{
                    clinical.encounter.attendingClinician
                }}</span>
                <span class="text-[11px] text-muted-foreground"
                    >{{ clinical.visit.visitNumber }} ·
                    {{
                        consultationCompleted
                            ? 'Completed'
                            : clinical.hold.isHeld
                              ? 'On Hold'
                              : 'In progress'
                    }}</span
                >
                <span class="text-[11px] text-muted-foreground"
                    >{{ waitedDurationLabel(waitedMinutes) }} · Active
                    {{ clinical.hold.activeMinutes }} min · Held
                    {{ clinical.hold.heldMinutes }} min</span
                >
                <span
                    v-if="treatmentProgress?.state"
                    class="inline-flex max-w-full"
                >
                    <TreatmentProgressBadge
                        :progress="treatmentProgress"
                        live
                    />
                </span>
                <p
                    v-if="consultationCompleted"
                    class="w-full text-xs text-muted-foreground"
                >
                    Completed means the consultation was handed off, not that
                    payment is finished.
                </p>
            </div>
            <div
                class="flex flex-wrap items-center justify-end gap-2 text-right text-xs text-muted-foreground max-sm:justify-start max-sm:text-left"
            >
                <Button
                    v-if="
                        page.props.auth.permissions.includes(
                            'medical_certificates.view.own',
                        )
                    "
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="`/visits/${clinical.visit.visitNumber}/medical-certificates`"
                        target="_blank"
                        rel="noopener"
                        >Medical certificates<ExternalLink
                            class="size-3.5"
                            aria-hidden="true"
                        /><span class="sr-only">(opens in a new tab)</span></a
                    ></Button
                >
                <Button
                    v-if="
                        page.props.auth.permissions.includes(
                            'referrals.view.own',
                        )
                    "
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="`/visits/${clinical.visit.visitNumber}/referrals`"
                        target="_blank"
                        rel="noopener"
                        >Referral letters<ExternalLink
                            class="size-3.5"
                            aria-hidden="true"
                        /><span class="sr-only">(opens in a new tab)</span></a
                    ></Button
                >
                <Button
                    v-if="
                        page.props.auth.permissions.includes(
                            'attendance_documents.view.own',
                        )
                    "
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="`/visits/${clinical.visit.visitNumber}/attendance`"
                        target="_blank"
                        rel="noopener"
                        >Attendance confirmation<ExternalLink
                            class="size-3.5"
                            aria-hidden="true"
                        /><span class="sr-only">(opens in a new tab)</span></a
                    ></Button
                >
                <!-- BIL-37 (Daniel): the doctor's read-only bill, same tab, so no external-link icon. -->
                <Button
                    v-if="billViewUrl"
                    variant="outline"
                    size="sm"
                    class="h-9 text-[14px] max-lg:h-11"
                    as-child
                >
                    <Link
                        :href="billViewUrl"
                        data-testid="consultation-view-bill"
                        >View bill</Link
                    ></Button
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
                    :disabled="recallProcessing || callNextProcessing"
                    data-testid="recall-patient"
                    @click="recallPatient"
                >
                    <Megaphone class="size-4" /> Call again
                </Button>
                <div
                    v-if="
                        clinical.queue.canRecall &&
                        (treatmentRooms?.length ?? 0) > 0
                    "
                    class="flex flex-col gap-1"
                >
                    <div class="flex items-center gap-2">
                        <OperationalSelect
                            v-if="(treatmentRooms?.length ?? 0) > 1"
                            v-model="treatmentRoomId"
                            label="Treatment room"
                            placeholder="Treatment room"
                            trigger-class="h-8 w-40"
                            :describedby="
                                treatmentError ? 'recall-error' : undefined
                            "
                            :invalid="treatmentError"
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
                            :disabled="recallProcessing || callNextProcessing"
                            :aria-describedby="
                                treatmentError ? 'recall-error' : undefined
                            "
                            data-testid="call-treatment"
                            @click="callToTreatment"
                        >
                            <Megaphone class="size-4" /> Call to treatment room
                        </Button>
                    </div>
                    <InputError
                        v-if="treatmentError"
                        id="recall-error"
                        class="max-w-72"
                        :message="recallError"
                    />
                </div>
                <InputError
                    v-if="recallError && !treatmentError"
                    class="max-w-56 text-right"
                    :message="recallError"
                />
                <TreatmentProgressActions
                    v-if="
                        treatmentProgress?.canManage && !consultationCompleted
                    "
                    :progress="treatmentProgress"
                    :visit-number="clinical.visit.visitNumber"
                    :branch-id="clinical.branch.id"
                    from="consultation"
                    inline="always"
                    compact
                    :subject="clinical.patient.name"
                />
                <Button
                    v-if="clinical.hold.canHold"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="holdProcessing || callNextProcessing"
                    @click="requestHold"
                >
                    <PauseCircle class="size-4" /> On Hold
                </Button>
                <Button
                    v-if="clinical.hold.canResume"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="holdProcessing || callNextProcessing"
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
                                    class="inline-flex rounded-md border border-destructive/25 bg-destructive/10 px-2 py-1 text-xs font-medium text-destructive"
                                    >Urgent</span
                                >
                                <!-- TRI-1c: the triage zone (the word always shown, never colour alone). -->
                                <span
                                    v-if="clinical.visit.triage?.triaged"
                                    class="inline-flex items-center gap-1 rounded-md border border-border bg-background px-2 py-1 text-xs font-medium"
                                    :class="
                                        clinical.visit.triage.zone === 'red'
                                            ? 'text-destructive'
                                            : clinical.visit.triage.zone ===
                                                'yellow'
                                              ? 'text-warning-strong'
                                              : 'text-foreground'
                                    "
                                    data-testid="consultation-triage-zone"
                                    ><span
                                        v-if="clinical.visit.triage.zone"
                                        aria-hidden="true"
                                        class="size-2 shrink-0 rounded-full"
                                        :class="
                                            {
                                                red: 'bg-destructive',
                                                yellow: 'bg-warning',
                                                green: 'bg-success',
                                            }[clinical.visit.triage.zone]
                                        "
                                    />{{
                                        clinical.visit.triage.zone
                                            ? {
                                                  red: 'Red zone',
                                                  yellow: 'Yellow zone',
                                                  green: 'Green zone',
                                              }[clinical.visit.triage.zone]
                                            : 'Triaged'
                                    }}</span
                                >
                                <!-- TRI-1e: the own doctor reads the whole triage record (view only, audited). -->
                                <Link
                                    v-if="clinical.visit.triageViewUrl"
                                    :href="clinical.visit.triageViewUrl"
                                    class="inline-flex min-h-11 items-center px-1 text-xs font-medium text-brand-strong underline underline-offset-4 lg:min-h-0"
                                    data-testid="consultation-view-triage"
                                    >View triage</Link
                                >
                            </div>
                            <div
                                class="flex shrink-0 flex-col items-end gap-2 text-right text-xs text-muted-foreground"
                            >
                                <Button
                                    v-if="canViewPatient"
                                    variant="outline"
                                    as-child
                                    class="h-11 text-foreground lg:h-9"
                                >
                                    <Link
                                        :href="`/patients/${encodeURIComponent(clinical.patient.patientNumber)}`"
                                        >View profile</Link
                                    >
                                </Button>
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
                            <p
                                v-if="clinical.visit.panelEstimate?.over"
                                data-testid="consultation-panel-estimate"
                                class="flex items-start gap-2 text-xs text-warning-strong"
                            >
                                <span
                                    aria-hidden="true"
                                    class="mt-1 size-2 shrink-0 rounded-full bg-current"
                                />
                                <span>
                                    Estimated Panel total
                                    {{
                                        myrNoBreak(
                                            clinical.visit.panelEstimate
                                                .totalSen,
                                        )
                                    }}
                                    is over the
                                    {{
                                        myrNoBreak(
                                            clinical.visit.panelEstimate
                                                .limitSen,
                                        )
                                    }}
                                    limit. Estimate only<template
                                        v-if="
                                            clinical.visit.panelEstimate.partial
                                        "
                                        >: some items have no Panel price
                                        yet.</template
                                    ><template v-else>.</template>
                                </span>
                            </p>
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

                <FamilyWaitingCard
                    v-if="clinical.familyWaiting"
                    :family="clinical.familyWaiting"
                />

                <DispensaryAttentionPanel
                    :items="clinical.dispensaryAttention"
                />

                <DispensaryCheckPanel
                    v-if="clinical.dispensaryCheck"
                    :check="clinical.dispensaryCheck"
                />
                <SupervisorCheckNote
                    v-if="clinical.dispensaryCheckedInstead"
                    :check="clinical.dispensaryCheckedInstead"
                />

                <div
                    v-if="stateError"
                    role="alert"
                    class="flex items-start gap-2 rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ stateError }}
                </div>
                <div
                    v-if="holdError"
                    role="alert"
                    class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
                >
                    {{ holdError }}
                </div>
                <!-- Q-4b: while held, the doctor can call their next patient from here (Yasmin's sketch). -->
                <div
                    v-if="clinical.hold.isHeld"
                    role="status"
                    class="flex flex-col gap-3 rounded-lg border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong sm:flex-row sm:items-center sm:justify-between"
                    data-testid="held-notice"
                >
                    <p class="min-w-0">
                        This patient stays in Serving Now. Resume the
                        consultation before saving or completing clinical work.
                        <template
                            v-if="
                                callNextAvailable &&
                                !holdProcessing &&
                                !recallProcessing
                            "
                            >You can call your next patient meanwhile.</template
                        >
                    </p>
                    <div
                        v-if="canCallNextWhileHeld"
                        class="shrink-0 sm:max-w-sm [&_#call-next-reason]:text-warning-strong"
                    >
                        <HeldCallNext
                            :branch-id="clinical.branch.id"
                            :visit-number="clinical.visit.visitNumber"
                            :busy="holdProcessing || recallProcessing"
                            @available="callNextAvailable = $event"
                            @processing="callNextProcessing = $event"
                        />
                    </div>
                </div>
                <div
                    v-if="recordDrifted"
                    role="alert"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong"
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
                        class="flex min-w-0 items-baseline gap-2 rounded-md border bg-card-quiet px-3 py-2"
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
                        class="space-y-1 rounded-md border bg-card-quiet px-3 py-2"
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
                        <!-- TRI-1c: the vitals were copied from triage when the consultation started. TRI-1d (Daniel): while the
                             doctor has unsaved changes the label stays, marked "(edited)" ("Not saved yet" stays on the summary
                             line); saving a change clears the triage source on the server, so the label goes. -->
                        <p
                            v-if="clinical.vitals.fromTriage"
                            class="text-xs text-muted-foreground"
                            data-testid="vitals-from-triage"
                        >
                            From triage, last taken by
                            {{
                                clinical.vitals.fromTriage.recordedBy ??
                                'a clinic assistant'
                            }}
                            at {{ clinical.vitals.fromTriage.recordedAt
                            }}<span
                                v-if="vitalsUnsaved"
                                class="font-medium text-warning-strong"
                                data-testid="vitals-from-triage-edited"
                            >
                                (edited)</span
                            >.
                        </p>
                        <div
                            v-if="hasVitals"
                            class="flex min-w-0 flex-wrap gap-x-2 gap-y-1 text-xs"
                            data-testid="vitals-summary"
                        >
                            <span
                                v-if="vitalsUnsaved"
                                class="font-medium text-warning-strong"
                                data-testid="vitals-unsaved"
                                >Not saved yet</span
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
                                    data-vitals-field="systolic_bp"
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
                                    data-vitals-field="diastolic_bp"
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
                                    data-vitals-field="pulse_bpm"
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
                                    data-vitals-field="temperature_celsius"
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
                                    data-vitals-field="spo2_percent"
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
                                    data-vitals-field="weight_kg"
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
                                    data-vitals-field="height_cm"
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
                            <div class="rounded-md bg-card-quiet p-3 text-sm">
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
                                id="clinical-note-heading"
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
                                aria-labelledby="clinical-note-heading"
                                maxlength="20000"
                                rows="3"
                                class="block min-h-20 w-full resize-y rounded-t-md border-0 bg-card p-3 text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
                                placeholder="Record the clinical consultation note…"
                            />
                            <InputError :message="form.errors.clinical_note" />
                            <div
                                v-if="showNoteVitals || filledFromNote.length"
                                role="status"
                                aria-live="polite"
                                data-testid="note-vitals-suggestion"
                                class="space-y-2 border-t bg-card-quiet px-3 py-2 text-xs"
                            >
                                <div
                                    v-if="filledFromNote.length"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2"
                                >
                                    <p
                                        class="min-w-0 basis-full sm:flex-1 sm:basis-0"
                                    >
                                        Filled from your note:
                                        {{ filledFromNote.join(' · ') }}. Check
                                        them, then save.
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="max-lg:h-11"
                                        data-testid="note-vitals-undo"
                                        @click="undoNoteVitals"
                                        >Undo</Button
                                    >
                                </div>
                                <p
                                    v-if="
                                        filledFromNote.length &&
                                        filledChecks.length
                                    "
                                    class="flex items-start gap-1.5 font-medium text-warning-strong"
                                    data-testid="note-vitals-check"
                                >
                                    <AlertTriangle
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >Check:
                                        {{ filledChecks.join(', ') }} (at or
                                        past the clinic's red limit; is it a
                                        typo in the note?)
                                        <span
                                            v-if="clinical.vitalLimits"
                                            class="font-normal text-muted-foreground"
                                            >Triage guideline v{{
                                                clinical.vitalLimits
                                                    .versionNumber
                                            }}.</span
                                        ></span
                                    >
                                </p>
                                <p
                                    v-if="noteVitalsPlan.skippedDetails.length"
                                    class="text-muted-foreground"
                                >
                                    Not filled:
                                    {{
                                        noteVitalsPlan.skippedDetails
                                            .map(
                                                (item) =>
                                                    `${item.label} (box already has ${item.current})`,
                                            )
                                            .join(', ')
                                    }}.
                                </p>
                                <p
                                    v-if="
                                        showNoteVitals &&
                                        noteVitals.notRead.length
                                    "
                                    class="text-muted-foreground"
                                >
                                    Not read:
                                    {{ noteVitals.notRead.join(', ') }}. Enter
                                    them in the vitals boxes in the units KPOne
                                    takes.
                                </p>
                            </div>
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
                                        variant="outline"
                                        :disabled="
                                            form.processing ||
                                            clinical.hold.isHeld ||
                                            consultationCompleted
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
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            workspaceTab === 'treatment' ? 'secondary' : 'ghost'
                        "
                        :aria-pressed="workspaceTab === 'treatment'"
                        class="h-11 lg:h-9"
                        @click="workspaceTab = 'treatment'"
                    >
                        Treatment plan
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            workspaceTab === 'history' ? 'secondary' : 'ghost'
                        "
                        :aria-pressed="workspaceTab === 'history'"
                        class="h-11 lg:h-9"
                        @click="workspaceTab = 'history'"
                    >
                        Patient history
                    </Button>
                </nav>
                <div
                    v-show="workspaceTab === 'treatment'"
                    data-testid="consultation-treatment-pane"
                >
                    <TreatmentPlanPanel
                        ref="treatmentPlanPanelRef"
                        @request-complete="requestComplete"
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
        <UnsavedClinicalWorkContinueDialog
            :open="continueOpen"
            :unsaved-items="unsavedItems"
            :processing="continueProcessing"
            :processing-action="continueAction"
            :error="continueError"
            @update:open="continueOpen = $event"
            @save-and-continue="saveAndContinue"
            @continue-without-saving="continueWithoutSaving"
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

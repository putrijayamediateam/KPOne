import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { appendPresetDiagnosis } from '@/lib/diagnosis-draft';
import type TreatmentPlanPanel from '@/pages/Clinical/Partials/TreatmentPlanPanel.vue';
import type { ClinicalEncounterPage, TreatmentProgress } from '@/types';

/**
 * Version 2 consultation page logic: a copy of the script of Classic `pages/Clinical/Show.vue` (which stays untouched),
 * so the V2 page keeps exactly the same form state, drift and adopt rules, unsaved-work guard, hold and resume, recall,
 * treatment-room call and held Call next, with the same endpoints, payload keys, lock versions and idempotency keys.
 * `tests/Frontend/ui-v2-clinical-parity.test.mjs` keeps the two equal.
 */
export type ConsultationV2Props = {
    clinical: ClinicalEncounterPage;
    treatmentRooms?: Array<{ id: number; name: string }>;
    treatmentProgress?: TreatmentProgress | null;
};

export const useConsultationV2 = (props: ConsultationV2Props) => {
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
        Object.values(form.vitals).some(
            (value) => value !== null && value !== '',
        ),
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
        () =>
            form.clinical_note !==
            (props.clinical.encounter.clinicalNote ?? ''),
    );
    const vitalsDirty = computed(
        () => JSON.stringify(form.vitals) !== JSON.stringify(vitalsFromProps()),
    );
    const diagnosesDirty = computed(
        () =>
            JSON.stringify(form.diagnoses) !==
            JSON.stringify(diagnosesFromProps()),
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
    const hasUnsavedWork = computed(
        () => form.isDirty || treatmentPlanDirty.value,
    );
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

    return {
        page,
        user,
        panelNote,
        consultationCompleted,
        canViewPatient,
        workspaceTab,
        patientAge,
        holdProcessing,
        holdError,
        vitalsExpanded,
        hasVitals,
        vitalsFromProps,
        diagnosesFromProps,
        form,
        recordDrifted,
        adoptLatestRecord,
        treatmentPlanPanelRef,
        noteDirty,
        vitalsDirty,
        diagnosesDirty,
        treatmentPlanDirty,
        unsavedItems,
        hasUnsavedWork,
        unsavedWorkOpen,
        unsavedWorkProcessing,
        unsavedWorkAction,
        unsavedWorkError,
        recallProcessing,
        callNextAvailable,
        callNextProcessing,
        canCallNextWhileHeld,
        recallError,
        recallErrorFor,
        recallPatient,
        treatmentRoomId,
        treatmentError,
        callToTreatment,
        requestHold,
        saveAndHold,
        holdWithoutSaving,
        bmi,
        stateError,
        waitedMinutes,
        addDiagnosis,
        choosePreset,
        removeDiagnosis,
        setPrimary,
        save,
        changeHoldState,
    };
};

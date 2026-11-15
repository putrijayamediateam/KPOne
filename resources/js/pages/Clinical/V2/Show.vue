<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ExternalLink,
    LoaderCircle,
    Megaphone,
    PauseCircle,
    PlayCircle,
    Plus,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import DiagnosisPresetPicker from '@/components/clinical/DiagnosisPresetPicker.vue';
import WaitingPatientsButton from '@/components/clinical/WaitingPatientsButton.vue';
import ConsultationMoreMenu from '@/components/clinical-v2/ConsultationMoreMenu.vue';
import UnsavedWorkDialogV2 from '@/components/clinical-v2/UnsavedWorkDialogV2.vue';
import InputError from '@/components/InputError.vue';
import HeldCallNext from '@/components/queue/HeldCallNext.vue';
import TreatmentProgressActions from '@/components/queue/TreatmentProgressActions.vue';
import TreatmentProgressBadge from '@/components/queue/TreatmentProgressBadge.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import UserMenuContent from '@/components/UserMenuContent.vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import WorkspaceBranchSwitcher from '@/components/workspace/WorkspaceBranchSwitcher.vue';
import { useConsultationV2 } from '@/composables/clinical/useConsultationV2';
import type { ConsultationV2Props } from '@/composables/clinical/useConsultationV2';
import { getInitials } from '@/composables/useInitials';
import { waitedDurationLabel } from '@/lib/r1c2-presentation';
import AllergyProblemPanel from '@/pages/Clinical/Partials/AllergyProblemPanel.vue';
import ClinicalHistoryPanel from '@/pages/Clinical/Partials/ClinicalHistoryPanel.vue';
import DispensaryAttentionPanel from '@/pages/Clinical/Partials/DispensaryAttentionPanel.vue';
import TreatmentPlanPanel from '@/pages/Clinical/Partials/TreatmentPlanPanel.vue';

/**
 * Version 2 consultation page, the shell only (shown only in V2; Classic is `pages/Clinical/Show.vue`, untouched).
 * The page chrome and the clinical record column are drawn on Version 2 blocks. The four Classic Partials
 * (Treatment plan, Allergy and problems, Patient history, Dispensary attention) are reused unchanged inside a wrapper
 * that keeps their Classic density; they are restyled in a later step. Same props, routes, permissions, lock versions,
 * idempotency keys and payloads: the logic is a copy in `composables/clinical/useConsultationV2.ts`, kept equal by
 * `ui-v2-clinical-parity.test.mjs`. Focus mode (no top bar) comes from the layout option below, as in Classic.
 */
defineOptions({
    layout: {
        focusMode: true,
        breadcrumbs: [{ title: 'Queue', href: '/queue' }],
    },
});

const props = defineProps<ConsultationV2Props>();
const {
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
    form,
    recordDrifted,
    adoptLatestRecord,
    treatmentPlanPanelRef,
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
    treatmentRoomId,
    treatmentError,
    recallPatient,
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
} = useConsultationV2(props);

// Unsaved clinical work (the record form and the treatment plan draft) is guarded on every way out of this page.
// V2LeaveGuard holds a link or button to another page (Back to My Queue, the menu) and browser Back, and warns on
// reload or closing the tab. Switching branch is a POST, which the shared rule never holds, so it is held here and asked
// through the same dialog. Hold has its own question (Save and hold / Discard and hold). Call again, the treatment-room
// call and the treatment step keep the page and the unsaved text, so they ask nothing.
const leaveGuard = ref<InstanceType<typeof V2LeaveGuard> | null>(null);
let replaying = false;
const stopBranchGuard = router.on('before', (event) => {
    const visit = event.detail.visit;

    if (
        replaying ||
        !hasUnsavedWork.value ||
        form.processing ||
        String(visit.method).toLowerCase() === 'get'
    ) {
        return;
    }

    if (
        new URL(visit.url.toString(), window.location.origin).pathname !==
        '/branch-context'
    ) {
        return;
    }

    leaveGuard.value?.ask(() => {
        replaying = true;
        router.post('/branch-context', visit.data as Record<string, never>, {
            preserveScroll: true,
            onFinish: () => (replaying = false),
        });
    });

    return false;
});
onBeforeUnmount(stopBranchGuard);
const discardUnsaved = () => {
    form.reset();
    form.clearErrors();
    treatmentPlanPanelRef.value?.discardDraft();
};
const documentLinks = () =>
    [
        [
            'medical_certificates.view.own',
            'mc',
            'Medical certificates',
            'medical-certificates',
        ],
        ['referrals.view.own', 'referral', 'Referral letters', 'referrals'],
        [
            'attendance_documents.view.own',
            'attendance',
            'Attendance confirmation',
            'attendance',
        ],
    ]
        .filter(([permission]) =>
            page.props.auth.permissions.includes(permission),
        )
        .map(([, id, label, path]) => ({
            id,
            label,
            href: `/visits/${props.clinical.visit.visitNumber}/${path}`,
        }));
const callRoom = (roomId: number) => {
    treatmentRoomId.value = roomId;
    callToTreatment();
};

const tabs = [
    { value: 'treatment', label: 'Treatment plan' },
    { value: 'history', label: 'Patient history' },
];
const statusLabel = () =>
    consultationCompleted.value
        ? 'Completed'
        : props.clinical.hold.isHeld
          ? 'On hold'
          : 'In progress';
const vitalFields = [
    { key: 'systolic_bp', label: 'Systolic BP', mode: 'numeric', min: '1' },
    { key: 'diastolic_bp', label: 'Diastolic BP', mode: 'numeric', min: '1' },
    { key: 'pulse_bpm', label: 'Pulse (bpm)', mode: 'numeric', min: '1' },
    {
        key: 'temperature_celsius',
        label: 'Temperature (°C)',
        mode: 'decimal',
        min: '0.01',
        step: '0.01',
    },
    {
        key: 'spo2_percent',
        label: 'SpO₂ (%)',
        mode: 'decimal',
        min: '0',
        max: '100',
        step: '0.01',
    },
    {
        key: 'weight_kg',
        label: 'Weight (kg)',
        mode: 'decimal',
        min: '0.01',
        step: '0.01',
    },
    {
        key: 'height_cm',
        label: 'Height (cm)',
        mode: 'decimal',
        min: '0.01',
        step: '0.01',
    },
] as const;
const saveDisabled = () =>
    form.processing ||
    props.clinical.hold.isHeld ||
    consultationCompleted.value;
const saveStatus = () =>
    form.processing
        ? 'Saving clinical record…'
        : form.recentlySuccessful
          ? 'Saved.'
          : form.isDirty
            ? 'You have unsaved changes'
            : 'Clinical record is up to date.';
</script>

<template>
    <Head title="Clinical Encounter" />
    <PageFrame width="wide">
        <div class="flex min-w-0 flex-col gap-4">
            <V2LeaveGuard
                ref="leaveGuard"
                :dirty="hasUnsavedWork && !form.processing"
                @discard="discardUnsaved"
            />
            <header class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <Link
                        href="/queue"
                        class="inline-flex h-11 items-center gap-1.5 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" /> My Queue
                    </Link>
                    <span
                        class="rounded-[10px] border bg-card px-2.5 py-1 text-sm"
                        >{{ clinical.encounter.attendingClinician }}</span
                    >
                    <span class="text-[13px] text-muted-foreground">{{
                        clinical.visit.visitNumber
                    }}</span>
                    <V2Chip
                        :tone="
                            consultationCompleted
                                ? 'success'
                                : clinical.hold.isHeld
                                  ? 'warning'
                                  : 'info'
                        "
                        :label="statusLabel()"
                    />
                    <span class="text-[13px] text-muted-foreground tabular-nums"
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
                </div>
                <p
                    v-if="consultationCompleted"
                    class="text-[13px] text-muted-foreground"
                >
                    Completed means the consultation was handed off, not that
                    payment is finished.
                </p>
                <div
                    class="flex flex-wrap items-center gap-2"
                    data-testid="consultation-toolbar"
                >
                    <Button
                        v-if="
                            page.props.auth.permissions.includes(
                                'medical_certificates.view.own',
                            )
                        "
                        variant="outline"
                        class="h-11 max-sm:hidden lg:h-9"
                        as-child
                    >
                        <a
                            :href="`/visits/${clinical.visit.visitNumber}/medical-certificates`"
                            target="_blank"
                            rel="noopener"
                            >Medical certificates<ExternalLink
                                class="size-3.5"
                                aria-hidden="true"
                            /><span class="sr-only"
                                >(opens in a new tab)</span
                            ></a
                        ></Button
                    >
                    <Button
                        v-if="
                            page.props.auth.permissions.includes(
                                'referrals.view.own',
                            )
                        "
                        variant="outline"
                        class="h-11 max-sm:hidden lg:h-9"
                        as-child
                    >
                        <a
                            :href="`/visits/${clinical.visit.visitNumber}/referrals`"
                            target="_blank"
                            rel="noopener"
                            >Referral letters<ExternalLink
                                class="size-3.5"
                                aria-hidden="true"
                            /><span class="sr-only"
                                >(opens in a new tab)</span
                            ></a
                        ></Button
                    >
                    <Button
                        v-if="
                            page.props.auth.permissions.includes(
                                'attendance_documents.view.own',
                            )
                        "
                        variant="outline"
                        class="h-11 max-sm:hidden lg:h-9"
                        as-child
                    >
                        <a
                            :href="`/visits/${clinical.visit.visitNumber}/attendance`"
                            target="_blank"
                            rel="noopener"
                            >Attendance confirmation<ExternalLink
                                class="size-3.5"
                                aria-hidden="true"
                            /><span class="sr-only"
                                >(opens in a new tab)</span
                            ></a
                        ></Button
                    >
                    <div class="contents">
                        <WaitingPatientsButton
                            v-if="
                                page.props.auth.permissions.some((permission) =>
                                    [
                                        'queue.view.own',
                                        'queue.view.branch',
                                    ].includes(permission),
                                )
                            "
                            :branch-id="clinical.branch.id"
                            :can-view-branch="
                                page.props.auth.permissions.includes(
                                    'queue.view.branch',
                                )
                            "
                        />
                    </div>
                    <Button
                        v-if="clinical.queue.canRecall"
                        type="button"
                        variant="outline"
                        class="h-11 lg:h-9"
                        :disabled="recallProcessing || callNextProcessing"
                        data-testid="recall-patient"
                        @click="recallPatient"
                    >
                        <Megaphone class="size-4" aria-hidden="true" /> Call
                        again
                    </Button>
                    <div class="sm:hidden">
                        <ConsultationMoreMenu
                            v-if="
                                documentLinks().length ||
                                (clinical.queue.canRecall &&
                                    (treatmentRooms?.length ?? 0) > 0)
                            "
                            :documents="documentLinks()"
                            :rooms="
                                clinical.queue.canRecall
                                    ? (treatmentRooms ?? [])
                                    : []
                            "
                            :rooms-disabled="
                                recallProcessing || callNextProcessing
                            "
                            @room="callRoom"
                        />
                    </div>
                    <div
                        v-if="
                            clinical.queue.canRecall &&
                            (treatmentRooms?.length ?? 0) > 0
                        "
                        class="flex flex-col gap-1 max-sm:hidden"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <OperationalSelect
                                v-if="(treatmentRooms?.length ?? 0) > 1"
                                v-model="treatmentRoomId"
                                label="Treatment room"
                                placeholder="Treatment room"
                                trigger-class="h-11 w-44 lg:h-9"
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
                                variant="outline"
                                class="h-11 lg:h-9"
                                :disabled="
                                    recallProcessing || callNextProcessing
                                "
                                :aria-describedby="
                                    treatmentError ? 'recall-error' : undefined
                                "
                                data-testid="call-treatment"
                                @click="callToTreatment"
                            >
                                <Megaphone class="size-4" aria-hidden="true" />
                                Call to treatment room
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
                        class="w-full max-w-prose"
                        :message="recallError"
                    />
                    <div class="contents">
                        <TreatmentProgressActions
                            v-if="
                                treatmentProgress?.canManage &&
                                !consultationCompleted
                            "
                            :progress="treatmentProgress"
                            :visit-number="clinical.visit.visitNumber"
                            :branch-id="clinical.branch.id"
                            from="consultation"
                            inline="always"
                            compact
                            :subject="clinical.patient.name"
                        />
                    </div>
                    <Button
                        v-if="clinical.hold.canHold"
                        type="button"
                        variant="outline"
                        class="h-11 lg:h-9"
                        :disabled="holdProcessing || callNextProcessing"
                        @click="requestHold"
                    >
                        <PauseCircle class="size-4" aria-hidden="true" /> On
                        Hold
                    </Button>
                    <Button
                        v-if="clinical.hold.canResume"
                        type="button"
                        variant="outline"
                        class="h-11 lg:h-9"
                        :disabled="holdProcessing || callNextProcessing"
                        @click="changeHoldState('resume')"
                    >
                        <PlayCircle class="size-4" aria-hidden="true" /> Resume
                        Consultation
                    </Button>
                    <div
                        class="ml-auto flex shrink-0 items-center gap-2"
                        data-testid="consultation-focus-controls"
                    >
                        <WorkspaceBranchSwitcher />
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-11 rounded-full px-0 lg:size-9"
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

            <div
                class="grid min-h-0 gap-5 lg:grid-cols-[minmax(0,55fr)_minmax(0,45fr)]"
            >
                <div class="min-w-0 space-y-4" data-testid="consultation-left">
                    <section
                        class="overflow-hidden rounded-[14px] border bg-card"
                    >
                        <div
                            class="grid sm:grid-cols-[minmax(0,11fr)_minmax(0,9fr)]"
                            data-testid="consultation-patient-grid"
                        >
                            <div class="flex min-w-0 items-start gap-3 p-4">
                                <div
                                    class="rounded-[10px] bg-muted p-2 text-muted-foreground"
                                >
                                    <UserRound
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div class="min-w-0 flex-1 space-y-1">
                                    <h1
                                        class="text-lg leading-6 font-semibold [overflow-wrap:anywhere]"
                                    >
                                        {{ clinical.patient.name }}
                                    </h1>
                                    <p class="text-sm text-muted-foreground">
                                        {{ patientAge }} ·
                                        {{ clinical.patient.sex }}
                                    </p>
                                    <p
                                        class="font-mono text-sm text-muted-foreground"
                                    >
                                        {{ clinical.patient.patientNumber }}
                                    </p>
                                    <V2Chip
                                        v-if="
                                            clinical.visit.priority === 'urgent'
                                        "
                                        tone="danger"
                                        label="Urgent"
                                    />
                                </div>
                                <div
                                    class="flex shrink-0 flex-col items-end gap-2 text-right text-sm text-muted-foreground"
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
                                <div class="text-sm font-medium">
                                    Payment method
                                </div>
                                <span
                                    class="inline-flex max-w-full rounded-[10px] bg-muted px-2.5 py-1 text-sm break-words"
                                    >{{
                                        clinical.visit.paymentMethodLabel
                                    }}</span
                                >
                                <div
                                    v-if="clinical.visit.isPanel && panelNote"
                                    data-testid="consultation-panel-note"
                                    class="space-y-1 text-sm text-muted-foreground"
                                >
                                    <div>Panel note</div>
                                    <div
                                        class="break-words whitespace-pre-wrap"
                                    >
                                        {{ panelNote }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="consultation-dense border-t">
                            <AllergyProblemPanel
                                :visit-number="clinical.visit.visitNumber"
                                :branch-id="clinical.branch.id"
                                :time-zone="clinical.branch.timezone"
                                :allergies="clinical.allergies"
                                :problems="clinical.problems"
                            />
                        </div>
                    </section>

                    <div class="consultation-dense">
                        <DispensaryAttentionPanel
                            :items="clinical.dispensaryAttention"
                        />
                    </div>

                    <div
                        v-if="stateError"
                        role="alert"
                        class="flex items-start gap-2 rounded-[14px] border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        <AlertTriangle
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ stateError }}
                    </div>
                    <div
                        v-if="holdError"
                        role="alert"
                        class="rounded-[14px] border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {{ holdError }}
                    </div>
                    <div
                        v-if="clinical.hold.isHeld"
                        role="status"
                        class="flex flex-col gap-3 rounded-[14px] border border-warning/40 bg-warning/10 p-3 text-sm text-warning-strong sm:flex-row sm:items-center sm:justify-between"
                        data-testid="held-notice"
                    >
                        <p class="min-w-0">
                            This patient stays in Serving Now. Resume the
                            consultation before saving or completing clinical
                            work.
                            <template
                                v-if="
                                    callNextAvailable &&
                                    !holdProcessing &&
                                    !recallProcessing
                                "
                                >You can call your next patient
                                meanwhile.</template
                            >
                        </p>
                        <div
                            v-if="canCallNextWhileHeld"
                            class="consultation-dense shrink-0 sm:max-w-sm [&_#call-next-reason]:text-warning-strong"
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
                        class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-warning/40 bg-warning/10 p-3 text-sm text-warning-strong"
                    >
                        <span class="flex items-start gap-2">
                            <AlertTriangle
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            This record has changed elsewhere. Reload to see the
                            latest version - your edits have not been saved.
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            class="h-11 lg:h-9"
                            @click="adoptLatestRecord"
                        >
                            Reload
                        </Button>
                    </div>

                    <form
                        class="space-y-5 rounded-[14px] border bg-card p-4 sm:p-5"
                        data-testid="consultation-record-card"
                        @submit.prevent="save"
                    >
                        <div
                            class="flex min-w-0 items-baseline gap-2 rounded-[10px] border bg-muted/30 px-3 py-2.5"
                            data-testid="consultation-visit-bar"
                        >
                            <span
                                class="shrink-0 text-[13px] font-medium text-muted-foreground"
                                >Visit note</span
                            >
                            <span class="min-w-0 break-words">{{
                                clinical.visit.registrationReason ??
                                'No reason recorded'
                            }}</span>
                        </div>

                        <section
                            class="space-y-3"
                            data-testid="consultation-vitals-bar"
                            aria-labelledby="vitals-heading"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h2
                                    id="vitals-heading"
                                    class="text-base font-semibold"
                                >
                                    Vital signs
                                </h2>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="h-11 lg:h-9"
                                    :aria-expanded="vitalsExpanded"
                                    @click="vitalsExpanded = !vitalsExpanded"
                                    >Record new</Button
                                >
                            </div>
                            <dl
                                v-if="hasVitals"
                                class="flex min-w-0 flex-wrap gap-x-4 gap-y-1 text-sm"
                                data-testid="vitals-summary"
                            >
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">BP</dt>
                                    <dd class="font-medium">
                                        {{ form.vitals.systolic_bp || '—' }}/{{
                                            form.vitals.diastolic_bp || '—'
                                        }}
                                    </dd>
                                </div>
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">Pulse</dt>
                                    <dd class="font-medium">
                                        {{ form.vitals.pulse_bpm || '—' }}
                                    </dd>
                                </div>
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">Temp</dt>
                                    <dd class="font-medium">
                                        {{
                                            form.vitals.temperature_celsius ||
                                            '—'
                                        }}
                                        °C
                                    </dd>
                                </div>
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">SpO₂</dt>
                                    <dd class="font-medium">
                                        {{ form.vitals.spo2_percent || '—' }}%
                                    </dd>
                                </div>
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">
                                        Weight
                                    </dt>
                                    <dd class="font-medium">
                                        {{ form.vitals.weight_kg || '—' }} kg
                                    </dd>
                                </div>
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">
                                        Height
                                    </dt>
                                    <dd class="font-medium">
                                        {{ form.vitals.height_cm || '—' }} cm
                                    </dd>
                                </div>
                                <div class="inline-flex items-baseline gap-1">
                                    <dt class="text-muted-foreground">BMI</dt>
                                    <dd class="font-medium">{{ bmi }}</dd>
                                </div>
                            </dl>
                            <p v-else class="text-sm text-muted-foreground">
                                No vital signs recorded.
                            </p>
                            <div
                                v-show="
                                    vitalsExpanded ||
                                    Object.keys(form.errors).some((key) =>
                                        key.startsWith('vitals.'),
                                    )
                                "
                                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <V2Field
                                    v-for="field in vitalFields"
                                    :key="field.key"
                                    v-slot="{ id, describedBy, invalid }"
                                    :label="field.label"
                                    :error="form.errors[`vitals.${field.key}`]"
                                >
                                    <Input
                                        :id="id"
                                        :model-value="
                                            form.vitals[field.key] ?? ''
                                        "
                                        @update:model-value="
                                            (value) =>
                                                (form.vitals[field.key] =
                                                    value === ''
                                                        ? ''
                                                        : Number(value))
                                        "
                                        :inputmode="field.mode"
                                        type="number"
                                        :min="field.min"
                                        :max="
                                            'max' in field
                                                ? field.max
                                                : undefined
                                        "
                                        :step="
                                            'step' in field
                                                ? field.step
                                                : undefined
                                        "
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </V2Field>
                                <div class="rounded-[10px] bg-muted/40 p-3">
                                    <div
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        Derived BMI
                                    </div>
                                    <div class="mt-1 text-lg font-semibold">
                                        {{ bmi }}
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section
                            class="space-y-2"
                            aria-labelledby="clinical-note-heading"
                        >
                            <h2
                                id="clinical-note-heading"
                                class="text-base font-semibold"
                                title="Consultation findings only. This is separate from the Visit Reason."
                            >
                                Clinical Note
                            </h2>
                            <div
                                class="overflow-hidden rounded-[10px] border border-input"
                            >
                                <textarea
                                    v-model="form.clinical_note"
                                    aria-labelledby="clinical-note-heading"
                                    maxlength="20000"
                                    rows="4"
                                    class="block min-h-24 w-full resize-y border-0 bg-card p-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                                    placeholder="Record the clinical consultation note…"
                                />
                                <InputError
                                    :message="form.errors.clinical_note"
                                />
                                <div
                                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t px-3 py-2"
                                >
                                    <span class="text-sm"
                                        >{{
                                            form.clinical_note.length.toLocaleString()
                                        }}
                                        / 20,000</span
                                    >
                                    <span
                                        class="text-[13px] text-muted-foreground"
                                        role="status"
                                        >{{ saveStatus() }}</span
                                    >
                                    <Button
                                        type="submit"
                                        class="h-11 max-sm:hidden lg:h-9"
                                        variant="outline"
                                        :disabled="saveDisabled()"
                                    >
                                        <LoaderCircle
                                            v-if="form.processing"
                                            class="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                        {{
                                            form.processing
                                                ? 'Saving…'
                                                : 'Save clinical record'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </section>

                        <section
                            class="space-y-3"
                            aria-labelledby="diagnoses-heading"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h2
                                    id="diagnoses-heading"
                                    class="text-base font-semibold"
                                >
                                    Diagnoses
                                </h2>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <div class="contents">
                                        <DiagnosisPresetPicker
                                            :visit-number="
                                                clinical.visit.visitNumber
                                            "
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
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="h-11 lg:h-9"
                                        @click="addDiagnosis"
                                    >
                                        <Plus
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Add diagnosis
                                    </Button>
                                </div>
                            </div>
                            <InputError :message="form.errors.diagnoses" />
                            <div
                                v-for="(diagnosis, index) in form.diagnoses"
                                :key="index"
                                class="grid gap-3 rounded-[10px] border p-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)]"
                            >
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Diagnosis"
                                    :error="
                                        form.errors[
                                            `diagnoses.${index}.diagnosis_text`
                                        ]
                                    "
                                >
                                    <Input
                                        :id="id"
                                        v-model="diagnosis.diagnosis_text"
                                        maxlength="500"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Code"
                                    :error="
                                        form.errors[
                                            `diagnoses.${index}.diagnosis_code`
                                        ]
                                    "
                                >
                                    <Input
                                        :id="id"
                                        v-model="diagnosis.diagnosis_code"
                                        maxlength="50"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Code system"
                                    :error="
                                        form.errors[
                                            `diagnoses.${index}.code_system`
                                        ]
                                    "
                                >
                                    <Input
                                        :id="id"
                                        v-model="diagnosis.code_system"
                                        maxlength="50"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </V2Field>
                                <label
                                    class="flex min-h-11 items-center gap-2 self-center text-sm"
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
                                    class="size-11 self-center lg:size-9"
                                    aria-label="Remove diagnosis"
                                    @click="removeDiagnosis(index)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                            <p
                                v-if="!form.diagnoses.length"
                                class="text-sm text-muted-foreground"
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
                    <V2Tabs
                        v-model="workspaceTab"
                        :tabs="tabs"
                        ariaLabel="Consultation panels"
                        id-prefix="consultation"
                    />
                    <div
                        v-show="workspaceTab === 'treatment'"
                        id="consultation-panel-treatment"
                        role="tabpanel"
                        aria-labelledby="consultation-tab-treatment"
                        class="consultation-dense"
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
                        id="consultation-panel-history"
                        role="tabpanel"
                        aria-labelledby="consultation-tab-history"
                        data-testid="consultation-history-pane"
                        class="consultation-dense min-h-0 rounded-[14px] border bg-card p-4 lg:h-[calc(100dvh-12rem)] lg:[&>div]:h-full"
                    >
                        <ClinicalHistoryPanel
                            :history="clinical.history"
                            :time-zone="clinical.branch.timezone"
                        />
                    </div>
                </aside>
            </div>

            <V2StickyBar>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="saveDisabled()"
                    @click="save"
                >
                    {{ form.processing ? 'Saving…' : 'Save clinical record' }}
                </Button>
            </V2StickyBar>

            <UnsavedWorkDialogV2
                :open="unsavedWorkOpen"
                :unsaved-items="unsavedItems"
                :processing="unsavedWorkProcessing"
                :processing-action="unsavedWorkAction"
                :error="unsavedWorkError"
                @update:open="unsavedWorkOpen = $event"
                @save-and-hold="saveAndHold"
                @hold-without-saving="holdWithoutSaving"
            />
        </div>
    </PageFrame>
</template>

<style scoped>
/* The Classic Partials keep the Classic density of the consultation page until their own restyle step. */
.consultation-dense {
    font-size: 12.5px;
    line-height: 1.5;
}
.consultation-dense :deep(.text-sm) {
    font-size: 12.5px;
}
.consultation-dense :deep(.text-xs) {
    font-size: 11.5px;
}
.consultation-dense :deep(h2),
.consultation-dense :deep(h3) {
    font-size: 13px;
}
.consultation-dense :deep(button),
.consultation-dense :deep(button[class]) {
    height: 30px;
    font-size: 12px;
    padding-inline: 8px;
}
.consultation-dense :deep(#clinical-safety button) {
    padding-inline: 4px;
}
.consultation-dense :deep(button:not(:focus-visible)) {
    box-shadow: none;
}
.consultation-dense :deep(button[aria-label^='Move ']),
.consultation-dense :deep(button[aria-label^='Withdraw ']) {
    width: 28px;
    padding-inline: 4px;
}
.consultation-dense :deep(input:not([type='radio']):not([type='checkbox'])) {
    height: 30px;
    font-size: 12px;
}
.consultation-dense :deep(textarea) {
    font-size: 12.5px;
}
</style>

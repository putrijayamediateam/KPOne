<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { formatDate, formatDateTime } from '@/lib/presentation';
import { queuePresentationLabel } from '@/lib/r1c2-presentation';
import type { BranchContext, VisitDetail } from '@/types';

/**
 * Version 2 Visit page (shown only in V2; Classic is `pages/Registration/Show.vue`, untouched). Same props, same
 * routes (`/visits/{visit}/queue`, `/visits/{visit}/cancel`), same permission checks, same lock versions and payloads.
 */
const props = defineProps<{
    visit: VisitDetail;
    attendanceCopies: { id: number; number: string }[];
}>();
const page = usePage<{
    branchContext: BranchContext;
    auth: { permissions: string[] };
}>();
const attendanceBranchChanged = ref(false);
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        attendanceBranchChanged.value = true;
    },
);
const permissions = computed(() => page.props.auth.permissions);
const canCreate = computed(() =>
    permissions.value.includes('visits.create.branch'),
);
const canViewAttendance = computed(
    () =>
        !attendanceBranchChanged.value &&
        permissions.value.includes('attendance_documents.view.branch') &&
        permissions.value.includes('attendance_documents.print.branch'),
);
// UX-2 (S1-005): offer the MC link only once the doctor's consultation record exists, otherwise say why.
const canViewMedicalCertificates = computed(
    () =>
        permissions.value.includes('medical_certificates.view.branch') &&
        props.visit.visitType === 'consultation',
);
const cancelOpen = ref(false);
const cancelForm = useForm({
    expected_branch_id: page.props.branchContext?.active?.id ?? 0,
    lock_version: props.visit.lockVersion,
    queue_lock_version: props.visit.queue?.lockVersion ?? null,
    cancellation_reason: '',
});
const queueForm = useForm({
    from: 'registration',
    expected_branch_id: page.props.branchContext?.active?.id ?? 0,
    visit_lock_version: props.visit.lockVersion,
});
const sendToWaiting = () => {
    queueForm.post(`/visits/${props.visit.visitNumber}/queue`);
};
const queueError = (key: string) =>
    (queueForm.errors as Record<string, string>)[key];
const cancelError = (key: string) =>
    (cancelForm.errors as Record<string, string>)[key];
const submitCancel = (reason: string | null) => {
    cancelForm.cancellation_reason = (reason ?? '').slice(0, 500);
    cancelForm.patch(`/visits/${props.visit.visitNumber}/cancel`, {
        preserveState: true,
        onSuccess: () => {
            cancelOpen.value = false;
        },
    });
};
const statusChip = computed(() => {
    const status = props.visit.status;

    return {
        label: status.charAt(0).toUpperCase() + status.slice(1),
        tone: (status === 'completed'
            ? 'success'
            : status === 'cancelled'
              ? 'danger'
              : 'neutral') as 'success' | 'danger' | 'neutral',
    };
});
const dob = computed(() =>
    props.visit.patient.dateOfBirth
        ? formatDate(props.visit.patient.dateOfBirth)
        : 'DOB not recorded',
);
const sexLabel = computed(() => {
    const sex = props.visit.patient.sex ?? '';

    return sex.charAt(0).toUpperCase() + sex.slice(1);
});
const errorText = computed(
    () =>
        queueError('visit_lock_version') ||
        queueError('expected_branch_id') ||
        queueError('queue'),
);
const cancelErrorText = computed(
    () =>
        cancelError('cancellation_reason') ||
        cancelError('expected_branch_id') ||
        cancelError('lock_version') ||
        cancelError('queue_lock_version') ||
        cancelError('queue'),
);
const linkButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
</script>

<template>
    <Head :title="visit.visitNumber" />
    <PageFrame>
        <V2WorkLayout
            eyebrow="Visit"
            :title="visit.visitNumber"
            :description="`${visit.branch.name} · ${formatDateTime(visit.registeredAt, visit.branch.timezone)}`"
            rail-width="narrow"
        >
            <template #actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <button
                        v-if="visit.can.sendToWaiting"
                        type="button"
                        :class="primaryButton"
                        :disabled="queueForm.processing"
                        @click="sendToWaiting"
                    >
                        {{
                            queueForm.processing
                                ? 'Sending…'
                                : 'Send to waiting'
                        }}
                    </button>
                    <Link
                        v-if="visit.can.update"
                        :href="`/visits/${visit.visitNumber}/edit`"
                        :class="
                            visit.can.sendToWaiting ? linkButton : primaryButton
                        "
                        >Edit visit</Link
                    >
                    <Link
                        v-if="canCreate"
                        href="/registration/create"
                        :class="linkButton"
                        >Register another patient</Link
                    >
                    <Link
                        href="/registration"
                        :class="[
                            linkButton,
                            'max-sm:order-last max-sm:border-0 max-sm:bg-transparent max-sm:px-0 max-sm:text-primary',
                        ]"
                        >← Registration</Link
                    >
                </div>
            </template>

            <template #main>
                <div class="flex flex-wrap items-center gap-2">
                    <V2Chip :tone="statusChip.tone" :label="statusChip.label" />
                    <V2Chip
                        v-if="visit.priority === 'urgent'"
                        tone="danger"
                        label="Urgent"
                    />
                </div>
                <p
                    v-if="errorText"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errorText }}
                </p>

                <section class="rounded-[14px] border bg-card p-4 sm:p-5">
                    <h2 class="text-base font-semibold">Patient</h2>
                    <p class="mt-2 text-lg font-semibold">
                        {{ visit.patient.fullName }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ visit.patient.patientNumber }}
                    </p>
                    <p class="mt-2 text-sm">{{ dob }} · {{ sexLabel }}</p>
                </section>

                <section class="rounded-[14px] border bg-card p-4 sm:p-5">
                    <h2 class="text-base font-semibold">Visit reason</h2>
                    <template v-if="visit.visitReasons.primary">
                        <p class="mt-2 text-sm">
                            <span class="font-medium">Primary:</span>
                            {{ visit.visitReasons.primary }}
                        </p>
                        <p
                            v-if="visit.visitReasons.additional.length"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            <span class="font-medium text-foreground"
                                >Additional:</span
                            >
                            {{ visit.visitReasons.additional.join(' · ') }}
                        </p>
                    </template>
                    <p
                        v-else
                        class="mt-2 text-sm break-words whitespace-pre-wrap"
                    >
                        {{ visit.visitReasons.legacy ?? 'Not recorded.' }}
                    </p>
                </section>

                <section
                    v-if="visit.coverage.type === 'panel'"
                    class="rounded-[14px] border bg-card p-4 sm:p-5"
                >
                    <h2 class="text-base font-semibold">
                        Provisional Panel coverage
                    </h2>
                    <p class="mt-2 text-sm break-words">
                        {{ visit.coverage.panelName }}
                    </p>
                    <p
                        v-if="visit.coverage.memberReference"
                        class="text-sm break-words text-muted-foreground"
                    >
                        Reference: {{ visit.coverage.memberReference }}
                    </p>
                </section>

                <section
                    v-if="visit.status === 'cancelled'"
                    class="rounded-[14px] border bg-card p-4 sm:p-5"
                >
                    <h2 class="text-base font-semibold">Cancelled</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            formatDateTime(
                                visit.cancelledAt,
                                visit.branch.timezone,
                            )
                        }}
                    </p>
                    <p class="mt-2 text-sm break-words whitespace-pre-wrap">
                        {{ visit.cancellationReason }}
                    </p>
                </section>
            </template>

            <template #rail>
                <section class="rounded-[14px] border bg-card p-4">
                    <h2 class="text-base font-semibold">Visit</h2>
                    <dl class="mt-2 grid gap-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Type</dt>
                            <dd
                                class="min-w-0 font-medium break-words capitalize"
                            >
                                {{ visit.visitType }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Doctor</dt>
                            <dd
                                class="min-w-0 text-right font-medium break-words"
                            >
                                {{ visit.doctor?.name ?? 'None' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Coverage</dt>
                            <dd
                                class="min-w-0 text-right font-medium break-words"
                            >
                                {{ visit.coverage.panelName ?? 'Self-pay' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    v-if="visit.queue"
                    class="rounded-[14px] border bg-card p-4"
                >
                    <h2 class="text-base font-semibold">Consultation queue</h2>
                    <p class="mt-2 text-2xl font-semibold">
                        {{ visit.queue.queueNumber }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ formatDate(visit.queue.operationalDate) }}
                    </p>
                    <p class="mt-1.5">
                        <V2Chip
                            tone="neutral"
                            :label="
                                queuePresentationLabel({
                                    status: visit.queue.status,
                                    visitStatus: visit.status,
                                })
                            "
                        />
                    </p>
                    <Link href="/queue" :class="`${linkButton} mt-3 w-full`"
                        >Open queue</Link
                    >
                </section>

                <section
                    v-if="
                        canViewMedicalCertificates ||
                        (canViewAttendance && attendanceCopies.length)
                    "
                    class="rounded-[14px] border bg-card p-4"
                >
                    <h2 class="text-base font-semibold">Documents</h2>
                    <div class="mt-2 flex flex-col gap-2 text-sm">
                        <template v-if="canViewMedicalCertificates">
                            <Link
                                v-if="visit.hasConsultation"
                                :href="`/visits/${visit.visitNumber}/medical-certificates`"
                                class="font-medium text-primary hover:underline"
                                >Medical certificates</Link
                            >
                            <p
                                v-else-if="visit.status === 'cancelled'"
                                class="text-muted-foreground"
                                data-testid="mc-unavailable"
                            >
                                No medical certificate: this visit was
                                cancelled.
                            </p>
                            <p
                                v-else
                                class="text-muted-foreground"
                                data-testid="mc-unavailable"
                            >
                                Medical certificates are available after the
                                doctor starts the consultation.
                            </p>
                        </template>
                        <template v-if="canViewAttendance">
                            <a
                                v-for="copy in attendanceCopies"
                                :key="copy.id"
                                :href="`/visits/${visit.visitNumber}/attendance/${copy.id}`"
                                target="_blank"
                                rel="noopener"
                                class="font-medium text-primary hover:underline"
                                >Attendance confirmation {{ copy.number }} ·
                                open COPY / print</a
                            >
                        </template>
                    </div>
                </section>
                <section
                    v-if="visit.can.cancel"
                    class="rounded-[14px] border border-destructive/30 bg-card p-4 sm:p-5"
                >
                    <h2 class="text-base font-semibold text-destructive">
                        Cancel visit
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cancellation is final. A cancelled visit cannot be
                        edited or reopened.
                    </p>
                    <button
                        type="button"
                        class="mt-3 inline-flex h-11 items-center justify-center rounded-[10px] border border-destructive/40 bg-card px-4 text-sm font-medium text-destructive hover:bg-destructive-soft focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:w-full lg:h-9"
                        @click="cancelOpen = true"
                    >
                        Cancel visit…
                    </button>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        v-model:open="cancelOpen"
        title="Cancel visit"
        :description="`${visit.patient.fullName}${visit.queue?.queueNumber ? `, ${visit.queue.queueNumber}` : ''}. The visit is removed from the queue and cannot be undone.`"
        confirm-label="Cancel visit"
        cancel-label="Keep visit"
        tone="danger"
        reason-label="Cancellation reason"
        :busy="cancelForm.processing"
        @confirm="submitCancel"
    >
        <p v-if="cancelErrorText" role="alert" class="text-sm text-destructive">
            {{ cancelErrorText }}
        </p>
    </V2Dialog>
</template>

<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Ban, ListOrdered, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status';
import { formatDate, formatDateTime } from '@/lib/presentation';
import { queuePresentationLabel } from '@/lib/r1c2-presentation';
import type { BranchContext, VisitDetail } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Registration', href: '/registration' }] },
});
const props = defineProps<{ visit: VisitDetail }>();
const page = usePage<{
    branchContext: BranchContext;
    auth: { permissions: string[] };
}>();
const canCreate = computed(() =>
    page.props.auth.permissions.includes('visits.create.branch'),
);
const cancelling = ref(false);
const cancelForm = useForm({
    expected_branch_id: page.props.branchContext?.active?.id ?? 0,
    lock_version: props.visit.lockVersion,
    queue_lock_version: props.visit.queue?.lockVersion ?? null,
    cancellation_reason: '',
});
const queueForm = useForm({
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
const submitCancel = () => {
    cancelForm.patch(`/visits/${props.visit.visitNumber}/cancel`, {
        preserveState: true,
    });
};
</script>

<template>
    <Head :title="visit.visitNumber" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4 md:p-6"
    >
        <div
            class="flex flex-col justify-between gap-3 md:flex-row md:items-start"
        >
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="font-mono text-2xl font-semibold">
                        {{ visit.visitNumber }}
                    </h1>
                    <StatusBadge :status="visit.status" /><span
                        v-if="visit.priority === 'urgent'"
                        class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700"
                        >Urgent</span
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ visit.branch.name }} ·
                    {{
                        formatDateTime(
                            visit.registeredAt,
                            visit.branch.timezone,
                        )
                    }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" as-child
                    ><Link href="/registration"
                        ><ArrowLeft class="size-4" /> Console</Link
                    ></Button
                ><Button v-if="visit.can.update" as-child
                    ><Link :href="`/visits/${visit.visitNumber}/edit`"
                        ><Pencil class="size-4" /> Edit</Link
                    ></Button
                ><Button v-if="canCreate" variant="outline" as-child
                    ><Link href="/registration/create"
                        >Register another Patient</Link
                    ></Button
                >
                <Button
                    v-if="visit.can.sendToWaiting"
                    :disabled="queueForm.processing"
                    @click="sendToWaiting"
                >
                    <ListOrdered class="size-4" />
                    {{ queueForm.processing ? 'Sending…' : 'Send to Waiting' }}
                </Button>
            </div>
        </div>
        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-lg border bg-card p-4 md:col-span-2">
                <div
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Patient
                </div>
                <div class="mt-2 text-lg font-semibold">
                    {{ visit.patient.fullName }}
                </div>
                <div class="font-mono text-sm text-muted-foreground">
                    {{ visit.patient.patientNumber }}
                </div>
                <div class="mt-2 text-sm">
                    {{ visit.patient.dateOfBirth ?? 'DOB not recorded' }} ·
                    {{ visit.patient.sex }}
                </div>
            </div>
            <div class="rounded-lg border bg-card p-4">
                <div
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Visit
                </div>
                <dl class="mt-2 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt>Type</dt>
                        <dd class="font-medium capitalize">
                            {{ visit.visitType }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Doctor</dt>
                        <dd class="font-medium">
                            {{ visit.doctor?.name ?? 'None' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Coverage</dt>
                        <dd class="font-medium">
                            {{ visit.coverage.panelName ?? 'Self-pay' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
        <section
            v-if="visit.queue"
            class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50/40 p-4"
        >
            <div class="flex items-center gap-3">
                <ListOrdered class="size-5 text-emerald-800" />
                <div>
                    <div
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Consultation Queue
                    </div>
                    <div class="font-mono text-2xl font-bold">
                        {{ visit.queue.queueNumber }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ formatDate(visit.queue.operationalDate) }} ·
                        <StatusBadge
                            :status="visit.queue.status"
                            :label="
                                queuePresentationLabel({
                                    status: visit.queue.status,
                                    visitStatus: visit.status,
                                })
                            "
                        />
                    </div>
                </div>
            </div>
            <Button variant="outline" as-child
                ><Link href="/queue">Open Queue</Link></Button
            >
        </section>
        <InputError
            :message="
                queueError('visit_lock_version') ||
                queueError('expected_branch_id') ||
                queueError('queue')
            "
        />
        <section class="rounded-lg border bg-card p-4">
            <h2 class="font-semibold">Visit Reason</h2>
            <template v-if="visit.visitReasons.primary">
                <p class="mt-2 text-sm">
                    <span class="font-medium">Primary:</span>
                    {{ visit.visitReasons.primary }}
                </p>
                <p
                    v-if="visit.visitReasons.additional.length"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    <span class="font-medium text-foreground">Additional:</span>
                    {{ visit.visitReasons.additional.join(' · ') }}
                </p>
            </template>
            <p v-else class="mt-2 text-sm whitespace-pre-wrap">
                {{ visit.visitReasons.legacy ?? 'Not recorded.' }}
            </p>
        </section>
        <section
            v-if="visit.coverage.type === 'panel'"
            class="rounded-lg border bg-card p-4"
        >
            <h2 class="font-semibold">Provisional Panel coverage</h2>
            <p class="mt-2 text-sm">{{ visit.coverage.panelName }}</p>
            <p
                v-if="visit.coverage.memberReference"
                class="text-sm text-muted-foreground"
            >
                Reference: {{ visit.coverage.memberReference }}
            </p>
        </section>
        <section
            v-if="visit.status === 'cancelled'"
            class="rounded-lg border border-muted bg-muted/20 p-4"
        >
            <h2 class="font-semibold">Cancelled</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ formatDateTime(visit.cancelledAt, visit.branch.timezone) }}
            </p>
            <p class="mt-2 text-sm whitespace-pre-wrap">
                {{ visit.cancellationReason }}
            </p>
        </section>
        <section
            v-if="visit.can.cancel"
            class="rounded-lg border border-red-200 bg-card"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between p-4 text-left"
                @click="cancelling = !cancelling"
            >
                <span
                    ><span class="font-semibold text-red-700">Cancel Visit</span
                    ><span class="block text-xs text-muted-foreground"
                        >Cancellation is final. A cancelled Visit cannot be
                        edited or reopened.</span
                    ></span
                ><Ban class="size-4 text-red-700" />
            </button>
            <form
                v-if="cancelling"
                class="space-y-3 border-t p-4"
                @submit.prevent="submitCancel"
            >
                <textarea
                    v-model="cancelForm.cancellation_reason"
                    maxlength="500"
                    rows="3"
                    autocomplete="off"
                    class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    placeholder="Cancellation reason"
                /><InputError
                    :message="
                        cancelError('cancellation_reason') ||
                        cancelError('expected_branch_id') ||
                        cancelError('lock_version') ||
                        cancelError('queue_lock_version') ||
                        cancelError('queue')
                    "
                />
                <div class="flex justify-end">
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="cancelForm.processing"
                        >Confirm cancellation</Button
                    >
                </div>
            </form>
        </section>
    </main>
</template>

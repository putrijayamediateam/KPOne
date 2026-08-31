<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Ban, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
    cancellation_reason: '',
});
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
                    <span
                        :class="
                            visit.status === 'registered'
                                ? 'bg-emerald-50 text-emerald-800'
                                : 'bg-muted text-muted-foreground'
                        "
                        class="rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                        >{{ visit.status }}</span
                    ><span
                        v-if="visit.priority === 'urgent'"
                        class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700"
                        >Urgent</span
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ visit.branch.name }} ·
                    {{ new Date(visit.registeredAt).toLocaleString() }}
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
        <section class="rounded-lg border bg-card p-4">
            <h2 class="font-semibold">Administrative Visit reason</h2>
            <p class="mt-2 text-sm whitespace-pre-wrap">
                {{ visit.visitReason ?? 'Not recorded.' }}
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
                {{
                    visit.cancelledAt
                        ? new Date(visit.cancelledAt).toLocaleString()
                        : ''
                }}
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
                        cancelForm.errors.cancellation_reason ||
                        cancelForm.errors.expected_branch_id ||
                        cancelForm.errors.lock_version
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

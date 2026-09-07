<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import VisitReasonPicker from '@/components/visit/VisitReasonPicker.vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';
import type { BranchContext, VisitDetail, VisitOptions } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Registration', href: '/registration' }] },
});
const props = defineProps<{ visit: VisitDetail; options: VisitOptions }>();
const visitTypeOptions = [
    { value: 'consultation', label: 'Consultation' },
    { value: 'otc', label: 'OTC' },
];
const coverageOptions = [
    { value: 'self_pay', label: 'Self-pay' },
    { value: 'panel', label: 'Panel' },
];
const priorityOptions = [
    { value: 'normal', label: 'Normal' },
    { value: 'urgent', label: 'Urgent' },
];
const doctorOptions = [
    { value: '', label: 'No doctor' },
    ...props.options.doctors.map((doctor) => ({
        value: doctor.id,
        label: doctor.name,
    })),
];
const panelOptions = [
    { value: '', label: 'Select Panel' },
    ...props.options.panels.map((panel) => ({
        value: panel.id,
        label: panel.name,
    })),
];
const page = usePage<{ branchContext: BranchContext }>();
const selectedVisitReasons = ref<VisitReasonOption[]>(
    props.visit.visitReasons.structured.map((reason) => ({
        publicId: reason.publicId,
        name: reason.label,
    })),
);
const form = useForm({
    expected_branch_id: page.props.branchContext?.active?.id ?? 0,
    lock_version: props.visit.lockVersion,
    queue_lock_version: props.visit.queue?.lockVersion ?? null,
    visit_type: props.visit.visitType,
    assigned_doctor_user_id: props.visit.doctor?.id ?? '',
    visit_reason_public_ids: props.visit.visitReasons.structured.map(
        (reason) => reason.publicId,
    ),
    coverage_type: props.visit.coverage.type,
    panel_id: props.visit.coverage.panelId ?? '',
    coverage_member_reference: props.visit.coverage.memberReference ?? '',
    priority: props.visit.priority,
});
const submit = () => {
    form.transform((data) => {
        if (props.visit.visitReasons.structured.length) {
            return data;
        }

        const legacy = { ...data };
        Reflect.deleteProperty(legacy, 'visit_reason_public_ids');

        return legacy;
    }).patch(`/visits/${props.visit.visitNumber}`);
};
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
</script>

<template>
    <Head :title="`Edit ${visit.visitNumber}`" />
    <main
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4 md:p-6"
    >
        <div class="flex justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Edit registered Visit</h1>
                <p class="font-mono text-sm text-muted-foreground">
                    {{ visit.visitNumber }} · {{ visit.patient.fullName }}
                </p>
            </div>
            <Button variant="outline" as-child
                ><Link :href="`/visits/${visit.visitNumber}`"
                    ><ArrowLeft class="size-4" /> Back</Link
                ></Button
            >
        </div>
        <form class="space-y-4" @submit.prevent="submit">
            <section class="rounded-lg border bg-card p-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="grid gap-1" for="visit-type"
                        ><span class="text-sm font-medium">Visit type</span
                        ><OperationalSelect
                            id="visit-type"
                            v-model="form.visit_type"
                            label="Visit type"
                            :options="visitTypeOptions"
                    /></label>
                    <label class="grid gap-1" for="assigned-doctor"
                        ><span class="text-sm font-medium">Doctor</span
                        ><OperationalSelect
                            id="assigned-doctor"
                            v-model="form.assigned_doctor_user_id"
                            label="Doctor"
                            :options="doctorOptions" />
                        ><InputError
                            :message="errorFor('assigned_doctor_user_id')"
                    /></label>
                    <div class="grid gap-1 md:col-span-2">
                        <span class="text-sm font-medium">Visit Reason</span>
                        <VisitReasonPicker
                            v-if="visit.visitReasons.structured.length"
                            v-model="form.visit_reason_public_ids"
                            v-model:selected="selectedVisitReasons"
                            :error="errorFor('visit_reason_public_ids')"
                        />
                        <div
                            v-else
                            class="rounded-md border bg-muted/30 px-3 py-2 text-sm"
                        >
                            <p class="whitespace-pre-wrap">
                                {{
                                    visit.visitReasons.legacy ?? 'Not recorded.'
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Historical free-text Visit Reason is preserved
                                unchanged.
                            </p>
                        </div>
                    </div>
                    <label class="grid gap-1" for="coverage-type"
                        ><span class="text-sm font-medium">Coverage</span
                        ><OperationalSelect
                            id="coverage-type"
                            v-model="form.coverage_type"
                            label="Coverage"
                            :options="coverageOptions"
                    /></label>
                    <label class="grid gap-1" for="visit-priority"
                        ><span class="text-sm font-medium">Priority</span
                        ><OperationalSelect
                            id="visit-priority"
                            v-model="form.priority"
                            label="Priority"
                            :options="priorityOptions"
                    /></label>
                    <template v-if="form.coverage_type === 'panel'"
                        ><label class="grid gap-1" for="panel-id"
                            ><span class="text-sm font-medium">Panel</span
                            ><OperationalSelect
                                id="panel-id"
                                v-model="form.panel_id"
                                label="Panel"
                                :options="panelOptions" />
                            ><InputError
                                :message="errorFor('panel_id')" /></label
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium"
                                >Member/staff reference</span
                            ><input
                                v-model="form.coverage_member_reference"
                                autocomplete="off"
                                class="h-10 rounded-md border bg-background px-3 text-sm" /></label
                    ></template>
                </div>
            </section>
            <InputError
                :message="
                    errorFor('expected_branch_id') ||
                    errorFor('lock_version') ||
                    errorFor('queue_lock_version') ||
                    errorFor('queue')
                "
            />
            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing"
                    >Save Visit</Button
                >
            </div>
        </form>
    </main>
</template>

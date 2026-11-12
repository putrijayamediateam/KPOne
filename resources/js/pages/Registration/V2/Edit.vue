<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import VisitReasonPicker from '@/components/visit/VisitReasonPicker.vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';
import type { BranchContext, VisitDetail, VisitOptions } from '@/types';

/**
 * Version 2 Edit visit (shown only in V2; Classic is `pages/Registration/Edit.vue`, untouched). Same props, same
 * `PATCH /visits/{visit}` payload, lock versions, Panel note rules and legacy free-text handling.
 */
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
    coverage_panel_note: props.visit.coverage.panelNote ?? '',
    panel_note_panel_id: props.visit.coverage.panelId ?? '',
    panel_note_idempotency_key: crypto.randomUUID(),
    priority: props.visit.priority,
});
watch(
    () => form.panel_id,
    (panelId) => {
        form.coverage_panel_note = '';
        form.panel_note_panel_id = panelId;
    },
);
watch(
    () => form.coverage_type,
    (coverage) => {
        if (coverage === 'self_pay') {
            form.coverage_panel_note = '';
            form.panel_note_panel_id = '';
        } else {
            form.panel_note_panel_id = form.panel_id;
        }
    },
);
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
const formError = computed(
    () =>
        errorFor('expected_branch_id') ||
        errorFor('lock_version') ||
        errorFor('queue_lock_version') ||
        errorFor('queue'),
);
const summary = computed(() => [
    { label: 'Visit', value: props.visit.visitNumber },
    { label: 'Patient', value: props.visit.patient.fullName },
    {
        label: 'Visit type',
        value: form.visit_type === 'consultation' ? 'Consultation' : 'OTC',
    },
    {
        label: 'Coverage',
        value:
            form.coverage_type === 'panel'
                ? (props.options.panels.find((p) => p.id === form.panel_id)
                      ?.name ?? 'Panel')
                : 'Self-pay',
    },
]);
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <Head :title="`Edit ${visit.visitNumber}`" />
    <PageFrame>
        <V2WorkLayout
            title="Edit visit"
            :description="`${visit.visitNumber} · ${visit.patient.fullName}`"
            rail-width="narrow"
        >
            <template #actions>
                <Link
                    :href="`/visits/${visit.visitNumber}`"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >Back to visit</Link
                >
            </template>

            <template #main>
                <form
                    id="v2-edit-visit-form"
                    class="flex flex-col gap-5"
                    @submit.prevent="submit"
                >
                    <V2FormCard
                        title="Visit details"
                        description="Change the visit type, doctor, reason, coverage or urgency."
                    >
                        <div class="grid gap-4 md:grid-cols-2">
                            <V2OptionGroup
                                v-model="form.visit_type"
                                label="Visit type"
                                :options="visitTypeOptions"
                                @update:model-value="
                                    (value) =>
                                        (form.visit_type = value as
                                            'consultation' | 'otc')
                                "
                            />
                            <V2Field
                                label="Doctor"
                                :error="errorFor('assigned_doctor_user_id')"
                            >
                                <OperationalSelect
                                    id="assigned-doctor"
                                    v-model="form.assigned_doctor_user_id"
                                    label="Doctor"
                                    :options="doctorOptions"
                                    trigger-class="h-11 lg:h-9"
                                />
                            </V2Field>
                            <div class="md:col-span-2">
                                <p class="mb-1.5 text-sm font-medium">
                                    Visit reason (up to 5, first is primary)
                                </p>
                                <VisitReasonPicker
                                    v-if="visit.visitReasons.structured.length"
                                    v-model="form.visit_reason_public_ids"
                                    v-model:selected="selectedVisitReasons"
                                    :error="errorFor('visit_reason_public_ids')"
                                />
                                <div
                                    v-else
                                    class="rounded-[10px] border bg-muted/30 px-3 py-2 text-sm"
                                >
                                    <p class="whitespace-pre-wrap">
                                        {{
                                            visit.visitReasons.legacy ??
                                            'Not recorded.'
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Historical free-text visit reason is
                                        preserved unchanged.
                                    </p>
                                </div>
                            </div>
                            <V2OptionGroup
                                v-model="form.coverage_type"
                                label="Coverage"
                                :options="coverageOptions"
                                @update:model-value="
                                    (value) =>
                                        (form.coverage_type = value as
                                            'self_pay' | 'panel')
                                "
                            />
                            <V2OptionGroup
                                v-model="form.priority"
                                label="Urgency"
                                :options="priorityOptions"
                                @update:model-value="
                                    (value) =>
                                        (form.priority = value as
                                            'normal' | 'urgent')
                                "
                            />
                            <template v-if="form.coverage_type === 'panel'">
                                <V2Field
                                    label="Panel"
                                    :error="errorFor('panel_id')"
                                >
                                    <OperationalSelect
                                        id="panel-id"
                                        v-model="form.panel_id"
                                        label="Panel"
                                        :options="panelOptions"
                                        trigger-class="h-11 lg:h-9"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Member or staff reference"
                                    :error="
                                        errorFor('coverage_member_reference')
                                    "
                                >
                                    <input
                                        :id="id"
                                        v-model="form.coverage_member_reference"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        autocomplete="off"
                                        :class="inputClass"
                                    />
                                </V2Field>
                                <div class="md:col-span-2">
                                    <V2Field
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Panel note"
                                        :hint="`Optional. ${form.coverage_panel_note.length} / 500`"
                                        :error="
                                            errorFor('coverage_panel_note') ||
                                            errorFor(
                                                'panel_note_idempotency_key',
                                            )
                                        "
                                    >
                                        <textarea
                                            :id="id"
                                            v-model="form.coverage_panel_note"
                                            :aria-describedby="describedBy"
                                            :aria-invalid="invalid"
                                            rows="2"
                                            maxlength="500"
                                            data-testid="registration-panel-note"
                                            class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                                        />
                                    </V2Field>
                                </div>
                            </template>
                        </div>
                    </V2FormCard>
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ formError }}
                    </p>
                    <V2StickyBar>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : 'Save visit' }}
                        </button>
                    </V2StickyBar>
                </form>
            </template>

            <template #rail>
                <V2StickySummary title="Summary" :items="summary">
                    <button
                        type="submit"
                        form="v2-edit-visit-form"
                        class="inline-flex items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Saving…' : 'Save visit' }}
                    </button>
                </V2StickySummary>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

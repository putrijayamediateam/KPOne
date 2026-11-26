<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{
    heading?: string;
    patientName: string;
    patientNumber?: string;
    newPatient: boolean;
    branch: string;
    visitType: 'consultation' | 'otc';
    doctor: string;
    reasons: string[];
    priority: 'normal' | 'urgent';
    coverage: 'self_pay' | 'panel';
    panel: string;
    memberReference: string;
}>();
const rows = computed(() => [
    { label: 'Patient', value: props.patientName || 'Name not entered' },
    {
        label: 'Patient record',
        value: props.newPatient
            ? 'New patient — created with this visit'
            : props.patientNumber || 'No patient selected',
    },
    { label: 'Branch', value: props.branch },
    {
        label: 'Visit',
        value: props.visitType === 'consultation' ? 'Consultation' : 'OTC',
    },
    {
        label: props.visitType === 'otc' ? 'Doctor (optional)' : 'Doctor',
        value: props.doctor,
    },
    {
        label: 'Urgency',
        value: props.priority === 'urgent' ? 'Urgent' : 'Normal',
    },
    {
        label: 'Who pays',
        value: props.coverage === 'panel' ? props.panel : 'Self-pay',
    },
]);
</script>

<template>
    <section
        aria-labelledby="registration-review-heading"
        class="rounded-lg border bg-card p-4 sm:p-5"
    >
        <h2 id="registration-review-heading" class="text-base font-semibold">
            {{ heading ?? 'Check and register' }}
        </h2>
        <p class="mt-1 text-sm text-muted-foreground">
            Review your current choices. Nothing is saved until you register the
            visit.
        </p>
        <dl class="mt-3 divide-y">
            <div
                v-for="row in rows"
                :key="row.label"
                class="grid gap-1 py-3 text-sm sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-4"
            >
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd class="min-w-0 font-medium break-words">{{ row.value }}</dd>
            </div>
            <div
                class="grid gap-1 py-3 text-sm sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-4"
            >
                <dt class="text-muted-foreground">
                    {{
                        visitType === 'otc'
                            ? 'Visit reasons (optional)'
                            : 'Visit reasons'
                    }}
                </dt>
                <dd class="min-w-0 break-words">
                    <ol v-if="reasons.length" class="space-y-1">
                        <li v-for="(reason, index) in reasons" :key="index">
                            {{ reason
                            }}<span
                                v-if="index === 0"
                                class="ml-2 text-xs text-muted-foreground"
                                >Primary</span
                            >
                        </li>
                    </ol>
                    <span v-else>No reasons selected</span>
                </dd>
            </div>
            <div
                v-if="coverage === 'panel' && memberReference.trim()"
                class="grid gap-1 py-3 text-sm sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-4"
            >
                <dt class="text-muted-foreground">Member reference</dt>
                <dd class="min-w-0 break-words">{{ memberReference }}</dd>
            </div>
        </dl>
    </section>
</template>

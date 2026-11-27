<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import CallReadinessActions from '@/components/queue/CallReadinessActions.vue';
import TreatmentProgressActions from '@/components/queue/TreatmentProgressActions.vue';
import TreatmentProgressBadge from '@/components/queue/TreatmentProgressBadge.vue';
import type { PatientBoardRow } from '@/types';
withDefaults(
    defineProps<{
        row: PatientBoardRow;
        branchId?: number;
        from?: 'registration' | 'queue';
        treatmentDialogKey: string | null;
        showTreatmentBadge?: boolean;
    }>(),
    { showTreatmentBadge: true },
);
defineEmits<{
    readinessChanged: [];
    'update:treatmentDialogKey': [key: string | null];
}>();
const statusClass = (tone: PatientBoardRow['statusTone']) =>
    ({
        neutral: 'border-border text-muted-foreground',
        waiting: 'border-border bg-muted text-foreground',
        serving: 'border-brand/25 bg-brand/10 text-brand-strong',
        held: 'border-warning/25 bg-warning/10 text-warning-strong',
        cancelled: 'border-border text-muted-foreground',
        removed: 'border-border text-muted-foreground',
    })[tone];
</script>
<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <span
            class="inline-flex rounded-md border px-2 py-0.5 text-[11px] font-normal"
            :class="statusClass(row.statusTone)"
            >{{ row.statusLabel }}</span
        >
        <span
            v-if="row.priority === 'urgent'"
            class="inline-flex rounded-md border border-destructive/25 bg-destructive/10 px-2 py-0.5 text-[11px] font-normal text-destructive"
            >Urgent</span
        >
        <slot name="triage" />
        <span
            v-if="row.returnedFromDispensary"
            class="inline-flex rounded-md border border-border bg-muted px-2 py-0.5 text-[11px] font-normal text-foreground"
            >Returned</span
        >
        <!-- Q-13 (Daniel): the doctor's own row whose dispensary case waits for the doctor's check. Plain text
                                 under the status; not interactive (the row's open-consultation action is the only way in). -->
        <span
            v-if="row.dispensaryCheckWaiting"
            class="block w-full rounded-md border border-warning/25 bg-warning/10 p-2 text-xs break-words text-warning-strong"
            data-testid="dispensary-check-waiting"
        >
            <span class="flex items-start gap-1.5 font-medium"
                ><AlertTriangle
                    class="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />Medicine ready for your check</span
            >
            <span class="mt-1 block text-warning-strong/80"
                >Open the consultation to verify.</span
            >
        </span>
        <span
            v-if="row.completedAt"
            class="text-[11px] text-muted-foreground"
            >{{ row.completedAt }}</span
        >
        <span
            v-if="row.callHold?.active || row.callHold?.overridden"
            class="block w-full rounded-md border border-warning/25 bg-warning/10 p-2 text-xs break-words text-warning-strong"
            data-testid="call-readiness-badge"
        >
            {{
                row.callHold.active
                    ? 'Not ready to call'
                    : 'Overridden by doctor'
            }}: {{ row.callHold.reason }}
        </span>
        <CallReadinessActions
            v-if="branchId"
            :row="row"
            :branch-id="branchId"
            :from="from ?? 'queue'"
            @changed="$emit('readinessChanged')"
        />
        <TreatmentProgressBadge
            :progress="row.treatmentProgress"
            :minutes="row.treatmentMinutes"
            v-if="showTreatmentBadge !== false"
        />
        <TreatmentProgressActions
            v-if="branchId && row.treatmentProgress"
            :open="treatmentDialogKey === row.key"
            :progress="row.treatmentProgress"
            :visit-number="row.visitNumber"
            :branch-id="branchId"
            from="queue"
            inline="running"
            :subject="`${row.queueNumber ?? row.visitNumber} · ${row.patientName}`"
            @update:open="
                $emit('update:treatmentDialogKey', $event ? row.key : null)
            "
        />
    </div>
</template>

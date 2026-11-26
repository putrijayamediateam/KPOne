<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TreatmentOutcomeFields from '@/components/treatment-room/TreatmentOutcomeFields.vue';
import TreatmentRoomRecordList from '@/components/treatment-room/TreatmentRoomRecordList.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import type {
    TreatmentRoomOptions,
    TreatmentRoomRecord,
    TreatmentRoomRecords,
    TreatmentRoomVisit,
} from '@/types/treatment-room';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Treatment room', href: '/treatment-room' }],
    },
});
const props = defineProps<{
    room: TreatmentRoomOptions;
    treatment: TreatmentRoomVisit;
    records: TreatmentRoomRecords;
}>();
const busy = ref(false);
const uncertain = ref(false);
const errors = ref<Record<string, string>>({});
const startProcedure = ref('');
const stepId = ref('');
const outcome = ref('done');
const reason = ref('');
const correction = ref<TreatmentRoomRecord | null>(null);
const correctionCode = ref('wrong_outcome');
const correctionProcedure = ref('');
const correctionOutcome = ref('done');
const correctionReason = ref('');
const voidTarget = ref<TreatmentRoomRecord | null>(null);
const eligibleSteps = computed(() =>
    props.treatment.steps.filter((step) => step.canRecordOutcome),
);
const selectedStep = computed(() =>
    eligibleSteps.value.find((step) => String(step.id) === stepId.value),
);
const hasStartedStep = computed(() =>
    props.treatment.steps.some((step) => step.state === 'started'),
);
const locked = computed(() => busy.value || uncertain.value);
type CommandPayload = Record<string, string | number | null>;
let pending: {
    action: string;
    payload: CommandPayload;
    fingerprint: string;
} | null = null;

watch(
    () => [props.treatment.visitNumber, props.treatment.branchId],
    () => {
        // Inertia may reuse the page across visits. Never retain another patient's draft or request key.
        pending = null;
        uncertain.value = false;
        errors.value = {};
        correction.value = null;
        voidTarget.value = null;
        startProcedure.value = '';
        stepId.value = '';
        outcome.value = 'done';
        reason.value = '';
    },
);
watch(
    eligibleSteps,
    (steps) => {
        if (!steps.some((step) => String(step.id) === stepId.value)) {
            stepId.value = steps[0] ? String(steps[0].id) : '';
        }
    },
    { immediate: true },
);

const reconcile = () => window.location.reload();
const send = (action: string, fields: CommandPayload, retry = false) => {
    if (busy.value || (uncertain.value && !retry)) {
        return;
    }

    if (!retry) {
        const payload = {
            expected_branch_id: props.treatment.branchId,
            visit_lock_version: props.treatment.visitLockVersion,
            queue_lock_version: props.treatment.queueLockVersion,
            ...fields,
        };
        const fingerprint = JSON.stringify([
            props.treatment.visitNumber,
            action,
            payload,
        ]);

        if (!pending || pending.fingerprint !== fingerprint) {
            pending = {
                action,
                payload: { ...payload, idempotency_key: crypto.randomUUID() },
                fingerprint,
            };
        }
    }

    if (!pending) {
        return;
    }

    busy.value = true;
    errors.value = {};
    router.post(
        `/treatment-room/${encodeURIComponent(props.treatment.visitNumber)}/${pending.action}`,
        pending.payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                pending = null;
                uncertain.value = false;
                correction.value = null;
                voidTarget.value = null;
                startProcedure.value = '';
                outcome.value = 'done';
                reason.value = '';
            },
            onError: (response) => {
                errors.value = response;
                uncertain.value = false;
            },
            onNetworkError: () => {
                uncertain.value = true;

                return false;
            },
            onHttpException: () => {
                uncertain.value = true;

                return false;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
const retry = () => send('', {}, true);
const record = () => {
    const step = selectedStep.value;

    if (!step) {
        return;
    }

    send('outcomes', {
        treatment_step_id: step.id,
        step_lock_version: step.lockVersion,
        procedure_code: step.code,
        outcome_code: outcome.value,
        reason_code: outcome.value === 'not_successful' ? reason.value : null,
    });
};
const openCorrection = (record: TreatmentRoomRecord) => {
    if (locked.value) {
        return;
    }

    correction.value = record;
    correctionCode.value = 'wrong_outcome';
    correctionProcedure.value = record.procedureCode;
    correctionOutcome.value = record.outcomeCode;
    correctionReason.value = record.reasonCode ?? '';
    errors.value = {};
};
watch(correctionCode, () => {
    if (!correction.value || locked.value) {
        return;
    }

    correctionProcedure.value = correction.value.procedureCode;
    correctionOutcome.value = correction.value.outcomeCode;
    correctionReason.value = correction.value.reasonCode ?? '';
});
const correct = () => {
    const target = correction.value;

    if (!target) {
        return;
    }

    const correctedOutcome =
        correctionCode.value === 'wrong_outcome'
            ? correctionOutcome.value
            : target.outcomeCode;
    send('corrections', {
        target_record_id: target.id,
        correction_code: correctionCode.value,
        procedure_code:
            correctionCode.value === 'wrong_procedure'
                ? correctionProcedure.value
                : target.procedureCode,
        outcome_code: correctedOutcome,
        reason_code:
            correctedOutcome === 'not_successful'
                ? correctionCode.value === 'wrong_outcome'
                    ? correctionReason.value
                    : target.reasonCode
                : null,
    });
};
const voidRecord = () => {
    if (voidTarget.value) {
        send('voids', {
            target_record_id: voidTarget.value.id,
            reason_code: 'wrong_patient',
        });
    }
};
const changeCorrectionOpen = (open: boolean) => {
    if (!open && !locked.value) {
        correction.value = null;
    }
};
const changeVoidOpen = (open: boolean) => {
    if (!open && !locked.value) {
        voidTarget.value = null;
    }
};
</script>

<template>
    <Head title="Treatment room record" />
    <main class="flex min-w-0 flex-1 flex-col">
        <PageFrame width="narrow" class="sm:px-6">
            <PageHeader
                title="Treatment room record"
                description="Record the procedure and its outcome. The doctor completes the consultation."
            >
                <template #actions
                    ><Button
                        variant="outline"
                        :disabled="locked"
                        @click="router.visit('/treatment-room')"
                        >Treatment room</Button
                    ></template
                >
            </PageHeader>
            <section
                class="space-y-1 rounded-lg border bg-card p-4 shadow-card"
                aria-label="Selected patient"
            >
                <h2 class="text-lg font-semibold">
                    {{ treatment.queueNumber }} · {{ treatment.patientName }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ treatment.patientNumber }} · {{ treatment.visitNumber }}
                </p>
                <p class="text-sm">
                    {{
                        treatment.canRecord
                            ? treatment.isHeld
                                ? 'On hold · Serving'
                                : 'Serving'
                            : 'Read only'
                    }}
                </p>
            </section>
            <div
                v-if="uncertain"
                class="space-y-3 rounded-lg border border-warning bg-warning-wash p-4"
                role="alert"
            >
                <p class="font-medium">
                    The result of the last action is not confirmed.
                </p>
                <p class="text-sm">
                    Keep this action unchanged and retry, or reload to check the
                    saved records before starting another action.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button :disabled="busy" @click="retry"
                        >Retry unchanged</Button
                    ><Button
                        variant="outline"
                        :disabled="busy"
                        @click="reconcile"
                        >Reload and check records</Button
                    >
                </div>
            </div>
            <div
                v-if="Object.keys(errors).length"
                class="space-y-2 rounded-lg border border-warning bg-warning-wash p-4"
                role="alert"
            >
                <p
                    v-for="(message, field) in errors"
                    :key="field"
                    class="text-sm"
                >
                    {{ message }}
                </p>
                <Button variant="outline" :disabled="busy" @click="reconcile"
                    >Reload current details</Button
                >
            </div>
            <template v-if="treatment.canRecord">
                <section
                    class="space-y-4 rounded-lg border bg-card p-4 shadow-card"
                >
                    <h2 class="font-semibold">Start treatment</h2>
                    <p
                        v-if="hasStartedStep"
                        class="text-sm text-muted-foreground"
                    >
                        Record the current treatment outcome before starting
                        another step.
                    </p>
                    <form
                        v-else
                        class="space-y-3"
                        @submit.prevent="
                            send('start', { procedure_code: startProcedure })
                        "
                    >
                        <label for="start-procedure" class="text-sm font-medium"
                            >Procedure or sample</label
                        >
                        <OperationalSelect
                            id="start-procedure"
                            v-model="startProcedure"
                            label="Procedure or sample"
                            :options="[
                                {
                                    value: '',
                                    label: 'Choose a procedure or sample',
                                },
                                ...room.procedures,
                            ]"
                            :disabled="locked"
                            trigger-class="h-9 w-full max-lg:min-h-11"
                        />
                        <InputError :message="errors.procedure_code" />
                        <Button
                            class="h-9 max-lg:h-11"
                            :disabled="locked || !startProcedure"
                            >Start treatment</Button
                        >
                    </form>
                </section>
                <section
                    v-if="eligibleSteps.length"
                    class="space-y-4 rounded-lg border bg-card p-4 shadow-card"
                >
                    <h2 class="font-semibold">Record outcome</h2>
                    <form class="space-y-4" @submit.prevent="record">
                        <div class="space-y-1">
                            <label
                                for="outcome-step"
                                class="text-sm font-medium"
                                >Treatment step</label
                            ><OperationalSelect
                                id="outcome-step"
                                v-model="stepId"
                                label="Treatment step"
                                :options="
                                    eligibleSteps.map((step) => ({
                                        value: String(step.id),
                                        label: `${step.label} · ${step.state}`,
                                    }))
                                "
                                :disabled="locked"
                                trigger-class="h-9 w-full max-lg:min-h-11"
                            />
                        </div>
                        <p v-if="selectedStep" class="text-sm">
                            Procedure: <strong>{{ selectedStep.label }}</strong>
                        </p>
                        <p
                            v-if="selectedStep?.state === 'cancelled'"
                            class="text-sm text-warning-strong"
                        >
                            This step was cancelled. Saving records a new
                            controlled step; the cancelled history stays
                            unchanged.
                        </p>
                        <TreatmentOutcomeFields
                            v-model:outcome="outcome"
                            v-model:reason="reason"
                            :reasons="room.reasons"
                            :disabled="locked"
                            :errors="errors"
                            id-prefix="record"
                        />
                        <Button
                            class="h-9 max-lg:h-11"
                            :disabled="
                                locked ||
                                !selectedStep ||
                                (outcome === 'not_successful' && !reason)
                            "
                            >Save outcome</Button
                        >
                    </form>
                </section>
            </template>
            <p v-else class="rounded-lg border bg-muted/30 p-4 text-sm">
                This record is read only. New entries and corrections require an
                authorized member of staff and a consultation that is Serving.
            </p>
            <section
                class="space-y-3 rounded-lg border bg-card p-4 shadow-card"
            >
                <h2 class="font-semibold">Treatment records</h2>
                <TreatmentRoomRecordList
                    :records="records"
                    :timezone="room.branch.timezone"
                    :can-correct="treatment.canRecord"
                    :busy="locked"
                    @correct="openCorrection"
                    @void="voidTarget = $event"
                />
            </section>
        </PageFrame>
    </main>
    <Dialog :open="correction !== null" @update:open="changeCorrectionOpen">
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader
                ><DialogTitle>Correct treatment record</DialogTitle
                ><DialogDescription
                    >The original evidence stays in history. Change only the
                    selected correction.</DialogDescription
                ></DialogHeader
            >
            <template v-if="correction">
                <p class="text-sm font-medium">
                    {{ treatment.patientName }} · {{ treatment.visitNumber }}
                </p>
                <label for="correction-kind" class="text-sm font-medium"
                    >Correction</label
                >
                <OperationalSelect
                    id="correction-kind"
                    v-model="correctionCode"
                    label="Correction"
                    :options="[
                        { value: 'wrong_outcome', label: 'Wrong outcome' },
                        { value: 'wrong_procedure', label: 'Wrong procedure' },
                    ]"
                    :disabled="locked"
                    trigger-class="h-9 w-full max-lg:min-h-11"
                />
                <template v-if="correctionCode === 'wrong_procedure'"
                    ><label
                        for="correction-procedure"
                        class="text-sm font-medium"
                        >Correct procedure or sample</label
                    ><OperationalSelect
                        id="correction-procedure"
                        v-model="correctionProcedure"
                        label="Correct procedure or sample"
                        :options="room.procedures"
                        :disabled="locked"
                        trigger-class="h-9 w-full max-lg:min-h-11"
                    />
                    <p class="text-sm">
                        Outcome stays {{ correction.outcomeLabel
                        }}<template v-if="correction.reasonLabel"
                            >: {{ correction.reasonLabel }}</template
                        >.
                    </p></template
                >
                <template v-else
                    ><p class="text-sm">
                        Procedure stays {{ correction.procedureLabel }}.
                    </p>
                    <TreatmentOutcomeFields
                        v-model:outcome="correctionOutcome"
                        v-model:reason="correctionReason"
                        :reasons="room.reasons"
                        :disabled="locked"
                        :errors="errors"
                        id-prefix="correct"
                /></template>
                <p v-if="uncertain" class="text-sm text-warning-strong">
                    The previous action is unconfirmed. Close nothing; use Retry
                    unchanged or reload below.
                </p>
                <InputError
                    v-for="(message, field) in errors"
                    :key="field"
                    :message="message"
                />
                <DialogFooter
                    ><Button
                        variant="outline"
                        class="h-9 max-lg:h-11"
                        :disabled="locked"
                        @click="correction = null"
                        >Cancel</Button
                    ><template v-if="uncertain"
                        ><Button :disabled="busy" @click="retry"
                            >Retry unchanged</Button
                        ><Button
                            variant="outline"
                            :disabled="busy"
                            @click="reconcile"
                            >Reload and check records</Button
                        ></template
                    ><Button
                        v-else
                        class="h-9 max-lg:h-11"
                        :disabled="
                            busy ||
                            (correctionOutcome === 'not_successful' &&
                                !correctionReason)
                        "
                        @click="correct"
                        >Save correction</Button
                    ></DialogFooter
                >
            </template>
        </DialogContent>
    </Dialog>
    <Dialog :open="voidTarget !== null" @update:open="changeVoidOpen">
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader
                ><DialogTitle
                    >Void record entered for the wrong patient?</DialogTitle
                ><DialogDescription
                    >This retains the original record as voided evidence on this
                    visit. It does not record treatment for another
                    patient.</DialogDescription
                ></DialogHeader
            >
            <p class="text-sm font-medium">
                {{ treatment.patientName }} · {{ treatment.visitNumber }}
            </p>
            <p class="text-sm">
                {{ voidTarget?.procedureLabel }} ·
                {{ voidTarget?.outcomeLabel }}
            </p>
            <p class="text-sm">
                If needed, open the correct patient's treatment room separately
                and record their treatment through the ordinary process.
            </p>
            <InputError
                v-for="(message, field) in errors"
                :key="field"
                :message="message"
            />
            <p v-if="uncertain" class="text-sm text-warning-strong">
                The last action is unconfirmed. Retry it unchanged or reload to
                check the record.
            </p>
            <DialogFooter
                ><Button
                    variant="outline"
                    class="h-9 max-lg:h-11"
                    :disabled="locked"
                    @click="voidTarget = null"
                    >Cancel</Button
                ><template v-if="uncertain"
                    ><Button :disabled="busy" @click="retry"
                        >Retry unchanged</Button
                    ><Button
                        variant="outline"
                        :disabled="busy"
                        @click="reconcile"
                        >Reload and check records</Button
                    ></template
                ><Button
                    v-else
                    variant="destructive"
                    class="h-9 max-lg:h-11"
                    :disabled="busy"
                    @click="voidRecord"
                    >Void wrong-patient record</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>
</template>

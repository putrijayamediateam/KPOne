<script setup lang="ts">
// DSP-23: Verify and Send back on a case waiting for the doctor's check. Used by the attending doctor on the
// consultation page and by a CA Supervisor who checks instead (owner answer 13 = B) on the Dispensary case page.
// Daniel's notes 1 and 2: both buttons are outline; red appears only in the Verify confirm sheet; the Send back
// picker starts empty and its confirm button stays off until a reason is chosen.
import { router } from '@inertiajs/vue3';
import { CheckCircle2, Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

const props = withDefaults(
    defineProps<{
        verifyUrl: string;
        sendBackUrl: string;
        caseLockVersion: number;
        expectedBranchId: number;
        asSupervisor?: boolean;
        // Daniel (change 5): a supervisor's sheet says whose check it replaces.
        doctorName?: string | null;
    }>(),
    { asSupervisor: false, doctorName: null },
);

const SEND_BACK_REASONS: Array<{ value: string; label: string }> = [
    { value: 'wrong_medicine', label: 'Wrong medicine' },
    { value: 'wrong_quantity', label: 'Wrong quantity' },
    { value: 'wrong_batch', label: 'Wrong batch' },
    { value: 'label_problem', label: 'Label problem' },
    { value: 'other', label: 'Other' },
];
const OVERRIDE_REASONS: Array<{ value: string; label: string }> = [
    { value: 'doctor_off_shift', label: 'The doctor is off shift' },
    { value: 'doctor_emergency', label: 'The doctor is in an emergency' },
    { value: 'other', label: 'Other' },
];

const verifyOpen = ref(false);
const sendBackOpen = ref(false);
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const reason = ref('');
const note = ref('');
const overrideReason = ref('');
const overrideNote = ref('');
// One key per attempt, so a double press records one decision (DSP-23 section 6).
const retryKey = ref(crypto.randomUUID());

const sendBackDisabledReason = computed(() => {
    if (reason.value === '') {
        return 'Pick a reason to continue.';
    }

    return reason.value === 'other' && note.value.trim() === ''
        ? 'Add a short note for "Other".'
        : '';
});
const verifyDisabledReason = computed(() => {
    if (!props.asSupervisor) {
        return '';
    }

    if (overrideReason.value === '') {
        return 'Pick why you are checking instead of the doctor.';
    }

    return overrideReason.value === 'other' && overrideNote.value.trim() === ''
        ? 'Add a short note for "Other".'
        : '';
});

const submit = (url: string, extra: Record<string, unknown>) => {
    busy.value = true;
    errors.value = {};
    router.post(
        url,
        {
            expected_branch_id: props.expectedBranchId,
            case_lock_version: props.caseLockVersion,
            retry_key: retryKey.value,
            ...extra,
        },
        {
            preserveScroll: true,
            onError: (failed) => {
                errors.value = failed;
                retryKey.value = crypto.randomUUID();
            },
            onSuccess: () => {
                verifyOpen.value = false;
                sendBackOpen.value = false;
            },
            onFinish: () => (busy.value = false),
        },
    );
};

const doctor = computed(() => props.doctorName ?? 'the doctor');
const verifyTitle = computed(() =>
    props.asSupervisor
        ? `Verify instead of ${doctor.value}?`
        : 'Verify and release to billing?',
);
const sendBackTitle = computed(() =>
    props.asSupervisor
        ? `Send back instead of ${doctor.value}`
        : 'Send back to the CA',
);
const firstError = computed(() => Object.values(errors.value)[0] ?? '');

const verify = () =>
    submit(
        props.verifyUrl,
        props.asSupervisor
            ? {
                  override_reason: overrideReason.value,
                  override_note:
                      overrideReason.value === 'other'
                          ? overrideNote.value
                          : null,
              }
            : {},
    );
const sendBack = () =>
    submit(props.sendBackUrl, {
        reason: reason.value,
        note: note.value.trim() === '' ? null : note.value,
    });
</script>

<template>
    <div class="flex flex-col gap-2" data-testid="doctor-check-actions">
        <div class="flex flex-col gap-2 sm:flex-row">
            <Button
                variant="outline"
                class="min-h-11 max-sm:w-full sm:min-h-9"
                :disabled="busy"
                data-testid="doctor-check-verify-open"
                @click="verifyOpen = true"
                ><CheckCircle2 class="size-4" />Verify</Button
            >
            <Button
                variant="outline"
                class="min-h-11 max-sm:w-full sm:min-h-9"
                :disabled="busy"
                data-testid="doctor-check-send-back-open"
                @click="sendBackOpen = true"
                ><Undo2 class="size-4" />Send back</Button
            >
        </div>
        <p class="text-xs text-muted-foreground">
            Verify dispenses the medicine and releases the visit to billing.
            Send back returns the case to the CA with your reason.
        </p>

        <OperationalConfirmDialog
            :open="verifyOpen"
            :title="verifyTitle"
            description="The medicine is dispensed when you confirm."
            confirm-label="Verify and release"
            processing-label="Verifying…"
            destructive
            :processing="busy"
            :error="firstError"
            :confirm-disabled="verifyDisabledReason !== ''"
            :confirm-disabled-reason="verifyDisabledReason"
            @update:open="verifyOpen = $event"
            @confirm="verify"
        >
            <fieldset
                v-if="asSupervisor"
                class="flex flex-col gap-2 text-sm"
                data-testid="doctor-check-override"
            >
                <legend class="mb-1 font-medium">
                    Why are you checking instead of the doctor?
                </legend>
                <label
                    v-for="option in OVERRIDE_REASONS"
                    :key="option.value"
                    class="flex min-h-11 items-center gap-2 sm:min-h-9"
                >
                    <input
                        v-model="overrideReason"
                        type="radio"
                        name="override_reason"
                        :value="option.value"
                    />
                    {{ option.label }}
                </label>
                <template v-if="overrideReason === 'other'">
                    <label class="font-medium" for="doctor-check-override-note"
                        >Short note</label
                    >
                    <textarea
                        id="doctor-check-override-note"
                        v-model="overrideNote"
                        maxlength="200"
                        rows="2"
                        class="rounded-md border bg-background px-2 py-1"
                    />
                    <InputError :message="errors.override_note" />
                </template>
                <p class="text-xs text-muted-foreground">
                    Your check and the reason are recorded.
                </p>
            </fieldset>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="sendBackOpen"
            :title="sendBackTitle"
            description="The case returns to the CA as Dispensing, with your reason shown at the top."
            confirm-label="Send back"
            processing-label="Sending back…"
            :processing="busy"
            :error="firstError"
            :confirm-disabled="sendBackDisabledReason !== ''"
            :confirm-disabled-reason="sendBackDisabledReason"
            @update:open="sendBackOpen = $event"
            @confirm="sendBack"
        >
            <fieldset
                class="flex flex-col gap-2 text-sm"
                data-testid="doctor-check-reasons"
            >
                <legend class="mb-1 font-medium">Reason</legend>
                <label
                    v-for="option in SEND_BACK_REASONS"
                    :key="option.value"
                    class="flex min-h-11 items-center gap-2 sm:min-h-9"
                >
                    <input
                        v-model="reason"
                        type="radio"
                        name="send_back_reason"
                        :value="option.value"
                    />
                    {{ option.label }}
                </label>
                <label class="font-medium" for="doctor-check-note">
                    Note{{ reason === 'other' ? '' : ' (optional)' }}
                </label>
                <textarea
                    id="doctor-check-note"
                    v-model="note"
                    maxlength="200"
                    rows="2"
                    class="rounded-md border bg-background px-2 py-1"
                />
                <InputError :message="errors.note" />
            </fieldset>
        </OperationalConfirmDialog>
    </div>
</template>

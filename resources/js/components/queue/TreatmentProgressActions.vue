<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Activity, CheckCircle2, ChevronDown, LoaderCircle } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    TREATMENT_CANCEL_REASONS,
    TREATMENT_STEPS,
    treatmentButtonLabel,
} from '@/lib/treatment-progress';
import type { TreatmentProgress } from '@/types';

// Q-2: start a controlled step, mark it done, or cancel one entered in error. The server re-checks everything.
// Board (Daniel's review): no inline button until a step runs; the row menu opens the dialog via v-model:open.
// Consultation page: `always` shows the button in every state.
const props = defineProps<{
    progress?: TreatmentProgress | null;
    visitNumber: string;
    branchId: number;
    from: 'queue' | 'consultation';
    inline?: 'running' | 'always';
    // Consultation top bar: a small toolbar button like its neighbours (Yasmin's check).
    compact?: boolean;
    // Names the record in the dialog, e.g. "A-004 · Synthetic Patient Delta" (Yasmin's check).
    subject?: string;
}>();
const emit = defineEmits<{ changed: [] }>();
const open = defineModel<boolean>('open', { default: false });
// Daniel's review of #256: the rare "entered in error" path stays collapsed until asked for.
const showCancel = ref(false);
watch(open, (value) => {
    if (value) {
        showCancel.value = false;
    }
});
// Focus the main action: "Mark done" while running, Close once done (the step select when empty).
const focusMainAction = (event: Event) => {
    if (!state.value) {
        return;
    }

    event.preventDefault();
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('[data-treatment-autofocus]')
            ?.focus(),
    );
};
const stepCode = ref('');
const cancelReason = ref('');
const error = ref('');
const busy = ref(false);
const busyAction = ref<'start' | 'done' | 'cancel' | null>(null);
// Q-2c (Mira): which action the error belongs to, so it shows beside the control in use.
const errorAction = ref<'start' | 'done' | 'cancel' | null>(null);
let key = '';
let keyAction = '';
const state = computed(() => props.progress?.state ?? null);
watch(
    () => [props.branchId, props.progress?.id, props.progress?.lockVersion],
    () => {
        open.value = false;
        stepCode.value = '';
        showCancel.value = false;
        cancelReason.value = '';
        error.value = '';
        key = '';
    },
);
watch([stepCode, cancelReason], () => {
    key = '';
});
const submit = (action: 'start' | 'done' | 'cancel') => {
    if (busy.value || !props.progress) {
        return;
    }

    errorAction.value = action;

    if (action === 'start' && !stepCode.value) {
        error.value = 'Select a treatment step.';

        return;
    }

    if (action === 'cancel' && !cancelReason.value) {
        error.value = 'Select why this step is being cancelled.';

        return;
    }

    if (keyAction !== action) {
        key = '';
        keyAction = action;
    }

    key ||= crypto.randomUUID();
    busy.value = true;
    busyAction.value = action;
    error.value = '';
    let succeeded = false;
    const base = `/visits/${encodeURIComponent(props.visitNumber)}/queue/treatment-steps`;
    router.visit(action === 'start' ? base : `${base}/${action}`, {
        method: action === 'start' ? 'post' : 'patch',
        data: {
            expected_branch_id: props.branchId,
            ...(action === 'start'
                ? { step_code: stepCode.value }
                : {
                      treatment_step_id: props.progress.id,
                      lock_version: props.progress.lockVersion,
                      ...(action === 'cancel'
                          ? { reason_code: cancelReason.value }
                          : {}),
                  }),
            idempotency_key: key,
            from: props.from,
        },
        preserveScroll: true,
        // On the consultation page keep unsaved clinical work (OH-06 lesson); props still refresh.
        preserveState: props.from === 'consultation',
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? 'The action failed.';
        },
        onSuccess: () => {
            open.value = false;
            succeeded = true;
        },
        onFinish: () => {
            busy.value = false;
            busyAction.value = null;

            if (succeeded) {
                emit('changed');
            }
        },
    });
};
</script>
<template>
    <Button
        v-if="
            progress?.canManage &&
            (inline === 'always' || progress.state === 'started')
        "
        type="button"
        variant="outline"
        :size="compact ? 'sm' : undefined"
        :class="
            compact
                ? 'w-auto gap-1.5 max-sm:min-h-11'
                : 'h-auto min-h-11 w-full py-2 text-xs leading-4 whitespace-normal'
        "
        data-testid="treatment-progress-button"
        @click="open = true"
    >
        <template v-if="compact">
            <CheckCircle2
                v-if="state === 'started'"
                class="size-4"
                aria-hidden="true"
            />
            <Activity v-else class="size-4" aria-hidden="true" />
        </template>
        {{ treatmentButtonLabel(progress) }}
    </Button>
    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="max-h-[90dvh] overflow-y-auto"
            @open-auto-focus="focusMainAction"
        >
            <DialogHeader>
                <DialogTitle>{{
                    state === 'started'
                        ? `Mark “${progress?.label}” done?`
                        : state === 'done'
                          ? `“${progress?.label}” is done`
                          : 'Start treatment step'
                }}</DialogTitle>
                <DialogDescription>
                    <span
                        v-if="subject"
                        class="block font-medium text-foreground"
                        data-testid="treatment-progress-subject"
                        >{{ subject }}</span
                    >
                    {{
                        state === 'started'
                            ? 'The attending doctor will be alerted to resume.'
                            : state === 'done'
                              ? 'Waiting for the doctor to resume. You can start the next step now.'
                              : 'Choose the step. This is for the board only. It is not a clinical record and is not shown on the TV.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <!-- Q-2c (Yasmin): the next step can start while a finished one waits for the doctor. -->
            <label v-if="!state || state === 'done'" class="grid gap-2"
                >Treatment step *
                <select
                    v-model="stepCode"
                    required
                    :aria-invalid="!!error && !stepCode"
                    aria-describedby="treatment-progress-error"
                    class="min-h-11 w-full rounded-md border bg-background px-3 aria-invalid:border-destructive dark:scheme-dark"
                >
                    <option value="">Select a step</option>
                    <option
                        v-for="item in TREATMENT_STEPS"
                        :key="item[0]"
                        :value="item[0]"
                    >
                        {{ item[1] }}
                    </option>
                </select>
                <span
                    v-if="error && errorAction !== 'cancel'"
                    id="treatment-progress-error"
                    role="alert"
                    class="text-sm text-destructive"
                    >{{ error }}</span
                >
            </label>
            <div v-if="state" class="grid gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    class="h-auto min-h-11 justify-start px-2 text-sm"
                    :aria-expanded="showCancel"
                    aria-controls="treatment-progress-cancel-panel"
                    data-testid="treatment-progress-cancel-toggle"
                    @click="showCancel = !showCancel"
                    >Entered in error?<ChevronDown
                        class="size-4 transition-transform"
                        :class="showCancel ? 'rotate-180' : ''"
                        aria-hidden="true"
                /></Button>
                <div
                    v-if="showCancel"
                    id="treatment-progress-cancel-panel"
                    class="grid gap-2 rounded-md border p-3"
                >
                    <p class="text-sm font-medium">
                        {{
                            state === 'done'
                                ? `Cancel “${progress?.label}” (entered in error)`
                                : 'Cancel this step (entered in error)'
                        }}
                    </p>
                    <label class="grid gap-2 text-sm"
                        >Reason *
                        <select
                            v-model="cancelReason"
                            required
                            :aria-invalid="!!error && !cancelReason"
                            aria-describedby="treatment-progress-cancel-error"
                            class="min-h-11 w-full rounded-md border bg-background px-3 aria-invalid:border-destructive dark:scheme-dark"
                        >
                            <option value="">Select a reason</option>
                            <option
                                v-for="item in TREATMENT_CANCEL_REASONS"
                                :key="item[0]"
                                :value="item[0]"
                            >
                                {{ item[1] }}
                            </option>
                        </select>
                        <span
                            v-if="
                                error &&
                                (errorAction === 'cancel' ||
                                    state === 'started')
                            "
                            id="treatment-progress-cancel-error"
                            role="alert"
                            class="text-sm text-destructive"
                            >{{ error }}</span
                        >
                    </label>
                    <Button
                        type="button"
                        variant="destructive"
                        class="min-h-11 gap-1.5"
                        :disabled="busy"
                        data-testid="treatment-progress-cancel"
                        @click="submit('cancel')"
                    >
                        <LoaderCircle
                            v-if="busyAction === 'cancel'"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        {{
                            busyAction === 'cancel'
                                ? 'Cancelling…'
                                : 'Cancel step'
                        }}
                    </Button>
                </div>
            </div>
            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    :disabled="busy"
                    :data-treatment-autofocus="
                        state === 'done' ? '' : undefined
                    "
                    @click="open = false"
                    >Close</Button
                >
                <Button
                    type="button"
                    class="min-h-11 gap-1.5"
                    :disabled="busy"
                    :data-treatment-autofocus="
                        state === 'started' ? '' : undefined
                    "
                    @click="submit(state === 'started' ? 'done' : 'start')"
                >
                    <LoaderCircle
                        v-if="busyAction === 'start' || busyAction === 'done'"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    {{
                        busyAction === 'start'
                            ? 'Starting…'
                            : busyAction === 'done'
                              ? 'Marking done…'
                              : state === 'started'
                                ? 'Mark done'
                                : state === 'done'
                                  ? 'Start next step'
                                  : 'Start'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

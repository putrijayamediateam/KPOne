<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, PhoneCall } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import StatusBadge from '@/components/ui/status/StatusBadge.vue';
import {
    CALL_NEXT_SWAP_PAUSE_MS,
    callNextDescription,
    callNextLabel,
    callNextSwapMessage,
    callNextTitle,
    sameCandidate,
} from '@/lib/call-next';
import { formatDate } from '@/lib/presentation';
import type { CallNextBlock, CallNextCandidate } from '@/types';

// Q-4 Call next (owner decisions 2026-11-05; Yasmin's sketch and Daniel's review). The person always confirms a
// named patient. If the server says that patient is no longer next, the dialog shows the new one and asks again;
// it never calls anyone else on its own.
const props = defineProps<{
    block: CallNextBlock;
    branchId: number;
    /** Another board action is running. */
    busy: boolean;
    /** Re-polls the board with the applied filters and resolves when the new block is in. */
    refresh: () => Promise<void>;
}>();
const emit = defineEmits<{ processing: [busy: boolean] }>();

const open = ref(false);
const confirmed = ref<CallNextCandidate | null>(null);
const gone = ref(false);
const processing = ref(false);
const paused = ref(false);
const error = ref('');
const liveMessage = ref('');
const titleRef = useTemplateRef<InstanceType<typeof DialogTitle>>('title');
const confirmRef = useTemplateRef<InstanceType<typeof Button>>('confirm');
let pauseTimer: ReturnType<typeof setTimeout> | undefined;

const ready = computed(
    () => props.block.state === 'ready' && props.block.candidate !== null,
);
const unavailable = computed(() => !ready.value || props.busy);
const label = computed(() => callNextLabel(props.block));

const start = () => {
    if (unavailable.value || processing.value) {
        return;
    }

    confirmed.value = { ...props.block.candidate! };
    gone.value = false;
    error.value = '';
    liveMessage.value = '';
    open.value = true;
};
const setOpen = (value: boolean) => {
    if (!processing.value) {
        open.value = value;
    }
};
const onOpenAutoFocus = (event: Event) => {
    event.preventDefault();
    (confirmRef.value?.$el as HTMLElement | undefined)?.focus();
};
const showLatest = async () => {
    await props.refresh();
    const next = props.block.state === 'ready' ? props.block.candidate : null;

    if (next === null) {
        gone.value = true;
        error.value = '';
        liveMessage.value = 'Nobody is waiting who can be called now.';

        return;
    }

    if (!sameCandidate(next, confirmed.value)) {
        confirmed.value = { ...next };
        error.value = '';
        liveMessage.value = callNextSwapMessage(next);
        // Daniel: hold the new Call button for a moment, so a second press meant for the old patient cannot land.
        paused.value = true;
        clearTimeout(pauseTimer);
        pauseTimer = setTimeout(
            () => (paused.value = false),
            CALL_NEXT_SWAP_PAUSE_MS,
        );
        await nextTick();
        (titleRef.value?.$el as HTMLElement | undefined)?.focus();
    }
};
const submit = () => {
    const candidate = confirmed.value;

    if (!candidate || processing.value || paused.value || gone.value) {
        return;
    }

    processing.value = true;
    emit('processing', true);
    error.value = '';
    router.post(
        '/queue/call-next',
        {
            expected_branch_id: props.branchId,
            doctor_user_id: props.block.doctorUserId,
            visit_number: candidate.visitNumber,
            visit_lock_version: candidate.visitLockVersion,
            queue_lock_version: candidate.queueLockVersion,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                if (errors.next_changed || errors.next_none) {
                    void showLatest();

                    return;
                }

                error.value =
                    Object.values(errors)[0] ??
                    'The patient could not be called.';
            },
            onFinish: () => {
                processing.value = false;
                emit('processing', false);
            },
        },
    );
};
onBeforeUnmount(() => clearTimeout(pauseTimer));
</script>

<template>
    <div
        class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3"
        data-testid="call-next"
    >
        <p
            v-if="!ready && block.reason"
            id="call-next-reason"
            class="text-xs text-muted-foreground sm:order-first"
            data-testid="call-next-reason"
        >
            {{ block.reason }}
        </p>
        <Button
            type="button"
            class="min-h-11 w-full sm:min-h-9 sm:w-auto"
            :aria-disabled="unavailable || processing ? 'true' : undefined"
            :aria-describedby="
                !ready && block.reason ? 'call-next-reason' : undefined
            "
            :class="
                unavailable || processing ? 'cursor-not-allowed opacity-50' : ''
            "
            data-testid="call-next-button"
            @click="start"
        >
            <LoaderCircle v-if="processing" class="size-4 animate-spin" />
            <PhoneCall v-else class="size-4" />{{
                processing ? 'Calling…' : label
            }}
        </Button>
    </div>

    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            class="gap-5 border-border/80 p-5 shadow-2xl shadow-black/15 sm:max-w-md sm:p-6 dark:bg-slate-900"
            :show-close-button="!processing"
            data-testid="call-next-dialog"
            @open-auto-focus="onOpenAutoFocus"
        >
            <DialogHeader class="gap-3 text-left">
                <div class="flex items-start gap-3.5">
                    <div
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-pink-50 text-pink-700 dark:bg-pink-950/50 dark:text-pink-300"
                        aria-hidden="true"
                    >
                        <PhoneCall class="size-5" />
                    </div>
                    <div class="min-w-0 space-y-1.5 pr-6">
                        <DialogTitle
                            ref="title"
                            tabindex="-1"
                            class="text-lg leading-6 break-words outline-none"
                            >{{
                                gone || !confirmed
                                    ? 'Nobody to call'
                                    : callNextTitle(confirmed)
                            }}</DialogTitle
                        >
                        <DialogDescription class="leading-5">{{
                            gone || !confirmed
                                ? 'Nobody is waiting who can be called now.'
                                : callNextDescription(confirmed)
                        }}</DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div
                v-if="confirmed && !gone"
                class="rounded-xl border border-border/70 bg-muted/35 px-4 py-3"
                data-testid="call-next-candidate"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-medium text-foreground">
                        {{ confirmed.patientName }}
                    </p>
                    <StatusBadge
                        v-if="confirmed.priority === 'urgent'"
                        status="urgent"
                        label="Urgent"
                        tone="danger"
                    />
                    <StatusBadge
                        v-if="confirmed.carryOver"
                        status="carry_over"
                        label="Carry-over"
                        tone="neutral"
                    />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Queue {{ confirmed.queueNumber }} ·
                    {{ confirmed.patientNumber }}
                </p>
                <p
                    v-if="confirmed.carryOver"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Waiting since {{ formatDate(confirmed.operationalDate) }}.
                </p>
            </div>

            <p class="sr-only" aria-live="polite" data-testid="call-next-live">
                {{ liveMessage }}
            </p>
            <p
                v-if="error"
                role="alert"
                class="rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
                {{ error }}
            </p>

            <DialogFooter class="gap-2 sm:gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="processing"
                    @click="setOpen(false)"
                    >{{ gone ? 'Close' : 'Cancel' }}</Button
                >
                <Button
                    v-if="confirmed && !gone"
                    ref="confirm"
                    type="button"
                    class="min-h-11 sm:min-h-9"
                    :disabled="processing || paused"
                    data-testid="call-next-confirm"
                    @click="submit"
                    ><LoaderCircle
                        v-if="processing"
                        class="size-4 animate-spin"
                    />{{
                        processing
                            ? 'Calling…'
                            : `Call ${confirmed.queueNumber}`
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

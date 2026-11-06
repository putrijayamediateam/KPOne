<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, PhoneCall, TriangleAlert } from '@lucide/vue';
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
    /** Q-4b: the held consultation this control sits on; a refusal returns there instead of the queue board. */
    fromVisit?: string;
}>();
const emit = defineEmits<{ processing: [busy: boolean] }>();

const open = ref(false);
const confirmed = ref<CallNextCandidate | null>(null);
const confirmedDoctorId = ref<number | null>(null);
const gone = ref(false);
const goneReason = ref('');
const goneTitle = ref('Nobody to call');
/** Visible, muted line under the patient card; the same words go to the live region. */
const notice = ref('');
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
    confirmedDoctorId.value = props.block.doctorUserId;
    gone.value = false;
    goneReason.value = '';
    goneTitle.value = 'Nobody to call';
    error.value = '';
    notice.value = '';
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
const focusTitle = async () => {
    await nextTick();
    (titleRef.value?.$el as HTMLElement | undefined)?.focus();
};
const latestCandidate = () =>
    props.block.state === 'ready' ? props.block.candidate : null;
/**
 * Re-poll and show the latest state. After "no longer next" this swaps to the new patient; after any other refusal
 * (for example the room was cleared) it shows why nobody can be called now, with Close as the only action.
 */
const showLatest = async (refusal?: string) => {
    let next: CallNextCandidate | null = null;

    try {
        // A poll already in flight can predate the refusal; poll once more before asking the person again.
        for (let attempt = 0; attempt < 2; attempt++) {
            await props.refresh();
            next = latestCandidate();

            if (!sameCandidate(next, confirmed.value)) {
                break;
            }
        }
    } catch {
        error.value =
            refusal ??
            'The queue could not be reloaded. Close this and try again.';

        return;
    }

    if (next === null) {
        // The real reason: nobody left, the doctor became busy, or the room was cleared.
        goneReason.value =
            props.block.reason ??
            refusal ??
            'Nobody is waiting who can be called now.';
        goneTitle.value = ['none_waiting', 'all_not_ready'].includes(
            props.block.state,
        )
            ? 'Nobody to call'
            : 'Cannot call now';
        gone.value = true;
        error.value = '';
        notice.value = '';
        liveMessage.value = goneReason.value;
        await focusTitle();

        return;
    }

    if (sameCandidate(next, confirmed.value)) {
        // The same patient is still next: show the refusal, or ask for another press if the board had not caught
        // up yet. Never call silently.
        error.value =
            refusal ??
            'The queue changed. Check the patient and press Call again.';

        return;
    }

    confirmed.value = { ...next };
    confirmedDoctorId.value = props.block.doctorUserId;
    error.value = '';
    notice.value = callNextSwapMessage(next);
    liveMessage.value = notice.value;
    // Daniel: hold the new Call button for a moment, so a second press meant for the old patient cannot land.
    paused.value = true;
    clearTimeout(pauseTimer);
    pauseTimer = setTimeout(
        () => (paused.value = false),
        CALL_NEXT_SWAP_PAUSE_MS,
    );
    await focusTitle();
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
            doctor_user_id: confirmedDoctorId.value,
            visit_number: candidate.visitNumber,
            visit_lock_version: candidate.visitLockVersion,
            queue_lock_version: candidate.queueLockVersion,
            ...(props.fromVisit
                ? { from: 'consultation', from_visit: props.fromVisit }
                : {}),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                if (errors.next_changed || errors.next_none) {
                    void showLatest().catch(() => undefined);

                    return;
                }

                const refusal =
                    Object.values(errors)[0] ??
                    'The patient could not be called.';
                error.value = refusal;
                // Daniel: re-check, so a refusal that blocks calling (no room, busy) leaves only Close.
                void showLatest(refusal).catch(() => undefined);
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
                                    ? goneTitle
                                    : callNextTitle(confirmed)
                            }}</DialogTitle
                        >
                        <DialogDescription class="leading-5">{{
                            gone || !confirmed
                                ? goneReason
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

            <!-- Daniel (Q-4b): the swap must be hard to miss. The live region below already reads it once. -->
            <p
                v-if="notice && !gone"
                class="-mt-2 flex items-start gap-2 rounded-lg border border-warning/25 bg-warning/10 px-3 py-2 text-xs text-warning-strong"
                data-testid="call-next-notice"
            >
                <TriangleAlert
                    class="mt-px size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span>{{ notice }}</span>
            </p>
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

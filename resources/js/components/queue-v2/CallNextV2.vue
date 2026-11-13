<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
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
const emit = defineEmits<{
    processing: [busy: boolean];
    dialog: [open: boolean];
    chooseRoom: [];
}>();

const open = ref(false);
// V2: the page pauses its 3 s poll while this dialog is open.
watch(open, (value) => emit('dialog', value));
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
const latestCandidate = () =>
    props.block.state === 'ready' ? props.block.candidate : null;
/**
 * Re-poll and show the latest state. After "no longer next" this swaps to the new patient; after any other refusal
 * (for example the room was cleared) it shows why nobody can be called now, with Close as the only action.
 */
// Classic moves focus to the title after a swap or when nobody is left; the V2 dialog takes focus on its own panel.
const focusDialog = () =>
    void nextTick(() =>
        document.querySelector<HTMLElement>('[data-slot="v2-dialog"]')?.focus(),
    );
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
        focusDialog();

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
    focusDialog();
    // Daniel: hold the new Call button for a moment, so a second press meant for the old patient cannot land.
    paused.value = true;
    clearTimeout(pauseTimer);
    pauseTimer = setTimeout(
        () => (paused.value = false),
        CALL_NEXT_SWAP_PAUSE_MS,
    );
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
onBeforeUnmount(() => {
    clearTimeout(pauseTimer);
    // The page pauses its poll while this dialog is open; never leave it paused when the control goes away.
    emit('dialog', false);
});
</script>

<template>
    <div class="flex flex-col gap-1.5 sm:items-end" data-testid="call-next">
        <button
            type="button"
            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-[10px] px-4 text-sm font-medium whitespace-nowrap focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none sm:w-auto lg:h-9"
            :class="[
                ready && !busy
                    ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                    : 'border border-border bg-card text-foreground',
                unavailable || processing
                    ? 'cursor-not-allowed opacity-60'
                    : '',
            ]"
            :aria-disabled="unavailable || processing ? 'true' : undefined"
            :aria-describedby="'call-next-status'"
            data-testid="call-next-button"
            @click="start"
        >
            <LoaderCircle v-if="processing" class="size-4 animate-spin" />{{
                processing ? 'Calling…' : label
            }}
        </button>
        <p
            id="call-next-status"
            class="text-[13px] text-muted-foreground sm:text-right"
            data-testid="call-next-reason"
        >
            <template v-if="ready && block.candidate"
                >Next: {{ block.candidate.queueNumber }} ·
                {{ block.candidate.patientName }}</template
            >
            <template v-else-if="block.state === 'no_room'"
                >Choose your room first.
                <button
                    type="button"
                    class="inline-flex h-11 items-center font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    @click="emit('chooseRoom')"
                >
                    Choose room
                </button></template
            >
            <template v-else>{{ block.reason ?? '' }}</template>
        </p>
    </div>

    <V2Dialog
        :open="open"
        :title="gone || !confirmed ? goneTitle : callNextTitle(confirmed)"
        :description="
            gone || !confirmed ? goneReason : callNextDescription(confirmed)
        "
        :confirm-label="
            gone || !confirmed
                ? 'Close'
                : processing
                  ? 'Calling…'
                  : `Call ${confirmed.queueNumber}`
        "
        cancel-label="Cancel"
        :busy="processing || paused"
        data-testid="call-next-dialog"
        @update:open="setOpen"
        @confirm="gone || !confirmed ? setOpen(false) : submit()"
    >
        <div
            v-if="confirmed && !gone"
            class="rounded-[14px] border bg-muted/40 px-4 py-3"
            data-testid="call-next-candidate"
        >
            <div class="flex flex-wrap items-center gap-2">
                <p class="font-medium text-foreground">
                    {{ confirmed.patientName }}
                </p>
                <V2Chip
                    v-if="confirmed.priority === 'urgent'"
                    tone="danger"
                    label="Urgent"
                />
                <V2Chip
                    v-if="confirmed.carryOver"
                    tone="neutral"
                    label="Carry-over"
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
        <p
            v-if="notice && !gone"
            class="flex items-start gap-2 rounded-[10px] border border-warning/25 bg-warning/10 px-3 py-2 text-xs text-warning-strong"
            data-testid="call-next-notice"
        >
            <TriangleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ notice }}</span>
        </p>
        <p class="sr-only" aria-live="polite" data-testid="call-next-live">
            {{ liveMessage }}
        </p>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </V2Dialog>
</template>

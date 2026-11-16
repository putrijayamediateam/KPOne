<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type { RefundsContext } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

/**
 * DD-83 (Billing part): the Version 2 refunds block. Same rules, routes and fields as the Classic
 * ReceiptRefunds.vue (BIL-6 refunds for a reversed receipt, BIL-10 refunds after completion), which stays
 * unchanged for the Classic page. V2 parts only: V2Picker for the method and reasons (they start empty and the
 * action stays off until both are picked), V2MoneyInput for a part refund, a reversed receipt's amount in words
 * ("Full amount RM x"), one filled action per receipt, and red only in the Reject confirm dialog.
 */
const props = defineProps<{
    refunds: RefundsContext;
    base: string;
    manage?: boolean;
    /** BIL-16: the Correct a receipt panel is shown below, so point staff to it. */
    correctionsBelow?: boolean;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const forms = reactive<
    Record<
        string,
        { method: string; reason: string; amount: string; key: string }
    >
>({});
const busy = reactive<Record<string, boolean>>({});
// The receipt whose form or decision was sent last, so its error shows beside it.
const lastSent = ref<string | null>(null);
const form = (publicId: string) =>
    (forms[publicId] ??= {
        method: '',
        reason: '',
        amount: '',
        key: crypto.randomUUID(),
    });
const errorMessage = computed(
    () =>
        errors.value.refund ??
        errors.value.reason_code ??
        errors.value.amount_sen ??
        errors.value.payment_method_id ??
        errors.value.expected_branch_id ??
        errors.value.idempotency_key,
);
const stateText = {
    waiting: 'Waiting for approval',
    approved: 'Approved',
    rejected: 'Rejected',
} as const;
const stateTone = {
    waiting: 'warning',
    approved: 'success',
    rejected: 'neutral',
} as const;

const methodPicks = computed(() =>
    props.refunds.methods.map((method) => ({
        id: String(method.id),
        label: method.name,
    })),
);
const reasonPicks = (kind: 'reversal' | 'after_completion') =>
    Object.entries(
        kind === 'after_completion'
            ? props.refunds.afterCompletionReasons
            : props.refunds.reasons,
    ).map(([id, label]) => ({ id, label }));
const rejectPicks = computed(() =>
    Object.entries(props.refunds.rejectReasons).map(([id, label]) => ({
        id,
        label,
    })),
);

type Receipt = RefundsContext['receipts'][number];
const missing = (receipt: Receipt): string | null => {
    const f = form(receipt.publicId);
    const parts = [
        receipt.kind === 'after_completion' && !f.amount ? 'an amount' : null,
        f.method ? null : 'a refund method',
        f.reason ? null : 'a reason',
    ].filter(Boolean) as string[];

    if (!parts.length) {
        return null;
    }

    const list =
        parts.length === 1
            ? parts[0]
            : `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}`;

    return `Pick ${list} to continue.`;
};
const hasDecision = (receipt: Receipt) =>
    receipt.refunds.some((refund) => refund.canDecide);

const send = (
    id: string,
    receipt: string,
    url: string,
    data: Record<string, unknown>,
    onDone?: () => void,
) => {
    busy[id] = true;
    lastSent.value = receipt;
    router.post(
        url,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            return_to: props.manage ? 'payments' : 'billing',
            ...data,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                delete forms[id];
                onDone?.();
            },
            onFinish: () => {
                busy[id] = false;
            },
        },
    );
};
const record = (receipt: Receipt) => {
    if (missing(receipt)) {
        return;
    }

    const f = form(receipt.publicId);
    send(
        receipt.publicId,
        receipt.publicId,
        `${props.base}/refunds/${receipt.publicId}`,
        {
            payment_method_id: Number(f.method),
            reason_code: f.reason,
            ...(receipt.kind === 'after_completion'
                ? { amount_sen: toSen(f.amount) }
                : {}),
            idempotency_key: f.key,
        },
    );
};
const approve = (receipt: string, refund: string) =>
    send(refund, receipt, `${props.base}/refunds/${refund}/approved`, {
        reason_code: null,
        idempotency_key: crypto.randomUUID(),
    });

// Reject: a confirm dialog with the reject reason; its confirm is the only red button.
const rejecting = ref<{
    receipt: string;
    refund: string;
    amountSen: number;
} | null>(null);
const rejectReason = ref<string | null>(null);
const openReject = (receipt: string, refund: string, amountSen: number) => {
    rejecting.value = { receipt, refund, amountSen };
    rejectReason.value = null;
};
const closeReject = (open: boolean) => {
    if (!open && !(rejecting.value && busy[rejecting.value.refund])) {
        rejecting.value = null;
    }
};
const confirmReject = () => {
    const target = rejecting.value;

    if (!target || !rejectReason.value) {
        return;
    }

    send(
        target.refund,
        target.receipt,
        `${props.base}/refunds/${target.refund}/rejected`,
        {
            reason_code: rejectReason.value,
            idempotency_key: crypto.randomUUID(),
        },
        () => {
            rejecting.value = null;
        },
    );
};
</script>

<template>
    <section
        class="rounded-xl border bg-card p-4 text-[15px]"
        data-testid="receipt-refunds-v2"
    >
        <header class="space-y-1 pb-2">
            <h2 class="font-medium">Refunds</h2>
            <p
                v-if="refunds.visitCompleted"
                class="text-sm text-muted-foreground"
            >
                Give back part or all of what the patient paid on a receipt.
                Someone else must approve it. Once approved, the invoice is
                lowered by the same amount, so nothing becomes due again. The
                receipt does not change.
            </p>
            <p
                v-if="refunds.visitCompleted && correctionsBelow"
                class="text-sm text-muted-foreground"
            >
                If the receipt was entered wrongly, use Correct a receipt below.
            </p>
            <!-- One explanation: the completed-visit text above, or this one (Classic shows both on a completed visit
                 without corrections). -->
            <p
                v-if="!refunds.visitCompleted"
                class="text-sm text-muted-foreground"
            >
                Record money handed back to the patient for a reversed receipt.
                Someone else must approve it. The invoice and receipt do not
                change.
            </p>
        </header>
        <InputError v-if="lastSent === null" :message="errorMessage" />
        <article
            v-for="receipt in refunds.receipts"
            :key="receipt.publicId"
            class="space-y-3 border-t py-3 last:pb-0"
            data-testid="refund-receipt-v2"
        >
            <p class="tabular-nums">
                <span class="font-medium">{{ receipt.number }}</span>
                · {{ receipt.method }} · {{ myr(receipt.amountSen) }} ·
                {{
                    receipt.kind === 'after_completion'
                        ? `Received · ${receipt.amountSen > receipt.refundableSen ? `${myr(receipt.amountSen - receipt.refundableSen)} refunded · ` : ''}${myr(receipt.refundableSen)} left to refund`
                        : 'Reversed'
                }}
            </p>
            <InputError
                v-if="lastSent === receipt.publicId"
                :message="errorMessage"
            />
            <div
                v-for="refund in receipt.refunds"
                :key="refund.publicId"
                class="space-y-2 rounded-lg border bg-muted/30 p-3"
                data-testid="refund-entry-v2"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="tabular-nums"
                        >Refund {{ myr(refund.amountSen) }} by
                        {{ refund.method }} · {{ refund.reason }}</span
                    >
                    <StatusBadge
                        :status="`refund_${refund.state}`"
                        :tone="stateTone[refund.state]"
                        :label="stateText[refund.state]"
                    />
                </div>
                <p class="text-sm text-muted-foreground">
                    Paid out {{ refund.paidOutAt }}, recorded by
                    {{ refund.recordedBy
                    }}{{
                        refund.decidedBy
                            ? ` · ${refund.state === 'approved' ? 'approved' : 'rejected'} by ${refund.decidedBy}`
                            : ''
                    }}{{
                        refund.rejectReason ? ` (${refund.rejectReason})` : ''
                    }}
                </p>
                <div v-if="refund.canDecide" class="flex flex-wrap gap-2">
                    <Button
                        class="max-lg:min-h-11"
                        :aria-label="`Approve refund of ${myr(refund.amountSen)}`"
                        :disabled="busy[refund.publicId]"
                        @click="approve(receipt.publicId, refund.publicId)"
                        >Approve refund</Button
                    >
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :aria-label="`Reject refund of ${myr(refund.amountSen)}`"
                        :disabled="busy[refund.publicId]"
                        @click="
                            openReject(
                                receipt.publicId,
                                refund.publicId,
                                refund.amountSen,
                            )
                        "
                        >Reject</Button
                    >
                </div>
                <p
                    v-else-if="
                        refund.state === 'waiting' && refund.recordedByYou
                    "
                    class="text-sm text-muted-foreground"
                    data-testid="refund-recorder-hint-v2"
                >
                    {{
                        refunds.can.approve
                            ? 'You recorded this refund. Someone else must approve it.'
                            : 'You recorded this refund. Waiting for a Finance Officer or Director to approve it.'
                    }}
                </p>
            </div>
            <form
                v-if="receipt.canRecord"
                class="grid gap-3 sm:grid-cols-2"
                data-testid="refund-form-v2"
                @submit.prevent="record(receipt)"
            >
                <V2MoneyInput
                    v-if="receipt.kind === 'after_completion'"
                    v-model="form(receipt.publicId).amount"
                    class="sm:col-span-2"
                    label="Amount given back"
                    :hint="`Up to ${myr(receipt.refundableSen)}`"
                    required
                />
                <p
                    v-else
                    class="text-[15px] tabular-nums sm:col-span-2"
                    data-testid="refund-full-amount"
                >
                    Full amount {{ myr(receipt.amountSen) }}
                </p>
                <V2Picker
                    :model-value="form(receipt.publicId).method || null"
                    label="Given back by"
                    :options="methodPicks"
                    placeholder="Choose a refund method"
                    required
                    @update:model-value="
                        form(receipt.publicId).method = $event ?? ''
                    "
                />
                <V2Picker
                    :model-value="form(receipt.publicId).reason || null"
                    label="Reason"
                    :options="reasonPicks(receipt.kind)"
                    placeholder="Choose a reason"
                    required
                    @update:model-value="
                        form(receipt.publicId).reason = $event ?? ''
                    "
                />
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <Button
                        type="submit"
                        :variant="hasDecision(receipt) ? 'outline' : 'default'"
                        class="max-lg:min-h-11"
                        :disabled="
                            busy[receipt.publicId] || missing(receipt) !== null
                        "
                        >{{
                            receipt.kind === 'after_completion'
                                ? 'Record refund'
                                : `Record refund of ${myr(receipt.amountSen)}`
                        }}</Button
                    >
                    <p
                        v-if="missing(receipt)"
                        class="text-sm text-muted-foreground"
                        data-testid="refund-missing-v2"
                    >
                        {{ missing(receipt) }}
                    </p>
                </div>
            </form>
        </article>

        <V2Dialog
            :open="rejecting !== null"
            title="Reject this refund?"
            :description="
                rejecting
                    ? `The refund of ${myr(rejecting.amountSen)} is not paid. The invoice and receipt do not change.`
                    : undefined
            "
            confirm-label="Reject refund"
            tone="danger"
            :busy="rejecting ? busy[rejecting.refund] : false"
            :confirm-disabled="!rejectReason"
            confirm-disabled-reason="Pick a reason to continue."
            @update:open="closeReject"
            @confirm="confirmReject"
        >
            <V2Picker
                :model-value="rejectReason"
                label="Reason"
                :options="rejectPicks"
                placeholder="Choose a reason"
                required
                @update:model-value="rejectReason = $event"
            />
        </V2Dialog>
    </section>
</template>

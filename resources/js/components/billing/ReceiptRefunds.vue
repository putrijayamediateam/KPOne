<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { RefundsContext } from '@/types/billing';
import { myr } from '@/types/billing';

/**
 * BIL-6: money handed back to the patient for a reversed receipt. One person records it; someone else approves
 * or rejects it. It never changes the invoice or the receipt.
 */
const props = defineProps<{
    refunds: RefundsContext;
    base: string;
    manage?: boolean;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const forms = reactive<
    Record<string, { method: string; reason: string; key: string }>
>({});
const rejectReason = reactive<Record<string, string>>({});
const busy = reactive<Record<string, boolean>>({});
// The receipt whose form or decision was sent last, so its error shows beside it.
const lastSent = ref<string | null>(null);
const form = (publicId: string) =>
    (forms[publicId] ??= {
        method: '',
        reason: '',
        key: crypto.randomUUID(),
    });
const errorMessage = computed(
    () =>
        errors.value.refund ??
        errors.value.reason_code ??
        errors.value.payment_method_id ??
        errors.value.expected_branch_id ??
        errors.value.idempotency_key,
);
const stateText = {
    waiting: 'Waiting for approval',
    approved: 'Approved',
    rejected: 'Rejected',
} as const;

const send = (
    id: string,
    receipt: string,
    url: string,
    data: Record<string, unknown>,
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
            },
            onFinish: () => {
                busy[id] = false;
            },
        },
    );
};
const record = (receipt: string) => {
    const f = form(receipt);
    send(receipt, receipt, `${props.base}/refunds/${receipt}`, {
        payment_method_id: Number(f.method),
        reason_code: f.reason,
        idempotency_key: f.key,
    });
};
const decide = (
    receipt: string,
    refund: string,
    decision: 'approved' | 'rejected',
) =>
    send(refund, receipt, `${props.base}/refunds/${refund}/${decision}`, {
        reason_code:
            decision === 'rejected' ? rejectReason[refund] || null : null,
        idempotency_key: crypto.randomUUID(),
    });
</script>

<template>
    <section
        class="rounded-xl border bg-card p-4 text-[13px]"
        data-testid="receipt-refunds"
    >
        <header class="pb-2">
            <h2 class="font-medium">Refunds</h2>
            <p class="text-xs text-muted-foreground">
                Record money handed back to the patient for a reversed receipt.
                Someone else must approve it. The invoice and receipt do not
                change.
            </p>
        </header>
        <InputError v-if="lastSent === null" :message="errorMessage" />
        <p
            v-if="refunds.visitCompleted"
            class="pt-1 text-xs text-muted-foreground"
        >
            This visit is completed. A refund after completion is not available
            yet.
        </p>
        <article
            v-for="receipt in refunds.receipts"
            :key="receipt.publicId"
            class="space-y-2 border-t py-3 last:pb-0"
            data-testid="refund-receipt"
        >
            <p class="text-sm">
                <span class="font-medium">{{ receipt.number }}</span>
                · {{ receipt.method }} · {{ myr(receipt.amountSen) }} · Reversed
            </p>
            <InputError
                v-if="lastSent === receipt.publicId"
                :message="errorMessage"
            />
            <div
                v-for="refund in receipt.refunds"
                :key="refund.publicId"
                class="rounded-md bg-muted/40 p-2 text-sm"
                data-testid="refund-entry"
            >
                <p>
                    Refund {{ myr(refund.amountSen) }} by {{ refund.method }} ·
                    {{ refund.reason }} ·
                    <span class="font-medium">{{
                        stateText[refund.state]
                    }}</span>
                </p>
                <p class="text-xs text-muted-foreground">
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
                <div
                    v-if="refund.canDecide"
                    class="mt-2 flex flex-wrap items-end gap-2"
                >
                    <label class="grid gap-1 text-xs font-medium">
                        Reason (needed to reject)
                        <select
                            :value="rejectReason[refund.publicId] ?? ''"
                            @change="
                                rejectReason[refund.publicId] = (
                                    $event.target as HTMLSelectElement
                                ).value
                            "
                            class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                        >
                            <option value="">Choose</option>
                            <option
                                v-for="(label, code) in refunds.rejectReasons"
                                :key="code"
                                :value="code"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </label>
                    <Button
                        variant="outline"
                        size="sm"
                        class="max-sm:min-h-11"
                        data-touch-target
                        :aria-label="`Reject refund of ${myr(refund.amountSen)}`"
                        :disabled="
                            busy[refund.publicId] ||
                            !rejectReason[refund.publicId]
                        "
                        @click="
                            decide(
                                receipt.publicId,
                                refund.publicId,
                                'rejected',
                            )
                        "
                        >Reject</Button
                    >
                    <Button
                        size="sm"
                        class="max-sm:min-h-11"
                        data-touch-target
                        :aria-label="`Approve refund of ${myr(refund.amountSen)}`"
                        :disabled="busy[refund.publicId]"
                        @click="
                            decide(
                                receipt.publicId,
                                refund.publicId,
                                'approved',
                            )
                        "
                        >Approve refund</Button
                    >
                </div>
                <p
                    v-else-if="
                        refund.state === 'waiting' && refund.recordedByYou
                    "
                    class="mt-1 text-xs text-muted-foreground"
                    data-testid="refund-recorder-hint"
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
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="record(receipt.publicId)"
            >
                <label class="grid gap-1 text-xs font-medium">
                    Given back by
                    <select
                        v-model="form(receipt.publicId).method"
                        class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                        required
                    >
                        <option value="">Choose</option>
                        <option
                            v-for="method in refunds.methods"
                            :key="method.id"
                            :value="String(method.id)"
                        >
                            {{ method.name }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-medium">
                    Reason
                    <select
                        v-model="form(receipt.publicId).reason"
                        class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                        required
                    >
                        <option value="">Choose</option>
                        <option
                            v-for="(label, code) in refunds.reasons"
                            :key="code"
                            :value="code"
                        >
                            {{ label }}
                        </option>
                    </select>
                </label>
                <Button
                    type="submit"
                    size="sm"
                    class="max-sm:min-h-11"
                    data-touch-target
                    :disabled="busy[receipt.publicId]"
                    >Record refund of {{ myr(receipt.amountSen) }}</Button
                >
            </form>
        </article>
    </section>
</template>

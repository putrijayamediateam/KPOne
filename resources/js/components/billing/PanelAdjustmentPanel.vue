<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatStatusLabel } from '@/lib/presentation';
import type { PanelAdjustment, PanelAdjustmentClaim } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

/**
 * PCA-2 (Daniel's board v10, sections 1 and 2): the Panel share as "original + Panel claim adjustment" beside the
 * amount claimed, the button that changes the invoice, and a waiting request with Confirm adjustment and Withdraw
 * request. Shared by the claim page and the Billing page, Classic and Version 2 (a new rule, so both layouts). The
 * server decides every amount and refusal; this page only shows them.
 */
const props = defineProps<{
    adjustment: PanelAdjustment;
    claim?: PanelAdjustmentClaim | null;
    canClaimOnly?: boolean;
}>();
const emit = defineEmits<{ claimOnly: [] }>();
const page = usePage();
const open = ref(false);
const busy = ref(false);
const direction = ref<'add' | 'deduct'>('add');
const amount = ref('');
const reason = ref('');
const note = ref('');
const key = ref(crypto.randomUUID());
const confirmKey = ref(crypto.randomUUID());
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const onClaim = computed(() => props.adjustment.from === 'claim');
const sen = computed(() => toSen(amount.value));
const delta = computed(() =>
    sen.value === null
        ? null
        : (direction.value === 'add' ? 1 : -1) * Number(sen.value),
);
const ready = computed(
    () =>
        sen.value !== null &&
        Number(sen.value) > 0 &&
        reason.value !== '' &&
        (reason.value !== 'other' || note.value.trim() !== ''),
);
const invoiceItemsSen = computed(
    () => props.adjustment.invoicePayableSen - props.adjustment.adjustmentSen,
);
const extraClaimedSeparatelySen = computed(() =>
    props.adjustment.supplementaryClaims.reduce((t, c) => t + c.amountSen, 0),
);
const reasonsText = computed(() =>
    props.adjustment.reasonsApplied.length
        ? ` (${props.adjustment.reasonsApplied.join(', ')})`
        : '',
);
const branchId = () => page.props.branchContext?.active?.id ?? 0;
const reset = () => {
    direction.value = 'add';
    amount.value = '';
    reason.value = '';
    note.value = '';
    key.value = crypto.randomUUID();
};
const submit = () => {
    if (!ready.value || busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        props.adjustment.requestUrl,
        {
            expected_branch_id: branchId(),
            invoice_lock_version: props.adjustment.invoiceLockVersion,
            direction: direction.value,
            amount_sen: sen.value,
            reason_code: reason.value,
            note: note.value.trim() === '' ? null : note.value.trim(),
            idempotency_key: key.value,
            from: props.adjustment.from,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                reset();
            },
            onFinish: () => (busy.value = false),
        },
    );
};
const decide = (kind: 'confirm' | 'withdraw') => {
    const waiting = props.adjustment.waiting;

    if (!waiting || busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        kind === 'confirm' ? waiting.confirmUrl : waiting.withdrawUrl,
        {
            expected_branch_id: branchId(),
            lock_version: waiting.lockVersion,
            idempotency_key: confirmKey.value,
            from: props.adjustment.from,
        },
        {
            preserveScroll: true,
            onSuccess: () => (confirmKey.value = crypto.randomUUID()),
            onFinish: () => (busy.value = false),
        },
    );
};
const signed = (dir: string, value: number) =>
    dir === 'add' ? `+${myr(value)}` : `−${myr(value)}`;
</script>

<template>
    <section
        class="grid gap-3 rounded-lg border p-4 text-sm"
        data-testid="panel-adjustment"
        aria-labelledby="panel-adjustment-title"
    >
        <h3 id="panel-adjustment-title" class="font-medium">
            {{ onClaim ? 'Panel share and claim' : 'Panel share' }}
        </h3>

        <!-- Billing and invoice page (board section 1). -->
        <template v-if="!onClaim">
            <p class="tabular-nums" data-testid="panel-adjustment-share">
                <template v-if="adjustment.adjustmentSen !== 0">
                    Panel share
                    <span class="font-semibold whitespace-nowrap">{{
                        myr(adjustment.shareSen)
                    }}</span>
                    =
                    <span class="whitespace-nowrap">{{
                        myr(adjustment.originalShareSen)
                    }}</span>
                    {{ adjustment.adjustmentSen > 0 ? '+' : '−' }} Panel claim
                    adjustment
                    <span class="whitespace-nowrap">{{
                        myr(Math.abs(adjustment.adjustmentSen))
                    }}</span
                    >{{ reasonsText }}. The original
                    {{ myr(adjustment.originalShareSen) }} is kept in Logs.
                </template>
                <template v-else>
                    Panel share
                    <span class="font-semibold whitespace-nowrap">{{
                        myr(adjustment.shareSen)
                    }}</span>
                </template>
            </p>
            <p
                v-if="adjustment.claim"
                class="tabular-nums"
                data-testid="panel-adjustment-claim"
            >
                Panel Claim
                {{ formatStatusLabel(adjustment.claim.statusKey) }} · Claim
                number {{ adjustment.claim.number }} · Amount claimed
                <span class="whitespace-nowrap">{{
                    myr(adjustment.claim.amountSen)
                }}</span>
            </p>
            <p
                v-if="adjustment.claim?.changedAfterSubmission"
                class="font-medium"
            >
                Amount changed after submission: tell the Panel.
            </p>
        </template>

        <!-- Claim page (board section 2). -->
        <template v-else>
            <dl
                v-if="!claim?.decided"
                class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 tabular-nums"
                data-testid="panel-adjustment-figures"
            >
                <dt>Invoice items</dt>
                <dd class="text-right whitespace-nowrap">
                    {{ myr(invoiceItemsSen) }}
                </dd>
                <template v-if="adjustment.adjustmentSen !== 0">
                    <dt>Panel adjustment (confirmed)</dt>
                    <dd class="text-right whitespace-nowrap">
                        {{
                            adjustment.adjustmentSen > 0
                                ? `+ ${myr(adjustment.adjustmentSen)}`
                                : `− ${myr(-adjustment.adjustmentSen)}`
                        }}
                    </dd>
                </template>
                <dt>Panel share on the invoice</dt>
                <dd class="text-right whitespace-nowrap">
                    {{ myr(adjustment.shareSen) }}
                </dd>
                <dt class="font-medium">Amount claimed</dt>
                <dd class="text-right font-medium whitespace-nowrap">
                    {{ myr(adjustment.claimedSen) }}
                </dd>
            </dl>
            <template v-else>
                <dl
                    class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 tabular-nums"
                    data-testid="panel-adjustment-figures"
                >
                    <dt>Accepted by the Panel</dt>
                    <dd class="text-right whitespace-nowrap">
                        {{ myr(adjustment.claim?.acceptedSen ?? 0) }}
                    </dd>
                    <dt>Panel share on the invoice</dt>
                    <dd class="text-right whitespace-nowrap">
                        {{ myr(adjustment.shareSen) }}
                    </dd>
                </dl>
                <p v-if="extraClaimedSeparatelySen > 0">
                    This claim is decided, so it is not changed. The extra
                    {{ myr(extraClaimedSeparatelySen) }} is claimed separately
                    below.
                </p>
            </template>
            <div
                v-if="claim?.kind !== 'supplementary'"
                class="grid gap-2"
                data-testid="panel-adjustment-change"
            >
                <h4 class="font-medium">Change the amount</h4>
                <div class="flex flex-wrap gap-2">
                    <Button
                        class="max-lg:min-h-11"
                        data-touch-target
                        v-if="adjustment.canRequest"
                        variant="outline"
                        data-testid="panel-adjustment-open"
                        @click="open = true"
                        >Adjust Panel amount: changes the invoice</Button
                    >
                    <Button
                        class="max-lg:min-h-11"
                        data-touch-target
                        v-if="canClaimOnly"
                        variant="outline"
                        data-testid="panel-adjustment-claim-only"
                        @click="emit('claimOnly')"
                        >Change claim amount only: the invoice stays the
                        same</Button
                    >
                </div>
                <p class="text-muted-foreground">
                    The first updates the invoice, the Panel share and the
                    claim. The second changes only this claim, and its Logs line
                    says "claim only".
                </p>
            </div>
            <ul
                v-if="adjustment.supplementaryClaims.length"
                class="grid gap-2"
                data-testid="panel-adjustment-supplementary"
            >
                <li class="font-medium">Supplementary claims</li>
                <li
                    v-for="child in adjustment.supplementaryClaims"
                    :key="child.number"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 tabular-nums"
                >
                    <span
                        >{{ child.number }} · supplementary to
                        {{ adjustment.claim?.number }}
                        <span class="whitespace-nowrap">{{
                            myr(child.amountSen)
                        }}</span
                        ><template v-if="child.reason"
                            >, Reason {{ child.reason }}</template
                        >, Status {{ formatStatusLabel(child.statusKey) }}</span
                    >
                    <Button
                        class="max-lg:min-h-11 max-sm:!h-auto max-sm:!min-h-11"
                        data-touch-target
                        v-if="child.url"
                        variant="outline"
                        size="sm"
                        as-child
                        ><Link data-touch-target :href="child.url"
                            >Open supplementary claim</Link
                        ></Button
                    >
                </li>
            </ul>
        </template>

        <div
            v-if="!onClaim && (adjustment.claimUrl || adjustment.canRequest)"
            class="flex flex-wrap gap-2"
        >
            <Button
                class="max-lg:min-h-11 max-sm:!h-auto max-sm:!min-h-11"
                data-touch-target
                v-if="adjustment.claimUrl"
                variant="outline"
                size="sm"
                as-child
                ><Link data-touch-target :href="adjustment.claimUrl"
                    >Open Panel claim</Link
                ></Button
            >
            <Button
                class="max-lg:min-h-11"
                data-touch-target
                v-if="adjustment.canRequest"
                variant="outline"
                size="sm"
                data-testid="panel-adjustment-open"
                @click="open = true"
                >Adjust Panel amount: changes the invoice</Button
            >
        </div>
        <InputError :message="errors.adjustment ?? errors.amount_sen" />

        <!-- Step 2: confirm (board section 3). -->
        <div
            v-if="adjustment.waiting"
            class="grid gap-2 rounded-md border border-warning/40 bg-warning/10 p-3"
            data-testid="panel-adjustment-waiting"
        >
            <p class="font-medium tabular-nums">
                Panel adjustment Requested · Invoice
                {{ adjustment.invoiceNumber }} · Panel share
                <span class="whitespace-nowrap">{{
                    myr(adjustment.shareSen)
                }}</span>
                →
                <span class="whitespace-nowrap">{{
                    myr(adjustment.waiting.newShareSen)
                }}</span>
                · {{ adjustment.waiting.reason }} · Requested by
                {{ adjustment.waiting.requestedBy }},
                {{ adjustment.waiting.requestedAt }}
            </p>
            <p
                v-if="adjustment.waiting.note"
                class="break-words whitespace-pre-wrap text-muted-foreground"
            >
                {{ adjustment.waiting.note }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    class="max-lg:min-h-11"
                    data-touch-target
                    v-if="adjustment.waiting.canConfirm"
                    :disabled="busy"
                    data-testid="panel-adjustment-confirm"
                    @click="decide('confirm')"
                    >Confirm adjustment</Button
                >
                <Button
                    class="max-lg:min-h-11"
                    data-touch-target
                    v-if="adjustment.waiting.canWithdraw"
                    variant="outline"
                    :disabled="busy"
                    data-testid="panel-adjustment-withdraw"
                    @click="decide('withdraw')"
                    >Withdraw request</Button
                >
            </div>
        </div>

        <ul
            v-if="adjustment.confirmed.length"
            class="grid gap-1 text-muted-foreground"
            data-testid="panel-adjustment-history"
        >
            <li v-for="(row, i) in adjustment.confirmed" :key="i">
                Panel claim adjustment
                <span class="whitespace-nowrap tabular-nums">{{
                    signed(row.direction, row.amountSen)
                }}</span>
                {{
                    row.samePerson
                        ? `requested and confirmed by the same person: ${row.confirmedBy}${row.confirmedByRole ? ` (${row.confirmedByRole})` : ''}.`
                        : `requested by ${row.requestedBy}, confirmed by ${row.confirmedBy}${row.confirmedByRole ? ` (${row.confirmedByRole})` : ''}.`
                }}
                Reason: {{ row.reason }}. {{ row.confirmedAt }}.
            </li>
            <li>
                Original Panel share
                {{ myr(adjustment.originalShareSen) }} kept.
            </li>
        </ul>

        <!-- Step 1: request (board section 3). -->
        <Dialog
            :open="open"
            @update:open="(value: boolean) => !busy && (open = value)"
        >
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Adjust Panel amount</DialogTitle>
                    <DialogDescription class="tabular-nums">
                        Panel share now
                        <span class="whitespace-nowrap">{{
                            myr(adjustment.shareSen)
                        }}</span>
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">Change</legend>
                        <div class="flex flex-wrap gap-2" role="radiogroup">
                            <label
                                v-for="choice in [
                                    { value: 'add', label: 'Add' },
                                    { value: 'deduct', label: 'Deduct' },
                                ]"
                                :key="choice.value"
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-md border px-3"
                            >
                                <input
                                    v-model="direction"
                                    type="radio"
                                    :value="choice.value"
                                />
                                {{ choice.label }}
                            </label>
                        </div>
                    </fieldset>
                    <div class="grid gap-2">
                        <Label for="panel-adjustment-amount">Amount (RM)</Label>
                        <Input
                            id="panel-adjustment-amount"
                            v-model="amount"
                            inputmode="decimal"
                            autocomplete="off"
                        />
                        <InputError :message="errors.amount_sen" />
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">Reason</legend>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="option in adjustment.reasons"
                                :key="option.value"
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-full border px-3"
                                :class="
                                    reason === option.value
                                        ? 'border-primary bg-primary/10'
                                        : ''
                                "
                            >
                                <input
                                    v-model="reason"
                                    type="radio"
                                    class="sr-only"
                                    :value="option.value"
                                />
                                {{ option.label }}
                            </label>
                        </div>
                        <InputError :message="errors.reason_code" />
                    </fieldset>
                    <div class="grid gap-2">
                        <Label for="panel-adjustment-note"
                            >Private note{{
                                reason === 'other' ? '' : ' (optional)'
                            }}</Label
                        >
                        <Textarea
                            id="panel-adjustment-note"
                            v-model="note"
                            maxlength="500"
                            rows="3"
                        />
                        <InputError :message="errors.note" />
                    </div>
                    <InputError :message="errors.adjustment" />
                </div>
                <DialogFooter class="items-center gap-3 sm:justify-between">
                    <p
                        class="text-xs text-muted-foreground tabular-nums"
                        data-testid="panel-adjustment-live"
                    >
                        <template v-if="delta !== null && ready">
                            Invoice payable becomes
                            {{ myr(adjustment.invoicePayableSen + delta) }} and
                            Panel share {{ myr(adjustment.shareSen + delta) }}.
                            What the patient paid and owes does not change. No
                            cash moves.
                        </template>
                        <template v-else>
                            Enter an amount and choose a reason to request the
                            adjustment.
                        </template>
                    </p>
                    <div class="flex gap-2">
                        <Button
                            class="max-lg:min-h-11"
                            data-touch-target
                            variant="outline"
                            :disabled="busy"
                            @click="open = false"
                            >Cancel</Button
                        >
                        <Button
                            class="max-lg:min-h-11"
                            data-touch-target
                            :disabled="!ready || busy"
                            data-testid="panel-adjustment-submit"
                            @click="submit"
                            >Request adjustment</Button
                        >
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>

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
import type { PanelAdjustment } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

/**
 * PCA-2: the Panel share as "original + Panel claim adjustments" next to the amount claimed, the button that changes
 * the invoice, and a waiting request with Confirm and Withdraw. Shared by the claim page and the Billing page (Classic
 * and Version 2): a new rule, so both layouts get it. The server decides every amount and refusal.
 */
const props = defineProps<{ adjustment: PanelAdjustment }>();
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
const sen = computed(() => toSen(amount.value));
const newShare = computed(() =>
    sen.value === null
        ? null
        : props.adjustment.shareSen +
          (direction.value === 'add' ? 1 : -1) * Number(sen.value),
);
const ready = computed(
    () =>
        sen.value !== null &&
        Number(sen.value) > 0 &&
        reason.value !== '' &&
        (reason.value !== 'other' || note.value.trim() !== ''),
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
const signed = (direction: string, sen: number) =>
    direction === 'add' ? `+${myr(sen)}` : `−${myr(sen)}`;
</script>

<template>
    <section
        class="grid gap-3 rounded-lg border p-4 text-sm"
        data-testid="panel-adjustment"
        aria-labelledby="panel-adjustment-title"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 id="panel-adjustment-title" class="font-medium">
                    Panel share
                </h3>
                <p class="tabular-nums" data-testid="panel-adjustment-share">
                    <span class="font-semibold">{{
                        myr(adjustment.shareSen)
                    }}</span>
                    <span
                        v-if="adjustment.adjustmentSen !== 0"
                        class="text-muted-foreground"
                    >
                        ({{ myr(adjustment.originalShareSen) }}
                        {{ adjustment.adjustmentSen > 0 ? '+' : '−' }}
                        Panel claim adjustment
                        {{ myr(Math.abs(adjustment.adjustmentSen)) }})</span
                    >
                </p>
                <p class="text-muted-foreground tabular-nums">
                    Claimed {{ myr(adjustment.claimedSen) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="adjustment.claimUrl && adjustment.from !== 'claim'"
                    variant="outline"
                    size="sm"
                    as-child
                    ><Link :href="adjustment.claimUrl"
                        >Open Panel claim</Link
                    ></Button
                >
                <Button
                    v-if="adjustment.canRequest"
                    variant="outline"
                    size="sm"
                    data-testid="panel-adjustment-open"
                    @click="open = true"
                    >Adjust Panel amount: changes the invoice</Button
                >
            </div>
        </div>
        <InputError :message="errors.adjustment ?? errors.amount_sen" />

        <div
            v-if="adjustment.waiting"
            class="grid gap-2 rounded-md border border-warning/40 bg-warning/10 p-3"
            data-testid="panel-adjustment-waiting"
        >
            <p class="font-medium">
                Waiting for confirmation:
                {{
                    signed(
                        adjustment.waiting.direction,
                        adjustment.waiting.amountSen,
                    )
                }}
                · {{ adjustment.waiting.reason }}
            </p>
            <p class="text-muted-foreground">
                Requested by {{ adjustment.waiting.requestedBy }} on
                {{ adjustment.waiting.requestedAt }}. Nothing changes until it
                is confirmed.
            </p>
            <p
                v-if="adjustment.waiting.note"
                class="break-words whitespace-pre-wrap"
            >
                {{ adjustment.waiting.note }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="adjustment.waiting.canConfirm"
                    size="sm"
                    :disabled="busy"
                    data-testid="panel-adjustment-confirm"
                    @click="decide('confirm')"
                    >{{
                        adjustment.waiting.requestedByYou
                            ? 'Confirm my request'
                            : 'Confirm'
                    }}</Button
                >
                <Button
                    v-if="adjustment.waiting.canWithdraw"
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    data-testid="panel-adjustment-withdraw"
                    @click="decide('withdraw')"
                    >Withdraw</Button
                >
            </div>
        </div>

        <ul
            v-if="adjustment.confirmed.length"
            class="grid gap-1 text-muted-foreground"
            data-testid="panel-adjustment-history"
        >
            <li v-for="(row, i) in adjustment.confirmed" :key="i">
                {{ signed(row.direction, row.amountSen) }} · {{ row.reason }} ·
                {{ row.confirmedAt }} ·
                {{
                    row.samePerson
                        ? `requested and confirmed by ${row.confirmedBy}`
                        : `requested by ${row.requestedBy}, confirmed by ${row.confirmedBy}`
                }}
            </li>
        </ul>

        <Dialog
            :open="open"
            @update:open="(value: boolean) => !busy && (open = value)"
        >
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Adjust Panel amount</DialogTitle>
                    <DialogDescription>
                        This changes the invoice and the Panel share by the same
                        amount. What the patient owes does not change. It counts
                        only once it is confirmed.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">Change</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex min-h-11 items-center gap-2">
                                <input
                                    v-model="direction"
                                    type="radio"
                                    value="add"
                                />
                                Panel pays more
                            </label>
                            <label class="flex min-h-11 items-center gap-2">
                                <input
                                    v-model="direction"
                                    type="radio"
                                    value="deduct"
                                />
                                Panel pays less
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
                        <p
                            v-if="newShare !== null"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            New Panel share {{ myr(newShare) }}
                        </p>
                        <InputError :message="errors.amount_sen" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="panel-adjustment-reason">Reason</Label>
                        <select
                            id="panel-adjustment-reason"
                            v-model="reason"
                            class="h-11 rounded-md border bg-background px-3"
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option
                                v-for="option in adjustment.reasons"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="errors.reason_code" />
                    </div>
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
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="busy"
                        @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="!ready || busy"
                        data-testid="panel-adjustment-submit"
                        @click="submit"
                        >Request adjustment</Button
                    >
                </DialogFooter>
                <p v-if="!ready" class="text-xs text-muted-foreground">
                    Enter an amount and choose a reason to request the
                    adjustment.
                </p>
            </DialogContent>
        </Dialog>
    </section>
</template>

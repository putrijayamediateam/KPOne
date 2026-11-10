<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { CorrectionsContext } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

/**
 * BIL-16 (owner decisions 2026-11-10): correct a receipt recorded in error on a completed visit. A Finance Officer
 * who did not record it reverses it and records what was really received, in one step. Only money not received
 * becomes due, collected with Add under Payments. It is never a refund.
 */
const props = defineProps<{
    corrections: CorrectionsContext;
    /** The invoice's billing address, for example /visits/KPV-1/billing/<invoice>. */
    base: string;
    manage?: boolean;
    /** What the visit owes now, to point staff to collecting it after a correction. */
    dueNowSen: number;
}>();

type Receipt = CorrectionsContext['receipts'][number];
type Form = {
    received: string;
    method: string;
    reference: string;
    reason: string;
    confirmed: boolean;
    key: string;
};

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const forms = reactive<Record<string, Form>>({});
const open = reactive<Record<string, boolean>>({});
const busy = ref<string | null>(null);
const lastSent = ref<string | null>(null);
const form = (receipt: Receipt): Form =>
    (forms[receipt.publicId] ??= {
        received: (receipt.amountSen / 100).toFixed(2),
        method: '',
        reference: '',
        reason: '',
        confirmed: false,
        key: crypto.randomUUID(),
    });
// Field errors show under their field; anything else shows once, above the button.
const fieldError = (receipt: Receipt, key: string): string | undefined =>
    lastSent.value === receipt.publicId ? errors.value[key] : undefined;
const generalError = (receipt: Receipt): string | undefined =>
    lastSent.value === receipt.publicId
        ? (errors.value.correction ??
          errors.value.recording_error_only ??
          errors.value.invoice ??
          errors.value.expected_branch_id ??
          errors.value.idempotency_key)
        : undefined;

const sen = (f: Form): number | null => {
    const value = toSen(f.received);

    return value === null ? null : Number(value);
};
const methodName = (f: Form) =>
    props.corrections.methods.find((m) => m.code === f.method)?.name ?? '';
const needsReference = (f: Form) =>
    props.corrections.methods.find((m) => m.code === f.method)
        ?.requiresReference ?? false;
// A problem with the amount, shown live under the amount box.
const amountProblem = (receipt: Receipt, f: Form): string | null => {
    const value = sen(f);

    if (value === null) {
        return 'Enter an amount such as 30.00.';
    }

    if (value > receipt.amountSen) {
        return `Cannot be more than ${myr(receipt.amountSen)} (the receipt amount).`;
    }

    if (value < receipt.payLaterSen) {
        return `Enter at least ${myr(receipt.payLaterSen)}. This receipt cleared ${myr(receipt.payLaterSen)} of pay-later, and that part cannot be reopened.`;
    }

    return null;
};
// What still stops the correction, shown under the disabled button.
const missing = (receipt: Receipt, f: Form): string | null => {
    if (amountProblem(receipt, f) !== null) {
        return 'Fix the amount to continue.';
    }

    if ((sen(f) ?? 0) > 0 && f.method === '') {
        return 'Choose how the money was received to continue.';
    }

    if (needsReference(f) && f.reference.trim() === '') {
        return 'Add the reference to continue.';
    }

    if (f.reason.trim() === '' || !f.confirmed) {
        return 'Add what was wrong and tick the box to continue.';
    }

    return null;
};
// Everything that will happen, shown before the click because it changes money records.
const summary = (receipt: Receipt, f: Form): string => {
    const value = sen(f) ?? 0;
    const due = receipt.amountSen - value;
    const replacement =
        value > 0
            ? `New receipt: ${myr(value)} ${methodName(f)}.`
            : 'Nothing was received, so there is no new receipt.';

    return `${receipt.number} (${myr(receipt.amountSen)}) will be reversed. ${replacement}${due > 0 ? ` ${myr(due)} becomes due.` : ' Nothing becomes due.'}`;
};
const formatAmount = (f: Form) => {
    const value = toSen(f.received);

    if (value !== null) {
        f.received = (Number(value) / 100).toFixed(2);
    }
};
const fieldIds = ['received', 'method', 'reference', 'reason'] as const;
const errorKeys: Record<(typeof fieldIds)[number], string> = {
    received: 'received_sen',
    method: 'method',
    reference: 'reference',
    reason: 'reason',
};

const submit = (receipt: Receipt) => {
    const f = form(receipt);
    const value = toSen(f.received) ?? '';
    busy.value = receipt.publicId;
    lastSent.value = receipt.publicId;
    router.post(
        `${props.base}/payments/${receipt.publicId}/correct`,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            return_to: props.manage ? 'payments' : 'billing',
            lock_version: props.corrections.invoiceLockVersion,
            payment_lock_version: receipt.lockVersion,
            received_sen: value,
            method: value === '0' ? null : f.method,
            reference: f.reference.trim() || null,
            reason: f.reason,
            recording_error_only: f.confirmed ? '1' : '0',
            idempotency_key: f.key,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                delete forms[receipt.publicId];
                open[receipt.publicId] = false;
            },
            onError: (bag) => {
                open[receipt.publicId] = true;
                // Move focus to the first field the server refused.
                const field = fieldIds.find((id) => bag[errorKeys[id]]);
                void nextTick(() =>
                    document
                        .getElementById(
                            field ? `${field}-${receipt.publicId}` : '',
                        )
                        ?.focus(),
                );
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
};
const field =
    'h-11 rounded-md border border-input bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-[invalid=true]:border-destructive sm:h-9';
</script>

<template>
    <section
        class="rounded-xl border bg-card p-4 text-[13px]"
        aria-labelledby="receipt-corrections-heading"
        data-testid="receipt-corrections"
    >
        <header class="space-y-1 pb-2">
            <h2 id="receipt-corrections-heading" class="font-semibold">
                Correct a receipt
            </h2>
            <p class="text-xs text-muted-foreground">
                For a receipt recorded in error, such as the wrong amount or
                method. The wrong receipt is reversed and what was really
                received is recorded instead. Only money not received becomes
                due.
                <strong class="text-foreground">This is not a refund.</strong>
                To give money back, use Refunds above.
            </p>
            <p
                v-if="corrections.corrections.length && dueNowSen > 0"
                class="text-xs font-medium"
                data-testid="correction-collect"
            >
                {{ myr(dueNowSen) }} is now due. Collect it with Add under
                Payments.
            </p>
        </header>
        <article
            v-for="receipt in corrections.receipts"
            :key="receipt.publicId"
            class="space-y-2 border-t py-3 last:pb-0"
            data-testid="correction-receipt"
        >
            <p class="text-sm tabular-nums">
                <span class="font-medium">{{ receipt.number }}</span>
                · {{ receipt.method }} · {{ myr(receipt.amountSen) }}
            </p>
            <p
                v-if="receipt.refused"
                class="text-xs text-muted-foreground"
                data-testid="correction-refused"
            >
                {{ receipt.refused }}
            </p>
            <details
                v-else
                class="group"
                :open="open[receipt.publicId] ?? false"
                @toggle="
                    open[receipt.publicId] = (
                        $event.target as HTMLDetailsElement
                    ).open
                "
            >
                <summary
                    class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1 rounded-md text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:min-h-9 [&::-webkit-details-marker]:hidden"
                    :aria-label="`Correct receipt ${receipt.number}`"
                >
                    <ChevronRight
                        class="size-4 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                    Correct this receipt
                </summary>
                <form
                    class="mt-2 grid gap-3"
                    data-testid="correction-form"
                    @submit.prevent="submit(receipt)"
                >
                    <p class="text-xs text-muted-foreground">
                        Enter 0.00 if nothing was received.
                    </p>
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="grid gap-1">
                            <label
                                class="text-xs font-medium"
                                :for="`received-${receipt.publicId}`"
                                >Amount actually received (RM)</label
                            >
                            <input
                                :id="`received-${receipt.publicId}`"
                                v-model="form(receipt).received"
                                inputmode="decimal"
                                autocomplete="off"
                                :class="[field, 'w-36 tabular-nums']"
                                :aria-invalid="
                                    fieldError(receipt, 'received_sen') ||
                                    amountProblem(receipt, form(receipt))
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="`received-problem-${receipt.publicId} received-error-${receipt.publicId}`"
                                @blur="formatAmount(form(receipt))"
                            />
                        </div>
                        <div
                            v-if="(sen(form(receipt)) ?? 1) !== 0"
                            class="grid gap-1"
                        >
                            <label
                                class="text-xs font-medium"
                                :for="`method-${receipt.publicId}`"
                                >Received by</label
                            >
                            <select
                                :id="`method-${receipt.publicId}`"
                                v-model="form(receipt).method"
                                :class="field"
                                :aria-invalid="
                                    fieldError(receipt, 'method')
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="`method-error-${receipt.publicId}`"
                            >
                                <option value="">Choose method</option>
                                <option
                                    v-for="m in corrections.methods"
                                    :key="m.code"
                                    :value="m.code"
                                >
                                    {{ m.name }}
                                </option>
                            </select>
                        </div>
                        <div
                            v-if="needsReference(form(receipt))"
                            class="grid gap-1"
                        >
                            <label
                                class="text-xs font-medium"
                                :for="`reference-${receipt.publicId}`"
                                >Reference</label
                            >
                            <input
                                :id="`reference-${receipt.publicId}`"
                                v-model="form(receipt).reference"
                                maxlength="100"
                                :class="field"
                                :aria-invalid="
                                    fieldError(receipt, 'reference')
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="`reference-error-${receipt.publicId}`"
                            />
                        </div>
                    </div>
                    <p
                        :id="`received-problem-${receipt.publicId}`"
                        class="text-xs text-destructive"
                        data-testid="correction-amount-problem"
                        aria-live="polite"
                    >
                        {{ amountProblem(receipt, form(receipt)) ?? '' }}
                    </p>
                    <InputError
                        :id="`received-error-${receipt.publicId}`"
                        :message="fieldError(receipt, 'received_sen')"
                    />
                    <InputError
                        :id="`method-error-${receipt.publicId}`"
                        :message="fieldError(receipt, 'method')"
                    />
                    <InputError
                        :id="`reference-error-${receipt.publicId}`"
                        :message="fieldError(receipt, 'reference')"
                    />
                    <div class="grid gap-1">
                        <label
                            class="text-xs font-medium"
                            :for="`reason-${receipt.publicId}`"
                            >What was wrong</label
                        >
                        <textarea
                            :id="`reason-${receipt.publicId}`"
                            v-model="form(receipt).reason"
                            maxlength="500"
                            rows="2"
                            class="rounded-md border border-input bg-background px-2 py-1 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-[invalid=true]:border-destructive"
                            :aria-invalid="
                                fieldError(receipt, 'reason')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="`reason-error-${receipt.publicId}`"
                        />
                        <InputError
                            :id="`reason-error-${receipt.publicId}`"
                            :message="fieldError(receipt, 'reason')"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="form(receipt).confirmed"
                            type="checkbox"
                            class="mt-1 size-4 accent-primary"
                        />
                        This receipt was recorded in error. No money is being
                        handed back. Use Refunds for that.
                    </label>
                    <p
                        v-if="missing(receipt, form(receipt)) === null"
                        class="rounded-md bg-muted/40 p-2 text-sm tabular-nums"
                        data-testid="correction-summary"
                    >
                        {{ summary(receipt, form(receipt)) }}
                    </p>
                    <InputError :message="generalError(receipt)" />
                    <div class="grid justify-items-start gap-1">
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            class="max-sm:min-h-11"
                            data-touch-target
                            :disabled="
                                busy !== null ||
                                missing(receipt, form(receipt)) !== null
                            "
                            >{{
                                busy === receipt.publicId
                                    ? 'Correcting…'
                                    : 'Correct receipt'
                            }}</Button
                        >
                        <p
                            v-if="missing(receipt, form(receipt))"
                            class="text-xs text-muted-foreground"
                            data-testid="correction-missing"
                        >
                            {{ missing(receipt, form(receipt)) }}
                        </p>
                    </div>
                </form>
            </details>
        </article>
        <div
            v-if="corrections.corrections.length"
            class="space-y-2 border-t pt-3"
            data-testid="correction-history"
        >
            <h3 class="text-xs font-semibold">Corrections made</h3>
            <div
                v-for="c in corrections.corrections"
                :key="c.publicId"
                class="rounded-md bg-muted/40 p-2 text-sm"
            >
                <p class="tabular-nums">
                    {{
                        c.replacement
                            ? `${c.original} reversed and replaced by ${c.replacement} (${myr(c.receivedSen)})`
                            : `${c.original} reversed; nothing was received`
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Reason: {{ c.reason }} · {{ c.by }}, {{ c.at }}
                </p>
            </div>
        </div>
    </section>
</template>

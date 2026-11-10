<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { CorrectionsContext } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

/**
 * BIL-16 (owner decisions 2026-11-10): correct a receipt recorded in error on a completed visit. A Finance Officer
 * who did not record it reverses it and records what was really received, in one step. Only money not received
 * becomes due. It is never a refund.
 */
const props = defineProps<{
    corrections: CorrectionsContext;
    /** The invoice's billing address, for example /visits/KPV-1/billing/<invoice>. */
    base: string;
    manage?: boolean;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
type Form = {
    received: string;
    method: string;
    reference: string;
    reason: string;
    confirmed: boolean;
    key: string;
};
const forms = reactive<Record<string, Form>>({});
const open = reactive<Record<string, boolean>>({});
const busy = ref<string | null>(null);
const lastSent = ref<string | null>(null);
const form = (receipt: CorrectionsContext['receipts'][number]): Form =>
    (forms[receipt.publicId] ??= {
        received: (receipt.amountSen / 100).toFixed(2),
        method: '',
        reference: '',
        reason: '',
        confirmed: false,
        key: crypto.randomUUID(),
    });
const errorMessage = computed(
    () =>
        errors.value.correction ??
        errors.value.received_sen ??
        errors.value.method ??
        errors.value.reference ??
        errors.value.reason ??
        errors.value.recording_error_only ??
        errors.value.invoice ??
        errors.value.expected_branch_id ??
        errors.value.idempotency_key,
);
const receivedSen = (f: Form) => toSen(f.received);
const needsReference = (f: Form) =>
    props.corrections.methods.find((m) => m.code === f.method)
        ?.requiresReference ?? false;
const ready = (
    receipt: CorrectionsContext['receipts'][number],
    f: Form,
): boolean => {
    const sen = receivedSen(f);

    return (
        sen !== null &&
        Number(sen) <= receipt.amountSen &&
        Number(sen) >= receipt.payLaterSen &&
        (Number(sen) === 0 || f.method !== '') &&
        (!needsReference(f) || f.reference.trim() !== '') &&
        f.reason.trim() !== '' &&
        f.confirmed
    );
};
const dueAfter = (
    receipt: CorrectionsContext['receipts'][number],
    f: Form,
): number | null => {
    const sen = receivedSen(f);

    return sen === null ? null : receipt.amountSen - Number(sen);
};

const submit = (receipt: CorrectionsContext['receipts'][number]) => {
    const f = form(receipt);
    const sen = receivedSen(f) ?? '';
    busy.value = receipt.publicId;
    lastSent.value = receipt.publicId;
    router.post(
        `${props.base}/payments/${receipt.publicId}/correct`,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            return_to: props.manage ? 'payments' : 'billing',
            lock_version: props.corrections.invoiceLockVersion,
            payment_lock_version: receipt.lockVersion,
            received_sen: sen,
            method: sen === '0' ? null : f.method,
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
            onError: () => {
                open[receipt.publicId] = true;
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
};
</script>

<template>
    <section
        class="rounded-xl border bg-card p-4 text-[13px]"
        aria-labelledby="receipt-corrections-heading"
        data-testid="receipt-corrections"
    >
        <header class="pb-2">
            <h2 id="receipt-corrections-heading" class="font-semibold">
                Correct a receipt
            </h2>
            <p class="text-xs text-muted-foreground">
                For a receipt recorded in error, such as the wrong amount or
                method. The wrong receipt is reversed and what was really
                received is recorded instead. Only money not received becomes
                due. This is not a refund.
            </p>
        </header>
        <article
            v-for="receipt in corrections.receipts"
            :key="receipt.publicId"
            class="space-y-2 border-t py-3 last:pb-0"
            data-testid="correction-receipt"
        >
            <p class="text-sm">
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
                    class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1 text-sm font-medium sm:min-h-9 [&::-webkit-details-marker]:hidden"
                >
                    <ChevronRight
                        class="size-4 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                    Correct {{ receipt.number }}
                </summary>
                <form
                    class="mt-2 grid gap-3"
                    data-testid="correction-form"
                    @submit.prevent="submit(receipt)"
                >
                    <div class="flex flex-wrap items-end gap-2">
                        <label
                            class="grid gap-1 text-xs font-medium"
                            :for="`received-${receipt.publicId}`"
                        >
                            Really received (RM)
                            <input
                                :id="`received-${receipt.publicId}`"
                                v-model="form(receipt).received"
                                inputmode="decimal"
                                autocomplete="off"
                                class="h-11 w-32 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                                :aria-describedby="`received-hint-${receipt.publicId}`"
                            />
                        </label>
                        <label
                            v-if="receivedSen(form(receipt)) !== '0'"
                            class="grid gap-1 text-xs font-medium"
                            :for="`method-${receipt.publicId}`"
                        >
                            How it was received
                            <select
                                :id="`method-${receipt.publicId}`"
                                v-model="form(receipt).method"
                                class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                            >
                                <option value="">Choose</option>
                                <option
                                    v-for="m in corrections.methods"
                                    :key="m.code"
                                    :value="m.code"
                                >
                                    {{ m.name }}
                                </option>
                            </select>
                        </label>
                        <label
                            v-if="needsReference(form(receipt))"
                            class="grid gap-1 text-xs font-medium"
                            :for="`reference-${receipt.publicId}`"
                        >
                            Reference
                            <input
                                :id="`reference-${receipt.publicId}`"
                                v-model="form(receipt).reference"
                                maxlength="100"
                                class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                            />
                        </label>
                    </div>
                    <p
                        :id="`received-hint-${receipt.publicId}`"
                        class="text-xs text-muted-foreground"
                        data-testid="correction-hint"
                    >
                        Enter 0.00 if nothing was received.
                        <template v-if="receipt.payLaterSen > 0">
                            This receipt paid off
                            {{ myr(receipt.payLaterSen) }} of pay-later, which
                            cannot grow back, so enter at least that.
                        </template>
                        <template
                            v-if="(dueAfter(receipt, form(receipt)) ?? 0) > 0"
                        >
                            {{ myr(dueAfter(receipt, form(receipt)) ?? 0) }}
                            will become due on this visit.
                        </template>
                    </p>
                    <label
                        class="grid gap-1 text-xs font-medium"
                        :for="`reason-${receipt.publicId}`"
                    >
                        What was wrong
                        <textarea
                            :id="`reason-${receipt.publicId}`"
                            v-model="form(receipt).reason"
                            maxlength="500"
                            rows="2"
                            class="rounded-md border border-input bg-background px-2 py-1 text-sm"
                        />
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            v-model="form(receipt).confirmed"
                            type="checkbox"
                            class="mt-1 size-4"
                        />
                        This receipt was recorded in error. No money is being
                        handed back.
                    </label>
                    <InputError
                        v-if="lastSent === receipt.publicId"
                        :message="errorMessage"
                    />
                    <div>
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            class="max-sm:min-h-11"
                            data-touch-target
                            :disabled="
                                busy !== null || !ready(receipt, form(receipt))
                            "
                            >Correct receipt</Button
                        >
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
            <p
                v-for="c in corrections.corrections"
                :key="c.publicId"
                class="text-xs"
            >
                {{ c.original }} reversed
                {{
                    c.replacement
                        ? `and replaced by ${c.replacement} (${myr(c.receivedSen)})`
                        : '(nothing was received)'
                }}
                · {{ c.reason }} ·
                <span class="text-muted-foreground"
                    >{{ c.by }}, {{ c.at }}</span
                >
            </p>
        </div>
    </section>
</template>

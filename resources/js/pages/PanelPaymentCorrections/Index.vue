<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { myr } from '@/types/billing';

type RecordedPayment = {
    kind: 'payment' | 'remittance';
    publicId: string;
    panelName: string;
    amountSen: number;
    receivedDate: string;
    reference: string | null;
    recordedBy: string;
    recordedByMe: boolean;
    claims: {
        claimNo: string;
        invoiceNumber: string;
        patientName: string;
        amountSen: number;
    }[];
    reversal: {
        reason: string;
        note: string;
        reversedBy: string;
        reversedDate: string;
    } | null;
};

const props = defineProps<{
    branch: { id: number; name: string };
    reasons: { code: string; label: string }[];
    payments: {
        data: RecordedPayment[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
// Every message in the error bag, so a refused request is never silent.
const errorMessages = computed(() => [
    ...new Set(
        Object.values(errors.value).filter((message): message is string =>
            Boolean(message),
        ),
    ),
]);

const open = ref<string | null>(null);
const form = reactive({
    reason: '',
    note: '',
    confirmed: false,
    busy: false,
    key: crypto.randomUUID(),
});

const focusOn = (id: string) =>
    nextTick(() => document.getElementById(id)?.focus());

const start = (payment: RecordedPayment) => {
    open.value = payment.publicId;
    form.reason = '';
    form.note = '';
    form.confirmed = false;
    form.key = crypto.randomUUID();
    focusOn(`reverse-reason-${payment.publicId}`);
};

const close = (payment: RecordedPayment) => {
    open.value = null;
    focusOn(`reverse-start-${payment.publicId}`);
};

const canSubmit = computed(
    () =>
        !form.busy &&
        form.reason !== '' &&
        form.note.trim() !== '' &&
        form.confirmed,
);

const reverse = (payment: RecordedPayment) => {
    if (!canSubmit.value) {
        return;
    }

    form.busy = true;
    router.post(
        '/panel-payment-corrections',
        {
            expected_branch_id: props.branch.id,
            idempotency_key: form.key,
            kind: payment.kind,
            target: payment.publicId,
            reason_code: form.reason,
            note: form.note,
            recording_error_only: form.confirmed,
            page: props.payments.currentPage,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = null;
            },
            onError: () => {
                focusOn(`reverse-reason-${payment.publicId}`);
            },
            onFinish: () => {
                form.busy = false;
            },
        },
    );
};

const turnPage = (value: number) =>
    router.get('/panel-payment-corrections', value > 1 ? { page: value } : {}, {
        preserveScroll: true,
        replace: true,
    });
</script>

<template>
    <Head title="Correct a Panel payment" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <Link
            href="/panel-invoices"
            class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground underline-offset-2 hover:text-foreground hover:underline max-lg:min-h-11"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            Back to Panel invoices
        </Link>
        <PageHeader
            title="Correct a Panel payment"
            :description="`${branch.name} · Reverse a Panel payment recorded by mistake. Nothing is deleted.`"
        />
        <p class="text-sm text-muted-foreground">
            Not a refund. You cannot reverse a payment you recorded.
        </p>
        <details class="text-sm text-muted-foreground">
            <summary
                class="cursor-pointer font-medium text-foreground max-lg:flex max-lg:min-h-11 max-lg:items-center"
            >
                How this works
            </summary>
            <p class="mt-2">
                Nothing is paid back to the Panel. The payment stays listed as
                reversed and no longer counts as received. A payment split
                across several claims is reversed as a whole. Record the correct
                payment again on the Panel payments page or the claim page.
            </p>
        </details>
        <p
            v-for="message in errorMessages"
            :key="message"
            class="rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
            role="alert"
        >
            {{ message }}
        </p>

        <EmptyState
            v-if="payments.data.length === 0"
            title="No Panel payments recorded yet"
            description="Payments recorded on the Panel payments page or a claim page appear here."
        />
        <ul
            v-else
            class="max-w-2xl divide-y rounded-xl border bg-card"
            aria-label="Recorded Panel payments"
        >
            <li
                v-for="payment in payments.data"
                :key="`${payment.kind}-${payment.publicId}`"
                class="space-y-2 p-4 text-sm"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <span class="min-w-0 font-medium wrap-anywhere">
                        {{ payment.panelName }} · {{ payment.receivedDate }}
                        <StatusBadge
                            v-if="payment.reversal"
                            status="reversed"
                            class="ml-1"
                        />
                        <span class="font-normal text-muted-foreground">
                            ·
                            {{
                                payment.kind === 'remittance'
                                    ? 'Split Panel payment'
                                    : 'Payment on one claim'
                            }}
                        </span>
                    </span>
                    <strong
                        class="tabular-nums"
                        :class="{
                            'text-muted-foreground line-through':
                                payment.reversal,
                        }"
                        >{{ myr(payment.amountSen) }}</strong
                    >
                </div>
                <p class="text-xs wrap-anywhere text-muted-foreground">
                    Reference: {{ payment.reference || 'None' }} · Recorded by
                    {{ payment.recordedBy }}
                </p>
                <ul class="space-y-1 text-xs">
                    <li
                        v-for="claim in payment.claims"
                        :key="claim.claimNo"
                        class="flex flex-wrap justify-between gap-2"
                    >
                        <span class="min-w-0 wrap-anywhere">
                            Claim {{ claim.claimNo }} ·
                            {{ claim.invoiceNumber }} ·
                            {{ claim.patientName }}
                        </span>
                        <span
                            class="ml-auto text-right tabular-nums"
                            :class="{
                                'text-muted-foreground line-through':
                                    payment.reversal,
                            }"
                            >{{ myr(claim.amountSen) }}</span
                        >
                    </li>
                </ul>

                <p
                    v-if="payment.reversal"
                    class="rounded-lg bg-muted p-3 text-xs wrap-anywhere"
                    data-testid="reversal-details"
                >
                    Reversed on {{ payment.reversal.reversedDate }} by
                    {{ payment.reversal.reversedBy }} ·
                    {{ payment.reversal.reason }}: {{ payment.reversal.note }}
                </p>
                <p
                    v-else-if="payment.recordedByMe"
                    class="text-xs text-muted-foreground"
                >
                    You recorded this payment, so someone else must reverse it.
                </p>
                <template v-else>
                    <Button
                        v-if="open !== payment.publicId"
                        :id="`reverse-start-${payment.publicId}`"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="start(payment)"
                    >
                        Reverse this payment
                    </Button>
                    <form
                        v-else
                        class="mt-2 grid gap-4 border-t pt-4"
                        @submit.prevent="reverse(payment)"
                    >
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                        >
                            Reason *
                            <select
                                :id="`reverse-reason-${payment.publicId}`"
                                v-model="form.reason"
                                class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm font-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 max-lg:text-base dark:bg-field-fill/30"
                                :aria-invalid="
                                    errors.reason_code ? 'true' : undefined
                                "
                                :aria-describedby="
                                    errors.reason_code
                                        ? `reverse-reason-error-${payment.publicId}`
                                        : undefined
                                "
                            >
                                <option value="" disabled>
                                    Choose a reason
                                </option>
                                <option
                                    v-for="reason in reasons"
                                    :key="reason.code"
                                    :value="reason.code"
                                >
                                    {{ reason.label }}
                                </option>
                            </select>
                            <span
                                v-if="errors.reason_code"
                                :id="`reverse-reason-error-${payment.publicId}`"
                                class="text-destructive"
                                >{{ errors.reason_code }}</span
                            >
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
                        >
                            What was wrong *
                            <textarea
                                v-model="form.note"
                                maxlength="200"
                                rows="2"
                                :aria-invalid="errors.note ? 'true' : undefined"
                                :aria-describedby="`reverse-note-hint-${payment.publicId}`"
                                class="w-full min-w-0 rounded-lg border border-input bg-card px-3 py-2 text-sm font-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:text-base dark:bg-field-fill/30"
                            />
                            <span
                                :id="`reverse-note-hint-${payment.publicId}`"
                                :class="
                                    errors.note
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    errors.note ||
                                    `Required, up to 200 characters (${form.note.length}/200). Do not type patient or clinical details: this note is stored as plain text.`
                                }}</span
                            >
                        </label>
                        <label
                            class="flex items-start gap-2 text-xs max-lg:min-h-11 max-lg:items-center"
                        >
                            <input
                                v-model="form.confirmed"
                                type="checkbox"
                                class="mt-0.5 size-4 max-lg:mt-0 max-lg:size-5"
                            />
                            <span>
                                This corrects a recording mistake. It is not a
                                refund.
                                <template v-if="payment.kind === 'remittance'">
                                    This payment no longer counts on any of its
                                    {{ payment.claims.length }}
                                    {{
                                        payment.claims.length === 1
                                            ? 'claim'
                                            : 'claims'
                                    }}.
                                </template>
                                <template v-else>
                                    This payment no longer counts on the claim.
                                </template>
                            </span>
                        </label>
                        <p
                            v-if="errors.reversal"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            {{ errors.reversal }}
                        </p>
                        <p
                            v-else-if="!canSubmit && !form.busy"
                            class="text-xs text-muted-foreground"
                        >
                            Choose a reason, write what was wrong and tick the
                            box to continue.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                variant="destructive"
                                size="sm"
                                class="max-lg:min-h-11"
                                :disabled="!canSubmit"
                            >
                                {{
                                    form.busy
                                        ? 'Reversing…'
                                        : `Reverse ${myr(payment.amountSen)}`
                                }}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="max-lg:min-h-11"
                                :disabled="form.busy"
                                @click="close(payment)"
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </template>
            </li>
        </ul>
        <CompactPagination
            v-if="payments.total > 0"
            class="max-w-2xl"
            :current-page="payments.currentPage"
            :last-page="payments.lastPage"
            :total="payments.total"
            @change="turnPage"
        />
    </main>
</template>

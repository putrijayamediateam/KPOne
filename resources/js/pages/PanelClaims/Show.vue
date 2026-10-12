<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { myr, toSen } from '@/types/billing';

type ClaimData = {
    publicId: string;
    lockVersion: number;
    status: string;
    patientName: string;
    memberReference: string | null;
    panelName: string;
    branchName: string;
    visitNumber: string;
    visitDate: string;
    invoiceNumber: string;
    invoiceTotalSen: number;
    claimAmountSen: number;
    adjustmentSen: number;
    acceptedAmountSen: number | null;
    paidAmountSen: number;
    outstandingSen: number;
    lines: {
        number: number;
        type: string;
        name: string;
        code: string;
        unit: string;
        quantity: string;
        unitPriceSen: number;
        totalSen: number;
        adjustment: boolean;
    }[];
    events: {
        type: string;
        previousAmountSen: number | null;
        amountSen: number | null;
        externalReference: string | null;
        note: string | null;
        occurredAt: string;
        actor: string;
    }[];
    payments: {
        amountSen: number;
        reference: string | null;
        receivedAt: string;
    }[];
    createdAt: string | null;
    canEditAmount: boolean;
    canSubmit: boolean;
    canRecordQuery: boolean;
    canRecordDecision: boolean;
    canRecordPayment: boolean;
};

const props = defineProps<{
    branch: { id: number; name: string; today: string };
    claim: ClaimData;
}>();
const page = usePage();
const busy = ref(false);
const error = ref('');
const amount = ref((props.claim.claimAmountSen / 100).toFixed(2));
const amountReason = ref('');
const confirmAboveInvoice = ref(false);
const submissionReference = ref('');
const queryReference = ref('');
const queryNote = ref('');
const decision = ref<'accepted' | 'partially_accepted' | 'rejected'>(
    'accepted',
);
const acceptedAmount = ref((props.claim.claimAmountSen / 100).toFixed(2));
const decisionNote = ref('');
const decisionReference = ref('');
const remittanceAmount = ref('');
const remittanceDate = ref(props.branch.today);
const remittanceReference = ref('');
let paymentKey = crypto.randomUUID();
watch(decision, (value) => {
    if (value === 'rejected') {
        acceptedAmount.value = '0.00';
    } else if (value === 'accepted') {
        acceptedAmount.value = (props.claim.claimAmountSen / 100).toFixed(2);
    }
});

const amountSen = computed(() => toSen(amount.value));
const aboveInvoice = computed(
    () =>
        amountSen.value !== null &&
        BigInt(amountSen.value) > BigInt(props.claim.invoiceTotalSen),
);
const post = (
    method: 'post' | 'patch',
    path: string,
    payload: Record<string, string | number | boolean | null>,
    onSuccess?: () => void,
) => {
    busy.value = true;
    error.value = '';
    const options = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) => {
            error.value =
                Object.values(errors)[0] ??
                'The request could not be completed.';
        },
        onSuccess,
        onFinish: () => {
            busy.value = false;
        },
    };

    if (method === 'patch') {
        router.patch(path, payload, options);
    } else {
        router.post(path, payload, options);
    }
};
const context = () => ({
    expected_branch_id: page.props.branchContext?.active?.id ?? props.branch.id,
    lock_version: props.claim.lockVersion,
});
const adjust = () => {
    const value = toSen(amount.value);

    if (value === null || BigInt(value) < 1n) {
        error.value =
            'Enter a positive MYR claim amount with at most two decimals.';

        return;
    }

    post('patch', `/panel-claims/${props.claim.publicId}/amount`, {
        ...context(),
        amount_sen: value,
        reason: amountReason.value,
        confirm_over_invoice: confirmAboveInvoice.value,
    });
};
const submit = () =>
    post('post', `/panel-claims/${props.claim.publicId}/submit`, {
        ...context(),
        external_reference: submissionReference.value || null,
    });
const recordQuery = () =>
    post('post', `/panel-claims/${props.claim.publicId}/query`, {
        ...context(),
        external_reference: queryReference.value || null,
        note: queryNote.value,
    });
const recordDecision = () => {
    const acceptedSen = toSen(acceptedAmount.value);

    if (acceptedSen === null) {
        error.value =
            'Enter the Panel-accepted amount in MYR with at most two decimals.';

        return;
    }

    post('post', `/panel-claims/${props.claim.publicId}/decision`, {
        ...context(),
        decision: decision.value,
        accepted_amount_sen: acceptedSen,
        external_reference: decisionReference.value || null,
        note: decisionNote.value,
    });
};
const recordPayment = () => {
    const value = toSen(remittanceAmount.value);

    if (value === null || BigInt(value) < 1n) {
        error.value =
            'Enter a positive MYR remittance with at most two decimals.';

        return;
    }

    post(
        'post',
        `/panel-claims/${props.claim.publicId}/payments`,
        {
            expected_branch_id:
                page.props.branchContext?.active?.id ?? props.branch.id,
            idempotency_key: paymentKey,
            amount_sen: value,
            received_date: remittanceDate.value,
            reference: remittanceReference.value || null,
        },
        () => {
            paymentKey = crypto.randomUUID();
            remittanceAmount.value = '';
            remittanceReference.value = '';
        },
    );
};
const printPack = () => window.print();
const eventTitle = (type: string) =>
    ({
        prepared: 'Claim pack prepared',
        amount_adjusted: 'Claim amount adjusted',
        submitted: 'Submitted to Panel',
        queried: 'Panel query recorded',
        decision_accepted: 'Panel accepted claim',
        decision_partially_accepted: 'Panel partially accepted claim',
        decision_rejected: 'Panel rejected claim',
    })[type] ?? type;
</script>

<template>
    <Head :title="`Panel claim · ${claim.invoiceNumber}`" />
    <main
        class="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 print:max-w-none print:px-0"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-4 border-b pb-4 print:hidden"
        >
            <div>
                <p class="text-sm text-muted-foreground">Panel Claims</p>
                <h1 class="text-2xl font-semibold text-foreground">
                    {{ claim.panelName }} · {{ claim.invoiceNumber }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ branch.name }} · Manual portal submission pack
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="secondary" @click="printPack">
                    Print claim pack
                </Button>
                <ActionLink href="/panel-claims" variant="secondary">
                    Back to Panel Claims
                </ActionLink>
            </div>
        </header>

        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive print:hidden"
        >
            {{ error }}
        </p>

        <section class="space-y-4 rounded-xl border bg-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold">Panel claim summary</h2>
                    <p class="text-sm text-muted-foreground">
                        Standard claim summary · complete the Panel’s portal
                        submission manually.
                    </p>
                </div>
                <StatusBadge :status="claim.status" />
            </div>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">Patient</dt>
                    <dd class="font-medium">{{ claim.patientName }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Panel</dt>
                    <dd class="font-medium">{{ claim.panelName }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Member reference
                    </dt>
                    <dd class="font-medium">
                        {{ claim.memberReference || 'Not recorded' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Visit date</dt>
                    <dd class="font-medium">{{ claim.visitDate }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Clinic branch</dt>
                    <dd class="font-medium">{{ claim.branchName }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Visit number</dt>
                    <dd class="font-mono">{{ claim.visitNumber }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Invoice number
                    </dt>
                    <dd class="font-mono">{{ claim.invoiceNumber }}</dd>
                </div>
            </dl>
            <p
                class="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground"
            >
                This pack contains billed invoice lines only. It does not
                include clinical notes, diagnoses, identity documents or
                attachments. Submit it through the Panel’s portal; KPOne does
                not connect to Panel systems.
            </p>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">
                Invoice lines and claim amount
            </h2>
            <OperationalTable
                label="Panel claim invoice lines"
                :columns="6"
                min-width="760px"
            >
                <template #head>
                    <tr>
                        <th scope="col">Description</th>
                        <th scope="col">Code</th>
                        <th scope="col" class="text-right">Quantity</th>
                        <th scope="col" class="text-right">Unit price</th>
                        <th scope="col" class="text-right">Line total</th>
                        <th scope="col">Type</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="line in claim.lines" :key="line.number">
                        <td class="font-medium">{{ line.name }}</td>
                        <td class="font-mono text-xs">{{ line.code }}</td>
                        <td class="text-right tabular-nums">
                            {{ line.quantity }} {{ line.unit }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{ myr(line.unitPriceSen) }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{ myr(line.totalSen) }}
                        </td>
                        <td class="capitalize">{{ line.type }}</td>
                    </tr>
                </template>
            </OperationalTable>
            <dl class="ml-auto grid max-w-md gap-2 border-t pt-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt>Invoice total</dt>
                    <dd class="font-medium tabular-nums">
                        {{ myr(claim.invoiceTotalSen) }}
                    </dd>
                </div>
                <div
                    v-if="claim.adjustmentSen > 0"
                    class="flex justify-between gap-4"
                >
                    <dt>Panel claim adjustment</dt>
                    <dd class="font-medium tabular-nums">
                        {{ myr(claim.adjustmentSen) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4 border-t pt-2 text-base">
                    <dt class="font-semibold">Claim amount</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ myr(claim.claimAmountSen) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="claim.acceptedAmountSen !== null"
            class="grid gap-4 sm:grid-cols-3"
        >
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Panel accepted</p>
                <p class="mt-1 text-xl font-semibold">
                    {{ myr(claim.acceptedAmountSen) }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Remittances received
                </p>
                <p class="mt-1 text-xl font-semibold">
                    {{ myr(claim.paidAmountSen) }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Accepted amount outstanding
                </p>
                <p class="mt-1 text-xl font-semibold">
                    {{ myr(claim.outstandingSen) }}
                </p>
            </div>
        </section>

        <section
            v-if="
                claim.canEditAmount ||
                claim.canSubmit ||
                claim.canRecordQuery ||
                claim.canRecordDecision ||
                claim.canRecordPayment
            "
            class="grid gap-4 lg:grid-cols-2 print:hidden"
        >
            <form
                v-if="claim.canEditAmount"
                class="space-y-3 rounded-xl border bg-card p-4"
                @submit.prevent="adjust"
            >
                <h2 class="font-semibold">Edit claim amount</h2>
                <label class="block space-y-1 text-sm">
                    <span>Claim amount (MYR)</span>
                    <input
                        v-model="amount"
                        inputmode="decimal"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Reason for changing the approved amount</span>
                    <textarea
                        v-model="amountReason"
                        maxlength="500"
                        required
                        rows="2"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label
                    v-if="aboveInvoice"
                    class="flex items-start gap-2 text-sm"
                >
                    <input
                        v-model="confirmAboveInvoice"
                        type="checkbox"
                        required
                        class="mt-1"
                    />
                    <span>
                        Confirm {{ myr(Number(amountSen ?? '0')) }} exceeds the
                        invoice total. The excess will be shown as a separate
                        Panel claim adjustment and will not change the invoice
                        or approved allocation.
                    </span>
                </label>
                <Button type="submit" :disabled="busy">
                    Save claim amount
                </Button>
            </form>

            <form
                v-if="claim.canSubmit"
                class="space-y-3 rounded-xl border bg-card p-4"
                @submit.prevent="submit"
            >
                <h2 class="font-semibold">Record portal submission</h2>
                <p class="text-sm text-muted-foreground">
                    Submit the claim pack manually in the Panel portal, then
                    record the submission here.
                </p>
                <label class="block space-y-1 text-sm">
                    <span>Panel portal reference (optional)</span>
                    <input
                        v-model="submissionReference"
                        maxlength="100"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <Button type="submit" :disabled="busy">
                    Record submission
                </Button>
            </form>

            <form
                v-if="claim.canRecordQuery"
                class="space-y-3 rounded-xl border bg-card p-4"
                @submit.prevent="recordQuery"
            >
                <h2 class="font-semibold">Record a Panel query</h2>
                <label class="block space-y-1 text-sm">
                    <span>Query details</span>
                    <textarea
                        v-model="queryNote"
                        maxlength="500"
                        required
                        rows="2"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Panel portal reference (optional)</span>
                    <input
                        v-model="queryReference"
                        maxlength="100"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <Button type="submit" :disabled="busy"> Save query </Button>
            </form>

            <form
                v-if="claim.canRecordDecision"
                class="space-y-3 rounded-xl border bg-card p-4"
                @submit.prevent="recordDecision"
            >
                <h2 class="font-semibold">Record Panel decision</h2>
                <label class="block space-y-1 text-sm">
                    <span>Decision</span>
                    <select
                        v-model="decision"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    >
                        <option value="accepted">Accepted</option>
                        <option value="partially_accepted">
                            Partially accepted
                        </option>
                        <option value="rejected">Rejected</option>
                    </select>
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Accepted amount (MYR; enter 0 if rejected)</span>
                    <input
                        v-model="acceptedAmount"
                        inputmode="decimal"
                        required
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Decision details</span>
                    <textarea
                        v-model="decisionNote"
                        maxlength="500"
                        required
                        rows="2"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Panel portal reference (optional)</span>
                    <input
                        v-model="decisionReference"
                        maxlength="100"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <Button type="submit" :disabled="busy">
                    Save Panel decision
                </Button>
            </form>

            <form
                v-if="claim.canRecordPayment"
                class="space-y-3 rounded-xl border bg-card p-4"
                @submit.prevent="recordPayment"
            >
                <h2 class="font-semibold">Record Panel remittance</h2>
                <p class="text-sm text-muted-foreground">
                    Up to {{ myr(claim.outstandingSen) }} remains on the
                    accepted amount. Remittances do not create patient receipts
                    or change the Invoice.
                </p>
                <label class="block space-y-1 text-sm">
                    <span>Amount received (MYR)</span>
                    <input
                        v-model="remittanceAmount"
                        inputmode="decimal"
                        required
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Date received</span>
                    <input
                        v-model="remittanceDate"
                        type="date"
                        required
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <label class="block space-y-1 text-sm">
                    <span>Panel payment reference (optional)</span>
                    <input
                        v-model="remittanceReference"
                        maxlength="100"
                        class="w-full rounded-md border bg-background px-3 py-2"
                    />
                </label>
                <Button type="submit" :disabled="busy">
                    Record remittance
                </Button>
            </form>
        </section>

        <section v-if="claim.payments.length" class="space-y-3">
            <h2 class="text-lg font-semibold">Remittance history</h2>
            <ul class="divide-y rounded-xl border bg-card">
                <li
                    v-for="(payment, index) in claim.payments"
                    :key="`${payment.receivedAt}-${index}`"
                    class="flex flex-wrap justify-between gap-2 p-4 text-sm"
                >
                    <span>{{ payment.receivedAt }}</span>
                    <span>{{ payment.reference || 'No reference' }}</span>
                    <strong>{{ myr(payment.amountSen) }}</strong>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Claim activity</h2>
            <ol class="space-y-3">
                <li
                    v-for="(event, index) in claim.events"
                    :key="`${event.type}-${index}`"
                    class="rounded-xl border bg-card p-4"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <strong>{{ eventTitle(event.type) }}</strong>
                        <time class="text-sm text-muted-foreground">
                            {{ event.occurredAt }}
                        </time>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Recorded by {{ event.actor }}
                    </p>
                    <p v-if="event.note" class="mt-2 text-sm">
                        {{ event.note }}
                    </p>
                    <p
                        v-if="event.externalReference"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Portal reference: {{ event.externalReference }}
                    </p>
                    <p
                        v-if="event.amountSen !== null"
                        class="mt-2 text-sm font-medium"
                    >
                        <template v-if="event.previousAmountSen !== null">
                            {{ myr(event.previousAmountSen) }} →
                        </template>
                        {{ myr(event.amountSen) }}
                    </p>
                </li>
            </ol>
        </section>
    </main>
</template>

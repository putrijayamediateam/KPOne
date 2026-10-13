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
    claimNo: string;
    statusKey: string;
    remarks: string | null;
    submittedOn: string | null;
    updatedBy: string | null;
    shareUrl: string;
    invoiceUrl: string;
    dueRule: string;
    verificationUrl: string | null;
    receivedOn: string | null;
    amountDueSen: number;
    identityVisible: boolean;
    patientPhone: string | null;
    patientIdentifierType: string | null;
    patientIdentifier: string | null;
    documents: {
        publicId: string;
        name: string;
        mimeType: string;
        sizeBytes: number;
        uploadedAt: string | null;
        url: string;
    }[];
    canEditRemarks: boolean;
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
const uploadRecoveryError = computed(() => page.props.errors?.file);
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
const editing = ref(false);
const copied = ref(false);
const noteOpen = ref(false);
const noteText = ref('');
const remarksText = ref(props.claim.remarks ?? '');
const fileInput = ref<HTMLInputElement | null>(null);
const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(props.claim.shareUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        error.value = 'The link could not be copied.';
    }
};
const branchId = () => page.props.branchContext?.active?.id ?? props.branch.id;
const addNote = () =>
    post(
        'post',
        `/panel-claims/${props.claim.publicId}/notes`,
        { expected_branch_id: branchId(), note: noteText.value },
        () => {
            noteText.value = '';
            noteOpen.value = false;
        },
    );
const saveRemarks = () =>
    post('patch', `/panel-claims/${props.claim.publicId}/remarks`, {
        ...context(),
        remarks: remarksText.value || null,
    });
const uploadDocument = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    busy.value = true;
    error.value = '';
    router.post(
        `/panel-claims/${props.claim.publicId}/documents`,
        { expected_branch_id: branchId(), file },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The photo could not be uploaded.';
            },
            onFinish: () => {
                busy.value = false;
                input.value = '';
            },
        },
    );
};
const sizeLabel = (bytes: number) =>
    bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
const eventTitle = (type: string) =>
    ({
        prepared: 'Panel claim was created',
        amount_adjusted: 'Claim amount adjusted',
        submitted: 'Submitted to Panel',
        queried: 'Panel query recorded',
        decision_accepted: 'Panel accepted claim',
        decision_partially_accepted: 'Panel partially accepted claim',
        decision_rejected: 'Panel rejected claim',
        note: 'Note added',
    })[type] ?? type;
</script>

<template>
    <Head :title="`Panel claim · ${claim.claimNo}`" />
    <main
        class="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 print:max-w-none print:px-0"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-4 print:items-center"
        >
            <div class="flex items-start gap-3">
                <ActionLink
                    href="/panel-claims"
                    variant="secondary"
                    aria-label="Back to Panel Claims"
                    class="print:hidden"
                >
                    ←
                </ActionLink>
                <div>
                    <h1
                        class="flex flex-wrap items-center gap-3 text-2xl font-semibold text-foreground"
                    >
                        <span>Panel Claims {{ claim.claimNo }}</span>
                        <span class="text-lg font-normal">
                            for {{ myr(claim.claimAmountSen) }}
                        </span>
                        <StatusBadge :status="claim.status" />
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        <template v-if="claim.submittedOn">
                            Submitted on {{ claim.submittedOn }}
                        </template>
                        <template v-else>
                            Created on {{ claim.createdAt }}
                        </template>
                        <template v-if="claim.updatedBy">
                            · Updated by {{ claim.updatedBy }}
                        </template>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 print:hidden">
                <Button type="button" variant="secondary" @click="copyLink">
                    {{ copied ? 'Link copied' : 'Copy link' }}
                </Button>
                <Button type="button" variant="secondary" @click="printPack">
                    Download PDF
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    @click="editing = !editing"
                >
                    {{ editing ? 'Done editing' : 'Edit panel claim' }}
                </Button>
            </div>
        </header>

        <p
            v-if="error || uploadRecoveryError"
            role="alert"
            class="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive print:hidden"
        >
            {{ error || uploadRecoveryError }}
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="space-y-6">
                <section class="space-y-5 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Summary</h2>
                    <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="font-medium">Billed to</dt>
                            <dd class="mt-1">
                                {{ claim.patientName }}
                                <span
                                    class="ml-1 rounded-full border border-brand/30 px-2 py-0.5 text-xs"
                                    >Self</span
                                >
                            </dd>
                            <dd
                                v-if="claim.patientPhone"
                                class="text-muted-foreground"
                            >
                                {{ claim.patientPhone }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Patient details</dt>
                            <dd class="mt-1">{{ claim.patientName }}</dd>
                            <dd
                                v-if="claim.patientIdentifier"
                                class="text-muted-foreground"
                            >
                                {{ claim.patientIdentifier }}
                            </dd>
                            <dd class="text-muted-foreground">
                                Member reference:
                                {{ claim.memberReference || 'N/A' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Person in charge</dt>
                            <dd class="mt-1 text-muted-foreground">N/A</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Panel claim number</dt>
                            <dd class="mt-1">{{ claim.claimNo }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Invoice number</dt>
                            <dd class="mt-1 font-mono">
                                {{ claim.invoiceNumber }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">Created date</dt>
                            <dd class="mt-1">{{ claim.createdAt }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Panel</dt>
                            <dd class="mt-1">{{ claim.panelName }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Visit</dt>
                            <dd class="mt-1">
                                {{ claim.visitNumber }} · {{ claim.visitDate }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium">
                                Panel claim submission due date
                            </dt>
                            <dd class="mt-1">{{ claim.dueRule }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Received date</dt>
                            <dd class="mt-1 text-muted-foreground">
                                {{ claim.receivedOn || 'N/A' }}
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="font-medium">Verification link</dt>
                            <dd class="mt-1">
                                <a
                                    v-if="claim.verificationUrl"
                                    :href="claim.verificationUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="break-all underline"
                                >
                                    {{ claim.verificationUrl }}
                                </a>
                                <span v-else class="text-muted-foreground"
                                    >N/A</span
                                >
                            </dd>
                        </div>
                    </dl>

                    <div class="flex items-center justify-between pt-2">
                        <h3 class="font-medium">Invoice items</h3>
                        <ActionLink
                            :href="claim.invoiceUrl"
                            variant="secondary"
                            size="sm"
                            class="print:hidden"
                        >
                            View invoice
                        </ActionLink>
                    </div>
                    <OperationalTable
                        label="Panel claim invoice items"
                        :columns="4"
                        min-width="560px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Item name</th>
                                <th scope="col" class="text-right">Rate</th>
                                <th scope="col" class="text-right">Qty</th>
                                <th scope="col" class="text-right">Total</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="line in claim.lines" :key="line.number">
                                <td class="font-medium">{{ line.name }}</td>
                                <td class="text-right tabular-nums">
                                    {{ myr(line.unitPriceSen) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ line.quantity }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ myr(line.totalSen) }}
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                    <p class="text-xs text-muted-foreground">
                        Total: {{ claim.lines.length }} items
                    </p>
                    <dl class="ml-auto grid max-w-sm gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt>Invoice total</dt>
                            <dd class="tabular-nums">
                                {{ myr(claim.invoiceTotalSen) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Claimed amount</dt>
                            <dd class="tabular-nums">
                                {{ myr(claim.claimAmountSen) }}
                            </dd>
                        </div>
                        <div
                            v-if="claim.acceptedAmountSen !== null"
                            class="flex justify-between gap-4"
                        >
                            <dt>Panel accepted</dt>
                            <dd class="tabular-nums">
                                {{ myr(claim.acceptedAmountSen) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-4 border-t pt-2 text-base"
                        >
                            <dt class="font-semibold">Amount due</dt>
                            <dd class="font-semibold tabular-nums">
                                {{ myr(claim.amountDueSen) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground print:hidden">
                        Submit this claim through the Panel’s portal; KPOne does
                        not connect to Panel systems. Clinical notes and
                        diagnoses are not part of the claim.
                    </p>
                </section>

                <section
                    class="space-y-3 rounded-xl border bg-card p-5 print:hidden"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Documents</h2>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :disabled="busy"
                            @click="fileInput?.click()"
                        >
                            Add
                        </Button>
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/jpeg,image/png"
                            class="sr-only"
                            aria-label="Upload a JPG or PNG photo"
                            @change="uploadDocument"
                        />
                    </div>
                    <p
                        v-if="claim.documents.length === 0"
                        class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        No document added. JPG or PNG, up to 5 MB each.
                    </p>
                    <ul v-else class="divide-y rounded-lg border">
                        <li
                            v-for="document in claim.documents"
                            :key="document.publicId"
                            class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                        >
                            <a
                                :href="document.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium underline"
                            >
                                {{ document.name }}
                            </a>
                            <span class="text-muted-foreground">
                                {{ sizeLabel(document.sizeBytes) }} ·
                                {{ document.uploadedAt }}
                            </span>
                        </li>
                    </ul>
                </section>

                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Payments</h2>
                    <p
                        v-if="claim.payments.length === 0"
                        class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        No payment added. Payments are recorded after the Panel
                        accepts the claim.
                    </p>
                    <ul v-else class="divide-y rounded-lg border">
                        <li
                            v-for="(payment, index) in claim.payments"
                            :key="`${payment.receivedAt}-${index}`"
                            class="flex flex-wrap justify-between gap-2 p-3 text-sm"
                        >
                            <span>{{ payment.receivedAt }}</span>
                            <span>{{
                                payment.reference || 'No reference'
                            }}</span>
                            <strong>{{ myr(payment.amountSen) }}</strong>
                        </li>
                    </ul>
                </section>

                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Remarks</h2>
                    <p
                        v-if="!editing || !claim.canEditRemarks"
                        class="text-sm"
                        :class="claim.remarks ? '' : 'text-muted-foreground'"
                    >
                        {{ claim.remarks || 'N/A' }}
                    </p>
                    <form
                        v-else
                        class="space-y-2 print:hidden"
                        @submit.prevent="saveRemarks"
                    >
                        <textarea
                            v-model="remarksText"
                            maxlength="500"
                            rows="3"
                            aria-label="Remarks"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        />
                        <Button type="submit" size="sm" :disabled="busy">
                            Save remarks
                        </Button>
                    </form>
                </section>
            </div>

            <div class="space-y-6">
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Logs</h2>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            class="print:hidden"
                            @click="noteOpen = !noteOpen"
                        >
                            Add note
                        </Button>
                    </div>
                    <form
                        v-if="noteOpen"
                        class="space-y-2 print:hidden"
                        @submit.prevent="addNote"
                    >
                        <textarea
                            v-model="noteText"
                            maxlength="500"
                            required
                            rows="2"
                            aria-label="Note"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        />
                        <Button type="submit" size="sm" :disabled="busy">
                            Save note
                        </Button>
                    </form>
                    <ol class="space-y-3">
                        <li
                            v-for="(event, index) in [
                                ...claim.events,
                            ].reverse()"
                            :key="`${event.type}-${index}`"
                            class="rounded-lg border p-3 text-sm"
                        >
                            <strong>{{ eventTitle(event.type) }}</strong>
                            <p class="text-xs text-muted-foreground">
                                {{ event.occurredAt }} · {{ event.actor }}
                            </p>
                            <p v-if="event.note" class="mt-1">
                                {{ event.note }}
                            </p>
                            <p
                                v-if="event.externalReference"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Portal reference: {{ event.externalReference }}
                            </p>
                            <p
                                v-if="event.amountSen !== null"
                                class="mt-1 font-medium"
                            >
                                <template
                                    v-if="event.previousAmountSen !== null"
                                >
                                    {{ myr(event.previousAmountSen) }} →
                                </template>
                                {{ myr(event.amountSen) }}
                            </p>
                        </li>
                    </ol>
                </section>

                <section
                    v-if="
                        (editing && claim.canEditAmount) ||
                        claim.canSubmit ||
                        claim.canRecordQuery ||
                        claim.canRecordDecision ||
                        claim.canRecordPayment
                    "
                    class="grid gap-4 print:hidden"
                >
                    <form
                        v-if="editing && claim.canEditAmount"
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
                                Confirm
                                {{ myr(Number(amountSen ?? '0')) }} exceeds the
                                invoice total. The excess will be shown as a
                                separate Panel claim adjustment and will not
                                change the invoice or approved allocation.
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
                            Submit the claim pack manually in the Panel portal,
                            then record the submission here.
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
                        <Button type="submit" :disabled="busy">
                            Save query
                        </Button>
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
                            <span
                                >Accepted amount (MYR; enter 0 if
                                rejected)</span
                            >
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
                            accepted amount. Remittances do not create patient
                            receipts or change the Invoice.
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
            </div>
        </div>
    </main>
</template>

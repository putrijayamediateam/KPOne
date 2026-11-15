<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePanelClaimShow } from '@/composables/panel-claims/usePanelClaimShow';
import type { Props } from '@/composables/panel-claims/usePanelClaimShow';
import { claimChipTone } from '@/lib/panel-claims-v2';
import { displayFromIso } from '@/lib/v2-date';
import { myr, toSen } from '@/types/billing';

/**
 * Version 2 Panel claim page (shown only in V2; Classic is `pages/PanelClaims/Show.vue`, untouched). Same props, same
 * requests, rules and lock versions (the logic is a copy of Classic's: `usePanelClaimShow`). Look only: the five record
 * forms become dialogs, and the one action that comes next is the only filled button on the page.
 */
const props = defineProps<Props>();
const claimPage = usePanelClaimShow(props);
const {
    aboveInvoice,
    acceptedAmount,
    addNote,
    adjust,
    amount,
    amountReason,
    amountSen,
    busy,
    confirmAboveInvoice,
    copied,
    copyLink,
    copyMessage,
    decision,
    decisionNote,
    decisionReference,
    error,
    eventTitle,
    messageCopied,
    noteText,
    packError,
    packUrl,
    printPack,
    queryNote,
    queryReference,
    recordDecision,
    recordPayment,
    recordQuery,
    remarksText,
    remittanceAmount,
    remittanceDate,
    remittanceReference,
    saveRemarks,
    sizeLabel,
    submissionReference,
    submit,
    uploadDocument,
    uploadRecoveryError,
} = claimPage;

// Version 2 wording of the approved design (Classic keeps "Edit claim amount").
const AMOUNT_LABEL = 'Change claim amount only: the invoice stays the same';

type Step = 'amount' | 'submit' | 'query' | 'decision' | 'payment' | 'note';
const open = ref<Step | null>(null);
const dialogOpen = computed({
    get: () => open.value !== null,
    set: (value: boolean) => {
        if (!value) {
            open.value = null;
        }
    },
});
const remarksEditing = ref(false);
const dateInvalid = ref(false);
const dateTried = ref(false);

const claim = computed(() => props.claim);

// One money step is open at a time; opening it starts from the claim's current values and a clean error.
const openStep = (step: Step) => {
    error.value = '';
    dateInvalid.value = false;
    dateTried.value = false;

    if (step === 'amount') {
        amount.value = (claim.value.claimAmountSen / 100).toFixed(2);
        amountReason.value = '';
        confirmAboveInvoice.value = false;
    }

    open.value = step;
};

// A dialog closes when its request finished without an error (the page props then carry the new state).
watch(busy, (isBusy, wasBusy) => {
    if (wasBusy && !isBusy && open.value !== null && error.value === '') {
        open.value = null;
    }
});

const nextStep = computed<{
    step: Step;
    label: string;
    hint: string;
} | null>(() => {
    if (claim.value.canSubmit) {
        return {
            step: 'submit',
            label: 'Record portal submission',
            hint: 'Submit the claim pack manually in the Panel portal, then record the submission here.',
        };
    }

    if (claim.value.canRecordDecision) {
        return {
            step: 'decision',
            label: 'Record Panel decision',
            hint: 'Record what the Panel decided: accepted, partially accepted or rejected.',
        };
    }

    if (claim.value.canRecordPayment) {
        return {
            step: 'payment',
            label: 'Record Panel remittance',
            hint: `Up to ${myr(claim.value.outstandingSen)} remains on the accepted amount.`,
        };
    }

    return null;
});

const actionGroups = computed(() => {
    const items = [
        {
            id: 'amount',
            label: AMOUNT_LABEL,
            on: claim.value.canEditAmount,
        },
        {
            id: 'query',
            label: 'Record a Panel query',
            on: claim.value.canRecordQuery,
        },
        {
            id: 'submit',
            label: 'Record portal submission',
            on: claim.value.canSubmit,
        },
        {
            id: 'decision',
            label: 'Record Panel decision',
            on: claim.value.canRecordDecision,
        },
        {
            id: 'payment',
            label: 'Record Panel remittance',
            on: claim.value.canRecordPayment,
        },
    ]
        .filter((item) => item.on && item.id !== nextStep.value?.step)
        .map(({ id, label }) => ({ id, label, allowed: true }));

    return items.length > 0 ? [{ label: '', items }] : [];
});

const sen = (value: string): number | null => {
    const parsed = toSen(value);

    return parsed === null ? null : Number(parsed);
};
const decisionLabel = computed(
    () => decisionOptions.find((o) => o.value === decision.value)?.label ?? '',
);
const decisionAmountSen = computed(() => sen(acceptedAmount.value));
const decisionSentence = computed(() =>
    decisionAmountSen.value === null
        ? null
        : `Records the Panel's decision: ${decisionLabel.value}, ${myr(decisionAmountSen.value)} of ${myr(claim.value.claimAmountSen)}.`,
);
const paymentSen = computed(() => sen(remittanceAmount.value));
const paymentOver = computed(
    () =>
        paymentSen.value !== null &&
        paymentSen.value > claim.value.outstandingSen,
);
const paymentSentence = computed(() => {
    if (
        paymentSen.value === null ||
        paymentSen.value < 1 ||
        paymentOver.value
    ) {
        return null;
    }

    const when = displayFromIso(remittanceDate.value);

    return `Records ${myr(paymentSen.value)} received from ${claim.value.panelName}${when ? ` on ${when}` : ''} for claim ${claim.value.claimNo}. ${myr(claim.value.outstandingSen - paymentSen.value)} will still be outstanding.`;
});

// Copy link and Copy message say what was copied; when the browser blocks copying, the text is shown to select.
const copyFallback = ref<{ label: string; text: string } | null>(null);
const tryCopy = async (
    run: () => Promise<void>,
    label: string,
    text: string,
) => {
    copyFallback.value = null;
    await run();

    if (error.value.endsWith('could not be copied.')) {
        error.value = '';
        copyFallback.value = { label, text };
    }
};
const MAX_DOCUMENTS = 10;

const decisionOptions = [
    { value: 'accepted', label: 'Accepted' },
    { value: 'partially_accepted', label: 'Partially accepted' },
    { value: 'rejected', label: 'Rejected' },
];

// The same checks Classic's HTML `required` attributes make, in words; the server stays the authority.
const fail = (message: string) => {
    error.value = message;
};
const dateError = computed(() =>
    dateTried.value && (dateInvalid.value || remittanceDate.value === '')
        ? 'Enter the date received, day first, such as 13 Nov 2026.'
        : undefined,
);
const confirmDialog = () => {
    error.value = '';

    if (open.value === 'amount') {
        if (amountReason.value.trim() === '') {
            return fail('Enter the reason for changing the approved amount.');
        }

        if (aboveInvoice.value && !confirmAboveInvoice.value) {
            return fail(
                'Tick the box to confirm the claim is above the invoice amount.',
            );
        }

        return adjust();
    }

    if (open.value === 'submit') {
        return submit();
    }

    if (open.value === 'query') {
        if (queryNote.value.trim() === '') {
            return fail('Enter the query details.');
        }

        return recordQuery();
    }

    if (open.value === 'decision') {
        if (decisionNote.value.trim() === '') {
            return fail('Enter the decision details.');
        }

        return recordDecision();
    }

    if (open.value === 'payment') {
        if (dateInvalid.value || remittanceDate.value === '') {
            dateTried.value = true;

            return;
        }

        return recordPayment();
    }

    if (open.value === 'note') {
        if (noteText.value.trim() === '') {
            return fail('Enter the note.');
        }

        return addNote();
    }
};

const titles: Record<Step, string> = {
    amount: AMOUNT_LABEL,
    submit: 'Record portal submission',
    query: 'Record a Panel query',
    decision: 'Record Panel decision',
    payment: 'Record Panel remittance',
    note: 'Add note',
};
const confirmLabels: Record<Step, string> = {
    amount: 'Save claim amount',
    submit: 'Record submission',
    query: 'Save query',
    decision: 'Save Panel decision',
    payment: 'Record remittance',
    note: 'Save note',
};

const rail = computed(() => [
    { label: 'Claimed', value: myr(claim.value.claimAmountSen) },
    {
        label: 'Panel accepted',
        value:
            claim.value.acceptedAmountSen === null
                ? 'Not decided yet'
                : myr(claim.value.acceptedAmountSen),
    },
    { label: 'Received', value: myr(claim.value.paidAmountSen) },
    { label: 'Amount due', value: myr(claim.value.amountDueSen) },
]);

const signed = (sen: number) => `${sen < 0 ? '−' : '+'}${myr(Math.abs(sen))}`;
const summary = computed(() => [
    { label: 'Panel claim number', value: claim.value.claimNo },
    { label: 'Panel', value: claim.value.panelName },
    { label: 'Invoice number', value: claim.value.invoiceNumber },
    {
        label: 'Visit',
        value: `${claim.value.visitNumber} · ${claim.value.visitDate}`,
    },
    { label: 'Created date', value: claim.value.createdAt },
    { label: 'Panel claim submission due date', value: claim.value.dueRule },
    { label: 'Received date', value: claim.value.receivedOn },
    { label: 'Verification link', value: claim.value.verificationUrl },
]);
const reversedLabel = (payment: { reversedOn?: string | null }) =>
    `Reversed on ${payment.reversedOn}: recorded by mistake, not counted as received.`;
const claimEvents = computed(() => [...claim.value.events].reverse());
</script>

<template>
    <Head :title="`Panel claim · ${claim.claimNo}`" />
    <PageFrame width="wide">
        <V2WorkLayout
            :title="`Panel claim ${claim.claimNo}`"
            rail-from="2xl"
            rail-label="Claim figures"
            :description="`${claim.panelName} · invoice ${claim.invoiceNumber} · ${
                claim.submittedOn
                    ? `submitted on ${claim.submittedOn}`
                    : `created on ${claim.createdAt}`
            }${claim.updatedBy ? ` · updated by ${claim.updatedBy}` : ''}`"
        >
            <template #actions>
                <Link
                    href="/panel-claims"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    aria-label="Back to Panel Claims"
                    >Back to Panel Claims</Link
                >
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    @click="tryCopy(copyLink, 'Link', claim.shareUrl)"
                >
                    {{ copied ? 'Link copied' : 'Copy link' }}
                </button>
                <button
                    v-if="claim.message"
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    @click="
                        tryCopy(copyMessage, 'Message', claim.message ?? '')
                    "
                >
                    {{ messageCopied ? 'Message copied' : 'Copy message' }}
                </button>
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    @click="printPack"
                >
                    Download PDF
                </button>
                <Link
                    :href="packUrl"
                    data-testid="print-claim-pack"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >Print claim pack</Link
                >
            </template>

            <template #rail-collapsed>
                <V2DisclosureBar title="Claim figures">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <V2Stat
                            v-for="item in rail"
                            :key="item.label"
                            :label="item.label"
                            :value="item.value"
                        />
                    </div>
                </V2DisclosureBar>
            </template>

            <template #main>
                <h2 class="sr-only">Panel claim</h2>
                <p
                    v-if="packError"
                    role="alert"
                    class="rounded-[10px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                    data-testid="claim-pack-error"
                >
                    {{ packError }}
                </p>
                <p
                    v-if="(error || uploadRecoveryError) && !dialogOpen"
                    role="alert"
                    class="rounded-[10px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm wrap-anywhere text-destructive"
                >
                    {{ error || uploadRecoveryError }}
                </p>

                <div
                    v-if="copyFallback"
                    class="grid gap-2 rounded-[10px] border bg-card p-3 text-sm"
                    role="status"
                >
                    <label for="claim-copy-fallback"
                        >Copying was blocked. Select and copy this
                        {{ copyFallback.label.toLowerCase() }}:</label
                    >
                    <textarea
                        id="claim-copy-fallback"
                        readonly
                        rows="3"
                        :value="copyFallback.text"
                        class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base md:text-[15px]"
                        @focus="($event.target as HTMLTextAreaElement).select()"
                    />
                </div>

                <div
                    class="flex flex-wrap items-center gap-3 rounded-[14px] border bg-card p-4"
                >
                    <V2Chip
                        :tone="claimChipTone(claim.status)"
                        :label="claim.status"
                    />
                    <p class="min-w-0 flex-1 text-sm text-muted-foreground">
                        {{
                            nextStep
                                ? nextStep.hint
                                : 'Nothing is waiting on you for this claim.'
                        }}
                    </p>
                    <button
                        v-if="nextStep"
                        type="button"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:hidden lg:h-9"
                        @click="openStep(nextStep.step)"
                    >
                        {{ nextStep.label }}
                    </button>
                    <V2RowMenu
                        v-if="actionGroups.length > 0"
                        :label="`Actions for claim ${claim.claimNo}`"
                        :groups="actionGroups"
                        show-label
                        @select="(id) => openStep(id as Step)"
                    />
                </div>

                <section class="grid gap-4 rounded-[14px] border bg-card p-4">
                    <h2 class="text-base leading-[22px] font-semibold">
                        Summary
                    </h2>
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="min-w-0">
                            <p class="text-xs text-muted-foreground">
                                Billed to
                            </p>
                            <p class="wrap-anywhere">
                                {{ claim.patientName }}
                                <span
                                    class="ml-1 rounded-full border border-brand/30 px-2 py-0.5 text-xs"
                                    >Self</span
                                >
                            </p>
                            <p
                                v-if="claim.patientPhone"
                                class="flex flex-wrap items-center gap-2 text-muted-foreground"
                            >
                                <span>{{ claim.patientPhone }}</span>
                                <WhatsAppLink
                                    :can-whatsapp="claim.canWhatsapp"
                                    :route-key="claim.publicId"
                                    context="claim"
                                />
                            </p>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-muted-foreground">
                                Patient details
                            </p>
                            <p class="wrap-anywhere">
                                {{ claim.patientName }}
                            </p>
                            <p
                                v-if="claim.patientIdentifier"
                                class="text-muted-foreground"
                            >
                                {{ claim.patientIdentifier }}
                            </p>
                            <p class="wrap-anywhere text-muted-foreground">
                                Member reference:
                                {{ claim.memberReference || 'N/A' }}
                            </p>
                        </div>
                    </div>
                    <V2KeyValueList :items="summary" empty-text="N/A">
                        <template #value="{ item }">
                            <a
                                v-if="
                                    item.label === 'Verification link' &&
                                    claim.verificationUrl
                                "
                                :href="claim.verificationUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="break-all underline"
                            >
                                {{ claim.verificationUrl }}
                            </a>
                            <template v-else-if="item.value">{{
                                item.value
                            }}</template>
                            <span v-else class="text-muted-foreground"
                                >N/A</span
                            >
                        </template>
                    </V2KeyValueList>
                </section>

                <section class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-base leading-[22px] font-semibold">
                            Invoice items
                        </h2>
                        <Link
                            :href="claim.invoiceUrl"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            >View invoice</Link
                        >
                    </div>
                    <V2Table
                        label="Panel claim invoice items"
                        :columns="4"
                        cards="lg"
                        min-width="560px"
                        :empty="claim.lines.length === 0"
                        empty-message="No invoice items on this claim."
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
                                <td
                                    data-label="Item name"
                                    data-primary
                                    data-wrap
                                >
                                    {{ line.name }}
                                </td>
                                <td
                                    data-label="Rate"
                                    class="text-right whitespace-nowrap"
                                >
                                    {{ myr(line.unitPriceSen) }}
                                </td>
                                <td data-label="Qty" class="text-right">
                                    {{ line.quantity }}
                                </td>
                                <td
                                    data-label="Total"
                                    class="text-right whitespace-nowrap"
                                >
                                    {{ myr(line.totalSen) }}
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <p class="text-xs text-muted-foreground">
                        Total: {{ claim.lines.length }} items
                    </p>
                    <dl class="ml-auto grid w-full max-w-sm gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt>Original subtotal (snapshot)</dt>
                            <dd class="shrink-0 whitespace-nowrap tabular-nums">
                                {{ myr(claim.invoiceSubtotalSen) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Invoice adjustment (snapshot)</dt>
                            <dd class="shrink-0 whitespace-nowrap tabular-nums">
                                {{ signed(claim.invoiceAdjustmentSen) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Adjusted invoice total (snapshot)</dt>
                            <dd class="shrink-0 whitespace-nowrap tabular-nums">
                                {{ myr(claim.invoiceTotalSen) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Claim-only adjustment (above invoice)</dt>
                            <dd class="shrink-0 whitespace-nowrap tabular-nums">
                                {{ signed(claim.claimOnlyAdjustmentSen) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Claimed amount</dt>
                            <dd class="shrink-0 whitespace-nowrap tabular-nums">
                                {{ myr(claim.claimAmountSen) }}
                            </dd>
                        </div>
                        <div
                            v-if="claim.acceptedAmountSen !== null"
                            class="flex justify-between gap-4"
                        >
                            <dt>Panel accepted</dt>
                            <dd class="shrink-0 whitespace-nowrap tabular-nums">
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
                    <p class="text-xs text-muted-foreground">
                        Submit this claim through the Panel’s portal; KPOne does
                        not connect to Panel systems. Clinical notes and
                        diagnoses are not part of the claim.
                    </p>
                </section>

                <section class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-base leading-[22px] font-semibold">
                            Documents
                        </h2>
                        <FileChooseButton
                            accept="image/jpeg,image/png"
                            label="Add photo"
                            :status="
                                busy
                                    ? 'Working…'
                                    : claim.documents.length >= MAX_DOCUMENTS
                                      ? `Full: ${MAX_DOCUMENTS} of ${MAX_DOCUMENTS} photos. JPG or PNG, up to 5 MB each.`
                                      : `${claim.documents.length} of ${MAX_DOCUMENTS} photos. JPG or PNG, up to 5 MB each.`
                            "
                            status-id="claim-photo-status"
                            :disabled="busy"
                            aria-label="Upload a JPG or PNG photo"
                            @change="uploadDocument"
                        />
                    </div>
                    <p
                        v-if="claim.documents.length === 0"
                        class="rounded-[10px] border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        No document added. JPG or PNG, up to 5 MB each.
                    </p>
                    <ul v-else class="divide-y rounded-[10px] border">
                        <li
                            v-for="document in claim.documents"
                            :key="document.publicId"
                            class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                        >
                            <a
                                :href="document.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="min-w-0 font-medium wrap-anywhere underline"
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

                <section class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <h2 class="text-base leading-[22px] font-semibold">
                        Payments
                    </h2>
                    <p
                        v-if="claim.payments.length === 0"
                        class="rounded-[10px] border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        No payment added. Payments are recorded after the Panel
                        accepts the claim.
                    </p>
                    <V2Table
                        v-else
                        label="Panel payments on this claim"
                        :columns="3"
                        cards="lg"
                        min-width="480px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Received</th>
                                <th scope="col">Reference</th>
                                <th scope="col" class="text-right">Amount</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="(payment, index) in claim.payments"
                                :key="`${payment.receivedAt}-${index}`"
                            >
                                <td data-label="Received" data-primary>
                                    {{ payment.receivedAt }}
                                    <span
                                        v-if="payment.reversed"
                                        class="mt-1 block text-xs font-normal text-muted-foreground"
                                        >{{ reversedLabel(payment) }}</span
                                    >
                                </td>
                                <td data-label="Reference" data-wrap>
                                    {{ payment.reference || 'No reference' }}
                                </td>
                                <td
                                    data-label="Amount"
                                    class="text-right font-medium whitespace-nowrap"
                                    :class="
                                        payment.reversed
                                            ? 'text-muted-foreground line-through'
                                            : ''
                                    "
                                >
                                    {{ myr(payment.amountSen) }}
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                </section>

                <!-- A "Panel adjustments" section for the Panel claim design (adjustments and supplementary claims) goes here when
                     that work lands; nothing is shown until a claim has one. -->
                <section class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-base leading-[22px] font-semibold">
                            Remarks
                        </h2>
                        <button
                            v-if="claim.canEditRemarks && !remarksEditing"
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            @click="remarksEditing = true"
                        >
                            Edit remarks
                        </button>
                    </div>
                    <p
                        v-if="!remarksEditing || !claim.canEditRemarks"
                        class="text-sm wrap-anywhere"
                        :class="claim.remarks ? '' : 'text-muted-foreground'"
                    >
                        {{ claim.remarks || 'N/A' }}
                    </p>
                    <form
                        v-else
                        class="grid gap-2"
                        @submit.prevent="
                            saveRemarks();
                            remarksEditing = false;
                        "
                    >
                        <textarea
                            v-model="remarksText"
                            maxlength="500"
                            rows="3"
                            aria-label="Remarks"
                            class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                        />
                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="busy"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                            >
                                Save remarks
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                @click="remarksEditing = false"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </section>

                <section class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-base leading-[22px] font-semibold">
                            Logs
                        </h2>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            @click="
                                noteText = '';
                                openStep('note');
                            "
                        >
                            Add note
                        </button>
                    </div>
                    <ol class="grid gap-3">
                        <li
                            v-for="(event, index) in claimEvents"
                            :key="`${event.type}-${index}`"
                            class="rounded-[10px] border p-3 text-sm"
                        >
                            <strong>{{ eventTitle(event.type) }}</strong>
                            <p class="text-xs text-muted-foreground">
                                {{ event.occurredAt }} · {{ event.actor }}
                            </p>
                            <p v-if="event.note" class="mt-1 wrap-anywhere">
                                {{ event.note }}
                            </p>
                            <p
                                v-if="event.externalReference"
                                class="mt-1 text-xs wrap-anywhere text-muted-foreground"
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

                <V2StickyBar v-if="nextStep" hide-from="sm">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                        @click="openStep(nextStep.step)"
                    >
                        {{ nextStep.label }}
                    </button>
                </V2StickyBar>
            </template>

            <template #rail>
                <div class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <h2 class="text-base leading-[22px] font-semibold">
                        Claim figures
                    </h2>
                    <V2Stat
                        v-for="item in rail"
                        :key="item.label"
                        :label="item.label"
                        :value="item.value"
                    />
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="dialogOpen"
            :title="open ? titles[open] : ''"
            :confirm-label="open ? confirmLabels[open] : 'Save'"
            :busy="busy"
            :confirm-disabled="open === 'payment' && paymentOver"
            confirm-disabled-reason="Enter an amount that is not more than what is still outstanding."
            @confirm="confirmDialog()"
        >
            <div v-if="open" class="grid gap-3">
                <template v-if="open === 'amount'">
                    <V2MoneyInput
                        v-model="amount"
                        label="Claim amount (MYR)"
                        required
                    />
                    <V2Field
                        label="Reason for changing the approved amount"
                        required
                    >
                        <template #default="{ id, describedBy }">
                            <textarea
                                :id="id"
                                v-model="amountReason"
                                :aria-describedby="describedBy"
                                maxlength="500"
                                rows="2"
                                class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                            />
                        </template>
                    </V2Field>
                    <label
                        v-if="aboveInvoice"
                        class="flex min-h-11 items-start gap-2 text-sm"
                    >
                        <input
                            v-model="confirmAboveInvoice"
                            type="checkbox"
                            class="mt-1 size-5"
                        />
                        <span>
                            I confirm the claim is above the invoice amount.
                            <span class="block text-muted-foreground">
                                {{ myr(Number(amountSen ?? '0')) }} exceeds the
                                invoice total. The excess will be shown as a
                                separate Panel claim adjustment and will not
                                change the invoice or approved allocation.
                            </span>
                        </span>
                    </label>
                </template>

                <template v-else-if="open === 'submit'">
                    <p class="text-sm text-muted-foreground">
                        Submit the claim pack manually in the Panel portal, then
                        record the submission here.
                    </p>
                    <V2Field label="Panel portal reference (optional)">
                        <template #default="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="submissionReference"
                                :aria-describedby="describedBy"
                                maxlength="100"
                                class="h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                            />
                        </template>
                    </V2Field>
                </template>

                <template v-else-if="open === 'query'">
                    <V2Field label="Query details" required>
                        <template #default="{ id, describedBy }">
                            <textarea
                                :id="id"
                                v-model="queryNote"
                                :aria-describedby="describedBy"
                                maxlength="500"
                                rows="2"
                                class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                            />
                        </template>
                    </V2Field>
                    <V2Field label="Panel portal reference (optional)">
                        <template #default="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="queryReference"
                                :aria-describedby="describedBy"
                                maxlength="100"
                                class="h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                            />
                        </template>
                    </V2Field>
                </template>

                <template v-else-if="open === 'decision'">
                    <V2OptionGroup
                        :model-value="decision"
                        :options="decisionOptions"
                        label="Decision"
                        @update:model-value="
                            (value) =>
                                (decision = value as
                                    | 'accepted'
                                    | 'partially_accepted'
                                    | 'rejected')
                        "
                    />
                    <V2MoneyInput
                        v-if="decision === 'partially_accepted'"
                        v-model="acceptedAmount"
                        label="Accepted amount (MYR; enter 0 if rejected)"
                        required
                    />
                    <p v-else class="text-sm">
                        Accepted amount:
                        <strong class="tabular-nums">{{
                            myr(decisionAmountSen ?? 0)
                        }}</strong>
                        <span class="text-muted-foreground">
                            {{
                                decision === 'accepted'
                                    ? '(the full claimed amount)'
                                    : '(nothing accepted)'
                            }}
                        </span>
                    </p>
                    <p
                        v-if="decisionSentence"
                        class="text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        {{ decisionSentence }}
                    </p>
                    <V2Field label="Decision details" required>
                        <template #default="{ id, describedBy }">
                            <textarea
                                :id="id"
                                v-model="decisionNote"
                                :aria-describedby="describedBy"
                                maxlength="500"
                                rows="2"
                                class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                            />
                        </template>
                    </V2Field>
                    <V2Field label="Panel portal reference (optional)">
                        <template #default="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="decisionReference"
                                :aria-describedby="describedBy"
                                maxlength="100"
                                class="h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                            />
                        </template>
                    </V2Field>
                </template>

                <template v-else-if="open === 'payment'">
                    <p class="text-sm text-muted-foreground">
                        Up to {{ myr(claim.outstandingSen) }} remains on the
                        accepted amount. Remittances do not create patient
                        receipts or change the Invoice.
                    </p>
                    <V2MoneyInput
                        v-model="remittanceAmount"
                        label="Amount received (MYR)"
                        required
                    />
                    <p
                        v-if="paymentOver"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        That is more than the
                        {{ myr(claim.outstandingSen) }} still outstanding.
                    </p>
                    <V2DateField
                        v-model="remittanceDate"
                        label="Date received"
                        subject="Date received"
                        required
                        :max="props.branch.today"
                        :error="dateError"
                        @update:invalid="(value) => (dateInvalid = value)"
                    />
                    <p
                        v-if="paymentSentence"
                        class="text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        {{ paymentSentence }}
                    </p>
                    <V2Field label="Panel payment reference (optional)">
                        <template #default="{ id, describedBy }">
                            <input
                                :id="id"
                                v-model="remittanceReference"
                                :aria-describedby="describedBy"
                                maxlength="100"
                                class="h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                            />
                        </template>
                    </V2Field>
                </template>

                <template v-else-if="open === 'note'">
                    <V2Field label="Note" required>
                        <template #default="{ id, describedBy }">
                            <textarea
                                :id="id"
                                v-model="noteText"
                                :aria-describedby="describedBy"
                                maxlength="500"
                                rows="3"
                                class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                            />
                        </template>
                    </V2Field>
                </template>

                <p
                    v-if="error"
                    role="alert"
                    class="text-sm wrap-anywhere text-destructive"
                >
                    {{ error }}
                </p>
            </div>
        </V2Dialog>
    </PageFrame>
</template>

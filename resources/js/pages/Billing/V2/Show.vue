<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, ChevronDown } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import BillingChargeRows from '@/components/billing/BillingChargeRows.vue';
import InvoiceActivityDrawer from '@/components/billing/InvoiceActivityDrawer.vue';
import InvoiceBillTo from '@/components/billing/InvoiceBillTo.vue';
import InvoiceNotes from '@/components/billing/InvoiceNotes.vue';
import IssuedCopies from '@/components/billing/IssuedCopies.vue';
import IssuePrintButton from '@/components/billing/IssuePrintButton.vue';
import OutstandingBills from '@/components/billing/OutstandingBills.vue';
import ReceiptCorrections from '@/components/billing/ReceiptCorrections.vue';
import ReceiptRefunds from '@/components/billing/ReceiptRefunds.vue';
import BillingCompletionDialogV2 from '@/components/billing/v2/BillingCompletionDialogV2.vue';
import InvoiceAdjustmentDialogV2 from '@/components/billing/v2/InvoiceAdjustmentDialogV2.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useBillingWorkspaceV2 } from '@/composables/billing/useBillingWorkspaceV2';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import {
    billingDueLine,
    billingDueParts,
    billingPrimary,
    billingPrimaryLabel,
} from '@/lib/billing-v2';
import { formatDate, formatStatusLabel, statusTone } from '@/lib/presentation';
import type { BillingPage } from '@/types/billing';
import { myr, outstandingSen } from '@/types/billing';

/**
 * BV2-1: Version 2 Patient Billing (shown only in Version 2; Classic is `components/billing/BillingWorkspace.vue`,
 * untouched). Same `billing` prop, routes, permission flags, lock versions, payloads, idempotency key and money rules:
 * the state and actions come from the BV2-0 copy of the Classic logic. Daniel's Billing department look (2026-11-13):
 * the patient's name and visit number head the page, the right column starts at 1280 px with Due now first below it,
 * one cobalt action per state (the bottom bar carries it below 1280 px), StatusBadge words through the V2 chip look.
 * Every dialog is a V2Dialog: the three entry dialogs here, and the adjustment and completion dialogs (BV2-2,
 * components/billing/v2). The refunds, corrections, Bill to and notes blocks stay Classic under the page wrapper.
 */
const props = withDefaults(
    defineProps<{ billing: BillingPage; manage?: boolean }>(),
    { manage: false },
);
const {
    responsibilityTotals,
    invoiceTabs,
    page,
    busy,
    receiptTone,
    contextCleared,
    completionOpen,
    paymentOpen,
    responsibilityOpen,
    responsibilityKind,
    reversalOpen,
    reversalReceipt,
    updateDialog,
    openEntry,
    openReversal,
    error,
    tab,
    form,
    base,
    invoiceBase,
    isCompleted,
    dueNowText,
    methodOptions,
    panelOptions,
    lines,
    post,
    payment,
    propose,
    approve,
    reverse,
} = useBillingWorkspaceV2(props);

// The copied logic resets "context cleared" only for the Classic page names; in Version 2 the page is Billing/V2/Show.
const stopV2Success = router.on('success', (event) => {
    if (event.detail.page.component === 'Billing/V2/Show') {
        contextCleared.value = false;
    }
});
onUnmounted(stopV2Success);

// The Version 2 wrapper over the shared Classic pieces (Daniel's BV2-1 exception, until BV2-2): while this page is open
// the body carries a marker, so page-only CSS reaches the Classic dialogs too (they open outside the page). No Classic
// file changes.
onMounted(() => document.body.setAttribute('data-billing-v2', ''));
onUnmounted(() => document.body.removeAttribute('data-billing-v2'));

usePageCrumbs(() => [
    ...(props.billing.backLink
        ? [
              {
                  title: props.billing.backLink.label,
                  href: props.billing.backLink.href,
              },
          ]
        : []),
    { title: props.billing.visit.visitNumber },
]);

const title = computed(() =>
    isCompleted.value ? 'Completed Visit' : 'Patient Billing',
);
// Whose bill it is, above any figure (Daniel): the patient's name and the visit number head the page.
const description = computed(
    () =>
        `${props.billing.patient.name} · ${props.billing.visit.visitNumber} · ${props.billing.branch}`,
);

// StatusBadge stays the wording source (one map); V2Chip is the look.
const chip = (status: string, tone?: 'neutral') => {
    const t = tone ?? statusTone(status);

    return {
        label: formatStatusLabel(status),
        tone: (t === 'accent' ? 'info' : t) as
            'neutral' | 'info' | 'success' | 'warning' | 'danger',
    };
};

const dueHint = computed(() =>
    props.billing.invoice
        ? `Due now ${myr(props.billing.invoice.state.due_now)}`
        : undefined,
);
// The pickers take string ids; the form keeps the Classic values, so payloads are unchanged.
const methodPicks = computed(() =>
    methodOptions.value
        .filter((option) => option.value !== '')
        .map((option) => ({ id: String(option.value), label: option.label })),
);
const panelPicks = computed(() =>
    panelOptions.value
        .filter((option) => option.value !== '')
        .map((option) => ({ id: String(option.value), label: option.label })),
);
const setPanel = (id: string | null) => {
    const match = panelOptions.value.find(
        (option) => option.value !== '' && String(option.value) === id,
    );
    form.panel_id = (match?.value ?? '') as string;
};
// "Discard changes" in a V2Dialog clears only that dialog's own fields, like Classic's Cancel kept the rest: the
// idempotency key and other forms on the page (a Governed correction reason) are untouched, and nothing is cleared
// while a request is still running.
const discardEntry = (kind: 'payment' | 'responsibility' | 'reversal') => {
    if (busy.value) {
        return;
    }

    if (kind === 'payment') {
        Object.assign(form, { amount: '', method: '', reference: '' });
    } else if (kind === 'responsibility') {
        Object.assign(form, {
            responsibility_amount: '',
            reason: '',
            panel_id: '',
            member_reference: '',
            due_date: '',
        });
    } else {
        Object.assign(form, { reason: '', recording_error_only: false });
    }
};
const dueParts = computed(() =>
    billingDueParts(props.billing.invoice?.state ?? null),
);
const dueLine = computed(() =>
    billingDueLine(props.billing.invoice?.state ?? null),
);

// The one cobalt action for the page's state (GUIDE 19), from the existing permission flags only.
type Primary = { label: string; run: () => void };
const primaryKind = computed(() =>
    billingPrimary(props.billing, isCompleted.value),
);
const primary = computed<Primary | null>(() => {
    const kind = primaryKind.value;

    if (kind === null) {
        return null;
    }

    const run = {
        build: () => post(`${base.value}/build`),
        finalize: () => post(`${invoiceBase.value}/finalize`),
        pay: () => openEntry('payment'),
        settle: () => openEntry('payment'),
        complete: () => {
            completionOpen.value = true;
        },
    }[kind];

    return { label: billingPrimaryLabel[kind], run };
});

// "More billing actions": the other responsibility entries, then the rare void (Danger, last).
const voidOpen = ref(false);
const voidReason = ref<HTMLTextAreaElement | null>(null);
const moreGroups = computed<RowMenuGroup[]>(() =>
    [
        {
            label: 'Panel or pay later',
            items: [
                {
                    id: 'panel',
                    label: 'Propose Panel responsibility',
                    allowed: props.billing.can.panelPropose,
                },
                {
                    id: 'deferment',
                    label: 'Request pay later',
                    allowed: props.billing.can.deferPropose,
                },
            ].filter((item) => item.allowed),
        },
        {
            label: 'Danger',
            items: [
                {
                    id: 'void',
                    label: 'Governed correction',
                    allowed: props.billing.can.void,
                    tone: 'danger' as const,
                },
            ].filter((item) => item.allowed),
        },
    ].filter((group) => group.items.length > 0),
);
const onMore = (id: string) => {
    if (id === 'panel' || id === 'deferment') {
        openEntry(id);

        return;
    }

    if (id === 'void') {
        voidOpen.value = true;
        // The menu hands focus back to its trigger after it closes (a timer); move focus in after that.
        window.setTimeout(() => {
            voidReason.value?.scrollIntoView({ block: 'center' });
            voidReason.value?.focus();
        }, 50);
    }
};
// A new visit or invoice (for example after a void and reissue) starts with the correction form closed.
watch(
    () => [props.billing.visit.visitNumber, props.billing.invoice?.publicId],
    () => {
        voidOpen.value = false;
    },
);
const closeVoid = () => {
    voidOpen.value = false;
};

const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
const card = 'min-w-0 rounded-[14px] border bg-card';
</script>

<template>
    <Head title="Patient Billing" />
    <PageFrame v-if="contextCleared">
        <section
            class="rounded-[14px] border bg-card p-5"
            role="status"
            data-testid="billing-context-cleared"
        >
            <h1 class="text-base font-semibold">Billing context cleared</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                The branch changed. Open the visit again from your workspace.
            </p>
            <ActionLink href="/workspace" variant="outline" class="mt-3">
                Return to workspace
            </ActionLink>
        </section>
    </PageFrame>
    <template v-else>
        <PageFrame class="billing-v2-page" data-testid="billing-columns">
            <V2WorkLayout
                eyebrow="Visit"
                rail-from="xl"
                rail-below="stack-top"
                rail-label="Payment summary"
                :title="title"
                :description="description"
                data-testid="active-billing"
            >
                <template #actions>
                    <button
                        v-if="primary"
                        type="button"
                        :class="[primaryButton, 'max-xl:hidden']"
                        :disabled="busy"
                        data-testid="billing-primary"
                        @click="primary.run()"
                    >
                        {{ primary.label }}
                    </button>
                    <InvoiceActivityDrawer
                        :context="billing.activity"
                        :notes-context="billing.notes"
                    />
                    <V2RowMenu
                        v-if="moreGroups.length"
                        label="More billing actions"
                        :groups="moreGroups"
                        :busy="busy"
                        @select="onMore"
                    />
                </template>

                <template #main>
                    <div class="flex flex-wrap items-center gap-2">
                        <V2Chip v-bind="chip(billing.visit.status)" />
                        <span
                            v-if="isCompleted"
                            class="text-[13px] text-muted-foreground"
                            >Read-only Visit record</span
                        >
                    </div>
                    <p
                        v-if="error"
                        role="alert"
                        class="rounded-[10px] border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {{ error }}
                    </p>

                    <!-- Patient -->
                    <section :class="[card, 'p-4 sm:p-5']">
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <h2
                                    class="text-[17px] leading-[22px] font-semibold break-words"
                                >
                                    {{ billing.patient.name }}
                                </h2>
                                <p
                                    class="mt-0.5 font-mono text-[13px] text-muted-foreground"
                                >
                                    {{ billing.patient.patientNumber }}
                                </p>
                            </div>
                            <ActionLink
                                v-if="billing.patient.profileUrl"
                                :href="billing.patient.profileUrl"
                                variant="outline"
                                >View profile</ActionLink
                            >
                        </div>
                        <dl
                            class="mt-4 grid gap-3 border-t pt-4 text-[15px] sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-[13px] text-muted-foreground">
                                    Visit No.
                                </dt>
                                <dd class="font-mono">
                                    {{ billing.visit.visitNumber }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[13px] text-muted-foreground">
                                    Branch
                                </dt>
                                <dd>{{ billing.branch }}</dd>
                            </div>
                            <div>
                                <dt class="text-[13px] text-muted-foreground">
                                    Coverage
                                </dt>
                                <dd>{{ billing.visit.coverage }}</dd>
                            </div>
                            <div v-if="billing.visit.completedAt">
                                <dt class="text-[13px] text-muted-foreground">
                                    Completed
                                </dt>
                                <dd>{{ billing.visit.completedAt }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-[13px] text-muted-foreground">
                            Registration coverage is not financial approval.
                        </p>
                    </section>

                    <!-- Invoice -->
                    <section :class="card">
                        <div class="p-3">
                            <V2Tabs
                                v-model="tab"
                                :tabs="invoiceTabs"
                                ariaLabel="Invoice content"
                            />
                        </div>
                        <div
                            role="tabpanel"
                            aria-label="Invoice content"
                            class="border-t"
                        >
                            <template v-if="tab !== 'documents'"
                                ><p
                                    v-if="!billing.invoice"
                                    class="px-4 py-6 text-[13px] text-muted-foreground"
                                >
                                    Awaiting Billing. Build the draft from
                                    completed fulfilment and confirmed services.
                                    No stock is moved here.
                                </p>
                                <BillingChargeRows v-else :lines="lines" />
                            </template>
                            <div v-else class="space-y-3 p-4">
                                <IssuePrintButton
                                    v-if="billing.can.print"
                                    :url="`${invoiceBase}/issue`"
                                    label="Print invoice"
                                    return-to="billing"
                                />
                                <div
                                    v-for="receipt in billing.payments"
                                    :key="receipt.publicId"
                                    class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium tabular-nums">
                                            {{ receipt.number }} ·
                                            {{ myr(receipt.amountSen) }}
                                        </p>
                                        <p
                                            class="text-[13px] text-muted-foreground"
                                        >
                                            {{ receipt.method }} ·
                                            {{ receipt.receivedAt }}
                                        </p>
                                        <p
                                            v-if="receipt.refundedSen"
                                            class="text-[13px] text-muted-foreground tabular-nums"
                                            data-testid="payment-refunded"
                                        >
                                            Refunded
                                            {{ myr(receipt.refundedSen) }}
                                            ·
                                            {{
                                                myr(
                                                    receipt.amountSen -
                                                        receipt.refundedSen,
                                                )
                                            }}
                                            kept
                                        </p>
                                        <p
                                            v-if="receipt.recordedBy"
                                            class="text-[13px] break-words text-muted-foreground"
                                        >
                                            Recorded by
                                            {{ receipt.recordedBy }}
                                        </p>
                                        <V2Chip
                                            class="mt-1"
                                            v-bind="
                                                chip(
                                                    receipt.status,
                                                    receiptTone(receipt.status),
                                                )
                                            "
                                        />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <IssuePrintButton
                                            v-if="billing.can.print"
                                            :url="`${invoiceBase}/receipts/${receipt.publicId}/issue`"
                                            label="Print receipt"
                                            return-to="billing"
                                        />
                                        <Button
                                            v-if="
                                                billing.can.reverse &&
                                                receipt.status === 'posted'
                                            "
                                            variant="outline"
                                            class="max-lg:min-h-11"
                                            :disabled="busy"
                                            @click="openReversal(receipt)"
                                        >
                                            Reverse recording error
                                        </Button>
                                    </div>
                                </div>
                                <IssuedCopies
                                    v-if="billing.issuedCopies"
                                    :copies="billing.issuedCopies"
                                    :base="base"
                                    return-to="billing"
                                />
                            </div>
                        </div>
                        <div
                            v-if="
                                billing.invoice &&
                                billing.can.build &&
                                primaryKind !== 'build'
                            "
                            class="flex flex-wrap gap-2 border-t p-3"
                        >
                            <Button
                                v-if="billing.can.build"
                                variant="outline"
                                class="max-lg:min-h-11"
                                :disabled="busy"
                                @click="post(`${base}/build`)"
                                >Build / refresh draft</Button
                            >
                        </div>
                    </section>

                    <!-- Panel and pay later -->
                    <section
                        v-if="
                            billing.panel ||
                            billing.deferment ||
                            billing.panelShortfall
                        "
                        :class="[card, 'divide-y']"
                    >
                        <template
                            v-for="kind in ['panel', 'deferment'] as const"
                            :key="kind"
                        >
                            <div v-if="billing[kind]" class="space-y-1 p-4">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <p class="font-medium">
                                        {{
                                            kind === 'panel'
                                                ? 'Panel'
                                                : 'Pay later'
                                        }}
                                        ·
                                        <span class="tabular-nums">{{
                                            myr(billing[kind]!.amountSen)
                                        }}</span>
                                    </p>
                                    <V2Chip
                                        v-bind="chip(billing[kind]!.status)"
                                    />
                                </div>
                                <p class="text-[13px] text-muted-foreground">
                                    {{ billing[kind]!.reason }}
                                </p>
                                <p
                                    v-if="billing[kind]!.panelName"
                                    class="text-[13px]"
                                >
                                    {{ billing[kind]!.panelName
                                    }}<span
                                        v-if="billing[kind]!.memberReference"
                                    >
                                        ·
                                        {{
                                            billing[kind]!.memberReference
                                        }}</span
                                    >
                                </p>
                                <p
                                    v-if="
                                        kind === 'deferment' &&
                                        billing[kind]!.status === 'approved' &&
                                        billing[kind]!.remainingSen === 0
                                    "
                                    class="flex items-center gap-1 text-[13px] font-medium text-success"
                                    data-testid="pay-later-settled"
                                >
                                    <Check
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >Paid in full · nothing left to
                                        collect</span
                                    >
                                </p>
                                <p
                                    v-else-if="billing[kind]!.dueDate"
                                    class="text-[13px]"
                                >
                                    <template
                                        v-if="
                                            kind === 'deferment' &&
                                            billing[kind]!.status ===
                                                'approved' &&
                                            billing[kind]!.remainingSen !==
                                                null &&
                                            billing[kind]!.remainingSen! <
                                                billing[kind]!.amountSen
                                        "
                                        >{{ myr(billing[kind]!.remainingSen!) }}
                                        still to collect ·
                                    </template>
                                    Due
                                    {{ formatDate(billing[kind]!.dueDate) }}
                                </p>
                                <p
                                    v-if="billing[kind]!.status === 'proposed'"
                                    class="text-[13px] text-muted-foreground"
                                    data-testid="proposal-hint"
                                >
                                    {{
                                        (
                                            kind === 'panel'
                                                ? billing.can.panelApprove
                                                : billing.can.deferApprove
                                        )
                                            ? 'Check the details, then press Approve.'
                                            : 'Waiting for approval. Someone with approval rights must press Approve before this counts.'
                                    }}
                                </p>
                                <Button
                                    v-if="
                                        billing[kind]!.status === 'proposed' &&
                                        (kind === 'panel'
                                            ? billing.can.panelApprove
                                            : billing.can.deferApprove)
                                    "
                                    variant="outline"
                                    class="mt-1 max-lg:min-h-11"
                                    :disabled="busy"
                                    @click="approve(kind, billing[kind]!)"
                                    >Approve</Button
                                >
                            </div>
                        </template>
                        <div
                            v-if="billing.panelShortfall"
                            role="note"
                            data-testid="panel-shortfall"
                            class="m-3 rounded-[10px] border border-warning/40 bg-warning/10 p-3 text-[13px] text-foreground"
                        >
                            <p class="font-semibold">
                                Panel shortfall:
                                {{ myr(billing.panelShortfall.shortfallSen) }}
                            </p>
                            <p>
                                {{
                                    billing.panelShortfall.decision ===
                                    'rejected'
                                        ? 'The Panel rejected the claim.'
                                        : billing.panelShortfall.decision ===
                                            'partially_accepted'
                                          ? 'The Panel accepted only part of the claim.'
                                          : 'The claim was for less than the Panel share.'
                                }}
                                The Panel accepted
                                {{ myr(billing.panelShortfall.acceptedSen) }}
                                of its
                                {{ myr(billing.panelShortfall.panelShareSen) }}
                                share.
                            </p>
                            <p class="mt-1">
                                Shown for information only. What the patient
                                owes is unchanged.
                            </p>
                        </div>
                    </section>

                    <OutstandingBills :groups="billing.outstanding" />

                    <section
                        v-if="billing.voidedInvoices?.length"
                        :class="[card, 'p-4']"
                        aria-labelledby="voided-invoices-heading"
                        data-testid="voided-invoices"
                    >
                        <h2 id="voided-invoices-heading" class="font-semibold">
                            Voided invoices
                        </h2>
                        <p class="text-[13px] text-muted-foreground">
                            Kept for the record. They no longer count towards
                            what is owed.
                        </p>
                        <div
                            v-for="voided in billing.voidedInvoices"
                            :key="voided.number"
                            class="mt-3 border-t pt-3"
                        >
                            <p
                                class="flex flex-wrap items-center gap-2 tabular-nums"
                            >
                                <span class="font-medium">{{
                                    voided.number
                                }}</span>
                                · {{ myr(voided.totalSen) }}
                                <V2Chip v-bind="chip('voided', 'neutral')" />
                            </p>
                            <p class="text-[13px] text-muted-foreground">
                                {{ voided.voidedAt }} by
                                {{ voided.voidedBy }}
                            </p>
                            <ul
                                class="mt-1 space-y-0.5"
                                :aria-label="`Receipts of ${voided.number}`"
                            >
                                <li
                                    v-for="r in voided.receipts"
                                    :key="r.number"
                                    class="text-[13px] tabular-nums"
                                >
                                    {{ r.number }} · {{ r.method }} ·
                                    {{ myr(r.amountSen) }} ·
                                    {{
                                        r.status === 'reversed'
                                            ? 'Reversed'
                                            : 'Received'
                                    }}
                                </li>
                            </ul>
                        </div>
                    </section>

                    <ReceiptRefunds
                        v-if="billing.refunds"
                        :refunds="billing.refunds"
                        :base="base"
                        :manage="manage"
                        :corrections-below="!!billing.corrections"
                    />
                    <ReceiptCorrections
                        v-if="billing.corrections"
                        :corrections="billing.corrections"
                        :base="invoiceBase"
                        :due-now-sen="billing.invoice?.state.due_now ?? 0"
                        :manage="manage"
                    />

                    <!-- Governed correction: opened from "More billing actions" (Danger), in view, with Cancel. -->
                    <section
                        v-if="voidOpen && billing.can.void"
                        :class="[card, 'space-y-3 p-4']"
                        aria-labelledby="governed-correction-heading"
                        data-testid="governed-correction"
                    >
                        <h2
                            id="governed-correction-heading"
                            class="font-semibold"
                        >
                            Governed correction
                        </h2>
                        <p class="text-[13px]">
                            Recording errors only. No refunds or post-completion
                            accounting correction.
                        </p>
                        <label class="block text-[15px]"
                            >Reason<textarea
                                ref="voidReason"
                                v-model="form.reason"
                                class="billing-input-v2"
                                maxlength="500"
                            />
                        </label>
                        <label
                            class="flex min-h-11 items-center gap-2 text-[15px]"
                            ><input
                                v-model="form.recording_error_only"
                                type="checkbox"
                            />No real refund: recording error only</label
                        >
                        <div class="flex flex-wrap justify-end gap-2">
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                :disabled="busy"
                                @click="closeVoid"
                                >Cancel</Button
                            >
                            <Button
                                variant="destructive"
                                class="max-lg:min-h-11"
                                :disabled="busy || !form.reason"
                                @click="
                                    post(`${invoiceBase}/void`, {
                                        reason: form.reason,
                                    })
                                "
                                >Void for reissue</Button
                            >
                        </div>
                    </section>

                    <p class="text-[13px] text-muted-foreground">
                        Billing never deducts stock. Panel and approved pay
                        later are not cash receipts.
                    </p>

                    <BillingCompletionDialogV2
                        v-if="billing.invoice"
                        v-model:open="completionOpen"
                        :visit-number="billing.visit.visitNumber"
                        :branch-id="page.props.branchContext?.active?.id ?? 0"
                        :visit-lock-version="billing.visit.lockVersion"
                        :invoice-lock-version="billing.invoice.lockVersion"
                    />
                    <!-- The bottom bar carries the one main action with the amount above it (V2StickyBar: below 640 px
                         for now; it will follow the right-column breakpoint once V2StickyBar reads it). -->
                    <V2StickyBar
                        v-if="primary"
                        data-testid="billing-sticky-bar"
                    >
                        <div class="flex h-auto! flex-col gap-1">
                            <p
                                v-if="dueLine"
                                class="text-[13px] text-muted-foreground tabular-nums"
                            >
                                {{ dueLine }}
                            </p>
                            <button
                                type="button"
                                :class="[primaryButton, 'w-full']"
                                :disabled="busy"
                                @click="primary.run()"
                            >
                                {{ primary.label }}
                            </button>
                        </div>
                    </V2StickyBar>
                </template>
                <!-- The payment summary: the right column from 1280 px, stacked above the work below it (railBelow
                     "stack-top"). Rendered once, so nothing remounts when the width changes. -->
                <template #rail>
                    <section
                        :class="[card, 'p-4']"
                        data-testid="billing-financial"
                    >
                        <template v-if="billing.invoice"
                            ><div
                                class="flex items-start justify-between gap-3"
                            >
                                <div>
                                    <p
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        Financial summary
                                    </p>
                                    <h2 class="font-semibold">
                                        {{
                                            billing.invoice.number ??
                                            'Draft Invoice'
                                        }}
                                    </h2>
                                </div>
                                <V2Chip v-bind="chip(billing.invoice.status)" />
                            </div>
                            <!-- Due now first: whose bill is in the page heading above. -->
                            <!-- One element, so a screen reader reads the label with the figure ("Due now RM 40.00"). -->
                            <p
                                v-if="dueParts"
                                class="mt-3"
                                data-testid="billing-due-now"
                            >
                                <span
                                    v-if="dueParts.label"
                                    class="block text-[13px] font-medium text-muted-foreground"
                                    >{{ dueParts.label }}
                                </span>
                                <span
                                    class="block text-3xl leading-[1.15] font-bold tracking-tight whitespace-nowrap tabular-nums"
                                    >{{ dueParts.figure }}</span
                                >
                            </p>
                            <dl
                                class="mt-3 text-[15px]"
                                data-testid="billing-summary-grid"
                            >
                                <div class="billing-summary-row-v2">
                                    <dt class="font-medium">Invoice Total</dt>
                                    <dd class="font-semibold tabular-nums">
                                        {{ myr(billing.invoice.state.total) }}
                                    </dd>
                                </div>
                                <div
                                    v-for="[key, label] in responsibilityTotals"
                                    :key="key"
                                    class="billing-summary-row-v2"
                                >
                                    <dt class="text-muted-foreground">
                                        {{ label }}
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{ myr(billing.invoice.state[key]) }}
                                    </dd>
                                </div>
                                <div class="billing-summary-row-v2">
                                    <dt class="font-medium">Outstanding</dt>
                                    <dd class="font-medium tabular-nums">
                                        {{
                                            myr(
                                                outstandingSen(
                                                    billing.invoice.state,
                                                ),
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <dl
                                v-if="
                                    billing.invoice.adjustment.adjustmentSen !==
                                        0 || billing.invoice.refundCreditSen
                                "
                                class="mt-2 border-t pt-2 text-[13px]"
                            >
                                <div class="flex justify-between py-1">
                                    <dt>Original subtotal</dt>
                                    <dd class="tabular-nums">
                                        {{
                                            myr(
                                                billing.invoice.adjustment
                                                    .subtotalSen,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    v-if="
                                        billing.invoice.adjustment
                                            .adjustmentSen !== 0
                                    "
                                    class="flex justify-between py-1"
                                >
                                    <dt>
                                        {{
                                            billing.invoice.adjustment
                                                .adjustmentSen < 0
                                                ? 'Discount'
                                                : 'Surcharge'
                                        }}
                                    </dt>
                                    <dd class="tabular-nums">
                                        {{
                                            billing.invoice.adjustment
                                                .adjustmentSen < 0
                                                ? '−'
                                                : '+'
                                        }}{{
                                            myr(
                                                Math.abs(
                                                    billing.invoice.adjustment
                                                        .adjustmentSen,
                                                ),
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    v-if="billing.invoice.refundCreditSen"
                                    class="flex justify-between py-1"
                                    data-testid="billing-refund-credit"
                                >
                                    <dt>Refund credit</dt>
                                    <dd class="tabular-nums">
                                        −{{
                                            myr(billing.invoice.refundCreditSen)
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <div class="mt-3">
                                <InvoiceAdjustmentDialogV2 :billing="billing" />
                            </div>
                            <p
                                v-if="billing.invoice.correctionHold"
                                role="status"
                                class="mt-3"
                            >
                                <V2Chip
                                    tone="warning"
                                    label="Correction hold — review required."
                                />
                            </p>
                            <details
                                v-if="
                                    billing.can.pay || billing.can.panelPropose
                                "
                                class="mt-3 text-[13px]"
                            >
                                <summary
                                    class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1 rounded-[10px] border border-border px-3 font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9 [&::-webkit-details-marker]:hidden"
                                >
                                    How to settle this invoice
                                    <ChevronDown
                                        class="size-4 transition-transform [details[open]_&]:rotate-180"
                                        aria-hidden="true"
                                    />
                                    <span class="sr-only">(opens below)</span>
                                </summary>
                                <p
                                    class="mt-2 rounded-[10px] bg-muted p-3 text-muted-foreground"
                                    data-testid="billing-howto"
                                >
                                    <span class="font-semibold text-foreground"
                                        >How to settle this invoice.</span
                                    >
                                    Use Add → Record new payment for money
                                    received. Use Add → Propose Panel
                                    responsibility or Request pay later for
                                    approval, then collect any remaining patient
                                    balance.
                                </p>
                            </details></template
                        >
                        <p v-else class="text-[13px] text-muted-foreground">
                            Build a draft to see the financial summary.
                        </p>
                    </section>

                    <section
                        v-if="billing.invoice"
                        :class="card"
                        data-testid="billing-payments"
                    >
                        <header
                            class="flex items-center justify-between gap-2 border-b px-4 py-3"
                        >
                            <h2 class="font-semibold">Payments</h2>
                            <div class="flex gap-2">
                                <ActionLink
                                    :href="`${base}/payments`"
                                    variant="outline"
                                    >Manage</ActionLink
                                ><DropdownMenu
                                    v-if="
                                        billing.can.pay ||
                                        billing.can.panelPropose ||
                                        billing.can.deferPropose
                                    "
                                    ><DropdownMenuTrigger as-child
                                        ><Button
                                            type="button"
                                            variant="outline"
                                            class="max-lg:min-h-11"
                                            :disabled="busy"
                                            >Add</Button
                                        ></DropdownMenuTrigger
                                    ><DropdownMenuContent align="end"
                                        ><DropdownMenuItem
                                            v-if="billing.can.pay"
                                            class="max-lg:min-h-11"
                                            @select="openEntry('payment')"
                                            >Record new
                                            payment</DropdownMenuItem
                                        ><DropdownMenuItem
                                            v-if="billing.can.panelPropose"
                                            class="max-lg:min-h-11"
                                            @select="openEntry('panel')"
                                            >Propose Panel
                                            responsibility</DropdownMenuItem
                                        ><DropdownMenuItem
                                            v-if="billing.can.deferPropose"
                                            class="max-lg:min-h-11"
                                            @select="openEntry('deferment')"
                                            >Request pay later</DropdownMenuItem
                                        ></DropdownMenuContent
                                    ></DropdownMenu
                                >
                            </div>
                        </header>
                        <p
                            v-if="!billing.payments.length"
                            class="p-4 text-[13px] text-muted-foreground"
                        >
                            No payments recorded.
                        </p>
                        <ul v-else class="divide-y">
                            <li
                                v-for="receipt in billing.payments"
                                :key="receipt.publicId"
                                class="p-4"
                            >
                                <!-- Rail-safe: the method takes the free width and wraps by word; the amount never shrinks. -->
                                <div
                                    class="flex items-start justify-between gap-2"
                                    data-testid="rail-payment-row"
                                >
                                    <span
                                        class="min-w-0 font-semibold [overflow-wrap:break-word]"
                                        >{{ receipt.method }}</span
                                    ><span
                                        class="shrink-0 whitespace-nowrap tabular-nums"
                                        >{{ myr(receipt.amountSen) }}</span
                                    >
                                </div>
                                <p
                                    class="mt-1 text-[13px] text-muted-foreground"
                                >
                                    {{ receipt.number }} ·
                                    {{ receipt.receivedAt }}
                                </p>
                                <V2Chip
                                    class="mt-1"
                                    v-bind="
                                        chip(
                                            receipt.status,
                                            receiptTone(receipt.status),
                                        )
                                    "
                                />
                                <p
                                    v-if="receipt.refundedSen"
                                    class="mt-1 text-[13px] text-muted-foreground tabular-nums"
                                    data-testid="payment-refunded"
                                >
                                    Refunded
                                    {{ myr(receipt.refundedSen) }} ·
                                    {{
                                        myr(
                                            receipt.amountSen -
                                                receipt.refundedSen,
                                        )
                                    }}
                                    kept
                                </p>
                                <p
                                    v-if="receipt.recordedBy"
                                    class="mt-1 text-[13px] break-words text-muted-foreground"
                                >
                                    Recorded by {{ receipt.recordedBy }}
                                </p>
                            </li>
                        </ul>
                    </section>
                    <section
                        v-if="billing.billTo || billing.notes"
                        :class="[card, 'divide-y']"
                    >
                        <div v-if="billing.billTo" class="p-4">
                            <h2
                                class="mb-2 text-[13px] font-medium text-muted-foreground"
                            >
                                Invoice
                            </h2>
                            <InvoiceBillTo :context="billing.billTo" />
                        </div>
                        <div
                            v-if="billing.notes"
                            class="flex flex-wrap items-center justify-between gap-2 p-4"
                        >
                            <span
                                class="text-[13px] font-medium text-muted-foreground"
                                >Invoice notes</span
                            >
                            <InvoiceNotes :context="billing.notes" />
                        </div>
                    </section>
                </template>
            </V2WorkLayout>
        </PageFrame>

        <!-- The three entry dialogs on V2Dialog (Daniel, BV2-1 round 2): bottom sheet on a phone, tall with a pinned
             footer so the action never scrolls away. Same calls, payloads and disabled conditions as Classic. -->
        <div data-testid="billing-inline-forms">
            <V2Dialog
                :open="paymentOpen"
                size="tall"
                title="Record new payment"
                description="Patient receipts are separate from approved Panel responsibility and pay later."
                :confirm-label="
                    isCompleted
                        ? 'Settle Outstanding Balance'
                        : 'Record payment'
                "
                :busy="busy || !form.method || !billing.can.pay"
                :dirty="!!(form.amount || form.reference)"
                @update:open="updateDialog('payment', $event)"
                @discard="discardEntry('payment')"
                @confirm="payment"
            >
                <dl
                    v-if="billing.invoice"
                    class="grid grid-cols-2 gap-x-3 gap-y-1 rounded-[10px] border bg-muted p-3 text-[13px]"
                >
                    <dt>Invoice total</dt>
                    <dd class="text-right tabular-nums">
                        {{ myr(billing.invoice.state.total) }}
                    </dd>
                    <dt>Self-pay received</dt>
                    <dd class="text-right tabular-nums">
                        {{ myr(billing.invoice.state.self_pay) }}
                    </dd>
                    <dt>Outstanding</dt>
                    <dd class="text-right tabular-nums">
                        {{ myr(outstandingSen(billing.invoice.state)) }}
                    </dd>
                    <dt class="font-semibold">Due now</dt>
                    <dd class="text-right font-semibold tabular-nums">
                        {{ myr(billing.invoice.state.due_now) }}
                    </dd>
                </dl>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <template v-if="billing.can.pay">
                    <p class="text-[13px] text-muted-foreground">
                        {{
                            isCompleted
                                ? 'Outstanding receivable: this governed settlement is separate from the completed Visit record.'
                                : 'For money the patient pays now. Panel responsibility is recorded separately.'
                        }}
                    </p>
                    <V2MoneyInput
                        v-model="form.amount"
                        label="Amount received"
                        :hint="dueHint"
                        required
                    />
                    <Button
                        v-if="
                            dueNowText &&
                            (!isCompleted ||
                                (billing.invoice?.state.due_now ?? 0) > 0)
                        "
                        type="button"
                        variant="outline"
                        class="h-11 w-fit text-[13px] lg:h-9"
                        @click="form.amount = dueNowText"
                    >
                        Use amount due (RM {{ dueNowText }})
                    </Button>
                    <V2Picker
                        :model-value="form.method || null"
                        label="Payment method"
                        :options="methodPicks"
                        placeholder="Choose a payment method"
                        required
                        @update:model-value="form.method = $event ?? ''"
                    />
                    <label class="block text-[15px]"
                        >Transaction reference (no card details)<input
                            v-model="form.reference"
                            class="billing-input-v2"
                            maxlength="100"
                            autocomplete="off"
                    /></label>
                    <p
                        v-if="!form.method || !form.amount"
                        class="text-[13px] text-muted-foreground"
                    >
                        {{
                            isCompleted
                                ? 'Choose an amount and a payment method to settle the balance.'
                                : 'Choose an amount and a payment method to record the payment.'
                        }}
                    </p>
                </template>
            </V2Dialog>

            <V2Dialog
                :open="responsibilityOpen"
                size="tall"
                :title="
                    responsibilityKind === 'panel'
                        ? 'Panel responsibility'
                        : 'Pay later'
                "
                description="Pay by Panel or pay later. This request needs approval and is not a cash receipt."
                :confirm-label="
                    responsibilityKind === 'panel'
                        ? 'Propose Panel responsibility'
                        : 'Request pay later'
                "
                :busy="
                    busy ||
                    !(responsibilityKind === 'panel'
                        ? billing.can.panelPropose
                        : billing.can.deferPropose)
                "
                :dirty="!!(form.responsibility_amount || form.reason)"
                @update:open="updateDialog('responsibility', $event)"
                @discard="discardEntry('responsibility')"
                @confirm="propose(responsibilityKind)"
            >
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <details
                    v-if="
                        responsibilityKind === 'panel' &&
                        billing.can.panelPropose
                    "
                    class="text-[13px]"
                >
                    <summary
                        class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1 rounded-[10px] border border-border px-3 font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9 [&::-webkit-details-marker]:hidden"
                    >
                        Panel approval steps
                        <ChevronDown
                            class="size-4 transition-transform [details[open]_&]:rotate-180"
                            aria-hidden="true"
                        />
                    </summary>
                    <ol
                        class="my-2 list-decimal space-y-1 pl-5 text-muted-foreground"
                        data-testid="panel-steps"
                    >
                        <li>Enter the amount the Panel will cover.</li>
                        <li>
                            Add the reason or the Panel confirmation reference.
                        </li>
                        <li>
                            Choose the verified Panel and add the member
                            reference if the Panel needs one.
                        </li>
                        <li>Press Propose Panel responsibility.</li>
                        <li>
                            Someone with Panel approval rights presses Approve.
                            Due now then drops by the Panel amount.
                        </li>
                        <li>
                            Collect any remaining balance with Record new
                            payment, then Complete Visitation.
                        </li>
                    </ol>
                </details>
                <V2MoneyInput
                    v-model="form.responsibility_amount"
                    :label="
                        responsibilityKind === 'panel'
                            ? 'Amount the Panel will cover'
                            : 'Amount to pay later'
                    "
                    :hint="dueHint"
                    required
                />
                <Button
                    v-if="dueNowText && !isCompleted"
                    type="button"
                    variant="outline"
                    class="h-11 w-fit text-[13px] lg:h-9"
                    @click="form.responsibility_amount = dueNowText"
                >
                    Use amount due (RM {{ dueNowText }})
                </Button>
                <label class="block text-[15px]"
                    >Reason or verification evidence<textarea
                        v-model="form.reason"
                        maxlength="500"
                        class="billing-input-v2"
                    />
                </label>
                <template
                    v-if="
                        responsibilityKind === 'panel' &&
                        billing.can.panelPropose
                    "
                >
                    <V2Picker
                        :model-value="
                            form.panel_id ? String(form.panel_id) : null
                        "
                        label="Verified Panel"
                        :options="panelPicks"
                        placeholder="Choose a Panel"
                        @update:model-value="setPanel"
                    />
                    <label class="block text-[15px]"
                        >Member reference (if the Panel needs one)<input
                            v-model="form.member_reference"
                            class="billing-input-v2"
                            maxlength="100"
                    /></label>
                    <p class="text-[13px] text-muted-foreground">
                        This only sends the request for approval. The Panel
                        amount counts after it is approved.
                    </p>
                </template>
                <template
                    v-if="
                        responsibilityKind === 'deferment' &&
                        billing.can.deferPropose
                    "
                >
                    <p class="text-[13px] text-muted-foreground">
                        Pay later: the patient pays on the due date. It needs
                        approval and is not a cash receipt.
                    </p>
                    <label class="block text-[15px]"
                        >Pay later due date<DateField v-model="form.due_date"
                    /></label>
                </template>
            </V2Dialog>

            <V2Dialog
                :open="reversalOpen"
                title="Reverse recording error"
                description="Recording errors only. No refunds or post-completion accounting correction."
                confirm-label="Reverse recording error"
                tone="danger"
                :busy="
                    busy ||
                    !form.reason ||
                    !form.recording_error_only ||
                    !reversalReceipt
                "
                :dirty="!!form.reason"
                @update:open="updateDialog('reversal', $event)"
                @discard="discardEntry('reversal')"
                @confirm="reversalReceipt && reverse(reversalReceipt)"
            >
                <p v-if="reversalReceipt" class="tabular-nums">
                    {{ reversalReceipt.number }} ·
                    {{ myr(reversalReceipt.amountSen) }}
                </p>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <label class="block text-[15px]"
                    >Reason<textarea
                        v-model="form.reason"
                        maxlength="500"
                        class="billing-input-v2"
                    />
                </label>
                <label class="flex min-h-11 items-center gap-2 text-[15px]"
                    ><input
                        v-model="form.recording_error_only"
                        type="checkbox"
                        class="size-4"
                    />No real refund: recording error only</label
                >
                <p
                    v-if="!form.reason || !form.recording_error_only"
                    class="text-[13px] text-muted-foreground"
                >
                    Enter a reason and tick the box to reverse this receipt.
                </p>
            </V2Dialog>
        </div>
    </template>
</template>

<style scoped>
.billing-summary-row-v2 {
    display: flex;
    min-height: 44px;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid var(--border);
}
.billing-input-v2 {
    display: block;
    width: 100%;
    margin-block: 0.25rem 0.5rem;
    padding: 0.5rem 0.75rem;
    min-height: 44px;
    border: 1px solid var(--input);
    border-radius: 10px;
    background: var(--card);
    font: inherit;
}
.billing-input-v2:is(textarea) {
    min-height: 5rem;
}
.billing-input-v2:focus-visible {
    border-color: var(--primary);
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
@media (min-width: 1024px) {
    .billing-input-v2:not(textarea) {
        min-height: 36px;
    }
}
</style>

<style>
/* Version 2 wrapper over the Classic pieces on this page only (Daniel, BV2-1 exception until BV2-2). Not scoped: the
   dialogs open outside the page, so the rules key on the body marker this page sets while it is open.
   1. Every control is at least 44 px below 1024 px, in the page and in any dialog it opens (the close cross excepted).
   2. Exactly one filled button on the page: the main action. Filled buttons inside the shared Classic blocks (refunds,
      corrections, outstanding bills) read as outline here. */
/* In the base layer, so a taller size (a Reason box's scoped 5rem, a utility class) still wins over this minimum. */
@layer base {
    @media (max-width: 1023.98px) {
        body[data-billing-v2]
            :where(.billing-v2-page, [role='dialog'])
            :where(
                button:not([data-slot='dialog-close']),
                select,
                textarea,
                [role='combobox'],
                input:not([type='checkbox'], [type='radio'])
            ) {
            min-height: 44px;
        }
    }
}
/* While any dialog is open, the bottom bar must not show through the scrim (Daniel, BV2-1 round 2). */
body[data-billing-v2]:has([role='dialog']) [data-slot='v2-sticky-bar'] {
    visibility: hidden;
}
body[data-billing-v2]
    .billing-v2-page
    :is(
        [data-testid='receipt-refunds'],
        [data-testid='receipt-corrections'],
        [data-testid='outstanding-bills']
    )
    button[data-slot='button']:is(
        :not([data-variant]),
        [data-variant='default']
    ) {
    border: 1px solid var(--border);
    background: var(--card);
    color: var(--foreground);
}
body[data-billing-v2]
    .billing-v2-page
    :is(
        [data-testid='receipt-refunds'],
        [data-testid='receipt-corrections'],
        [data-testid='outstanding-bills']
    )
    button[data-slot='button']:is(
        :not([data-variant]),
        [data-variant='default']
    ):hover {
    background: var(--muted);
}
</style>

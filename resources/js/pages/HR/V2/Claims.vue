<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, reactive, toRef } from 'vue';
import ReceiptList from '@/components/hr/claims/ReceiptList.vue';
import { paidLine, statusTone } from '@/components/hr/claims/types';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { MonthField } from '@/components/ui/date-field';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useClaimSheet } from '@/composables/hr/useClaimSheet';
import { useClaimsPage } from '@/composables/hr/useClaimsPage';
import type { ClaimsPageProps } from '@/composables/hr/useClaimsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { myr } from '@/types/billing';

/**
 * Version 2 Staff claims (shown only in V2; Classic is `pages/HR/Claims.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useClaimsPage` and `useClaimSheet`, which
 * Classic uses too, so this page sends none of its own. KPOne pays nothing: Pay records that someone paid outside KPOne.
 * A receipt opens only through its existing audited link when pressed: V2 shows a file tile, never a thumbnail that
 * would load the file ahead of the person asking. Limits and warnings stay warnings.
 */
const props = defineProps<ClaimsPageProps>();
usePageCrumbs(() => [{ title: 'Staff claims' }]);

const {
    errors,
    tabs,
    selectTab,
    sheet,
    newClaim,
    resend,
    withdrawing,
    busy,
    askWithdraw,
    withdraw,
    decision,
    askDecision,
    decide,
    limitLine,
    payment,
    askPay,
    pay,
    payMissing,
    reversal,
    askReverse,
    reverse,
    month,
    showMonth,
    settingsForm,
    saveSettings,
    categoryForm,
    editCategory,
    saveCategory,
    unusedSuggestions,
    limitWords,
} = useClaimsPage(props);

// The New claim dialog: the same request and key as Classic's sheet.
const sheetProps = reactive({
    open: toRef(sheet, 'open'),
    categories: computed(() => props.claims.options.categories),
    branch: computed(() => props.claims.branch),
    today: computed(() => props.claims.today),
    maxReceipts: computed(() => props.claims.options.maxReceipts),
    from: toRef(sheet, 'from'),
});
const {
    errors: sheetErrors,
    form: claimForm,
    category,
    copied,
    room,
    receiptError,
    onFiles,
    removeFile,
    send: sendClaim,
} = useClaimSheet(sheetProps, () => (sheet.open = false));

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';

// The manager sentence belongs to the manager tabs only (Daniel).
const description = computed(() =>
    props.claims.tab === 'my'
        ? 'Claim back what you paid for the clinic. Someone else approves it, and HR or Finance records that it was paid. KPOne pays nothing.'
        : 'Claims staff sent, approved and paid. KPOne pays nothing: it only records that someone paid outside KPOne.',
);
const categoryOptions = computed(() =>
    props.claims.options.categories.map((c) => ({
        value: c.value,
        label: c.label,
    })),
);

const claimMissing = computed(() => {
    if (claimForm.categoryId === '') {
        return 'Choose a category to continue.';
    }

    if (claimForm.spentOn === '') {
        return 'Choose the date you paid to continue.';
    }

    if (claimForm.amount.trim() === '') {
        return 'Enter the amount to continue.';
    }

    return claimForm.purpose.trim() === ''
        ? 'Say what it was for to continue.'
        : '';
});
const decisionMissing = computed(() =>
    decision.kind === 'reject' && decision.reason === ''
        ? 'Pick a reason to continue.'
        : '',
);
const reverseMissing = computed(() =>
    reversal.reason === '' ? 'Pick a reason to continue.' : '',
);
const categoryMissing = computed(() =>
    categoryForm.name.trim() === '' ? 'Enter a name to continue.' : '',
);
const receiptsNote = computed(
    () =>
        `Up to ${props.claims.options.maxReceipts} receipts. JPG, PNG or PDF, 5 MB each.`,
);
</script>

<template>
    <Head title="Staff claims" />
    <PageFrame>
        <V2WorkLayout title="Staff claims" :description="description">
            <template v-if="claims.tab === 'my' && claims.setUp" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="claim-new"
                    @click="newClaim"
                >
                    New claim
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="claims.tab"
                        :tabs="tabs"
                        ariaLabel="Staff claim sections"
                        @update:model-value="selectTab"
                    />
                    <p
                        v-if="
                            errors.claims &&
                            !sheet.open &&
                            !withdrawing &&
                            !decision.row &&
                            !payment.row &&
                            !reversal.row &&
                            !categoryForm.open
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.claims }}
                    </p>

                    <!-- My claims -->
                    <section
                        v-if="claims.tab === 'my' && claims.my"
                        aria-labelledby="v2-my-claims-title"
                        class="flex flex-col gap-3"
                        data-test="my-claims"
                    >
                        <h2 id="v2-my-claims-title" class="sr-only">
                            My claims
                        </h2>
                        <div
                            v-if="!claims.setUp"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                Staff claims are not set up yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                HR adds the claim categories first. Ask HR if
                                you need to claim something.
                            </p>
                        </div>
                        <div
                            v-else-if="!claims.my.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No claims yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Press New claim to claim back what you paid for
                                the clinic.
                            </p>
                        </div>
                        <article
                            v-for="row in claims.my"
                            :key="row.id"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-claim-${row.id}`"
                            data-test="my-claim"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <h3
                                        :id="`v2-claim-${row.id}`"
                                        class="text-base font-semibold"
                                    >
                                        {{ row.category }} · {{ row.number }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        Paid by you on {{ row.spentOn }} ·
                                        {{ row.branch }}
                                    </p>
                                </div>
                                <div
                                    class="flex shrink-0 flex-col items-end gap-1"
                                >
                                    <span
                                        class="text-base font-semibold whitespace-nowrap tabular-nums"
                                        >{{ row.amount }}</span
                                    >
                                    <V2Chip
                                        :tone="statusTone(row.status)"
                                        :label="row.statusWords"
                                    />
                                </div>
                            </div>
                            <p v-if="row.purpose" class="text-sm break-words">
                                {{ row.purpose }}
                            </p>
                            <p
                                v-if="row.waitingFor"
                                class="text-sm text-muted-foreground"
                            >
                                {{ row.waitingFor }}.
                            </p>
                            <p
                                v-if="row.rejectReason"
                                class="text-sm"
                                data-test="claim-reject-reason"
                            >
                                Rejected: {{ row.rejectReason
                                }}<template v-if="row.rejectNote">
                                    · {{ row.rejectNote }}</template
                                ><template v-if="row.decidedBy">
                                    · by {{ row.decidedBy }}</template
                                >
                            </p>
                            <p
                                v-if="row.reversed"
                                class="text-sm text-muted-foreground"
                            >
                                Payment reversed on {{ row.reversed.on }}:
                                {{ row.reversed.reason }}. The claim is back to
                                Approved.
                            </p>
                            <p
                                v-if="row.paid"
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                {{ paidLine(row) }}
                            </p>
                            <ReceiptList :receipts="row.receipts" />
                            <div
                                v-if="row.canWithdraw || row.canResend"
                                class="flex flex-wrap gap-2"
                            >
                                <button
                                    v-if="row.canWithdraw"
                                    type="button"
                                    :class="dangerButton"
                                    @click="askWithdraw(row)"
                                >
                                    Withdraw
                                </button>
                                <button
                                    v-if="row.canResend"
                                    type="button"
                                    :class="button"
                                    data-test="claim-resend"
                                    @click="resend(row)"
                                >
                                    Send a new claim
                                </button>
                            </div>
                        </article>
                    </section>

                    <!-- Approvals -->
                    <section
                        v-if="claims.tab === 'approvals' && claims.approvals"
                        aria-labelledby="v2-approvals-title"
                        class="flex flex-col gap-3"
                        data-test="claim-approvals"
                    >
                        <h2
                            id="v2-approvals-title"
                            class="text-base font-semibold"
                        >
                            Waiting for approval
                        </h2>
                        <div
                            v-if="!claims.approvals.claims.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                Nothing waiting for approval.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Claims staff send appear here. Limits only warn;
                                you decide.
                            </p>
                        </div>
                        <article
                            v-for="row in claims.approvals.claims"
                            :key="row.id"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-approve-${row.id}`"
                            data-test="approval-card"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <h3
                                        :id="`v2-approve-${row.id}`"
                                        class="text-base font-semibold"
                                    >
                                        {{ row.person }} · {{ row.number }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ row.category }} · paid
                                        {{ row.spentOn }} · {{ row.branch }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 text-base font-semibold whitespace-nowrap tabular-nums"
                                    >{{ row.amount }}</span
                                >
                            </div>
                            <p v-if="row.purpose" class="text-sm break-words">
                                {{ row.purpose }}
                            </p>
                            <p
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                {{ limitLine(row) }}
                            </p>
                            <ul
                                v-if="row.warnings.length"
                                class="flex flex-wrap gap-2"
                                data-test="claim-warnings"
                            >
                                <li v-for="w in row.warnings" :key="w">
                                    <V2Chip tone="warning" :label="w" />
                                </li>
                            </ul>
                            <ReceiptList :receipts="row.receipts" />
                            <p
                                v-if="row.refusal"
                                class="text-sm text-muted-foreground"
                                data-test="claim-refusal"
                            >
                                {{ row.refusal }}
                            </p>
                            <div v-else class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="claim-approve"
                                    @click="askDecision(row, 'approve')"
                                >
                                    Approve
                                </button>
                                <button
                                    type="button"
                                    :class="dangerButton"
                                    data-test="claim-reject"
                                    @click="askDecision(row, 'reject')"
                                >
                                    Reject
                                </button>
                            </div>
                        </article>
                    </section>

                    <!-- Pay -->
                    <section
                        v-if="claims.tab === 'pay' && claims.pay"
                        aria-labelledby="v2-pay-title"
                        class="flex flex-col gap-3"
                        data-test="claim-pay"
                    >
                        <div>
                            <h2
                                id="v2-pay-title"
                                class="text-base font-semibold"
                            >
                                Approved claims
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                KPOne pays nothing. Record a claim as paid once
                                it was paid outside KPOne. Not linked to daily
                                cash closing.
                            </p>
                        </div>
                        <div
                            v-if="!claims.pay.claims.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                No approved claims to pay.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Approved claims appear here until they are
                                recorded as paid.
                            </p>
                        </div>
                        <article
                            v-for="row in claims.pay.claims"
                            :key="row.id"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            data-test="pay-card"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">
                                        {{ row.person }} · {{ row.number }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ row.category }} · {{ row.branch }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 font-semibold whitespace-nowrap tabular-nums"
                                    >{{ row.amount }}</span
                                >
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <V2Chip
                                    :tone="statusTone(row.status)"
                                    :label="row.statusWords"
                                />
                                <V2Chip
                                    v-if="row.reversed"
                                    tone="neutral"
                                    :label="`Payment reversed: ${row.reversed.reason}`"
                                />
                            </div>
                            <p
                                v-if="row.paid"
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                {{ paidLine(row) }}
                            </p>
                            <ReceiptList :receipts="row.receipts" />
                            <p
                                v-if="row.refusal"
                                class="text-sm text-muted-foreground"
                            >
                                {{ row.refusal }}
                            </p>
                            <div v-else-if="row.status === 'approved'">
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="claim-record-payment"
                                    @click="askPay(row)"
                                >
                                    Record payment
                                </button>
                            </div>
                            <div v-else-if="row.status === 'paid'">
                                <button
                                    type="button"
                                    :class="dangerButton"
                                    @click="askReverse(row)"
                                >
                                    Reverse payment
                                </button>
                            </div>
                        </article>
                    </section>

                    <!-- Monthly -->
                    <section
                        v-if="claims.tab === 'monthly' && claims.monthly"
                        aria-labelledby="v2-monthly-title"
                        class="flex flex-col gap-3"
                        data-test="claim-monthly"
                    >
                        <form
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4 sm:flex-row sm:items-end"
                            @submit.prevent="showMonth"
                        >
                            <div class="grid gap-1.5">
                                <label
                                    for="v2-claim-month"
                                    class="text-sm font-medium"
                                    >Month</label
                                >
                                <MonthField
                                    id="v2-claim-month"
                                    v-model="month"
                                />
                            </div>
                            <button type="submit" :class="primaryButton">
                                Show
                            </button>
                        </form>
                        <div>
                            <h2
                                id="v2-monthly-title"
                                class="text-base font-semibold"
                            >
                                Approved and paid claims ·
                                {{ claims.monthly.monthLabel }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                By the date paid by staff. On screen only: there
                                is no download.
                            </p>
                        </div>
                        <div
                            v-if="!claims.monthly.rows.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                No approved claims in
                                {{ claims.monthly.monthLabel }}.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Choose another month.
                            </p>
                        </div>
                        <div
                            v-else
                            class="overflow-x-auto rounded-[14px] border bg-card"
                        >
                            <table class="w-full min-w-[36rem] text-sm">
                                <caption class="sr-only">
                                    Approved and paid claims by branch and
                                    category
                                </caption>
                                <thead class="text-left text-muted-foreground">
                                    <tr class="border-b">
                                        <th
                                            scope="col"
                                            class="px-4 py-2 font-medium"
                                        >
                                            Branch
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-2 font-medium"
                                        >
                                            Category
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Claims
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Total
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Paid
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-4 py-2 text-right font-medium"
                                        >
                                            Not paid yet
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="tabular-nums">
                                    <tr
                                        v-for="r in claims.monthly.rows"
                                        :key="r.branch + r.category"
                                        class="border-b"
                                    >
                                        <td class="px-4 py-2">
                                            {{ r.branch }}
                                        </td>
                                        <td class="px-4 py-2">
                                            {{ r.category }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{ r.claims }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{ myr(r.totalSen) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{ myr(r.paidSen) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{ myr(r.unpaidSen) }}
                                        </td>
                                    </tr>
                                    <tr
                                        class="font-semibold"
                                        data-test="claim-monthly-total"
                                    >
                                        <th
                                            scope="row"
                                            colspan="2"
                                            class="px-4 py-2 text-left"
                                        >
                                            Total
                                        </th>
                                        <td class="px-4 py-2 text-right">
                                            {{ claims.monthly.total.claims }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{
                                                myr(
                                                    claims.monthly.total
                                                        .totalSen,
                                                )
                                            }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{
                                                myr(
                                                    claims.monthly.total
                                                        .paidSen,
                                                )
                                            }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{
                                                myr(
                                                    claims.monthly.total
                                                        .unpaidSen,
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Settings -->
                    <section
                        v-if="claims.tab === 'settings' && claims.settings"
                        aria-labelledby="v2-claim-settings-title"
                        class="flex flex-col gap-6"
                        data-test="claim-settings"
                    >
                        <h2 id="v2-claim-settings-title" class="sr-only">
                            Settings
                        </h2>
                        <form
                            class="flex flex-col gap-4 rounded-[14px] border bg-card p-4"
                            @submit.prevent="saveSettings"
                        >
                            <h3 class="text-sm font-semibold">
                                Approval and dates
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                KPOne ships no number. Nothing counts until you
                                save. Each save is a new version.
                            </p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <V2MoneyInput
                                    v-model="settingsForm.level"
                                    label="Director approves claims above"
                                    hint="Leave empty: a Director or HR Manager approves any amount."
                                    :error="errors.director_level"
                                />
                                <V2Field
                                    v-slot="{ id, describedBy }"
                                    label="How far back a claim may go (days)"
                                    :hint="`The form suggests ${claims.options.suggestedDaysBack}. Leave empty for no limit.`"
                                    :error="errors.days_back"
                                >
                                    <input
                                        :id="id"
                                        v-model="settingsForm.days"
                                        type="number"
                                        min="1"
                                        max="366"
                                        inputmode="numeric"
                                        :placeholder="
                                            String(
                                                claims.options
                                                    .suggestedDaysBack,
                                            )
                                        "
                                        :class="`${inputClass} w-40`"
                                        :aria-describedby="describedBy"
                                    />
                                </V2Field>
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    claims.settings.version
                                        ? `Version ${claims.settings.version} saved.`
                                        : 'Not saved yet.'
                                }}
                            </p>
                            <div>
                                <button
                                    type="submit"
                                    :class="primaryButton"
                                    :disabled="settingsForm.processing"
                                >
                                    {{
                                        settingsForm.processing
                                            ? 'Saving…'
                                            : 'Save settings'
                                    }}
                                </button>
                            </div>
                        </form>

                        <div class="flex flex-col gap-3">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h3 class="text-sm font-semibold">
                                    Categories
                                </h3>
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="category-add"
                                    @click="editCategory(null)"
                                >
                                    Add a category
                                </button>
                            </div>
                            <div
                                v-if="!claims.settings.categories.length"
                                class="rounded-[14px] border bg-card p-6 text-center"
                            >
                                <p class="font-medium">No categories yet.</p>
                                <p class="text-sm text-muted-foreground">
                                    KPOne ships none. Add the categories staff
                                    may claim; each can need a receipt and have
                                    limits that warn.
                                </p>
                            </div>
                            <div
                                v-if="unusedSuggestions.length"
                                class="flex flex-wrap items-center gap-2 text-sm"
                            >
                                <span class="text-muted-foreground"
                                    >Suggested:</span
                                >
                                <button
                                    v-for="s in unusedSuggestions"
                                    :key="s"
                                    type="button"
                                    :class="button"
                                    @click="editCategory(null, s)"
                                >
                                    {{ s }}
                                </button>
                            </div>
                            <article
                                v-for="c in claims.settings.categories"
                                :key="c.id"
                                class="flex flex-col gap-2 rounded-[14px] border bg-card p-4"
                                data-test="claim-category"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="flex flex-wrap items-center gap-2 font-semibold [overflow-wrap:anywhere]"
                                        >
                                            {{ c.name }}
                                            <V2Chip
                                                v-if="!c.isActive"
                                                tone="neutral"
                                                label="Not used"
                                            />
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                c.needsReceipt
                                                    ? 'Needs a receipt'
                                                    : 'Receipt optional'
                                            }}
                                            · {{ limitWords(c) }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        :class="button"
                                        :aria-label="`Change ${c.name}`"
                                        @click="editCategory(c)"
                                    >
                                        Change
                                    </button>
                                </div>
                                <details class="text-sm">
                                    <summary
                                        class="min-h-11 cursor-pointer content-center text-muted-foreground lg:min-h-0"
                                    >
                                        Versions ({{ c.versions.length }})
                                    </summary>
                                    <ul
                                        class="mt-2 flex flex-col gap-1 text-xs"
                                    >
                                        <li
                                            v-for="v in c.versions"
                                            :key="v.version"
                                        >
                                            Version {{ v.version }} ·
                                            {{ v.name }}
                                            ·
                                            {{
                                                v.needsReceipt
                                                    ? 'needs a receipt'
                                                    : 'receipt optional'
                                            }}
                                            · {{ limitWords(v)
                                            }}{{
                                                v.isActive ? '' : ' · not used'
                                            }}
                                            · {{ v.on }} by {{ v.by }}
                                        </li>
                                    </ul>
                                </details>
                            </article>
                        </div>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- New claim -->
        <V2Dialog
            :open="sheet.open"
            :title="
                sheet.from
                    ? `Send a new claim for ${sheet.from.number}`
                    : 'New claim'
            "
            :description="`Claim back what you paid for the clinic${claims.branch ? ` at ${claims.branch}` : ''}. Someone else approves it. KPOne pays nothing; HR or Finance pays it outside KPOne.`"
            size="tall"
            :confirm-label="
                claimForm.processing
                    ? claimForm.files.length
                        ? 'Uploading…'
                        : 'Sending…'
                    : 'Send claim'
            "
            :busy="claimForm.processing"
            :confirm-disabled="claimMissing !== ''"
            :confirm-disabled-reason="claimMissing"
            data-test="claim-sheet"
            @update:open="(open: boolean) => (sheet.open = open)"
            @confirm="sendClaim"
        >
            <p
                v-if="sheetErrors.claims"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ sheetErrors.claims }}
            </p>
            <V2OptionGroup
                :model-value="claimForm.categoryId || null"
                :options="categoryOptions"
                label="Category"
                required
                :error="sheetErrors.category_id"
                @update:model-value="claimForm.categoryId = $event ?? ''"
            />
            <p
                v-if="category"
                class="text-xs text-muted-foreground"
                data-test="claim-category-rule"
            >
                {{
                    category.needsReceipt
                        ? 'Needs a receipt.'
                        : 'A receipt is optional.'
                }}<template v-if="category.perClaimLimitSen">
                    Limit {{ myr(category.perClaimLimitSen) }} a
                    claim.</template
                ><template v-if="category.monthlyLimitSen">
                    {{ myr(category.monthlyLimitSen) }} a month.</template
                >
            </p>
            <V2DateField
                v-model="claimForm.spentOn"
                label="Date you paid"
                required
                :max="claims.today"
                :error="sheetErrors.spent_on"
            />
            <V2MoneyInput
                v-model="claimForm.amount"
                label="Amount"
                required
                hint="In RM. For example 45.60"
                :error="sheetErrors.amount"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="What it was for"
                required
                hint="Up to 200 characters. Do not write medical details here."
                :error="sheetErrors.purpose"
            >
                <textarea
                    :id="id"
                    v-model="claimForm.purpose"
                    maxlength="200"
                    rows="3"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
            <div class="flex flex-col gap-1.5">
                <p class="text-sm font-medium">
                    Receipts{{ category?.needsReceipt ? ' (required)' : '' }}
                </p>
                <p
                    v-if="sheet.from && copied"
                    class="text-sm"
                    data-test="claim-copied"
                >
                    The
                    {{ copied === 1 ? 'receipt' : `${copied} receipts` }} of
                    {{ sheet.from.number }}
                    {{ copied === 1 ? 'is' : 'are' }} copied in.
                </p>
                <label
                    :class="[
                        button,
                        'self-start',
                        claimForm.files.length >= room &&
                            'pointer-events-none opacity-50',
                    ]"
                >
                    Choose receipts
                    <input
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,application/pdf"
                        class="sr-only"
                        :disabled="claimForm.files.length >= room"
                        :aria-invalid="!!receiptError"
                        aria-describedby="v2-claim-receipts-help"
                        @change="onFiles"
                    />
                </label>
                <p
                    id="v2-claim-receipts-help"
                    class="text-xs text-muted-foreground"
                >
                    {{ receiptsNote }}
                </p>
                <ul
                    v-if="claimForm.files.length"
                    class="flex flex-col gap-1 text-sm"
                >
                    <li
                        v-for="(f, i) in claimForm.files"
                        :key="`${f.name}-${i}`"
                        class="flex items-center justify-between gap-2"
                    >
                        <span class="min-w-0 truncate"
                            >Receipt {{ copied + i + 1 }} · {{ f.name }}</span
                        >
                        <button
                            type="button"
                            :class="dangerButton"
                            :aria-label="`Remove receipt ${copied + i + 1}`"
                            @click="removeFile(i)"
                        >
                            Remove
                        </button>
                    </li>
                </ul>
                <p
                    v-if="receiptError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ receiptError }}
                </p>
            </div>
        </V2Dialog>

        <!-- Withdraw -->
        <V2Dialog
            :open="withdrawing !== null"
            title="Withdraw this claim?"
            :description="
                withdrawing
                    ? `${withdrawing.number} · ${withdrawing.amount}. It is kept as withdrawn and nobody approves it.`
                    : ''
            "
            confirm-label="Withdraw"
            tone="danger"
            :busy="busy"
            @update:open="(open: boolean) => !open && (withdrawing = null)"
            @confirm="withdraw"
        >
            <p
                v-if="errors.claims"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.claims }}
            </p>
        </V2Dialog>

        <!-- Approve or reject -->
        <V2Dialog
            :open="decision.row !== null"
            :title="
                decision.kind === 'approve'
                    ? 'Approve this claim?'
                    : 'Reject this claim?'
            "
            :description="
                decision.row
                    ? decision.kind === 'approve'
                        ? `${decision.row.person} · ${decision.row.number} · ${decision.row.amount}. It is decided as sent; it then waits for payment.`
                        : `${decision.row.person} · ${decision.row.number} · ${decision.row.amount}. The rejection is kept with its reason.`
                    : ''
            "
            :confirm-label="decision.kind === 'approve' ? 'Approve' : 'Reject'"
            :tone="decision.kind === 'approve' ? 'default' : 'danger'"
            :busy="busy"
            :confirm-disabled="decisionMissing !== ''"
            :confirm-disabled-reason="decisionMissing"
            @update:open="(open: boolean) => !open && (decision.row = null)"
            @confirm="decide"
        >
            <p
                v-if="errors.claims"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.claims }}
            </p>
            <template v-if="decision.kind === 'reject'">
                <V2OptionGroup
                    :model-value="decision.reason || null"
                    :options="claims.options.rejectReasons"
                    label="Why it is rejected"
                    required
                    :error="errors.reason"
                    @update:model-value="decision.reason = $event ?? ''"
                />
                <V2Field
                    v-slot="{ id, describedBy }"
                    :label="
                        decision.reason === 'other' ? 'Note' : 'Note (optional)'
                    "
                    :required="decision.reason === 'other'"
                    :hint="
                        decision.reason === 'wrong_amount'
                            ? 'The person can send a new claim with the receipts copied in.'
                            : undefined
                    "
                    :error="errors.note"
                >
                    <textarea
                        :id="id"
                        v-model="decision.note"
                        maxlength="200"
                        rows="2"
                        :class="textareaClass"
                        :aria-describedby="describedBy"
                    ></textarea>
                </V2Field>
            </template>
        </V2Dialog>

        <!-- Record payment -->
        <V2Dialog
            :open="payment.row !== null"
            title="Record payment"
            :description="
                payment.row
                    ? `${payment.row.person} · ${payment.row.number} · ${payment.row.amount}. KPOne pays nothing; this records that it was paid outside KPOne.`
                    : ''
            "
            confirm-label="Record payment"
            :busy="busy"
            :confirm-disabled="payMissing !== ''"
            :confirm-disabled-reason="payMissing"
            @update:open="(open: boolean) => !open && (payment.row = null)"
            @confirm="pay"
        >
            <p
                v-if="errors.claims"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.claims }}
            </p>
            <div class="grid gap-1.5">
                <label
                    id="v2-claim-pay-method-label"
                    class="text-sm font-medium"
                    >How it was paid</label
                >
                <OperationalSelect
                    v-model="payment.method"
                    placeholder="Choose how it was paid"
                    labelledby="v2-claim-pay-method-label"
                    label="How it was paid"
                    trigger-class="max-lg:h-11"
                    :options="claims.options.methods"
                    :invalid="!!errors.method"
                />
                <p
                    v-if="errors.method"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.method }}
                </p>
            </div>
            <V2DateField
                v-model="payment.paidOn"
                label="Paid on"
                required
                :max="claims.today"
                :error="errors.paid_on"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="Reference"
                :required="payment.method !== '' && payment.method !== 'cash'"
                hint="Kept encrypted and shown as its last four."
                :error="errors.reference"
            >
                <input
                    :id="id"
                    v-model="payment.reference"
                    maxlength="60"
                    autocomplete="off"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
        </V2Dialog>

        <!-- Reverse a payment -->
        <V2Dialog
            :open="reversal.row !== null"
            title="Reverse this payment?"
            :description="
                reversal.row
                    ? `${reversal.row.number} · ${reversal.row.amount}. The claim goes back to Approved, and both the payment and its reversal are kept.`
                    : ''
            "
            confirm-label="Reverse payment"
            tone="danger"
            :busy="busy"
            :confirm-disabled="reverseMissing !== ''"
            :confirm-disabled-reason="reverseMissing"
            @update:open="(open: boolean) => !open && (reversal.row = null)"
            @confirm="reverse"
        >
            <p
                v-if="errors.claims"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.claims }}
            </p>
            <V2OptionGroup
                :model-value="reversal.reason || null"
                :options="claims.options.reversalReasons"
                label="Why the payment is reversed"
                required
                :error="errors.reason"
                @update:model-value="reversal.reason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Add or change a category -->
        <V2Dialog
            :open="categoryForm.open"
            :title="
                categoryForm.category
                    ? `Change ${categoryForm.category.name}`
                    : 'Add a category'
            "
            description="Saves a new version; earlier versions stay listed. Limits only warn the approver."
            :confirm-label="
                categoryForm.processing
                    ? 'Saving…'
                    : categoryForm.category
                      ? 'Save new version'
                      : 'Save category'
            "
            :busy="categoryForm.processing"
            :confirm-disabled="categoryMissing !== ''"
            :confirm-disabled-reason="categoryMissing"
            data-test="category-sheet"
            @update:open="(open: boolean) => (categoryForm.open = open)"
            @confirm="saveCategory"
        >
            <p
                v-if="errors.claims"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.claims }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Name"
                required
                :error="errors.name"
            >
                <input
                    :id="id"
                    v-model="categoryForm.name"
                    maxlength="60"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <label class="flex min-h-11 items-center gap-3 text-sm">
                <input
                    v-model="categoryForm.needsReceipt"
                    type="checkbox"
                    class="size-5"
                />
                Needs a receipt
            </label>
            <V2MoneyInput
                v-model="categoryForm.perClaim"
                label="Limit a claim (optional)"
                :error="errors.per_claim_limit"
            />
            <V2MoneyInput
                v-model="categoryForm.monthly"
                label="Limit a month for each person (optional)"
                :error="errors.monthly_limit"
            />
            <label
                v-if="categoryForm.category"
                class="flex min-h-11 items-center gap-3 text-sm"
            >
                <input
                    v-model="categoryForm.isActive"
                    type="checkbox"
                    class="size-5"
                />
                In use (staff can choose it)
            </label>
        </V2Dialog>
    </PageFrame>
</template>

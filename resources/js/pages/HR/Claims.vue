<script setup lang="ts">
// HR-6 /hr/claims: My claims (everyone), Approvals and Settings (Director, HR Manager), Pay (Finance Officer, Director)
// and Monthly (both). HR-PLAN 13.2 and 13.6, with Daniel's early look folded in. Staff claims are not Panel Claims,
// and KPOne pays nothing.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ClaimSheet from '@/components/hr/claims/ClaimSheet.vue';
import ReceiptList from '@/components/hr/claims/ReceiptList.vue';
import type {
    ApprovalRow,
    CategoryOption,
    ClaimRow,
    Option,
    PayRow,
} from '@/components/hr/claims/types';
import { paidLine, statusTone } from '@/components/hr/claims/types';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { myr } from '@/types/billing';

type Tab = 'my' | 'approvals' | 'pay' | 'monthly' | 'settings';
type Category = {
    id: string;
    name: string;
    needsReceipt: boolean;
    perClaimLimitSen: number | null;
    monthlyLimitSen: number | null;
    isActive: boolean;
    version: number;
    lockVersion: number;
    versions: {
        version: number;
        name: string;
        needsReceipt: boolean;
        perClaimLimitSen: number | null;
        monthlyLimitSen: number | null;
        isActive: boolean;
        by: string;
        on: string;
    }[];
};
type MonthlyRow = {
    branch: string;
    category: string;
    claims: number;
    totalSen: number;
    paidSen: number;
    unpaidSen: number;
};

const props = defineProps<{
    claims: {
        tab: Tab;
        tabs: Tab[];
        branch: string | null;
        today: string;
        setUp: boolean;
        options: {
            categories: CategoryOption[];
            rejectReasons: Option[];
            reversalReasons: Option[];
            methods: Option[];
            suggestedCategories: string[];
            suggestedDaysBack: number;
            daysBack: number | null;
            maxReceipts: number;
        };
        my: ClaimRow[] | null;
        approvals: { claims: ApprovalRow[] } | null;
        pay: { claims: PayRow[] } | null;
        monthly: {
            month: string;
            monthLabel: string;
            rows: MonthlyRow[];
            total: Omit<MonthlyRow, 'branch' | 'category'>;
        } | null;
        settings: {
            version: number;
            directorLevelSen: number | null;
            daysBack: number | null;
            categories: Category[];
        } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const tabLabels: Record<Tab, string> = {
    my: 'My claims',
    approvals: 'Approvals',
    pay: 'Pay',
    monthly: 'Monthly',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.claims.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/claims', { tab: value }, { preserveScroll: true });
const rm = (sen: number | null) => (sen === null ? '' : (sen / 100).toFixed(2));

// ---- My claims ----
const sheet = reactive({ open: false, from: null as ClaimRow | null });
const newClaim = () => Object.assign(sheet, { open: true, from: null });
const resend = (row: ClaimRow) =>
    Object.assign(sheet, { open: true, from: row });
const withdrawing = ref<ClaimRow | null>(null);
const withdrawKey = ref('');
const busy = ref(false);
const askWithdraw = (row: ClaimRow) => {
    withdrawing.value = row;
    withdrawKey.value = crypto.randomUUID();
};
const withdraw = () => {
    const row = withdrawing.value;

    if (!row) {
        return;
    }

    busy.value = true;
    router.post(
        `/hr/claims/${row.id}/withdraw`,
        {
            expected_lock_version: row.lockVersion,
            idempotency_key: withdrawKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (withdrawing.value = null),
            onFinish: () => (busy.value = false),
        },
    );
};

// ---- Approvals ----
const decision = reactive({
    row: null as ApprovalRow | null,
    kind: 'approve' as 'approve' | 'reject',
    reason: '',
    note: '',
    key: '',
});
const askDecision = (row: ApprovalRow, kind: 'approve' | 'reject') =>
    Object.assign(decision, {
        row,
        kind,
        reason: '',
        note: '',
        key: crypto.randomUUID(),
    });
const decide = () => {
    const row = decision.row;

    if (!row) {
        return;
    }

    busy.value = true;
    router.post(
        `/hr/claims/${row.id}/${decision.kind}`,
        {
            reason: decision.kind === 'reject' ? decision.reason : null,
            note: decision.kind === 'reject' ? decision.note : null,
            expected_lock_version: row.lockVersion,
            idempotency_key: decision.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (decision.row = null),
            onFinish: () => (busy.value = false),
        },
    );
};
const limitLine = (row: ApprovalRow) =>
    [
        row.perClaimLimitSen
            ? `Limit ${myr(row.perClaimLimitSen)} a claim`
            : '',
        row.monthlyLimitSen ? `${myr(row.monthlyLimitSen)} a month` : '',
        `${myr(row.monthTotalSen)} in ${row.category} this month`,
    ]
        .filter((p) => p)
        .join(' · ');

// ---- Pay ----
const payment = reactive({
    row: null as PayRow | null,
    method: 'bank_transfer',
    paidOn: '',
    reference: '',
    key: '',
});
const askPay = (row: PayRow) =>
    Object.assign(payment, {
        row,
        method: 'bank_transfer',
        paidOn: props.claims.today,
        reference: '',
        key: crypto.randomUUID(),
    });
const pay = () => {
    const row = payment.row;

    if (!row) {
        return;
    }

    busy.value = true;
    router.post(
        `/hr/claims/${row.id}/pay`,
        {
            method: payment.method,
            paid_on: payment.paidOn,
            reference: payment.reference,
            expected_lock_version: row.lockVersion,
            idempotency_key: payment.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (payment.row = null),
            onFinish: () => (busy.value = false),
        },
    );
};
const reversal = reactive({ row: null as PayRow | null, reason: '', key: '' });
const askReverse = (row: PayRow) =>
    Object.assign(reversal, { row, reason: '', key: crypto.randomUUID() });
const reverse = () => {
    const row = reversal.row;

    if (!row) {
        return;
    }

    busy.value = true;
    router.post(
        `/hr/claims/${row.id}/reverse`,
        {
            reason: reversal.reason,
            expected_lock_version: row.lockVersion,
            idempotency_key: reversal.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (reversal.row = null),
            onFinish: () => (busy.value = false),
        },
    );
};

// ---- Monthly ----
const month = ref(props.claims.monthly?.month ?? '');
watch(
    () => props.claims.monthly?.month,
    (m) => (month.value = m ?? ''),
);
const showMonth = () =>
    router.get(
        '/hr/claims',
        { tab: 'monthly', month: month.value },
        { preserveScroll: true },
    );

// ---- Settings ----
const settingsForm = reactive({
    level: '',
    days: '',
    key: '',
    processing: false,
});
watch(
    () => props.claims.settings,
    (s) =>
        Object.assign(settingsForm, {
            level: rm(s?.directorLevelSen ?? null),
            days: s?.daysBack ? String(s.daysBack) : '',
            key: crypto.randomUUID(),
        }),
    { immediate: true },
);
const saveSettings = () => {
    settingsForm.processing = true;
    router.post(
        '/hr/claims/settings',
        {
            director_level: settingsForm.level,
            days_back: settingsForm.days,
            expected_version: props.claims.settings?.version ?? 0,
            idempotency_key: settingsForm.key,
        },
        {
            preserveScroll: true,
            onFinish: () => (settingsForm.processing = false),
        },
    );
};
const categoryForm = reactive({
    open: false,
    category: null as Category | null,
    name: '',
    needsReceipt: true,
    perClaim: '',
    monthly: '',
    isActive: true,
    key: '',
    processing: false,
});
const editCategory = (category: Category | null, name = '') =>
    Object.assign(categoryForm, {
        open: true,
        category,
        name: category?.name ?? name,
        needsReceipt: category?.needsReceipt ?? true,
        perClaim: rm(category?.perClaimLimitSen ?? null),
        monthly: rm(category?.monthlyLimitSen ?? null),
        isActive: category?.isActive ?? true,
        key: crypto.randomUUID(),
    });
const saveCategory = () => {
    categoryForm.processing = true;
    router.post(
        '/hr/claims/categories',
        {
            category_id: categoryForm.category?.id ?? null,
            name: categoryForm.name,
            needs_receipt: categoryForm.needsReceipt,
            per_claim_limit: categoryForm.perClaim,
            monthly_limit: categoryForm.monthly,
            is_active: categoryForm.isActive,
            ...(categoryForm.category
                ? { expected_lock_version: categoryForm.category.lockVersion }
                : {}),
            idempotency_key: categoryForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (categoryForm.open = false),
            onFinish: () => (categoryForm.processing = false),
        },
    );
};
const unusedSuggestions = computed(() =>
    props.claims.options.suggestedCategories.filter(
        (s) =>
            !(props.claims.settings?.categories ?? []).some(
                (c) => c.name.toLowerCase() === s.toLowerCase(),
            ),
    ),
);
const limitWords = (c: {
    perClaimLimitSen: number | null;
    monthlyLimitSen: number | null;
}) =>
    [
        c.perClaimLimitSen
            ? `${myr(c.perClaimLimitSen)} a claim`
            : 'No limit a claim',
        c.monthlyLimitSen
            ? `${myr(c.monthlyLimitSen)} a month`
            : 'no monthly limit',
    ].join(' · ');
const dialogOpen = computed(
    () =>
        sheet.open ||
        !!withdrawing.value ||
        !!decision.row ||
        !!payment.row ||
        !!reversal.row ||
        categoryForm.open,
);
</script>

<template>
    <Head title="Staff claims" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Staff claims"
                description="Claim a refund of what you paid for the clinic. Someone else approves it, and HR or Finance records that it was paid. KPOne pays nothing."
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="claims.tab"
            :tabs="tabs"
            label="Staff claim sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="errors.claims && !dialogOpen"
            :message="errors.claims"
        />

        <!-- My claims -->
        <section
            v-if="claims.tab === 'my' && claims.my"
            aria-labelledby="my-claims-title"
            class="flex flex-col gap-3"
            data-test="my-claims"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="my-claims-title" class="text-base font-semibold">
                    My claims
                </h2>
                <Button
                    v-if="claims.setUp"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="claim-new"
                    @click="newClaim"
                    >New claim</Button
                >
            </div>
            <EmptyState
                v-if="!claims.setUp"
                title="Staff claims are not set up yet."
                description="HR adds the claim categories first. Ask HR if you need to claim something."
            />
            <EmptyState
                v-else-if="!claims.my.length"
                title="No claims yet."
                description="Press New claim to claim back what you paid for the clinic."
            />
            <article
                v-for="row in claims.my"
                :key="row.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`claim-${row.id}`"
                data-test="my-claim"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`claim-${row.id}`"
                            class="text-base font-semibold"
                        >
                            {{ row.category }} · {{ row.number }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Paid by you on {{ row.spentOn }} · {{ row.branch }}
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span class="text-base font-semibold tabular-nums">{{
                            row.amount
                        }}</span>
                        <StatusBadge
                            :status="row.status"
                            :label="row.statusWords"
                            :tone="statusTone(row.status)"
                        />
                    </div>
                </div>
                <p v-if="row.purpose" class="text-sm break-words">
                    {{ row.purpose }}
                </p>
                <p v-if="row.waitingFor" class="text-sm text-muted-foreground">
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
                <p v-if="row.reversed" class="text-sm text-muted-foreground">
                    Payment reversed on {{ row.reversed.on }}:
                    {{ row.reversed.reason }}. The claim is back to Approved.
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
                    <Button
                        v-if="row.canWithdraw"
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="askWithdraw(row)"
                        >Withdraw</Button
                    >
                    <Button
                        v-if="row.canResend"
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="claim-resend"
                        @click="resend(row)"
                        >Send a new claim</Button
                    >
                </div>
            </article>
        </section>

        <!-- Approvals -->
        <section
            v-if="claims.tab === 'approvals' && claims.approvals"
            aria-labelledby="approvals-title"
            class="flex flex-col gap-3"
            data-test="claim-approvals"
        >
            <h2 id="approvals-title" class="text-base font-semibold">
                Waiting for approval
            </h2>
            <EmptyState
                v-if="!claims.approvals.claims.length"
                title="Nothing waiting for approval."
                description="Claims staff send appear here. Limits only warn; you decide."
            />
            <article
                v-for="row in claims.approvals.claims"
                :key="row.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`approve-${row.id}`"
                data-test="approval-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`approve-${row.id}`"
                            class="text-base font-semibold"
                        >
                            {{ row.person }} · {{ row.number }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ row.category }} · paid {{ row.spentOn }} ·
                            {{ row.branch }}
                        </p>
                    </div>
                    <span class="text-base font-semibold tabular-nums">{{
                        row.amount
                    }}</span>
                </div>
                <p v-if="row.purpose" class="text-sm break-words">
                    {{ row.purpose }}
                </p>
                <p class="text-sm text-muted-foreground tabular-nums">
                    {{ limitLine(row) }}
                </p>
                <ul
                    v-if="row.warnings.length"
                    class="flex flex-wrap gap-2"
                    data-test="claim-warnings"
                >
                    <li v-for="w in row.warnings" :key="w">
                        <StatusBadge
                            status="warning"
                            :label="w"
                            tone="warning"
                        />
                    </li>
                </ul>
                <ReceiptList :receipts="row.receipts" thumbnails />
                <p
                    v-if="row.refusal"
                    class="text-sm text-muted-foreground"
                    data-test="claim-refusal"
                >
                    {{ row.refusal }}
                </p>
                <div v-else class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="claim-approve"
                        @click="askDecision(row, 'approve')"
                        >Approve</Button
                    >
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="claim-reject"
                        @click="askDecision(row, 'reject')"
                        >Reject</Button
                    >
                </div>
            </article>
        </section>

        <!-- Pay -->
        <section
            v-if="claims.tab === 'pay' && claims.pay"
            aria-labelledby="pay-title"
            class="flex flex-col gap-3"
            data-test="claim-pay"
        >
            <div>
                <h2 id="pay-title" class="text-base font-semibold">
                    Approved claims
                </h2>
                <p class="text-sm text-muted-foreground">
                    KPOne pays nothing. Record a claim as paid once it was paid
                    outside KPOne. Not linked to daily cash closing.
                </p>
            </div>
            <EmptyState
                v-if="!claims.pay.claims.length"
                title="No approved claims to pay."
                description="Approved claims appear here until they are recorded as paid."
            />
            <div
                v-else
                class="overflow-x-auto rounded-xl border bg-card max-lg:hidden"
            >
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th scope="col" class="px-4 py-2 font-medium">
                                Claim
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Person
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Category
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Amount
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Receipts
                            </th>
                            <th scope="col" class="px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in claims.pay.claims"
                            :key="row.id"
                            class="border-b align-top last:border-0"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ row.number }}
                            </td>
                            <td class="px-4 py-3">
                                {{ row.person
                                }}<span class="block text-muted-foreground">{{
                                    row.branch
                                }}</span>
                            </td>
                            <td class="px-4 py-3">{{ row.category }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ row.amount }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge
                                    :status="row.status"
                                    :label="row.statusWords"
                                    :tone="statusTone(row.status)"
                                />
                                <span
                                    v-if="row.paid"
                                    class="mt-1 block text-muted-foreground tabular-nums"
                                    >{{ paidLine(row) }}</span
                                >
                                <span v-if="row.reversed" class="mt-1 block">
                                    <StatusBadge
                                        status="reversed"
                                        :label="`Payment reversed: ${row.reversed.reason}`"
                                        tone="neutral"
                                    />
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <ReceiptList :receipts="row.receipts" />
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span
                                    v-if="row.refusal"
                                    class="text-muted-foreground"
                                    >{{ row.refusal }}</span
                                >
                                <Button
                                    v-else-if="row.status === 'approved'"
                                    variant="outline"
                                    size="sm"
                                    data-test="claim-record-payment"
                                    @click="askPay(row)"
                                    >Record payment</Button
                                >
                                <Button
                                    v-else-if="row.status === 'paid'"
                                    variant="outline"
                                    size="sm"
                                    @click="askReverse(row)"
                                    >Reverse payment</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <article
                v-for="row in claims.pay.claims"
                :key="`card-${row.id}`"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4 lg:hidden"
                data-test="pay-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold">
                            {{ row.person }} · {{ row.number }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.category }} · {{ row.branch }}
                        </p>
                    </div>
                    <span class="font-semibold tabular-nums">{{
                        row.amount
                    }}</span>
                </div>
                <div>
                    <StatusBadge
                        :status="row.status"
                        :label="row.statusWords"
                        :tone="statusTone(row.status)"
                    />
                </div>
                <p
                    v-if="row.paid"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    {{ paidLine(row) }}
                </p>
                <div v-if="row.reversed">
                    <StatusBadge
                        status="reversed"
                        :label="`Payment reversed: ${row.reversed.reason}`"
                        tone="neutral"
                    />
                </div>
                <ReceiptList :receipts="row.receipts" />
                <p v-if="row.refusal" class="text-sm text-muted-foreground">
                    {{ row.refusal }}
                </p>
                <Button
                    v-else-if="row.status === 'approved'"
                    variant="outline"
                    class="min-h-11 self-start"
                    @click="askPay(row)"
                    >Record payment</Button
                >
                <Button
                    v-else-if="row.status === 'paid'"
                    variant="outline"
                    class="min-h-11 self-start"
                    @click="askReverse(row)"
                    >Reverse payment</Button
                >
            </article>
        </section>

        <!-- Monthly -->
        <section
            v-if="claims.tab === 'monthly' && claims.monthly"
            aria-labelledby="monthly-title"
            class="flex flex-col gap-3"
            data-test="claim-monthly"
        >
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="showMonth"
            >
                <div class="grid gap-1.5">
                    <Label for="claim-month">Month</Label>
                    <Input
                        id="claim-month"
                        v-model="month"
                        type="month"
                        class="w-44 max-lg:h-11"
                    />
                </div>
                <Button type="submit" variant="outline" class="max-lg:min-h-11"
                    >Show</Button
                >
            </form>
            <h2 id="monthly-title" class="text-base font-semibold">
                Approved and paid claims · {{ claims.monthly.monthLabel }}
            </h2>
            <p class="text-sm text-muted-foreground">
                By the date paid by staff. On screen only: there is no download.
            </p>
            <EmptyState
                v-if="!claims.monthly.rows.length"
                :title="`No approved claims in ${claims.monthly.monthLabel}.`"
                description="Choose another month."
            />
            <div v-else class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th scope="col" class="px-4 py-2 font-medium">
                                Branch
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
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
                            <td class="px-4 py-2">{{ r.branch }}</td>
                            <td class="px-4 py-2">{{ r.category }}</td>
                            <td class="px-4 py-2 text-right">{{ r.claims }}</td>
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
                                {{ myr(claims.monthly.total.totalSen) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ myr(claims.monthly.total.paidSen) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ myr(claims.monthly.total.unpaidSen) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Settings -->
        <section
            v-if="claims.tab === 'settings' && claims.settings"
            aria-labelledby="claim-settings-title"
            class="flex flex-col gap-4"
            data-test="claim-settings"
        >
            <h2 id="claim-settings-title" class="sr-only">Settings</h2>
            <form
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                @submit.prevent="saveSettings"
            >
                <h3 class="text-sm font-semibold">Approval and dates</h3>
                <p class="text-sm text-muted-foreground">
                    KPOne ships no number. Nothing counts until you save. Each
                    save is a new version.
                </p>
                <div class="flex flex-wrap gap-4">
                    <div class="grid gap-1.5">
                        <Label for="claim-director-level"
                            >Director approves claims above</Label
                        >
                        <div
                            class="flex w-44 items-center rounded-md border focus-within:ring-2 focus-within:ring-ring"
                        >
                            <span
                                class="pl-3 text-sm text-muted-foreground"
                                aria-hidden="true"
                                >RM</span
                            >
                            <Input
                                id="claim-director-level"
                                v-model="settingsForm.level"
                                inputmode="decimal"
                                class="border-0 text-right tabular-nums shadow-none focus-visible:ring-0 max-lg:h-11"
                                aria-describedby="claim-director-level-help"
                            />
                        </div>
                        <p
                            id="claim-director-level-help"
                            class="text-xs text-muted-foreground"
                        >
                            Leave empty: a Director or HR Manager approves any
                            amount.
                        </p>
                        <InputError :message="errors.director_level" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="claim-days-back"
                            >How far back a claim may go (days)</Label
                        >
                        <Input
                            id="claim-days-back"
                            v-model="settingsForm.days"
                            type="number"
                            min="1"
                            max="366"
                            inputmode="numeric"
                            :placeholder="
                                String(claims.options.suggestedDaysBack)
                            "
                            class="w-32 max-lg:h-11"
                            aria-describedby="claim-days-back-help"
                        />
                        <p
                            id="claim-days-back-help"
                            class="text-xs text-muted-foreground"
                        >
                            The form suggests
                            {{ claims.options.suggestedDaysBack }}. Leave empty
                            for no limit.
                        </p>
                        <InputError :message="errors.days_back" />
                    </div>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        claims.settings.version
                            ? `Version ${claims.settings.version} saved.`
                            : 'Not saved yet.'
                    }}
                </p>
                <Button
                    type="submit"
                    class="self-start max-lg:min-h-11"
                    :disabled="settingsForm.processing"
                >
                    {{ settingsForm.processing ? 'Saving…' : 'Save settings' }}
                </Button>
            </form>

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Categories</h3>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="category-add"
                        @click="editCategory(null)"
                        >Add a category</Button
                    >
                </div>
                <EmptyState
                    v-if="!claims.settings.categories.length"
                    title="No categories yet."
                    description="KPOne ships none. Add the categories staff may claim; each can need a receipt and have limits that warn."
                />
                <div
                    v-if="unusedSuggestions.length"
                    class="flex flex-wrap items-center gap-2 text-sm"
                >
                    <span class="text-muted-foreground">Suggested:</span>
                    <Button
                        v-for="s in unusedSuggestions"
                        :key="s"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="editCategory(null, s)"
                        >{{ s }}</Button
                    >
                </div>
                <article
                    v-for="c in claims.settings.categories"
                    :key="c.id"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                    data-test="claim-category"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold">
                                {{ c.name }}
                                <StatusBadge
                                    v-if="!c.isActive"
                                    status="inactive"
                                    label="Not used"
                                    tone="neutral"
                                />
                            </p>
                            <p
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                {{
                                    c.needsReceipt
                                        ? 'Needs a receipt'
                                        : 'Receipt optional'
                                }}
                                · {{ limitWords(c) }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Change ${c.name}`"
                            @click="editCategory(c)"
                            >Change</Button
                        >
                    </div>
                    <details class="text-sm">
                        <summary
                            class="min-h-11 cursor-pointer py-2 text-muted-foreground lg:min-h-0"
                        >
                            Versions ({{ c.versions.length }})
                        </summary>
                        <ul class="flex flex-col divide-y">
                            <li
                                v-for="v in c.versions"
                                :key="v.version"
                                class="py-1.5 tabular-nums"
                            >
                                Version {{ v.version }} · {{ v.name }} ·
                                {{
                                    v.needsReceipt
                                        ? 'needs a receipt'
                                        : 'receipt optional'
                                }}
                                · {{ limitWords(v)
                                }}{{ v.isActive ? '' : ' · not used' }} ·
                                {{ v.on }} by {{ v.by }}
                            </li>
                        </ul>
                    </details>
                </article>
            </div>
        </section>

        <ClaimSheet
            v-model:open="sheet.open"
            :categories="claims.options.categories"
            :branch="claims.branch"
            :today="claims.today"
            :max-receipts="claims.options.maxReceipts"
            :from="sheet.from"
        />

        <OperationalConfirmDialog
            :open="withdrawing !== null"
            title="Withdraw this claim?"
            :description="
                withdrawing
                    ? `${withdrawing.number} · ${withdrawing.amount}. It is kept as withdrawn and nobody approves it.`
                    : ''
            "
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="busy"
            :error="errors.claims"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdraw"
        />

        <OperationalConfirmDialog
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
            processing-label="Saving…"
            :processing="busy"
            :error="errors.claims"
            @update:open="(v: boolean) => !v && (decision.row = null)"
            @confirm="decide"
        >
            <template v-if="decision.kind === 'reject'">
                <div class="grid gap-1.5">
                    <Label for="claim-reject-reason"
                        ><span id="claim-reject-reason-label"
                            >Why it is rejected *</span
                        ></Label
                    >
                    <OperationalSelect
                        id="claim-reject-reason"
                        v-model="decision.reason"
                        labelledby="claim-reject-reason-label"
                        label="Why it is rejected"
                        placeholder="Choose a reason"
                        trigger-class="max-lg:h-11"
                        :options="claims.options.rejectReasons"
                        :invalid="!!errors.reason"
                    />
                    <InputError :message="errors.reason" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="claim-reject-note"
                        >Note{{
                            decision.reason === 'other' ? ' *' : ''
                        }}</Label
                    >
                    <Textarea
                        id="claim-reject-note"
                        v-model="decision.note"
                        maxlength="200"
                        rows="2"
                    />
                    <p
                        v-if="decision.reason === 'wrong_amount'"
                        class="text-xs text-muted-foreground"
                    >
                        The person can send a new claim with the receipts copied
                        in.
                    </p>
                    <InputError :message="errors.note" />
                </div>
            </template>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="payment.row !== null"
            title="Record payment"
            :description="
                payment.row
                    ? `${payment.row.person} · ${payment.row.number} · ${payment.row.amount}. KPOne pays nothing; this records that it was paid outside KPOne.`
                    : ''
            "
            confirm-label="Record payment"
            processing-label="Saving…"
            :processing="busy"
            :error="errors.claims"
            @update:open="(v: boolean) => !v && (payment.row = null)"
            @confirm="pay"
        >
            <div class="grid gap-1.5">
                <Label for="claim-pay-method"
                    ><span id="claim-pay-method-label"
                        >How it was paid *</span
                    ></Label
                >
                <OperationalSelect
                    id="claim-pay-method"
                    v-model="payment.method"
                    labelledby="claim-pay-method-label"
                    label="How it was paid"
                    trigger-class="max-lg:h-11"
                    :options="claims.options.methods"
                    :invalid="!!errors.method"
                />
                <InputError :message="errors.method" />
            </div>
            <div class="grid gap-1.5">
                <Label for="claim-pay-date">Paid on *</Label>
                <Input
                    id="claim-pay-date"
                    v-model="payment.paidOn"
                    type="date"
                    :max="claims.today"
                    class="w-44 max-lg:h-11"
                />
                <InputError :message="errors.paid_on" />
            </div>
            <div class="grid gap-1.5">
                <Label for="claim-pay-reference"
                    >Reference{{ payment.method === 'cash' ? '' : ' *' }}</Label
                >
                <Input
                    id="claim-pay-reference"
                    v-model="payment.reference"
                    maxlength="60"
                    autocomplete="off"
                    class="max-lg:h-11"
                    aria-describedby="claim-pay-reference-help"
                />
                <p
                    id="claim-pay-reference-help"
                    class="text-xs text-muted-foreground"
                >
                    Kept encrypted and shown as its last four.
                </p>
                <InputError :message="errors.reference" />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="reversal.row !== null"
            title="Reverse this payment?"
            :description="
                reversal.row
                    ? `${reversal.row.number} · ${reversal.row.amount}. The claim goes back to Approved, and both the payment and its reversal are kept.`
                    : ''
            "
            confirm-label="Reverse payment"
            processing-label="Saving…"
            :processing="busy"
            :error="errors.claims"
            @update:open="(v: boolean) => !v && (reversal.row = null)"
            @confirm="reverse"
        >
            <div class="grid gap-1.5">
                <Label for="claim-reverse-reason"
                    ><span id="claim-reverse-reason-label">Why *</span></Label
                >
                <OperationalSelect
                    id="claim-reverse-reason"
                    v-model="reversal.reason"
                    labelledby="claim-reverse-reason-label"
                    label="Why the payment is reversed"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:h-11"
                    :options="claims.options.reversalReasons"
                    :invalid="!!errors.reason"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>

        <Sheet v-model:open="categoryForm.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="category-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        categoryForm.category
                            ? `Change ${categoryForm.category.name}`
                            : 'Add a category'
                    }}</SheetTitle>
                    <SheetDescription
                        >Saves a new version; earlier versions stay listed.
                        Limits only warn the approver.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveCategory"
                >
                    <ErrorState v-if="errors.claims" :message="errors.claims" />
                    <div class="grid gap-1.5">
                        <Label for="category-name">Name *</Label>
                        <Input
                            id="category-name"
                            v-model="categoryForm.name"
                            maxlength="60"
                            class="max-lg:h-11"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <label
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                    >
                        <input
                            v-model="categoryForm.needsReceipt"
                            type="checkbox"
                            class="size-4"
                        />
                        Needs a receipt
                    </label>
                    <div class="grid gap-1.5">
                        <Label for="category-per-claim"
                            >Limit a claim (optional)</Label
                        >
                        <div
                            class="flex w-44 items-center rounded-md border focus-within:ring-2 focus-within:ring-ring"
                        >
                            <span
                                class="pl-3 text-sm text-muted-foreground"
                                aria-hidden="true"
                                >RM</span
                            >
                            <Input
                                id="category-per-claim"
                                v-model="categoryForm.perClaim"
                                inputmode="decimal"
                                class="border-0 text-right tabular-nums shadow-none focus-visible:ring-0 max-lg:h-11"
                            />
                        </div>
                        <InputError :message="errors.per_claim_limit" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="category-monthly"
                            >Limit a month for each person (optional)</Label
                        >
                        <div
                            class="flex w-44 items-center rounded-md border focus-within:ring-2 focus-within:ring-ring"
                        >
                            <span
                                class="pl-3 text-sm text-muted-foreground"
                                aria-hidden="true"
                                >RM</span
                            >
                            <Input
                                id="category-monthly"
                                v-model="categoryForm.monthly"
                                inputmode="decimal"
                                class="border-0 text-right tabular-nums shadow-none focus-visible:ring-0 max-lg:h-11"
                            />
                        </div>
                        <InputError :message="errors.monthly_limit" />
                    </div>
                    <label
                        v-if="categoryForm.category"
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                    >
                        <input
                            v-model="categoryForm.isActive"
                            type="checkbox"
                            class="size-4"
                        />
                        In use (staff can choose it)
                    </label>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="categoryForm.processing"
                    >
                        {{
                            categoryForm.processing
                                ? 'Saving…'
                                : categoryForm.category
                                  ? 'Save new version'
                                  : 'Save category'
                        }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>

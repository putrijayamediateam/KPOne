<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Inbox } from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PanelStatementHelper from '@/components/panel/PanelStatementHelper.vue';
import type { Statement } from '@/components/panel/PanelStatementHelper.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status';
import { myr } from '@/types/billing';

type OpenClaim = {
    publicId: string;
    claimNo: string;
    invoiceNumber: string;
    patientName: string;
    visitDate: string;
    acceptedSen: number;
    receivedSen: number;
    outstandingSen: number;
};
type Remittance = {
    publicId: string;
    panelName: string;
    amountSen: number;
    receivedDate: string;
    reference: string | null;
    recordedBy: string;
    reversed?: boolean;
    splits: {
        claimNo: string;
        invoiceNumber: string;
        patientName: string;
        amountSen: number;
    }[];
};

const props = defineProps<{
    branch: { id: number; name: string; today: string };
    panels: { code: string; name: string }[];
    filters: { panel: string };
    maxSplits: number;
    canReverse?: boolean;
    statement?: Statement | null;
    statementLimits?: { maxRows: number; retentionDays: number };
    openClaims: OpenClaim[];
    openClaimsTruncated?: boolean;
    remittances: {
        data: Remittance[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const errorMessages = computed(() => {
    const keys = [
        'remittance',
        'panel',
        'amount_sen',
        'received_date',
        'reference',
        'splits',
        'expected_branch_id',
        'idempotency_key',
    ];
    const itemKey = Object.keys(errors.value).find((key) =>
        key.startsWith('splits.'),
    );

    return [...keys, ...(itemKey ? [itemKey] : [])]
        .map((key) => errors.value[key])
        .filter((message): message is string => Boolean(message));
});

// RM text to whole sen without floating point; null when the text is not a valid amount.
const toSen = (text: string): number | null => {
    const value = text.trim();

    if (value === '') {
        return 0;
    }

    const match = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(value);

    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
};
const fromSen = (sen: number) =>
    `${Math.floor(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;

const form = reactive({
    amount: '',
    receivedDate: props.branch.today,
    reference: '',
    busy: false,
});
const parts = ref<Record<string, string>>({});
// One key per payment; a retry after a refusal reuses it, so a payment is never recorded twice.
const idempotencyKey = ref(crypto.randomUUID());

// A new Panel starts a new split. When the list reloads (a refusal, paging the history), typed amounts are
// kept for claims that are still listed.
watch(
    () => props.filters.panel,
    () => {
        parts.value = {};
    },
);
watch(
    () => props.openClaims,
    (claims) => {
        const listed = new Set(claims.map((claim) => claim.publicId));
        parts.value = Object.fromEntries(
            Object.entries(parts.value).filter(([id]) => listed.has(id)),
        );
    },
);

const amountSen = computed(() => toSen(form.amount));
const partSen = (claim: OpenClaim) => toSen(parts.value[claim.publicId] ?? '');
const invalidParts = computed(() =>
    props.openClaims.filter((claim) => {
        const sen = partSen(claim);

        return sen === null || sen > claim.outstandingSen;
    }),
);
const splitRows = computed(() =>
    props.openClaims
        .map((claim) => ({ claim, sen: partSen(claim) ?? 0 }))
        .filter((row) => row.sen > 0),
);
const splitTotalSen = computed(() =>
    splitRows.value.reduce((sum, row) => sum + row.sen, 0),
);
const leftSen = computed(() => (amountSen.value ?? 0) - splitTotalSen.value);
const canRecord = computed(
    () =>
        !form.busy &&
        props.filters.panel !== '' &&
        amountSen.value !== null &&
        amountSen.value > 0 &&
        invalidParts.value.length === 0 &&
        splitRows.value.length > 0 &&
        splitRows.value.length <= props.maxSplits &&
        leftSen.value === 0,
);

// Phone text size and dark fill for the amount boxes; kept apart so the pinned class string (ending max-lg:h-11) stays intact.
const amountFieldExtra =
    'max-lg:text-base max-lg:scroll-mb-24 dark:bg-field-fill/30';

// One quiet line above Record payment for the one case no other line on the page explains.
const recordHelp = computed(() =>
    props.filters.panel !== '' &&
    props.openClaims.length > 0 &&
    !form.busy &&
    (amountSen.value ?? 0) > 0 &&
    invalidParts.value.length === 0 &&
    splitRows.value.length === 0
        ? 'Enter an amount for at least one claim.'
        : '',
);

const choosePanel = (code: string) => {
    router.get('/panel-remittances', code === '' ? {} : { panel: code }, {
        preserveScroll: true,
        replace: true,
    });
};
// Fills the oldest claims first with what is still unpaid, until the payment is used up.
const fillOldestFirst = () => {
    let left = amountSen.value ?? 0;
    const next: Record<string, string> = {};

    for (const claim of props.openClaims) {
        if (left <= 0) {
            break;
        }

        const take = Math.min(left, claim.outstandingSen);
        next[claim.publicId] = fromSen(take);
        left -= take;
    }

    parts.value = next;
};
const clearSplit = () => {
    parts.value = {};
};
// PNL-7: ticked statement rows fill the split; amounts already typed are replaced only after a confirmation.
const pendingStatementParts = ref<{ claim: string; sen: number }[] | null>(
    null,
);
const applyStatementParts = (rows: { claim: string; sen: number }[]) => {
    const listed = new Set(props.openClaims.map((claim) => claim.publicId));
    const usable = rows.filter((row) => listed.has(row.claim));
    parts.value = Object.fromEntries(
        usable.map((row) => [row.claim, fromSen(row.sen)]),
    );
    pendingStatementParts.value = null;
    toast.success(
        usable.length === rows.length
            ? 'Split filled from the statement. Check the total, then record the payment.'
            : `Split filled from the statement. ${rows.length - usable.length} of the claims are not in the list below; record this payment, then use the statement again.`,
    );
    nextTick(() => document.getElementById('split-summary')?.focus());
};
const useStatement = (rows: { claim: string; sen: number }[]) => {
    if (splitRows.value.length > 0) {
        pendingStatementParts.value = rows;

        return;
    }

    applyStatementParts(rows);
};
const turnPage = (value: number) =>
    router.get(
        '/panel-remittances',
        {
            ...(props.filters.panel ? { panel: props.filters.panel } : {}),
            page: value,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
const record = () => {
    if (!canRecord.value) {
        return;
    }

    form.busy = true;
    router.post(
        '/panel-remittances',
        {
            expected_branch_id: props.branch.id,
            idempotency_key: idempotencyKey.value,
            panel: props.filters.panel,
            amount_sen: amountSen.value,
            received_date: form.receivedDate,
            reference: form.reference,
            splits: splitRows.value.map((row) => ({
                claim: row.claim.publicId,
                amount_sen: row.sen,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                form.amount = '';
                form.reference = '';
                parts.value = {};
                idempotencyKey.value = crypto.randomUUID();
            },
            onFinish: () => {
                form.busy = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Panel payments" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Panel payments"
            :description="`${branch.name} · Record one payment from a Panel and split it across that Panel's accepted claims.`"
        />

        <section
            class="space-y-5 rounded-xl border bg-card p-4 md:p-5"
            aria-label="Record a Panel payment"
        >
            <h2 class="text-base font-semibold">Record a Panel payment</h2>
            <p class="text-sm text-muted-foreground">
                Choose the Panel, enter the payment as it appears on the Panel's
                statement or bank transfer, then enter how much goes to each
                claim. The split must add up exactly to the payment. A recorded
                payment cannot be edited or deleted.
            </p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <label
                    class="grid min-w-0 gap-1.5 text-[13px] font-medium md:col-span-2"
                >
                    Panel
                    <select
                        :value="filters.panel"
                        class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 lg:text-sm dark:bg-field-fill/30"
                        aria-label="Panel"
                        @change="
                            choosePanel(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Select a Panel</option>
                        <option
                            v-for="panel in panels"
                            :key="panel.code"
                            :value="panel.code"
                        >
                            {{ panel.name }}
                        </option>
                    </select>
                </label>
                <label class="grid min-w-0 gap-1.5 text-[13px] font-medium">
                    Amount received (RM)
                    <Input
                        v-model="form.amount"
                        inputmode="decimal"
                        placeholder="0.00"
                        :disabled="filters.panel === ''"
                        class="max-lg:h-11"
                    />
                </label>
                <label class="grid min-w-0 gap-1.5 text-[13px] font-medium">
                    Date received
                    <DateField
                        v-model="form.receivedDate"
                        :max="branch.today"
                        :disabled="filters.panel === ''"
                        :echo="false"
                    />
                </label>
                <label
                    class="grid min-w-0 gap-1.5 text-[13px] font-medium md:col-span-2"
                >
                    Payment reference (optional)
                    <Input
                        v-model="form.reference"
                        maxlength="100"
                        :disabled="filters.panel === ''"
                        class="max-lg:h-11"
                    />
                </label>
            </div>

            <div
                v-if="filters.panel === ''"
                class="flex flex-col items-center gap-2 px-4 py-6 text-center"
            >
                <Inbox
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="text-sm font-medium">
                    Choose a Panel to see its accepted claims that still wait
                    for payment.
                </p>
            </div>
            <div
                v-else-if="openClaims.length === 0"
                data-testid="no-open-claims"
                class="flex flex-col items-center gap-2 px-4 py-6 text-center"
            >
                <Inbox
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="text-sm font-medium">
                    This Panel has no accepted claims waiting for payment at
                    this branch.
                </p>
            </div>
            <template v-else>
                <PanelStatementHelper
                    :branch-id="branch.id"
                    :panel="filters.panel"
                    :statement="statement ?? null"
                    :max-rows="statementLimits?.maxRows ?? 500"
                    :retention-days="statementLimits?.retentionDays ?? 30"
                    :error="errors.statement"
                    @use="useStatement"
                />
                <p
                    v-if="openClaimsTruncated"
                    class="rounded-lg border border-warning/30 bg-warning-soft p-3 text-sm text-warning-strong"
                    role="status"
                >
                    Only the 200 oldest claims are listed. Record this payment,
                    then the next claims will appear.
                </p>
                <div
                    class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center"
                >
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="max-lg:min-h-11"
                        :disabled="!amountSen"
                        @click="fillOldestFirst"
                    >
                        Fill oldest claims first
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="clearSplit"
                    >
                        Clear split
                    </Button>
                </div>
                <!-- Phones and tablets: one card per claim so the amount box is always on screen (PNL-14: at 768 px the table cut the amount box off). -->
                <ul
                    class="divide-y rounded-xl border lg:hidden"
                    aria-label="Accepted claims waiting for payment"
                >
                    <li
                        v-for="claim in openClaims"
                        :key="claim.publicId"
                        class="grid gap-2 p-3 text-sm"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <span class="font-medium">
                                Claim {{ claim.claimNo }}
                            </span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ claim.invoiceNumber }}
                            </span>
                        </div>
                        <p class="wrap-anywhere">
                            {{ claim.patientName }} · {{ claim.visitDate }}
                        </p>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            Accepted {{ myr(claim.acceptedSen) }} · Received
                            {{ myr(claim.receivedSen) }}
                        </p>
                        <div
                            class="flex flex-wrap items-end justify-between gap-2"
                        >
                            <span class="font-medium tabular-nums">
                                Unpaid {{ myr(claim.outstandingSen) }}
                            </span>
                            <!-- PNL-14 (Daniel): the box carries the same visible label as the table column. -->
                            <label
                                class="grid gap-1 text-[13px] font-medium text-muted-foreground"
                                data-testid="split-card-payment-label"
                            >
                                This payment (RM)
                                <input
                                    v-model="parts[claim.publicId]"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    :aria-label="`Amount of this payment for claim ${claim.claimNo}`"
                                    :aria-invalid="invalidParts.includes(claim)"
                                    :aria-describedby="
                                        invalidParts.includes(claim)
                                            ? 'split-amount-error'
                                            : undefined
                                    "
                                    class="h-9 w-32 rounded-lg border border-input bg-card px-2 text-right text-sm text-foreground tabular-nums focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 aria-invalid:border-destructive aria-invalid:ring-destructive/30 max-lg:h-11"
                                    :class="amountFieldExtra"
                                />
                            </label>
                        </div>
                    </li>
                </ul>
                <div
                    class="relative hidden max-w-full overflow-x-auto rounded-xl border lg:block"
                    role="region"
                    aria-label="Accepted claims waiting for payment"
                    tabindex="0"
                >
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="border-b bg-muted/60 text-xs text-muted-foreground"
                        >
                            <tr class="[&_th]:px-3 [&_th]:py-2.5">
                                <th scope="col">Claim</th>
                                <th scope="col">Patient</th>
                                <th scope="col">Visit date</th>
                                <th scope="col" class="text-right">Accepted</th>
                                <th scope="col" class="text-right">Received</th>
                                <th scope="col" class="text-right">Unpaid</th>
                                <th scope="col" class="w-36 text-right">
                                    This payment (RM)
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="[&_td]:px-3 [&_td]:py-2.5 [&_tr]:border-b [&_tr:last-child]:border-0"
                        >
                            <tr
                                v-for="claim in openClaims"
                                :key="claim.publicId"
                            >
                                <td>
                                    <div class="font-medium">
                                        Claim {{ claim.claimNo }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ claim.invoiceNumber }}
                                    </div>
                                </td>
                                <td class="wrap-anywhere">
                                    {{ claim.patientName }}
                                </td>
                                <td class="whitespace-nowrap">
                                    {{ claim.visitDate }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ myr(claim.acceptedSen) }}
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ myr(claim.receivedSen) }}
                                </td>
                                <td class="text-right font-medium tabular-nums">
                                    {{ myr(claim.outstandingSen) }}
                                </td>
                                <td class="text-right">
                                    <input
                                        v-model="parts[claim.publicId]"
                                        inputmode="decimal"
                                        placeholder="0.00"
                                        :aria-label="`Amount of this payment for claim ${claim.claimNo}`"
                                        :aria-invalid="
                                            invalidParts.includes(claim)
                                        "
                                        :aria-describedby="
                                            invalidParts.includes(claim)
                                                ? 'split-amount-error'
                                                : undefined
                                        "
                                        class="h-9 w-28 rounded-lg border border-input bg-card px-2 text-right text-sm tabular-nums focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 aria-invalid:border-destructive aria-invalid:ring-destructive/30 dark:bg-field-fill/30"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    id="split-summary"
                    tabindex="-1"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-muted/40 p-3 text-sm max-lg:sticky max-lg:bottom-0 max-lg:z-10 max-lg:rounded-none max-lg:border-x-0 max-lg:border-b-0 max-lg:bg-card max-lg:py-3 max-lg:shadow-[0_-2px_8px_rgb(0_0_0/0.06)] max-md:-mx-4 max-md:px-4 md:max-lg:-mx-5 md:max-lg:px-5"
                    data-testid="split-summary"
                >
                    <span class="tabular-nums">
                        Split {{ myr(splitTotalSen) }} of
                        {{ myr(amountSen ?? 0) }} across
                        {{ splitRows.length }} claim{{
                            splitRows.length === 1 ? '' : 's'
                        }}
                    </span>
                    <span
                        class="font-medium tabular-nums"
                        :class="
                            !amountSen
                                ? 'text-muted-foreground'
                                : leftSen === 0
                                  ? 'text-success'
                                  : 'text-destructive'
                        "
                    >
                        {{
                            amountSen === null
                                ? 'Enter RM with up to two decimals'
                                : !amountSen
                                  ? 'Enter the amount received'
                                  : leftSen === 0
                                    ? 'Fully split'
                                    : leftSen > 0
                                      ? `${myr(leftSen)} still to split`
                                      : `${myr(-leftSen)} over the payment`
                        }}
                    </span>
                </div>
                <p
                    v-if="invalidParts.length > 0"
                    id="split-amount-error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    Check the amounts for claim
                    {{ invalidParts.map((claim) => claim.claimNo).join(', ') }}:
                    enter RM with up to two decimals, no more than what is still
                    unpaid.
                </p>
                <p
                    v-if="splitRows.length > maxSplits"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    Split one payment across at most {{ maxSplits }} claims.
                </p>
            </template>

            <div v-if="errorMessages.length > 0" class="space-y-1">
                <p
                    v-for="message in errorMessages"
                    :key="message"
                    role="alert"
                    class="text-sm wrap-anywhere text-destructive"
                >
                    {{ message }}
                </p>
            </div>
            <div class="flex flex-col gap-2 sm:items-end">
                <p
                    v-if="recordHelp"
                    id="record-help"
                    class="text-sm text-muted-foreground"
                >
                    {{ recordHelp }}
                </p>
                <Button
                    type="button"
                    class="max-lg:min-h-11 max-sm:w-full"
                    :disabled="!canRecord"
                    :aria-describedby="recordHelp ? 'record-help' : undefined"
                    @click="record"
                >
                    Record payment
                </Button>
            </div>
        </section>

        <section
            class="space-y-4 rounded-xl border bg-card p-4 md:p-5"
            aria-label="Recorded Panel payments"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold">Recorded Panel payments</h2>
                <Button
                    v-if="canReverse"
                    as-child
                    variant="outline"
                    size="sm"
                    class="max-lg:min-h-11 max-sm:w-full"
                >
                    <Link href="/panel-payment-corrections"
                        >Correct a Panel payment</Link
                    >
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                A payment recorded by mistake cannot be edited. The Finance
                Officer or the Director reverses it, then the correct payment is
                recorded again.
            </p>
            <div
                v-if="remittances.data.length === 0"
                class="flex flex-col items-center gap-2 px-4 py-6 text-center"
            >
                <Inbox
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="text-sm font-medium">
                    No Panel payment has been recorded at this branch yet.
                </p>
            </div>
            <ul v-else class="divide-y rounded-xl border">
                <li
                    v-for="remittance in remittances.data"
                    :key="remittance.publicId"
                    class="space-y-2 p-3 text-sm"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <span class="min-w-0 font-medium wrap-anywhere">
                            {{ remittance.panelName }} ·
                            {{ remittance.receivedDate }}
                            <StatusBadge
                                v-if="remittance.reversed"
                                status="reversed"
                                class="ml-1"
                            />
                        </span>
                        <strong
                            class="tabular-nums"
                            :class="{
                                'text-muted-foreground line-through':
                                    remittance.reversed,
                            }"
                            >{{ myr(remittance.amountSen) }}</strong
                        >
                    </div>
                    <p class="text-xs wrap-anywhere text-muted-foreground">
                        Reference: {{ remittance.reference || 'None' }} ·
                        Recorded by {{ remittance.recordedBy }}
                    </p>
                    <ul class="space-y-1 text-xs">
                        <li
                            v-for="split in remittance.splits"
                            :key="split.claimNo"
                            class="flex flex-wrap justify-between gap-2"
                        >
                            <span class="min-w-0 wrap-anywhere">
                                Claim {{ split.claimNo }} ·
                                {{ split.invoiceNumber }} ·
                                {{ split.patientName }}
                            </span>
                            <span
                                class="tabular-nums"
                                :class="{
                                    'text-muted-foreground line-through':
                                        remittance.reversed,
                                }"
                                >{{ myr(split.amountSen) }}</span
                            >
                        </li>
                    </ul>
                </li>
            </ul>
            <CompactPagination
                v-if="remittances.total > 0"
                :current-page="remittances.currentPage"
                :last-page="remittances.lastPage"
                :total="remittances.total"
                @change="turnPage"
            />
        </section>
        <OperationalConfirmDialog
            :open="pendingStatementParts !== null"
            title="Replace the current split?"
            description="Amounts you typed will be cleared."
            confirm-label="Replace split"
            @update:open="
                (value) => {
                    if (!value) pendingStatementParts = null;
                }
            "
            @confirm="
                pendingStatementParts &&
                applyStatementParts(pendingStatementParts)
            "
        />
    </main>
</template>

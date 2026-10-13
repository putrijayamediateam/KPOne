<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import { myr } from '@/types/billing';

type PageSet<T> = {
    data: T[];
    total: number;
    currentPage: number;
    lastPage: number;
};
type Figure = { count: number; amountSen: number };

const props = defineProps<{
    work: {
        branch: { id: number; name: string };
        summary: Record<
            | 'pending'
            | 'overdue'
            | 'approved'
            | 'received'
            | 'rejected'
            | 'outstanding',
            Figure
        >;
        tabs: Record<string, number>;
        panels: string[];
        filters: {
            tab: string;
            range: string;
            from: string | null;
            to: string | null;
            panel: string;
            claimNo: string;
            amountMin: string;
            amountMax: string;
            invoiceNo: string;
            name: string;
            doctor: string;
            visitType: string;
            visitFrom: string;
            visitTo: string;
            dueFrom: string;
            dueTo: string;
            diagnosis: string;
            treatment: string;
        };
        can: { clinicalFilters: boolean };
        eligible: PageSet<{
            allocationId: string;
            patientName: string;
            visitNumber: string;
            invoiceNumber: string;
            invoiceUrl: string;
            panelName: string;
            amountSen: number;
            invoiceTotalSen: number;
            visitType: string;
            doctorName: string | null;
            visitDate: string;
            dueDate: string;
            overdue: boolean;
            remarkNote: string;
        }>;
        claims: PageSet<{
            publicId: string;
            claimNo: string;
            patientName: string;
            visitNumber: string;
            invoiceNumber: string;
            panelName: string;
            claimAmountSen: number;
            status: string;
            overdue: boolean;
            createdAt: string | null;
            updatedBy: string | null;
            remarks: string | null;
            showUrl: string;
        }>;
    };
}>();

const rangeOptions = [
    { value: 'all', label: 'All time' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'last_7_days', label: 'Last 7 days' },
    { value: 'last_30_days', label: 'Last 30 days' },
    { value: 'last_90_days', label: 'Last 90 days' },
    { value: 'this_week', label: 'This week' },
    { value: 'this_month', label: 'This month' },
    { value: 'this_year', label: 'This year' },
    { value: 'last_week', label: 'Last week' },
    { value: 'last_month', label: 'Last month' },
    { value: 'custom', label: 'Custom' },
];
const tabLabels: [string, string][] = [
    ['all', 'All'],
    ['pending', 'Pending'],
    ['overdue', 'Overdue'],
    ['submitted', 'Submitted'],
    ['approved', 'Approved'],
    ['rejected', 'Rejected'],
    ['received', 'Received'],
];
const claimColumns = [
    { key: 'amount', label: 'Amount' },
    { key: 'claimNo', label: 'Claim no' },
    { key: 'panel', label: 'Panel' },
    { key: 'patient', label: 'Patient name' },
    { key: 'status', label: 'Status' },
    { key: 'date', label: 'Date' },
    { key: 'updatedBy', label: 'Updated by' },
    { key: 'remarks', label: 'Remarks' },
] as const;
const invoiceColumns = [
    { key: 'amount', label: 'Amount' },
    { key: 'invoiceNo', label: 'Invoice no' },
    { key: 'panel', label: 'Panel' },
    { key: 'patient', label: 'Patient name' },
    { key: 'status', label: 'Status' },
    { key: 'visitDate', label: 'Visit date' },
    { key: 'dueDate', label: 'Due date' },
    { key: 'doctor', label: 'Doctor' },
    { key: 'remarks', label: 'Remark note' },
] as const;
type ColumnKey =
    | (typeof claimColumns)[number]['key']
    | (typeof invoiceColumns)[number]['key'];

const visitTypeOptions = [
    { value: '', label: 'All visit types' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'otc', label: 'OTC' },
];
const draft = reactive({
    panel: props.work.filters.panel,
    claimNo: props.work.filters.claimNo,
    amountMin: props.work.filters.amountMin,
    amountMax: props.work.filters.amountMax,
    invoiceNo: props.work.filters.invoiceNo,
    name: props.work.filters.name,
    doctor: props.work.filters.doctor,
    visitType: props.work.filters.visitType,
    visitFrom: props.work.filters.visitFrom,
    visitTo: props.work.filters.visitTo,
    dueFrom: props.work.filters.dueFrom,
    dueTo: props.work.filters.dueTo,
    diagnosis: props.work.filters.diagnosis,
    treatment: props.work.filters.treatment,
    from: props.work.filters.from ?? '',
    to: props.work.filters.to ?? '',
});
const visibleColumns = ref<Record<ColumnKey, boolean>>({
    amount: true,
    claimNo: true,
    invoiceNo: true,
    panel: true,
    patient: true,
    status: true,
    date: true,
    visitDate: true,
    dueDate: true,
    doctor: false,
    updatedBy: true,
    remarks: true,
});
const invoiceMode = computed(() =>
    ['pending', 'overdue'].includes(props.work.filters.tab),
);
const columnList = computed(() =>
    invoiceMode.value ? invoiceColumns : claimColumns,
);
const shownColumnCount = computed(
    () =>
        columnList.value.filter((column) => visibleColumns.value[column.key])
            .length + 1,
);
const tabs = computed(() =>
    tabLabels.map(([value, label]) => ({
        value,
        label,
        count: props.work.tabs[value] ?? 0,
    })),
);
const panelOptions = computed(() => [
    { value: '', label: 'All Panels' },
    ...props.work.panels.map((name) => ({ value: name, label: name })),
]);
const filtersActive = computed(() =>
    [
        draft.panel,
        draft.claimNo,
        draft.amountMin,
        draft.amountMax,
        draft.invoiceNo,
        draft.name,
        draft.doctor,
        draft.visitType,
        draft.visitFrom,
        draft.visitTo,
        draft.dueFrom,
        draft.dueTo,
        draft.diagnosis,
        draft.treatment,
    ].some((value) => value !== ''),
);

const query = (overrides: Record<string, string | number | null> = {}) => {
    const values: Record<string, string | number | null> = {
        tab: props.work.filters.tab,
        range: props.work.filters.range,
        from: draft.from,
        to: draft.to,
        panel: draft.panel,
        claim_no: draft.claimNo,
        amount_min: draft.amountMin,
        amount_max: draft.amountMax,
        invoice_no: draft.invoiceNo,
        name: draft.name,
        doctor: draft.doctor,
        visit_type: draft.visitType,
        visit_from: draft.visitFrom,
        visit_to: draft.visitTo,
        due_from: draft.dueFrom,
        due_to: draft.dueTo,
        diagnosis: draft.diagnosis,
        treatment: draft.treatment,
        ...overrides,
    };

    return Object.fromEntries(
        Object.entries(values).filter(
            ([key, value]) =>
                value !== '' &&
                value !== null &&
                !(key === 'tab' && value === 'all') &&
                !(key === 'range' && value === 'all'),
        ),
    );
};
const go = (overrides: Record<string, string | number | null> = {}) =>
    router.get('/panel-claims', query(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
const resetFilters = () => {
    draft.panel = '';
    draft.claimNo = '';
    draft.amountMin = '';
    draft.amountMax = '';
    draft.invoiceNo = '';
    draft.name = '';
    draft.doctor = '';
    draft.visitType = '';
    draft.visitFrom = '';
    draft.visitTo = '';
    draft.dueFrom = '';
    draft.dueTo = '';
    draft.diagnosis = '';
    draft.treatment = '';
    go({ claims_page: null, allocations_page: null });
};
const turnPage = (key: 'allocations_page' | 'claims_page', value: number) =>
    go({ [key]: value });

const error = ref('');
const createClaim = (allocationId: string) => {
    router.visit(
        `/panel-claims/allocations/${encodeURIComponent(allocationId)}/create`,
    );
};
const canMarkSubmitted = (status: string) =>
    status === 'Prepared' || status === 'Panel query';
const visitTypeLabel = (value: string) =>
    value === 'otc' ? 'OTC' : 'Consultation';
</script>

<template>
    <Head title="Panel Claims" />
    <main class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-foreground">
                    Panel Claims
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ work.branch.name }} · Create claims for manual submission
                    and track Panel decisions and remittances.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="work.filters.range === 'custom'">
                    <label class="sr-only" for="claims-from">From</label>
                    <input
                        id="claims-from"
                        v-model="draft.from"
                        type="date"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                        @change="go({ claims_page: null })"
                    />
                    <label class="sr-only" for="claims-to">To</label>
                    <input
                        id="claims-to"
                        v-model="draft.to"
                        type="date"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                        @change="go({ claims_page: null })"
                    />
                </template>
                <OperationalSelect
                    :model-value="work.filters.range"
                    :options="rangeOptions"
                    label="Date range"
                    trigger-class="min-w-40"
                    @update:model-value="
                        (value) =>
                            go({ range: String(value), claims_page: null })
                    "
                />
            </div>
        </header>

        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                class="rounded-xl border bg-card p-5"
                aria-label="Submissions"
            >
                <h2 class="mb-3 text-base font-semibold">Submissions</h2>
                <dl class="divide-y text-sm">
                    <div class="flex items-center justify-between py-2">
                        <dt>Pending</dt>
                        <dd class="font-medium tabular-nums">
                            {{ work.summary.pending.count }} claims
                        </dd>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <dt>Overdue</dt>
                        <dd class="font-medium text-destructive tabular-nums">
                            {{ work.summary.overdue.count }} claims
                        </dd>
                    </div>
                </dl>
            </section>
            <section class="rounded-xl border bg-card p-5" aria-label="Payouts">
                <h2 class="mb-3 text-base font-semibold">Payouts</h2>
                <dl class="grid gap-x-8 text-sm sm:grid-cols-2">
                    <div
                        v-for="[key, label] in [
                            ['approved', 'Approved'],
                            ['received', 'Received'],
                            ['rejected', 'Rejected'],
                            ['outstanding', 'Outstanding'],
                        ] as const"
                        :key="key"
                        class="flex items-center justify-between gap-3 border-b py-2"
                    >
                        <dt>{{ label }}</dt>
                        <dd class="text-right tabular-nums">
                            <span class="text-muted-foreground"
                                >{{ work.summary[key].count }} claims</span
                            >
                            <span class="ml-3 font-medium">{{
                                myr(work.summary[key].amountSen)
                            }}</span>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section class="space-y-4 rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <OperationalTabs
                    :model-value="work.filters.tab"
                    :tabs="tabs"
                    label="Claim status"
                    @update:model-value="
                        (value) =>
                            go({
                                tab: value,
                                claims_page: null,
                                allocations_page: null,
                            })
                    "
                />
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            aria-label="Customize columns"
                        >
                            Columns
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuLabel>Customize column</DropdownMenuLabel>
                        <DropdownMenuCheckboxItem
                            v-for="column in columnList"
                            :key="column.key"
                            :model-value="visibleColumns[column.key]"
                            @select.prevent
                            @update:model-value="
                                (value) =>
                                    (visibleColumns[column.key] =
                                        Boolean(value))
                            "
                        >
                            {{ column.label }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <p v-if="invoiceMode" class="text-sm text-muted-foreground">
                Approved Panel invoices without a claim yet. Create claim opens
                the creation page for a submitted claim with invoice lines,
                patient name, member reference, visit date, branch and invoice
                number. The claim summary excludes clinical notes, diagnoses,
                identity documents or attachments. Photos can be added as claim
                documents. A claim is overdue once the Panel’s due days after
                the visit have passed.
            </p>

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="
                    go({ claims_page: null, allocations_page: null })
                "
            >
                <template v-if="invoiceMode">
                    <label class="text-xs font-medium text-muted-foreground">
                        Invoice number
                        <input
                            v-model="draft.invoiceNo"
                            class="mt-1 block h-9 w-32 rounded-md border bg-background px-2 text-sm"
                        />
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Name
                        <input
                            v-model="draft.name"
                            class="mt-1 block h-9 w-40 rounded-md border bg-background px-2 text-sm"
                        />
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Doctor
                        <input
                            v-model="draft.doctor"
                            class="mt-1 block h-9 w-36 rounded-md border bg-background px-2 text-sm"
                        />
                    </label>
                    <div class="text-xs font-medium text-muted-foreground">
                        <span class="block">Visit type</span>
                        <OperationalSelect
                            class="mt-1"
                            :model-value="draft.visitType"
                            :options="visitTypeOptions"
                            label="Visit type"
                            trigger-class="min-w-36"
                            @update:model-value="
                                (value) => {
                                    draft.visitType = String(value);
                                    go({
                                        claims_page: null,
                                        allocations_page: null,
                                    });
                                }
                            "
                        />
                    </div>
                    <label class="text-xs font-medium text-muted-foreground">
                        Visit date
                        <span class="mt-1 flex items-center gap-1">
                            <input
                                v-model="draft.visitFrom"
                                type="date"
                                aria-label="Visit date from"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                            />
                            <input
                                v-model="draft.visitTo"
                                type="date"
                                aria-label="Visit date to"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                            />
                        </span>
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Due date
                        <span class="mt-1 flex items-center gap-1">
                            <input
                                v-model="draft.dueFrom"
                                type="date"
                                aria-label="Due date from"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                            />
                            <input
                                v-model="draft.dueTo"
                                type="date"
                                aria-label="Due date to"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                            />
                        </span>
                    </label>
                    <template v-if="work.can.clinicalFilters">
                        <label
                            class="text-xs font-medium text-muted-foreground"
                        >
                            Diagnosis
                            <input
                                v-model="draft.diagnosis"
                                class="mt-1 block h-9 w-40 rounded-md border bg-background px-2 text-sm"
                            />
                        </label>
                        <label
                            class="text-xs font-medium text-muted-foreground"
                        >
                            Treatment
                            <input
                                v-model="draft.treatment"
                                class="mt-1 block h-9 w-40 rounded-md border bg-background px-2 text-sm"
                            />
                        </label>
                    </template>
                </template>
                <template v-else>
                    <label class="text-xs font-medium text-muted-foreground">
                        Amount (RM)
                        <span class="mt-1 flex items-center gap-1">
                            <input
                                v-model="draft.amountMin"
                                inputmode="decimal"
                                placeholder="Min"
                                aria-label="Minimum amount"
                                class="h-9 w-24 rounded-md border bg-background px-2 text-sm"
                            />
                            <input
                                v-model="draft.amountMax"
                                inputmode="decimal"
                                placeholder="Max"
                                aria-label="Maximum amount"
                                class="h-9 w-24 rounded-md border bg-background px-2 text-sm"
                            />
                        </span>
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Claim number
                        <input
                            v-model="draft.claimNo"
                            inputmode="numeric"
                            class="mt-1 block h-9 w-32 rounded-md border bg-background px-2 text-sm"
                        />
                    </label>
                    <label class="text-xs font-medium text-muted-foreground">
                        Name
                        <input
                            v-model="draft.name"
                            class="mt-1 block h-9 w-40 rounded-md border bg-background px-2 text-sm"
                        />
                    </label>
                </template>
                <div class="text-xs font-medium text-muted-foreground">
                    <span class="block">Panel</span>
                    <OperationalSelect
                        class="mt-1"
                        :model-value="draft.panel"
                        :options="panelOptions"
                        label="Panel"
                        trigger-class="min-w-40"
                        @update:model-value="
                            (value) => {
                                draft.panel = String(value);
                                go({
                                    claims_page: null,
                                    allocations_page: null,
                                });
                            }
                        "
                    />
                </div>
                <Button type="submit" variant="secondary" size="sm">
                    Apply
                </Button>
                <button
                    v-if="filtersActive"
                    type="button"
                    class="ml-auto text-sm text-muted-foreground underline"
                    @click="resetFilters"
                >
                    Reset filters
                </button>
            </form>

            <template v-if="invoiceMode">
                <EmptyState
                    v-if="work.eligible.data.length === 0"
                    :title="
                        work.filters.tab === 'overdue'
                            ? 'No overdue invoices'
                            : 'No pending invoices'
                    "
                    description="Approved Panel invoices without a claim appear here. Change the date range or filters to see more."
                />
                <OperationalTable
                    v-else
                    label="Approved Panel invoices without a claim"
                    :columns="shownColumnCount"
                    min-width="1000px"
                >
                    <template #head>
                        <tr>
                            <th v-if="visibleColumns.amount" scope="col">
                                Amount
                            </th>
                            <th v-if="visibleColumns.invoiceNo" scope="col">
                                Invoice no
                            </th>
                            <th v-if="visibleColumns.panel" scope="col">
                                Panel
                            </th>
                            <th v-if="visibleColumns.patient" scope="col">
                                Patient name
                            </th>
                            <th v-if="visibleColumns.status" scope="col">
                                Status
                            </th>
                            <th v-if="visibleColumns.visitDate" scope="col">
                                Visit date
                            </th>
                            <th v-if="visibleColumns.dueDate" scope="col">
                                Due date
                            </th>
                            <th v-if="visibleColumns.doctor" scope="col">
                                Doctor
                            </th>
                            <th v-if="visibleColumns.remarks" scope="col">
                                Remark note
                            </th>
                            <th scope="col" class="text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in work.eligible.data"
                            :key="row.allocationId"
                        >
                            <td
                                v-if="visibleColumns.amount"
                                class="whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.amountSen) }}
                            </td>
                            <td
                                v-if="visibleColumns.invoiceNo"
                                class="font-mono text-xs"
                            >
                                {{ row.invoiceNumber }}
                            </td>
                            <td v-if="visibleColumns.panel">
                                {{ row.panelName }}
                            </td>
                            <td v-if="visibleColumns.patient">
                                <span class="block font-medium text-foreground">
                                    {{ row.patientName }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ row.visitNumber }} ·
                                    {{ visitTypeLabel(row.visitType) }}
                                </span>
                            </td>
                            <td v-if="visibleColumns.status">
                                <StatusBadge
                                    v-if="row.overdue"
                                    status="overdue"
                                    label="Overdue"
                                    tone="danger"
                                />
                                <StatusBadge
                                    v-else
                                    status="pending"
                                    label="Pending"
                                    tone="warning"
                                />
                            </td>
                            <td
                                v-if="visibleColumns.visitDate"
                                class="whitespace-nowrap"
                            >
                                {{ row.visitDate }}
                            </td>
                            <td
                                v-if="visibleColumns.dueDate"
                                class="whitespace-nowrap"
                            >
                                {{ row.dueDate }}
                            </td>
                            <td v-if="visibleColumns.doctor">
                                {{ row.doctorName }}
                            </td>
                            <td
                                v-if="visibleColumns.remarks"
                                class="max-w-64 truncate"
                            >
                                {{ row.remarkNote }}
                            </td>
                            <td class="text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            :aria-label="`Actions for invoice ${row.invoiceNumber}`"
                                        >
                                            …
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <a :href="row.invoiceUrl"
                                                >View invoice</a
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            @select="
                                                createClaim(row.allocationId)
                                            "
                                        >
                                            Create claim
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
                <CompactPagination
                    v-if="work.eligible.total > 0"
                    :current-page="work.eligible.currentPage"
                    :last-page="work.eligible.lastPage"
                    :total="work.eligible.total"
                    @change="(value) => turnPage('allocations_page', value)"
                />
            </template>
            <template v-else>
                <EmptyState
                    v-if="work.claims.data.length === 0"
                    title="No claims match"
                    description="Create a claim from the Pending or Overdue tab, or change the tab, date range or filters."
                />
                <OperationalTable
                    v-else
                    label="Panel claims"
                    :columns="shownColumnCount"
                    min-width="1000px"
                >
                    <template #head>
                        <tr>
                            <th v-if="visibleColumns.amount" scope="col">
                                Amount
                            </th>
                            <th v-if="visibleColumns.claimNo" scope="col">
                                Claim no
                            </th>
                            <th v-if="visibleColumns.panel" scope="col">
                                Panel
                            </th>
                            <th v-if="visibleColumns.patient" scope="col">
                                Patient name
                            </th>
                            <th v-if="visibleColumns.status" scope="col">
                                Status
                            </th>
                            <th v-if="visibleColumns.date" scope="col">Date</th>
                            <th v-if="visibleColumns.updatedBy" scope="col">
                                Updated by
                            </th>
                            <th v-if="visibleColumns.remarks" scope="col">
                                Remarks
                            </th>
                            <th scope="col" class="text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in work.claims.data" :key="row.publicId">
                            <td
                                v-if="visibleColumns.amount"
                                class="whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.claimAmountSen) }}
                            </td>
                            <td
                                v-if="visibleColumns.claimNo"
                                class="font-mono text-xs"
                            >
                                {{ row.claimNo }}
                            </td>
                            <td v-if="visibleColumns.panel">
                                {{ row.panelName }}
                            </td>
                            <td v-if="visibleColumns.patient">
                                <span class="block font-medium text-foreground">
                                    {{ row.patientName }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ row.visitNumber }} ·
                                    {{ row.invoiceNumber }}
                                </span>
                            </td>
                            <td v-if="visibleColumns.status">
                                <StatusBadge :status="row.status" />
                            </td>
                            <td
                                v-if="visibleColumns.date"
                                class="whitespace-nowrap"
                            >
                                {{ row.createdAt }}
                            </td>
                            <td v-if="visibleColumns.updatedBy">
                                {{ row.updatedBy }}
                            </td>
                            <td
                                v-if="visibleColumns.remarks"
                                class="max-w-64 truncate"
                            >
                                {{ row.remarks }}
                            </td>
                            <td class="text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            :aria-label="`Actions for claim ${row.claimNo}`"
                                        >
                                            …
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <a :href="row.showUrl"
                                                >View claim</a
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="canMarkSubmitted(row.status)"
                                            as-child
                                        >
                                            <a :href="row.showUrl"
                                                >Submit claim (manual)</a
                                            >
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
                <CompactPagination
                    v-if="work.claims.total > 0"
                    :current-page="work.claims.currentPage"
                    :last-page="work.claims.lastPage"
                    :total="work.claims.total"
                    @change="(value) => turnPage('claims_page', value)"
                />
            </template>
        </section>
    </main>
</template>

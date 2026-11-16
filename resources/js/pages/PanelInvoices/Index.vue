<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';

type Row = {
    invoiceNumber: string;
    visitNumber: string;
    visitDate: string;
    visitTime: string;
    patientName: string;
    memberReference: string;
    panelName: string;
    doctorName: string;
    visitType: string;
    invoiceTotalSen: number;
    panelAmountSen: number;
    panelAdjustmentSen: number;
    claimNo: string;
    claimStatus: string;
};

const props = defineProps<{
    branch: { id: number; name: string };
    filters: {
        from: string;
        to: string;
        timeFrom: string;
        timeTo: string;
        panel: string;
        doctor: string;
        visitType: string;
        treatment: string;
    };
    panels: string[];
    can: {
        clinicalFilters: boolean;
        recordRemittances?: boolean;
        reversePayments?: boolean;
    };
    maxRows: number;
    downloadUrl: string | null;
    ready: boolean;
    rows: Row[];
    total: number;
    currentPage: number;
    lastPage: number;
    truncated: boolean;
}>();

const form = reactive({ ...props.filters });
const myr = (sen: number) =>
    `RM ${(sen / 100).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const adjustment = (sen: number) =>
    sen === 0 ? myr(0) : `${sen > 0 ? '+' : '−'}${myr(Math.abs(sen))}`;
// DD-98 (Daniel): "PCL-00000001 · Paid", not a bare "Paid · 1". Display only;
// the payload and the CSV keep the plain claim id.
const claimLabel = (row: Row) =>
    row.claimNo
        ? `PCL-${row.claimNo.padStart(8, '0')} · ${row.claimStatus}`
        : row.claimStatus;
const panelOptions = computed(() =>
    props.panels.map((name) => ({ value: name, label: name })),
);
const visitTypeOptions = [
    { value: '', label: 'All' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'otc', label: 'OTC' },
];

const query = (page = 1) => ({
    from: form.from || undefined,
    to: form.to || undefined,
    time_from: form.timeFrom || undefined,
    time_to: form.timeTo || undefined,
    panel: form.panel || undefined,
    doctor: form.doctor || undefined,
    visit_type: form.visitType || undefined,
    treatment: props.can.clinicalFilters
        ? form.treatment || undefined
        : undefined,
    page: page > 1 ? page : undefined,
});
const search = (page = 1) =>
    router.get('/panel-invoices', query(page), {
        preserveScroll: true,
        preserveState: true,
    });
const reset = () => {
    Object.assign(form, {
        from: '',
        to: '',
        timeFrom: '',
        timeTo: '',
        panel: '',
        doctor: '',
        visitType: '',
        treatment: '',
    });
    router.get('/panel-invoices');
};
const canDownload = computed(() => props.ready && props.total > 0);
</script>

<template>
    <Head title="Panel invoices" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Panel invoices"
            description="Invoices billed to a Panel at this branch. Choose a Panel and a date range, then download the list as a CSV file."
        />
        <div
            v-if="can.recordRemittances || can.reversePayments"
            class="flex flex-wrap gap-2"
        >
            <Button
                v-if="can.recordRemittances"
                as-child
                variant="secondary"
                size="sm"
            >
                <Link href="/panel-remittances">Record Panel payment</Link>
            </Button>
            <Button
                v-if="can.reversePayments"
                as-child
                variant="outline"
                size="sm"
            >
                <Link href="/panel-payment-corrections"
                    >Correct a Panel payment</Link
                >
            </Button>
        </div>

        <form
            class="grid grid-cols-1 gap-3 rounded-xl border bg-card p-4 md:grid-cols-4"
            @submit.prevent="search()"
        >
            <label class="grid min-w-0 gap-1 text-xs">
                Visit date from
                <DateField v-model="form.from" :echo="false" />
            </label>
            <label class="grid min-w-0 gap-1 text-xs">
                Visit date to
                <DateField v-model="form.to" :echo="false" />
            </label>
            <label class="grid min-w-0 gap-1 text-xs">
                Time from
                <Input v-model="form.timeFrom" type="time" />
            </label>
            <label class="grid min-w-0 gap-1 text-xs">
                Time to
                <Input v-model="form.timeTo" type="time" />
            </label>
            <label class="grid min-w-0 gap-1 text-xs md:col-span-2">
                Panel
                <OperationalSelect
                    v-model="form.panel"
                    :options="panelOptions"
                    label="Panel"
                    placeholder="Select a Panel"
                    trigger-class="h-9 w-full min-w-0"
                />
            </label>
            <label class="grid min-w-0 gap-1 text-xs">
                Doctor
                <Input v-model="form.doctor" maxlength="100" />
            </label>
            <label class="grid min-w-0 gap-1 text-xs">
                Visit type
                <OperationalSelect
                    v-model="form.visitType"
                    :options="visitTypeOptions"
                    label="Visit type"
                    placeholder="All"
                    trigger-class="h-9 w-full min-w-0"
                />
            </label>
            <label
                v-if="can.clinicalFilters"
                class="grid min-w-0 gap-1 text-xs"
            >
                Treatment
                <Input v-model="form.treatment" maxlength="100" />
            </label>
            <div class="flex flex-wrap items-end gap-2 md:col-span-4">
                <Button type="submit">Show invoices</Button>
                <Button type="button" variant="outline" @click="reset">
                    Reset filters
                </Button>
                <div class="ml-auto">
                    <Button
                        v-if="canDownload && downloadUrl"
                        as-child
                        variant="outline"
                    >
                        <a :href="downloadUrl" download>Download CSV</a>
                    </Button>
                    <Button v-else type="button" variant="outline" disabled
                        >Download CSV</Button
                    >
                </div>
            </div>
        </form>

        <div
            v-if="!ready"
            class="rounded-xl border border-dashed p-10 text-center"
            data-testid="panel-invoices-empty"
        >
            <p class="font-medium">
                Apply a date range and a Panel to display data
            </p>
            <p class="text-sm text-muted-foreground">
                Use the filters above, then press Show invoices.
            </p>
        </div>
        <template v-else>
            <p
                v-if="truncated"
                class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            >
                Only the first {{ maxRows.toLocaleString() }} invoices are shown
                and downloaded. Narrow the date range to see the rest.
            </p>
            <div
                v-if="rows.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No invoices match these filters.
            </div>
            <ul
                v-else
                class="grid gap-3 md:grid-cols-2 lg:hidden"
                data-testid="panel-invoice-cards"
            >
                <li
                    v-for="row in rows"
                    :key="row.invoiceNumber"
                    class="min-w-0 rounded-xl border bg-card p-4 text-sm"
                >
                    <div class="flex min-w-0 items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium wrap-anywhere">
                                {{ row.patientName }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.visitDate }} · {{ row.visitTime }}
                            </p>
                        </div>
                        <span class="shrink-0 font-mono text-xs">{{
                            row.invoiceNumber
                        }}</span>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Member reference
                            </dt>
                            <dd class="wrap-anywhere">
                                {{ row.memberReference || 'N/A' }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">Visit</dt>
                            <dd class="wrap-anywhere">
                                {{ row.visitNumber }} · {{ row.visitType }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Doctor
                            </dt>
                            <dd class="wrap-anywhere">
                                {{ row.doctorName || '—' }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">Claim</dt>
                            <dd class="wrap-anywhere">
                                {{ claimLabel(row) }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Invoice total
                            </dt>
                            <dd class="tabular-nums">
                                {{ myr(row.invoiceTotalSen) }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Panel amount
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ myr(row.panelAmountSen) }}
                            </dd>
                        </div>
                        <div class="col-span-2 min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Panel adjustment (included in Panel amount)
                            </dt>
                            <dd class="tabular-nums">
                                {{ adjustment(row.panelAdjustmentSen) }}
                            </dd>
                        </div>
                    </dl>
                </li>
            </ul>
            <div
                v-if="rows.length > 0"
                class="hidden overflow-x-auto rounded-xl border bg-card lg:block"
            >
                <table class="w-full min-w-[1000px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="p-3 font-medium">Invoice</th>
                            <th class="p-3 font-medium">Visit</th>
                            <th class="p-3 font-medium">Patient</th>
                            <th class="p-3 font-medium">Member reference</th>
                            <th class="p-3 font-medium">Doctor</th>
                            <th class="p-3 font-medium">Type</th>
                            <th class="p-3 text-right font-medium">
                                Invoice total
                            </th>
                            <th class="p-3 text-right font-medium">
                                Panel amount
                            </th>
                            <th class="p-3 text-right font-medium">
                                Panel adjustment (included in Panel amount)
                            </th>
                            <th class="p-3 font-medium">Claim</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.invoiceNumber"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-mono text-xs">
                                {{ row.invoiceNumber }}
                            </td>
                            <td class="p-3">
                                {{ row.visitDate }}
                                <div class="text-xs text-muted-foreground">
                                    {{ row.visitTime }} · {{ row.visitNumber }}
                                </div>
                            </td>
                            <td class="p-3">{{ row.patientName }}</td>
                            <td class="p-3">
                                {{ row.memberReference || 'N/A' }}
                            </td>
                            <td class="p-3">{{ row.doctorName || '—' }}</td>
                            <td class="p-3">{{ row.visitType }}</td>
                            <td class="p-3 text-right tabular-nums">
                                {{ myr(row.invoiceTotalSen) }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ myr(row.panelAmountSen) }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{
                                    row.panelAdjustmentSen === 0
                                        ? myr(0)
                                        : `${row.panelAdjustmentSen > 0 ? '+' : '−'}${myr(Math.abs(row.panelAdjustmentSen))}`
                                }}
                            </td>
                            <td class="p-3">{{ claimLabel(row) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">
                    {{ total.toLocaleString() }} invoice{{
                        total === 1 ? '' : 's'
                    }}
                </span>
                <div v-if="lastPage > 1" class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="currentPage <= 1"
                        @click="search(currentPage - 1)"
                    >
                        Previous
                    </Button>
                    <span>{{ currentPage }} / {{ lastPage }}</span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="currentPage >= lastPage"
                        @click="search(currentPage + 1)"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </template>
    </main>
</template>

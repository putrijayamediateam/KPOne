<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

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
// The claim status word is shown exactly as the server sends it; the badge only adds the dot and tone.
const statusKey = (status: string) => status.toLowerCase().replaceAll(' ', '_');
const canDownload = computed(() => props.ready && props.total > 0);
</script>

<template>
    <Head title="Panel invoices" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Panel invoices"
            description="Invoices billed to a Panel at this branch. Choose a Panel and a date range, then download the list as a CSV file."
        >
            <template
                v-if="can.recordRemittances || can.reversePayments"
                #actions
            >
                <Button
                    v-if="can.recordRemittances"
                    as-child
                    variant="outline"
                    class="max-lg:h-11 max-sm:w-full"
                >
                    <Link href="/panel-remittances">Record Panel payment</Link>
                </Button>
                <Button
                    v-if="can.reversePayments"
                    as-child
                    variant="outline"
                    class="max-lg:h-11 max-sm:w-full"
                >
                    <Link href="/panel-payment-corrections"
                        >Correct a Panel payment</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <form
            class="grid grid-cols-1 gap-3 rounded-xl border bg-card p-4 md:grid-cols-4"
            aria-label="Panel invoice filters"
            @submit.prevent="search()"
        >
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground md:col-span-2"
            >
                Panel
                <select
                    v-model="form.panel"
                    class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 max-lg:text-base"
                    aria-label="Panel"
                >
                    <option value="">Select a Panel</option>
                    <option v-for="name in panels" :key="name" :value="name">
                        {{ name }}
                    </option>
                </select>
            </label>
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Doctor
                <Input
                    v-model="form.doctor"
                    maxlength="100"
                    class="rounded-lg max-lg:h-11 max-lg:text-base"
                />
            </label>
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Visit type
                <select
                    v-model="form.visitType"
                    class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 max-lg:text-base"
                    aria-label="Visit type"
                >
                    <option value="">All</option>
                    <option value="consultation">Consultation</option>
                    <option value="otc">OTC</option>
                </select>
            </label>
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Visit date from
                <DateField v-model="form.from" :echo="false" />
            </label>
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Visit date to
                <DateField v-model="form.to" :echo="false" />
            </label>
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Time from
                <Input
                    v-model="form.timeFrom"
                    type="time"
                    class="rounded-lg max-lg:h-11 max-lg:text-base"
                />
            </label>
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Time to
                <Input
                    v-model="form.timeTo"
                    type="time"
                    class="rounded-lg max-lg:h-11 max-lg:text-base"
                />
            </label>
            <label
                v-if="can.clinicalFilters"
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground"
            >
                Treatment
                <Input
                    v-model="form.treatment"
                    maxlength="100"
                    class="rounded-lg max-lg:h-11 max-lg:text-base"
                />
            </label>
            <div class="flex flex-wrap items-end gap-2 md:col-span-4">
                <Button type="submit" class="max-lg:h-11 max-sm:flex-1"
                    >Show invoices</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="max-lg:h-11 max-sm:flex-1"
                    @click="reset"
                >
                    Reset filters
                </Button>
            </div>
        </form>

        <EmptyState
            v-if="!ready"
            title="Apply a date range and a Panel to display data"
            description="Use the filters above, then press Show invoices."
            data-testid="panel-invoices-empty"
        />
        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm text-muted-foreground tabular-nums">
                    {{ total.toLocaleString() }} invoice{{
                        total === 1 ? '' : 's'
                    }}
                </span>
                <Button
                    v-if="canDownload && downloadUrl"
                    as-child
                    variant="outline"
                    class="max-lg:h-11"
                >
                    <a :href="downloadUrl" download
                        ><Download class="size-4" aria-hidden="true" />Download
                        CSV</a
                    >
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    class="max-lg:h-11"
                    disabled
                >
                    <Download class="size-4" aria-hidden="true" />Download CSV
                </Button>
            </div>
            <p
                v-if="truncated"
                class="flex items-start gap-2 rounded-xl border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong"
                role="note"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span
                    >Only the first {{ maxRows.toLocaleString() }} invoices are
                    shown and downloaded. Narrow the date range to see the
                    rest.</span
                >
            </p>
            <EmptyState
                v-if="rows.length === 0"
                title="No invoices match these filters."
            />
            <template v-else>
                <!-- Phones and tablets: one card per invoice. -->
                <ul
                    class="divide-y rounded-xl border bg-card lg:hidden"
                    aria-label="Panel invoices"
                    data-testid="panel-invoices-cards"
                >
                    <li
                        v-for="row in rows"
                        :key="row.invoiceNumber"
                        class="grid gap-2 p-4 text-sm"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p
                                class="min-w-0 font-mono text-xs font-medium break-all"
                            >
                                {{ row.invoiceNumber }}
                            </p>
                            <StatusBadge
                                :status="statusKey(row.claimStatus)"
                                :label="row.claimStatus"
                                class="shrink-0"
                            />
                        </div>
                        <p class="font-medium wrap-anywhere">
                            {{ row.patientName }}
                        </p>
                        <p class="text-xs wrap-anywhere text-muted-foreground">
                            {{
                                [
                                    row.memberReference || 'N/A',
                                    row.doctorName,
                                    row.visitType,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{ row.visitDate }} · {{ row.visitTime }} ·
                            {{ row.visitNumber }}
                        </p>
                        <p
                            v-if="row.claimNo"
                            class="font-mono text-xs text-muted-foreground"
                        >
                            Claim {{ row.claimNo }}
                        </p>
                        <dl
                            class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 border-t pt-2 tabular-nums"
                        >
                            <dt class="text-muted-foreground">Invoice total</dt>
                            <dd class="text-right">
                                {{ myr(row.invoiceTotalSen) }}
                            </dd>
                            <dt class="text-muted-foreground">Panel amount</dt>
                            <dd class="text-right font-semibold">
                                {{ myr(row.panelAmountSen) }}
                            </dd>
                        </dl>
                    </li>
                </ul>
                <OperationalTable
                    class="hidden lg:block"
                    label="Panel invoices"
                    :columns="9"
                    min-width="960px"
                    data-testid="panel-invoices-table"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Invoice</th>
                            <th scope="col">Visit</th>
                            <th scope="col">Patient</th>
                            <th scope="col">Member reference</th>
                            <th scope="col">Doctor</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-right">
                                Invoice total
                            </th>
                            <th scope="col" class="text-right">Panel amount</th>
                            <th scope="col">Claim</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in rows" :key="row.invoiceNumber">
                            <td class="font-mono text-xs whitespace-nowrap">
                                {{ row.invoiceNumber }}
                            </td>
                            <td>
                                <span class="whitespace-nowrap">{{
                                    row.visitDate
                                }}</span>
                                <div
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ row.visitTime }} · {{ row.visitNumber }}
                                </div>
                            </td>
                            <td>{{ row.patientName }}</td>
                            <td class="text-xs wrap-anywhere">
                                {{ row.memberReference || 'N/A' }}
                            </td>
                            <td>{{ row.doctorName || '—' }}</td>
                            <td>{{ row.visitType }}</td>
                            <td
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.invoiceTotalSen) }}
                            </td>
                            <td
                                class="text-right font-medium whitespace-nowrap tabular-nums"
                            >
                                {{ myr(row.panelAmountSen) }}
                            </td>
                            <td>
                                <StatusBadge
                                    :status="statusKey(row.claimStatus)"
                                    :label="row.claimStatus"
                                />
                                <div
                                    v-if="row.claimNo"
                                    class="mt-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{ row.claimNo }}
                                </div>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </template>
            <CompactPagination
                v-if="lastPage > 1"
                class="justify-end"
                :current-page="currentPage"
                :last-page="lastPage"
                @change="search"
            />
        </template>
    </main>
</template>

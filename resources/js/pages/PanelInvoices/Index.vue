<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

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
    can: { clinicalFilters: boolean };
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

        <form
            class="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-4"
            @submit.prevent="search()"
        >
            <label class="grid gap-1 text-xs">
                Visit date from
                <Input v-model="form.from" type="date" />
            </label>
            <label class="grid gap-1 text-xs">
                Visit date to
                <Input v-model="form.to" type="date" />
            </label>
            <label class="grid gap-1 text-xs">
                Time from
                <Input v-model="form.timeFrom" type="time" />
            </label>
            <label class="grid gap-1 text-xs">
                Time to
                <Input v-model="form.timeTo" type="time" />
            </label>
            <label class="grid gap-1 text-xs md:col-span-2">
                Panel
                <select
                    v-model="form.panel"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                    aria-label="Panel"
                >
                    <option value="">Select a Panel</option>
                    <option v-for="name in panels" :key="name" :value="name">
                        {{ name }}
                    </option>
                </select>
            </label>
            <label class="grid gap-1 text-xs">
                Doctor
                <Input v-model="form.doctor" maxlength="100" />
            </label>
            <label class="grid gap-1 text-xs">
                Visit type
                <select
                    v-model="form.visitType"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                    aria-label="Visit type"
                >
                    <option value="">All</option>
                    <option value="consultation">Consultation</option>
                    <option value="otc">OTC</option>
                </select>
            </label>
            <label v-if="can.clinicalFilters" class="grid gap-1 text-xs">
                Treatment
                <Input v-model="form.treatment" maxlength="100" />
            </label>
            <div class="flex items-end gap-2 md:col-span-4">
                <Button type="submit">Show invoices</Button>
                <Button type="button" variant="outline" @click="reset">
                    Reset filters
                </Button>
                <div class="ml-auto">
                    <Button v-if="canDownload && downloadUrl" as-child>
                        <a :href="downloadUrl" download>Download CSV</a>
                    </Button>
                    <Button v-else type="button" disabled>Download CSV</Button>
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
            <div v-else class="overflow-x-auto rounded-xl border bg-card">
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
                            <td class="p-3 text-right">
                                {{ myr(row.invoiceTotalSen) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ myr(row.panelAmountSen) }}
                            </td>
                            <td class="p-3">
                                {{ row.claimStatus
                                }}<span v-if="row.claimNo">
                                    · {{ row.claimNo }}</span
                                >
                            </td>
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

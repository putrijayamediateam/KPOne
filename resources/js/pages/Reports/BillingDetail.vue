<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    PopoverRoot,
    PopoverTrigger,
    PopoverPortal,
    PopoverContent,
} from 'reka-ui';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import MultiSelectFilterChip from '@/components/reports/MultiSelectFilterChip.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/presentation';

type Method = { id: number; code: string; name: string; amountSen: number };
type Filters = {
    expected_branch_id: number;
    from: string;
    to: string;
    time_from: string;
    time_to: string;
    doctors: string[];
    methods: string[];
    visit_types: string[];
    page: number;
};
type Row = {
    number: number;
    finalizedAt: string;
    patientNumber: string;
    patientName: string;
    visitNumber: string;
    invoiceNumber: string;
    netSen: number;
    receivedSen: number;
    panelSen: number;
    deferredSen: number;
    dueSen: number;
    methods: Method[];
};
type Result = {
    generatedAt: string;
    count: number;
    page: number;
    pages: number;
    totals: Record<string, number>;
    methods: Method[];
    rows: Row[];
};
type Payload = {
    branch: { id: number; name: string; timezone: string };
    options: Record<
        'doctors' | 'methods' | 'visit_types',
        { value: string; label: string }[]
    >;
    filters: Filters;
    canExport: boolean;
    basis: string;
    result: Result | null;
};
const props = defineProps<Payload>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clinic management', href: '/clinic-management' },
            { title: 'Billing Detail', href: '/reports/billing-detail' },
        ],
    },
});
const page = usePage();
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const draft = ref(clone(props.filters));
const applied = ref(clone(props.filters));
const data = ref<Result | null>(null);
const error = ref('');
const busy = ref(false);
const pending = ref<Record<string, boolean>>({});
const timeOpen = ref(false);
const times = ref({ from: props.filters.time_from, to: props.filters.time_to });
const alive = ref(true);
let request: AbortController | null = null;
const dirty = computed(
    () =>
        JSON.stringify({ ...draft.value, page: 1 }) !==
            JSON.stringify({ ...applied.value, page: 1 }) ||
        Object.values(pending.value).some(Boolean) ||
        (timeOpen.value &&
            (times.value.from !== draft.value.time_from ||
                times.value.to !== draft.value.time_to)),
);
const columns = [
    { key: 'netSen', label: 'Net invoice' },
    { key: 'receivedSen', label: 'Net patient receipts' },
    { key: 'panelSen', label: 'Approved Panel' },
    { key: 'deferredSen', label: 'Pay-later outstanding' },
    { key: 'dueSen', label: 'Due now' },
] as const;
const money = (sen: number) =>
    `${sen < 0 ? '-' : ''}${Math.floor(Math.abs(sen) / 100)}.${String(Math.abs(sen) % 100).padStart(2, '0')}`;
const headers = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN':
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '',
});
function clear() {
    request?.abort();
    data.value = null;
    error.value = '';
    pending.value = {};
    timeOpen.value = false;
    busy.value = false;
    draft.value = clone(props.filters);
    applied.value = clone(props.filters);
    times.value = { from: props.filters.time_from, to: props.filters.time_to };
}
watch(() => props.branch.id, clear);
watch(() => page.props.branchContext?.active?.id, clear);
onBeforeUnmount(() => {
    alive.value = false;
    request?.abort();
    data.value = null;
});
async function perform(download = false, targetPage = 1) {
    if (
        busy.value ||
        (download && (dirty.value || !data.value || !props.canExport))
    ) {
        return;
    }

    busy.value = true;
    error.value = '';
    request = new AbortController();
    const branchId = props.branch.id;
    const filters = {
        ...clone(download || targetPage !== 1 ? applied.value : draft.value),
        page: targetPage,
    };

    try {
        const response = await fetch(
            `/reports/billing-detail/${download ? 'download' : 'run'}`,
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: headers(),
                body: JSON.stringify(filters),
                signal: request.signal,
            },
        );

        if (!alive.value || branchId !== props.branch.id) {
            return;
        }

        if (!response.ok) {
            const payload = await response.json().catch(() => null);

            throw new Error(
                payload?.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : response.status === 429
                      ? 'Too many requests. Try again shortly.'
                      : response.status === 403
                        ? 'Report access is no longer available. Reload the page.'
                        : 'Unable to prepare the report. Try again.',
            );
        }

        if (download) {
            const blob = await response.blob();

            if (!alive.value || branchId !== props.branch.id) {
                return;
            }

            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `billing-detail_${filters.from}_${filters.to}.csv`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } else {
            const payload: Payload = await response.json();

            if (!alive.value || branchId !== props.branch.id) {
                return;
            }

            data.value = payload.result;
            applied.value = clone(payload.filters);
            draft.value = clone(payload.filters);
        }
    } catch (reason) {
        if (
            reason instanceof Error &&
            reason.name !== 'AbortError' &&
            alive.value &&
            branchId === props.branch.id
        ) {
            error.value = reason.message;

            if (!download) {
                data.value = null;
            }
        }
    } finally {
        if (alive.value && branchId === props.branch.id) {
            busy.value = false;
        }
    }
}
function applySelection(
    key: 'doctors' | 'methods' | 'visit_types',
    value: string[],
) {
    draft.value[key] = value;

    if (data.value) {
        void perform();
    }
}
function applyTime() {
    draft.value.time_from = times.value.from;
    draft.value.time_to = times.value.to;
    timeOpen.value = false;

    if (data.value) {
        void perform();
    }
}
</script>
<template>
    <Head title="Billing Detail" />
    <main class="mx-auto w-full max-w-[1440px] min-w-0 space-y-5 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Billing Detail</h1>
                <p class="text-sm text-muted-foreground">
                    {{ branch.name }} · {{ branch.timezone }} · MYR
                </p>
            </div>
            <Button
                v-if="canExport"
                :disabled="busy || !data || dirty"
                @click="perform(true)"
                >Download CSV</Button
            >
        </header>
        <p class="max-w-5xl text-sm text-muted-foreground">{{ basis }}</p>
        <section
            aria-label="Report filters"
            class="space-y-3 rounded-lg border bg-card p-4"
        >
            <div class="flex flex-wrap items-end gap-3">
                <label class="min-w-0 text-sm"
                    >From<Input
                        v-model="draft.from"
                        type="date"
                        :disabled="busy" /></label
                ><label class="min-w-0 text-sm"
                    >To<Input
                        v-model="draft.to"
                        type="date"
                        :disabled="busy" /></label
                ><Button :disabled="busy" @click="perform()">{{
                    busy ? 'Preparing…' : 'Run report'
                }}</Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Maximum 31 local dates. Change branch using the workspace
                selector. Download uses applied filters and current records at
                its own generation time.
            </p>
            <div class="flex flex-wrap gap-2">
                <MultiSelectFilterChip
                    label="Doctor"
                    :model-value="draft.doctors"
                    :options="options.doctors"
                    :disabled="busy"
                    @update:model-value="applySelection('doctors', $event)"
                    @pending="pending.doctors = $event"
                />
                <MultiSelectFilterChip
                    label="Payment method"
                    :model-value="draft.methods"
                    :options="options.methods"
                    :disabled="busy"
                    @update:model-value="applySelection('methods', $event)"
                    @pending="pending.methods = $event"
                />
                <MultiSelectFilterChip
                    label="Visit type"
                    :model-value="draft.visit_types"
                    :options="options.visit_types"
                    :disabled="busy"
                    @update:model-value="applySelection('visit_types', $event)"
                    @pending="pending.visit_types = $event"
                />
                <PopoverRoot
                    v-model:open="timeOpen"
                    @update:open="
                        times = { from: draft.time_from, to: draft.time_to }
                    "
                    ><PopoverTrigger as-child
                        ><Button
                            variant="outline"
                            size="sm"
                            :disabled="busy"
                            class="rounded-full border-dashed"
                            >Time · {{ draft.time_from }}–{{
                                draft.time_to
                            }}</Button
                        ></PopoverTrigger
                    ><PopoverPortal
                        ><PopoverContent
                            class="z-50 w-64 rounded-lg border bg-popover p-4 text-popover-foreground shadow-lg"
                            :side-offset="8"
                            align="start"
                            ><p class="mb-3 text-sm">
                                Local time · {{ branch.timezone }}
                            </p>
                            <label class="text-sm"
                                >From<Input
                                    v-model="times.from"
                                    type="time" /></label
                            ><label class="text-sm"
                                >To<Input v-model="times.to" type="time"
                            /></label>
                            <div class="mt-3 flex justify-between">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="timeOpen = false"
                                    >Cancel</Button
                                ><Button size="sm" @click="applyTime"
                                    >Apply</Button
                                >
                            </div></PopoverContent
                        ></PopoverPortal
                    ></PopoverRoot
                >
            </div>
            <p v-if="dirty" class="text-sm text-muted-foreground">
                Unapplied changes. Apply selections or run the report before
                downloading.
            </p>
        </section>
        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive p-3 text-sm text-destructive"
        >
            {{ error }}
        </p>
        <template v-if="data">
            <div class="space-y-1 text-sm">
                <p>
                    {{ data.count }}
                    {{ data.count === 1 ? 'invoice' : 'invoices' }} · Generated
                    <time :datetime="data.generatedAt">{{
                        formatDateTime(data.generatedAt, branch.timezone)
                    }}</time>
                    ({{ branch.timezone }})
                </p>
                <p class="text-muted-foreground">
                    Results and totals use the applied filters, across every
                    page. A later download can differ if records change.
                </p>
            </div>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="rounded-lg border bg-card p-3"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ column.label }} (MYR)
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums">
                        {{ money(data.totals[column.key]) }}
                    </dd>
                </div>
            </dl>
            <section
                aria-label="Filtered payment method totals"
                class="rounded-lg border bg-card p-3"
            >
                <h2 class="mb-2 text-sm font-medium">
                    Net allocated receipts by method (MYR)
                </h2>
                <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <li
                        v-for="method in data.methods"
                        :key="method.id"
                        class="break-words"
                    >
                        {{ method.name }}:
                        <strong class="tabular-nums">{{
                            money(method.amountSen)
                        }}</strong>
                    </li>
                    <li v-if="!data.methods.length">No linked receipts.</li>
                </ul>
            </section>
            <div
                class="max-w-full overflow-x-auto rounded-lg border"
                tabindex="0"
                aria-label="Billing Detail table; scroll horizontally for settlement columns"
            >
                <table class="w-full min-w-[1200px] text-left text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="p-3">#</th>
                            <th class="p-3">Local finalisation</th>
                            <th class="p-3">Patient</th>
                            <th class="p-3">Visit / Invoice</th>
                            <th class="p-3">Payment methods (net MYR)</th>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                class="p-3 text-right"
                            >
                                {{ column.label }} (MYR)
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-card">
                        <tr
                            v-for="row in data.rows"
                            :key="row.invoiceNumber"
                            class="border-t"
                        >
                            <td class="p-3">{{ row.number }}</td>
                            <td class="p-3 whitespace-nowrap">
                                {{ row.finalizedAt }}
                            </td>
                            <td class="p-3">
                                <span class="block font-medium">{{
                                    row.patientName
                                }}</span
                                ><span class="text-xs text-muted-foreground">{{
                                    row.patientNumber
                                }}</span>
                            </td>
                            <td class="p-3">
                                <span class="block">{{
                                    row.invoiceNumber
                                }}</span
                                ><span class="text-xs text-muted-foreground">{{
                                    row.visitNumber
                                }}</span>
                            </td>
                            <td class="p-3">
                                <p
                                    v-for="method in row.methods"
                                    :key="method.id"
                                >
                                    {{ method.name }}:
                                    {{ money(method.amountSen) }}
                                </p>
                                <span v-if="!row.methods.length">—</span>
                            </td>
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="p-3 text-right tabular-nums"
                            >
                                {{ money(row[column.key]) }}
                            </td>
                        </tr>
                        <tr v-if="!data.rows.length">
                            <td
                                colspan="10"
                                class="p-6 text-center text-muted-foreground"
                            >
                                No finalised invoices match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                aria-label="Report pages"
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <Button
                    variant="outline"
                    :disabled="busy || dirty || data.page <= 1"
                    @click="perform(false, data.page - 1)"
                    >Previous</Button
                ><span class="text-sm"
                    >Page {{ data.page }} of {{ data.pages }}</span
                ><Button
                    variant="outline"
                    :disabled="busy || dirty || data.page >= data.pages"
                    @click="perform(false, data.page + 1)"
                    >Next</Button
                >
            </nav>
        </template>
        <p
            v-else-if="!busy && !error"
            class="rounded-lg border p-6 text-center text-muted-foreground"
        >
            Choose a period and run the report. Patient rows are not loaded
            automatically.
        </p>
    </main>
</template>

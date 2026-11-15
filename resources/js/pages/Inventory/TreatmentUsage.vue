<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { ActionLink } from '@/components/ui/action-link';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/ui/page-header';
import { formatDate } from '@/lib/presentation';
import type { ScannedSku } from '@/lib/scan';
import {
    firstReason,
    ineligibleBatchLabels,
    usageKindLabels,
    usageReasonLabels,
    usageReversalLabels,
} from '@/lib/treatment-usage';
import type { UsageKind } from '@/lib/treatment-usage';

type StockRow = {
    id: number;
    location_id: number;
    location: string;
    sku_code: string;
    stock_unit: string;
    item_name: string;
    batch_id: number;
    batch_number: string;
    expiry_date: string;
    eligibility_reason: string | null;
    on_hand: string;
    usable: boolean;
};
type EntryRow = {
    id: number;
    usage_number: string;
    kind: UsageKind;
    reason: string;
    quantity: string;
    base_unit: string;
    recorder_user_id: number;
    recorded_by_name?: string | null;
    sku_code: string;
    item_name: string;
    batch_number: string;
    expiry_date: string;
    location: string;
    room: string | null;
    reversal_id: number | null;
    reversal_reason: string | null;
    reversed_by: number | null;
    reversed_by_name?: string | null;
    recordedAt: string;
    reversedAt: string | null;
    recordedByYou: boolean;
    canReverse: boolean;
};
type Page<T> = { rows: T[]; total: number; page: number; lastPage: number };
const props = defineProps<{
    usage: {
        branchId: number;
        branchName: string;
        q: string;
        stockQ: string;
        canRecord: boolean;
        canReverse: boolean;
        reasons: Record<string, string[]>;
        reversalReasons: string[];
        rooms: { id: number; name: string }[];
        entries: Page<EntryRow>;
        stock: Page<StockRow>;
    };
}>();

const q = ref(props.usage.q);
const stockQ = ref(props.usage.stockQ);
const open = ref(false);
const label = ref('');
const endpoint = ref('');
const operation = ref<'record' | 'reverse'>('record');
const form = useForm({
    expected_branch_id: props.usage.branchId,
    retry_key: '',
    location_id: 0,
    batch_id: 0,
    unit: '',
    quantity: '',
    kind: 'usage' as UsageKind,
    reason: firstReason(props.usage.reasons, 'usage'),
    room_id: '' as number | '',
    reversal_reason: 'recorded_in_error',
});
// Server field names (state, retry_key) are not all form fields.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

watch(
    () => form.kind,
    (kind) => {
        form.reason = firstReason(props.usage.reasons, kind);
    },
);

function scanned(sku: ScannedSku) {
    stockQ.value = sku.sku_code;
    search(1, 1);
}
function search(page = 1, stockPage = 1) {
    router.get(
        '/inventory/treatment-usage',
        { q: q.value, stock_q: stockQ.value, page, stock_page: stockPage },
        { preserveScroll: true },
    );
}
function showRecord(row: StockRow) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.usage.branchId;
    form.retry_key = crypto.randomUUID();
    form.location_id = row.location_id;
    form.batch_id = row.batch_id;
    form.unit = row.stock_unit;
    form.kind = 'usage';
    form.reason = firstReason(props.usage.reasons, form.kind);
    label.value = `${row.item_name} · ${row.sku_code} · batch ${row.batch_number} (expires ${formatDate(row.expiry_date)}) · ${row.location} · on hand ${row.on_hand} ${row.stock_unit}`;
    operation.value = 'record';
    endpoint.value = '/inventory/treatment-usage';
    open.value = true;
}
function showReverse(row: EntryRow) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.usage.branchId;
    form.retry_key = crypto.randomUUID();
    label.value = `${row.usage_number} · ${row.quantity} ${row.base_unit} · ${row.item_name} · batch ${row.batch_number} · ${row.location}`;
    operation.value = 'reverse';
    endpoint.value = `/inventory/treatment-usage/${row.id}/reverse`;
    open.value = true;
}
function submit() {
    form.transform((data) =>
        operation.value === 'record'
            ? {
                  expected_branch_id: data.expected_branch_id,
                  retry_key: data.retry_key,
                  location_id: data.location_id,
                  batch_id: data.batch_id,
                  unit: data.unit,
                  quantity: data.quantity,
                  kind: data.kind,
                  reason: data.reason,
                  room_id: data.room_id === '' ? null : data.room_id,
              }
            : {
                  expected_branch_id: data.expected_branch_id,
                  retry_key: data.retry_key,
                  reason: data.reversal_reason,
              },
    ).post(endpoint.value, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
const page = usePage();
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        open.value = false;
    },
);
</script>

<template>
    <Head title="Treatment room usage" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Treatment room usage"
            :description="usage.branchName"
        />
        <p class="text-sm text-muted-foreground">
            Record stock used in treatment (for example a dressing, a syringe or
            nebuliser solution) or wasted, outside dispensing. Each entry takes
            the stock out straight away. It is not linked to a patient and
            creates no bill. Do not enter patient details. If an entry is wrong,
            a CA Supervisor, Finance Officer or Director who did not record it
            can reverse it once, which puts the stock back.
        </p>

        <section
            v-if="usage.canRecord"
            class="space-y-3"
            aria-labelledby="stock-heading"
        >
            <h2 id="stock-heading" class="text-lg font-semibold">
                Stock on hand ({{ usage.stock.total }})
            </h2>
            <ScanInput
                context="search"
                refocus-key="treatment-usage"
                @found="scanned"
            />
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="search(1, 1)"
            >
                <label class="min-w-[min(100%,18rem)] flex-1 basis-72"
                    >Search item, SKU or batch<input
                        v-model="stockQ"
                        maxlength="100"
                        type="search"
                        class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                /></label>
                <button
                    type="submit"
                    class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                >
                    Search stock
                </button>
            </form>
            <p
                v-if="usage.stock.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No stock on hand matches.
            </p>
            <article
                v-for="row in usage.stock.rows"
                :key="row.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
            >
                <div class="min-w-0 flex-1 basis-64 text-sm">
                    <p class="font-medium break-words">
                        {{ row.item_name }} · {{ row.sku_code }}
                    </p>
                    <p class="break-words text-muted-foreground">
                        batch {{ row.batch_number }} (expires
                        <span class="whitespace-nowrap">{{
                            formatDate(row.expiry_date)
                        }}</span
                        >) · {{ row.location }} · on hand {{ row.on_hand }}
                        {{ row.stock_unit }}
                        <span
                            v-if="!row.usable"
                            class="ml-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium whitespace-nowrap text-foreground"
                            >{{
                                ineligibleBatchLabels[
                                    row.eligibility_reason ?? ''
                                ] ?? 'not usable'
                            }}</span
                        >
                    </p>
                </div>
                <button
                    v-if="row.usable"
                    class="min-h-11 rounded border px-3"
                    @click="showRecord(row)"
                >
                    Record use or waste
                </button>
                <a
                    v-else
                    href="/inventory/writeoffs"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Use the governed Write-off</a
                >
            </article>
            <div v-if="usage.stock.lastPage > 1" class="flex gap-2">
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="usage.stock.page <= 1"
                    @click="search(usage.entries.page, usage.stock.page - 1)"
                >
                    Previous
                </button>
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="usage.stock.page >= usage.stock.lastPage"
                    @click="search(usage.entries.page, usage.stock.page + 1)"
                >
                    Next
                </button>
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="entries-heading">
            <h2 id="entries-heading" class="text-lg font-semibold">
                Entries ({{ usage.entries.total }})
            </h2>
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="search(1, usage.stock.page)"
            >
                <label class="min-w-[min(100%,18rem)] flex-1 basis-72"
                    >Search item, SKU, batch or entry number<input
                        v-model="q"
                        maxlength="100"
                        type="search"
                        class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                /></label>
                <button
                    type="submit"
                    class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                >
                    Search entries
                </button>
            </form>
            <p
                v-if="usage.entries.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No treatment room usage recorded yet.
            </p>
            <article
                v-for="row in usage.entries.rows"
                :key="row.id"
                class="space-y-2 rounded-lg border p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-mono text-sm">{{
                        row.usage_number
                    }}</span>
                    <span
                        class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium"
                        >{{
                            row.reversal_id
                                ? 'Reversed'
                                : (usageKindLabels[row.kind] ?? row.kind)
                        }}</span
                    >
                </div>
                <p class="text-sm break-words">
                    {{ row.quantity }} {{ row.base_unit }} ·
                    {{ row.item_name }} · {{ row.sku_code }} · batch
                    {{ row.batch_number }} · {{ row.location }}
                    <span v-if="row.room"> · {{ row.room }}</span>
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ usageKindLabels[row.kind] ?? row.kind }}:
                    {{ usageReasonLabels[row.reason] ?? row.reason }} · recorded
                    {{ row.recordedAt }} by
                    {{ row.recorded_by_name ?? 'Staff member' }}
                </p>
                <p v-if="row.reversal_id" class="text-sm text-muted-foreground">
                    Reversed {{ row.reversedAt }} by
                    {{ row.reversed_by_name ?? 'Staff member' }}:
                    {{
                        usageReversalLabels[row.reversal_reason ?? ''] ??
                        row.reversal_reason
                    }}. The stock was put back.
                </p>
                <p
                    v-else-if="usage.canReverse && row.recordedByYou"
                    class="text-xs text-muted-foreground"
                >
                    You recorded this entry, so a different CA Supervisor,
                    Finance Officer or Director must reverse it.
                </p>
                <button
                    v-if="row.canReverse"
                    class="min-h-11 rounded border px-3"
                    @click="showReverse(row)"
                >
                    Reverse
                </button>
            </article>
            <div v-if="usage.entries.lastPage > 1" class="flex gap-2">
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="usage.entries.page <= 1"
                    @click="search(usage.entries.page - 1, usage.stock.page)"
                >
                    Previous
                </button>
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="usage.entries.page >= usage.entries.lastPage"
                    @click="search(usage.entries.page + 1, usage.stock.page)"
                >
                    Next
                </button>
            </div>
        </section>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{
                        operation === 'record'
                            ? 'Record treatment room use or waste'
                            : 'Reverse entry'
                    }}</DialogTitle>
                    <DialogDescription>{{ label }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="submit">
                    <template v-if="operation === 'record'">
                        <fieldset class="space-y-1 text-sm">
                            <legend>What happened?</legend>
                            <label
                                v-for="kind in [
                                    'usage',
                                    'waste',
                                ] as UsageKind[]"
                                :key="kind"
                                class="mr-4 inline-flex min-h-11 items-center gap-2"
                                ><input
                                    v-model="form.kind"
                                    type="radio"
                                    name="kind"
                                    :value="kind"
                                />{{ usageKindLabels[kind] }}</label
                            >
                        </fieldset>
                        <InputError :message="form.errors.kind" />
                        <label class="block text-sm"
                            >Reason<select
                                v-model="form.reason"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                            >
                                <option
                                    v-for="reason in usage.reasons[form.kind] ??
                                    []"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{ usageReasonLabels[reason] ?? reason }}
                                </option>
                            </select></label
                        >
                        <InputError :message="form.errors.reason" />
                        <label class="block text-sm"
                            >Quantity ({{ form.unit }})<input
                                v-model="form.quantity"
                                inputmode="decimal"
                                required
                                class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                        /></label>
                        <InputError
                            :message="form.errors.quantity ?? form.errors.unit"
                        />
                        <label
                            v-if="usage.rooms.length > 0"
                            class="block text-sm"
                            >Treatment room (optional)<select
                                v-model="form.room_id"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                            >
                                <option value="">Not recorded</option>
                                <option
                                    v-for="room in usage.rooms"
                                    :key="room.id"
                                    :value="room.id"
                                >
                                    {{ room.name }}
                                </option>
                            </select></label
                        >
                        <InputError :message="form.errors.room_id" />
                        <InputError
                            :message="
                                form.errors.batch_id ?? form.errors.location_id
                            "
                        />
                    </template>
                    <template v-else>
                        <label class="block text-sm"
                            >Why are you reversing it?<select
                                v-model="form.reversal_reason"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                            >
                                <option
                                    v-for="reason in usage.reversalReasons"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{ usageReversalLabels[reason] ?? reason }}
                                </option>
                            </select></label
                        >
                        <InputError :message="errors.reason" />
                    </template>
                    <InputError
                        :message="
                            errors.state ??
                            errors.retry_key ??
                            form.errors.expected_branch_id
                        "
                    />
                    <button
                        type="submit"
                        class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                        :disabled="form.processing"
                    >
                        {{
                            operation === 'record'
                                ? 'Record and take out of stock'
                                : 'Reverse and put stock back'
                        }}
                    </button>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>

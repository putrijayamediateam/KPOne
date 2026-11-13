<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate } from '@/lib/presentation';
import type { ScannedSku } from '@/lib/scan';
import { trimQuantity } from '@/lib/supplier-terms';
import {
    formatTransferThousandths,
    transferQuantityThousandths,
} from '@/lib/transfer-receipt';
import {
    firstReason,
    ineligibleBatchLabels,
    usageKindLabels,
    usageReasonLabels,
    usageReversalLabels,
} from '@/lib/treatment-usage';
import type { UsageKind } from '@/lib/treatment-usage';

/**
 * Inventory V2 page 4: Treatment room usage (STK-8). Shown only in V2; Classic is `pages/Inventory/TreatmentUsage.vue`,
 * untouched. Same props, routes, payloads and permissions: the script from `type StockRow` to the branch watch is a
 * verbatim copy of Classic (a test compares them). Only the layout and the plain sentences differ.
 */
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

// ---- V2 only -------------------------------------------------------------------------------------------------------
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Treatment room usage' },
]);

/** The row the open sheet is about, kept for its words. */
const activeStock = ref<StockRow | null>(null);
const activeEntry = ref<EntryRow | null>(null);
const openRecord = (row: StockRow) => {
    activeEntry.value = null;
    activeStock.value = row;
    showRecord(row);
};
const openReverse = (row: EntryRow) => {
    activeStock.value = null;
    activeEntry.value = row;
    showReverse(row);
};
const closeSheet = (value: boolean) => {
    open.value = value;
};

const kindOptions = [
    { value: 'usage', label: usageKindLabels.usage },
    { value: 'waste', label: usageKindLabels.waste },
];
const reasonOptions = computed(() =>
    (props.usage.reasons[form.kind] ?? []).map((reason) => ({
        value: reason,
        label: usageReasonLabels[reason] ?? reason,
    })),
);
const roomOptions = computed(() => [
    { value: '', label: 'No room' },
    ...props.usage.rooms.map((room) => ({
        value: String(room.id),
        label: room.name,
    })),
]);
const reversalOptions = computed(() =>
    props.usage.reversalReasons.map((reason) => ({
        value: reason,
        label: usageReversalLabels[reason] ?? reason,
    })),
);
const roomName = computed(
    () =>
        props.usage.rooms.find((room) => room.id === form.room_id)?.name ??
        null,
);

const f = (value: bigint) => trimQuantity(formatTransferThousandths(value));
/** Record: never more than is on hand (Daniel's page 3 rule, kept for page 4). */
const overOnHand = computed(() => {
    const row = activeStock.value;

    if (operation.value !== 'record' || !row) {
        return null;
    }

    const asked = transferQuantityThousandths(String(form.quantity));
    const have = transferQuantityThousandths(row.on_hand);

    return asked !== null && have !== null && asked > have
        ? `Only ${quantityWithUnit(f(have), row.stock_unit)} ${Number(f(have)) === 1 ? 'is' : 'are'} on hand.`
        : null;
});
/** Said before the press: what leaves stock, from where, and why. */
const recordSentence = computed(() => {
    const row = activeStock.value;

    if (!row || !String(form.quantity).trim()) {
        return 'Enter the quantity to see what this records.';
    }

    const why =
        form.kind === 'waste'
            ? `wasted (${(usageReasonLabels[form.reason] ?? form.reason).toLowerCase()})`
            : `used in treatment (${(usageReasonLabels[form.reason] ?? form.reason).toLowerCase()})`;

    return `Takes ${quantityWithUnit(String(form.quantity).trim(), row.stock_unit)} of ${row.item_name}, batch ${row.batch_number}, out of ${row.location} now, as ${why}${roomName.value ? `, in ${roomName.value}` : ''}. Not linked to a patient; no bill.`;
});
const stockRows = computed<KeyValueItem[]>(() => {
    const row = activeStock.value;

    return row
        ? [
              { label: 'Item', value: row.item_name },
              { label: 'SKU', value: row.sku_code },
              {
                  label: 'Batch',
                  value: `${row.batch_number} (expires ${formatDate(row.expiry_date)})`,
              },
              { label: 'Location', value: row.location },
              {
                  label: 'On hand',
                  value: quantityWithUnit(row.on_hand, row.stock_unit),
              },
          ]
        : [];
});
const entryRows = computed<KeyValueItem[]>(() => {
    const row = activeEntry.value;

    return row
        ? [
              { label: 'Entry', value: row.usage_number },
              {
                  label: 'Recorded',
                  value: `${usageKindLabels[row.kind] ?? row.kind}: ${usageReasonLabels[row.reason] ?? row.reason}`,
              },
              {
                  label: 'Quantity',
                  value: quantityWithUnit(row.quantity, row.base_unit),
              },
              { label: 'Item', value: row.item_name },
              { label: 'Batch', value: row.batch_number },
              { label: 'Location', value: row.location },
          ]
        : [];
});
const errorList = computed(() =>
    Object.values(errors.value).filter((message): message is string =>
        Boolean(message),
    ),
);
const kindChip = (row: EntryRow) =>
    row.reversal_id
        ? { tone: 'neutral' as const, label: 'Reversed' }
        : row.kind === 'waste'
          ? { tone: 'warning' as const, label: usageKindLabels.waste }
          : { tone: 'info' as const, label: usageKindLabels.usage };

// At phone width the entries stay behind their bar, so the stock and its Record buttons come first.
const wide =
    typeof window === 'undefined' ||
    window.matchMedia('(min-width: 640px)').matches;
</script>

<template>
    <Head title="Treatment room usage" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Treatment room usage"
            :description="usage.branchName"
        >
            <template #main>
                <p class="text-sm text-muted-foreground">
                    Record stock used in treatment, or wasted. It leaves stock
                    at once, is not linked to a patient and creates no bill. Do
                    not enter patient details. A CA Supervisor, Finance Officer
                    or Director who did not record an entry can reverse it once.
                </p>

                <!-- Record use or waste: the stock on hand. -->
                <section
                    v-if="usage.canRecord"
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-usage-stock"
                >
                    <h2 id="v2-usage-stock" class="v2-section-title">
                        Record use or waste ({{ usage.stock.total }})
                    </h2>
                    <ScanInput
                        context="search"
                        refocus-key="treatment-usage"
                        lookup-variant="outline"
                        @found="scanned"
                    />
                    <form
                        class="flex min-w-0 flex-wrap items-end gap-2"
                        role="search"
                        @submit.prevent="search(1, 1)"
                    >
                        <label
                            class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                            >Search item, SKU or batch
                            <input
                                v-model="stockQ"
                                type="search"
                                maxlength="100"
                                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9"
                        /></label>
                        <button
                            type="submit"
                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                        >
                            Search stock
                        </button>
                    </form>
                    <V2Table
                        label="Stock on hand"
                        :columns="5"
                        cards="xl"
                        min-width="820px"
                        :empty="usage.stock.rows.length === 0"
                        empty-message="No stock on hand matches."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col">Batch and expiry</th>
                                <th scope="col">Location</th>
                                <th scope="col" class="text-right">On hand</th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in usage.stock.rows" :key="row.id">
                                <td data-stack data-label="Item" data-primary>
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ row.item_name }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.sku_code }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Batch and expiry">
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm break-words"
                                            >{{ row.batch_number }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >Expires
                                            {{
                                                formatDate(row.expiry_date)
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td data-label="Location" class="break-words">
                                    {{ row.location }}
                                </td>
                                <td
                                    data-label="On hand"
                                    class="text-right tabular-nums"
                                >
                                    {{
                                        quantityWithUnit(
                                            row.on_hand,
                                            row.stock_unit,
                                        )
                                    }}
                                </td>
                                <td data-label="Action">
                                    <button
                                        v-if="row.usable"
                                        type="button"
                                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                        :aria-label="`Record use or waste of ${row.item_name}, batch ${row.batch_number}, at ${row.location}`"
                                        @click="openRecord(row)"
                                    >
                                        Record
                                    </button>
                                    <span
                                        v-else
                                        class="inline-flex flex-wrap items-center justify-end gap-2"
                                    >
                                        <V2Chip
                                            tone="warning"
                                            :label="
                                                (
                                                    ineligibleBatchLabels[
                                                        row.eligibility_reason ??
                                                            ''
                                                    ] ?? 'not usable'
                                                ).replace(/^./, (c) =>
                                                    c.toUpperCase(),
                                                )
                                            "
                                        />
                                        <Link
                                            href="/inventory/writeoffs"
                                            class="inline-flex h-11 items-center text-sm font-medium whitespace-nowrap text-primary hover:underline lg:h-9"
                                            >Use Write-off</Link
                                        >
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        :current-page="usage.stock.page"
                        :last-page="usage.stock.lastPage"
                        :total="usage.stock.total"
                        :per-page="25"
                        @change="(next) => search(usage.entries.page, next)"
                    />
                </section>

                <!-- Entries: behind a bar on a phone. -->
                <V2DisclosureBar
                    :title="`Entries (${usage.entries.total})`"
                    :default-open="wide || !usage.canRecord"
                    keep-mounted
                >
                    <div class="flex min-w-0 flex-col gap-3">
                        <form
                            class="flex min-w-0 flex-wrap items-end gap-2"
                            role="search"
                            @submit.prevent="search(1, usage.stock.page)"
                        >
                            <label
                                class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                                >Search item, SKU, batch or entry number
                                <input
                                    v-model="q"
                                    type="search"
                                    maxlength="100"
                                    class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9"
                            /></label>
                            <button
                                type="submit"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            >
                                Search entries
                            </button>
                        </form>
                        <V2Table
                            label="Entries"
                            :columns="5"
                            cards="xl"
                            min-width="880px"
                            :empty="usage.entries.rows.length === 0"
                            empty-message="No treatment room usage recorded yet."
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Entry</th>
                                    <th scope="col">Quantity and item</th>
                                    <th scope="col">Where</th>
                                    <th scope="col">Recorded</th>
                                    <th scope="col">
                                        <span class="sr-only">Action</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in usage.entries.rows"
                                    :key="row.id"
                                >
                                    <td data-label="Entry" data-primary>
                                        <span class="block font-mono text-sm">{{
                                            row.usage_number
                                        }}</span>
                                        <V2Chip
                                            class="mt-1"
                                            :tone="kindChip(row).tone"
                                            :label="kindChip(row).label"
                                        />
                                    </td>
                                    <td
                                        data-stack
                                        data-label="Quantity and item"
                                    >
                                        <div class="min-w-0">
                                            <span
                                                class="block text-sm font-medium break-words"
                                                >{{
                                                    quantityWithUnit(
                                                        row.quantity,
                                                        row.base_unit,
                                                    )
                                                }}
                                                of {{ row.item_name }}</span
                                            >
                                            <span
                                                class="block text-xs break-words text-muted-foreground"
                                                >{{ row.sku_code }} · batch
                                                {{ row.batch_number }}</span
                                            >
                                        </div>
                                    </td>
                                    <td data-stack data-label="Where">
                                        <div class="min-w-0">
                                            <span
                                                class="block text-sm break-words"
                                                >{{ row.location }}</span
                                            >
                                            <span
                                                v-if="row.room"
                                                class="block text-xs break-words text-muted-foreground"
                                                >{{ row.room }}</span
                                            >
                                        </div>
                                    </td>
                                    <td data-stack data-label="Recorded">
                                        <div class="min-w-0 text-sm">
                                            <span class="block break-words"
                                                >{{
                                                    usageKindLabels[row.kind] ??
                                                    row.kind
                                                }}:
                                                {{
                                                    usageReasonLabels[
                                                        row.reason
                                                    ] ?? row.reason
                                                }}</span
                                            >
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >{{ row.recordedAt }} by
                                                {{
                                                    row.recorded_by_name ??
                                                    'Staff member'
                                                }}</span
                                            >
                                            <span
                                                v-if="row.reversal_id"
                                                class="block text-xs text-muted-foreground"
                                                >Reversed
                                                {{ row.reversedAt }} by
                                                {{
                                                    row.reversed_by_name ??
                                                    'Staff member'
                                                }}:
                                                {{
                                                    usageReversalLabels[
                                                        row.reversal_reason ??
                                                            ''
                                                    ] ?? row.reversal_reason
                                                }}. The stock was put
                                                back.</span
                                            >
                                            <span
                                                v-else-if="
                                                    usage.canReverse &&
                                                    row.recordedByYou
                                                "
                                                class="block text-xs text-muted-foreground"
                                                >You recorded this, so someone
                                                else must reverse it.</span
                                            >
                                        </div>
                                    </td>
                                    <td data-label="Action">
                                        <button
                                            v-if="row.canReverse"
                                            type="button"
                                            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap text-destructive hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                            :aria-label="`Reverse ${row.usage_number}`"
                                            @click="openReverse(row)"
                                        >
                                            Reverse
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <V2Pager
                            :current-page="usage.entries.page"
                            :last-page="usage.entries.lastPage"
                            :total="usage.entries.total"
                            :per-page="25"
                            @change="(next) => search(next, usage.stock.page)"
                        />
                    </div>
                </V2DisclosureBar>
            </template>
        </V2WorkLayout>

        <!-- One sheet for Record and Reverse; one filled button; it asks before closing with input typed. -->
        <V2Dialog
            :open="open"
            :title="
                operation === 'record'
                    ? 'Record use or waste'
                    : `Reverse ${activeEntry?.usage_number ?? ''}`
            "
            :confirm-label="operation === 'record' ? 'Record' : 'Reverse entry'"
            :tone="operation === 'reverse' ? 'danger' : 'default'"
            :busy="form.processing"
            :dirty="operation === 'record' && form.isDirty"
            :confirm-disabled="overOnHand !== null"
            :confirm-disabled-reason="
                overOnHand
                    ? 'Enter no more than is on hand to continue.'
                    : undefined
            "
            @update:open="closeSheet"
            @confirm="submit"
        >
            <div
                v-if="errorList.length"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <p class="font-medium">This was not saved.</p>
                <p v-for="message in errorList" :key="message">
                    {{ message }}
                </p>
            </div>

            <!-- Record: what, then why, then how much, then the sentence. -->
            <template v-if="operation === 'record'">
                <V2KeyValueList :items="stockRows" />
                <V2OptionGroup
                    :model-value="form.kind"
                    label="What happened"
                    required
                    :options="kindOptions"
                    @update:model-value="
                        (value) => (form.kind = value as UsageKind)
                    "
                />
                <V2OptionGroup
                    :model-value="form.reason"
                    label="Reason"
                    required
                    :options="reasonOptions"
                    @update:model-value="(value) => (form.reason = value)"
                />
                <V2OptionGroup
                    v-if="usage.rooms.length"
                    :model-value="
                        form.room_id === '' ? '' : String(form.room_id)
                    "
                    label="Treatment room (optional)"
                    :options="roomOptions"
                    @update:model-value="
                        (value) =>
                            (form.room_id = value === '' ? '' : Number(value))
                    "
                />
                <label class="flex flex-col gap-1.5 text-sm font-medium"
                    >Quantity ({{ form.unit }})
                    <input
                        v-model="form.quantity"
                        inputmode="decimal"
                        maxlength="16"
                        required
                        :aria-invalid="overOnHand !== null"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span
                        v-if="overOnHand"
                        class="text-sm font-normal text-destructive"
                        >{{ overOnHand }}</span
                    >
                </label>
                <p
                    class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
                    role="status"
                >
                    {{ recordSentence }}
                </p>
            </template>

            <!-- Reverse: names the entry, then why. -->
            <template v-if="operation === 'reverse' && activeEntry">
                <p
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm"
                >
                    Puts
                    {{
                        quantityWithUnit(
                            activeEntry.quantity,
                            activeEntry.base_unit,
                        )
                    }}
                    of {{ activeEntry.item_name }} back into
                    {{ activeEntry.location }}. An entry can be reversed once.
                </p>
                <V2KeyValueList :items="entryRows" />
                <V2OptionGroup
                    :model-value="form.reversal_reason"
                    label="Reason"
                    required
                    :options="reversalOptions"
                    @update:model-value="
                        (value) => (form.reversal_reason = value)
                    "
                />
            </template>
        </V2Dialog>
    </PageFrame>
</template>

<style scoped>
/* Phone and tablet cards: a cell with several lines puts its label on its own line and uses the full width. */
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
}
</style>

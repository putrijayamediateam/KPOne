<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import { formatInventoryReason } from '@/lib/inventory-picker';
import type { ScannedSku } from '@/lib/scan';
import type { InventoryDocument } from '@/types/inventory-operations';
const props = defineProps<{
    row: InventoryDocument;
    branchId: number;
    nonce: string;
}>();
const lot = () => ({
    batch_number: '',
    expiry_date: '',
    quantity: '',
    outcome: 'accepted',
    reason: null as string | null,
    // STK-10B: the total paid for this lot, exactly as on the supplier invoice (RM, two decimals).
    cost_total_rm: '',
    unit: null as string | null,
});
const form = useForm({
    expected_branch_id: props.branchId,
    lock_version: props.row.lockVersion,
    delivery_reference: '',
    idempotency_key: crypto.randomUUID(),
    retry_key: crypto.randomUUID(),
    receipt_session_nonce: props.nonce,
    lines: props.row.lines.map((line) => ({
        included: true,
        line_public_id: line.publicId,
        physical_quantity: '',
        short_delivery: false,
        lots: [lot()],
    })),
});
const reasons = [
    'damaged',
    'wrong_item',
    'short_expiry',
    'documentation_missing',
    'other_approved',
];
/** Exact RM to sen without floating point; null for blank, undefined for an invalid amount. */
function lotTotalSen(
    value: string | number | null | undefined,
): number | null | undefined {
    // A type="number" v-model hands back a number once typed, so read it as text first.
    const text = String(value ?? '').trim();

    if (text === '') {
        return null;
    }

    const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(text);

    return match
        ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
        : undefined;
}
const costError = ref<string | null>(null);
const submit = (review: boolean) => {
    costError.value = form.lines.some((line) =>
        line.lots.some(
            (entry) =>
                entry.outcome !== 'rejected' &&
                lotTotalSen(entry.cost_total_rm) === undefined,
        ),
    )
        ? 'Enter each lot total as RM with up to 2 decimal places, for example 12.34.'
        : null;

    if (costError.value) {
        return;
    }

    form.expected_branch_id = props.branchId;
    form.lock_version = props.row.lockVersion;
    form.receipt_session_nonce = props.nonce;
    form.transform((data) => ({
        ...data,
        lines: data.lines
            .filter((line) => line.included)
            .map(({ included, ...line }) => {
                void included;

                return {
                    ...line,
                    lots: line.lots.map(({ cost_total_rm, ...lot }) => ({
                        ...lot,
                        // A rejected lot never enters stock, so it carries no cost.
                        cost_total_sen:
                            lot.outcome === 'rejected'
                                ? null
                                : (lotTotalSen(cost_total_rm) ?? null),
                        unit:
                            props.row.lines.find(
                                (source) =>
                                    source.publicId === line.line_public_id,
                            )?.stockUnit ?? null,
                    })),
                };
            }),
    })).post(
        `/inventory/purchase-orders/${props.row.publicId}/${review ? 'receiving-reviews' : 'receipts'}`,
        { preserveScroll: true },
    );
};
// STK-9: a scan only finds and highlights the order line; it never ticks, fills or saves anything.
const scannedLine = ref<string | null>(null);
function lineFor(sku: ScannedSku) {
    return props.row.lines.find((line) => line.skuPublicId === sku.public_id);
}
function onOrder(sku: ScannedSku): string | null {
    return lineFor(sku)
        ? null
        : `SKU ${sku.sku_code} · ${sku.item_name} is not on this receiving order. Nothing was changed.`;
}
async function highlight(sku: ScannedSku) {
    const line = lineFor(sku);

    if (!line) {
        return;
    }

    scannedLine.value = line.publicId;
    await nextTick();
    // Keep focus in the scan box: moving it into a quantity field would let the next scan type into it and
    // submit the receipt with its Enter.
    document
        .getElementById(`receiving-line-${line.publicId}`)
        ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}
watch(
    () => [form.delivery_reference, JSON.stringify(form.lines)],
    () => {
        form.idempotency_key = crypto.randomUUID();
        form.retry_key = crypto.randomUUID();
    },
);
watch(
    () => [props.branchId, props.row.lockVersion],
    () => {
        form.reset();
        form.clearErrors();
    },
);
</script>
<template>
    <details class="min-w-0 rounded-lg border p-3">
        <summary class="min-h-11 cursor-pointer py-3 font-medium">
            Split lots and delivery exceptions
        </summary>
        <p class="mb-3 text-sm text-muted-foreground">
            A real future expiry and delivery reference are required. Enter SKU
            base stock units, never purchase packs; identity factor 1.000.
            Maximum 100 lots per receipt. Exceptions need a different approver
            before posting. Quarantine holds the whole batch at this branch,
            including any earlier balance. Nothing here releases a hold.
        </p>
        <ScanInput
            context="receiving"
            :refocus-key="`receiving-${row.publicId}`"
            :accept="onOrder"
            @found="highlight"
        />
        <form class="space-y-4" @submit.prevent="submit(false)">
            <label class="grid gap-1 text-sm"
                >Delivery note or supplier invoice reference<input
                    v-model="form.delivery_reference"
                    maxlength="120"
                    required
                    class="h-11 min-w-0 rounded border bg-background px-3"
            /></label>
            <p
                :id="`${row.publicId}-lot-cost-help`"
                class="text-xs text-muted-foreground"
            >
                Optional. Type each lot total from the supplier invoice. Leave
                blank to use the order's estimate.
            </p>
            <fieldset
                v-for="(line, index) in form.lines"
                :id="`receiving-line-${line.line_public_id}`"
                :key="line.line_public_id"
                class="min-w-0 space-y-3 rounded border p-3"
                :class="{
                    'border-2 border-primary':
                        scannedLine === line.line_public_id,
                }"
            >
                <legend class="max-w-full px-1 text-sm wrap-anywhere">
                    {{ row.lines[index].sku }}
                    <span
                        v-if="scannedLine === line.line_public_id"
                        class="ml-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                        >Scanned</span
                    >
                </legend>
                <label class="flex min-h-11 items-center gap-2"
                    ><input
                        v-model="line.included"
                        type="checkbox"
                        class="size-5"
                    />Include this order line</label
                >
                <template v-if="line.included">
                    <label class="grid gap-1 text-sm"
                        >Physical quantity counted ({{
                            row.lines[index].stockUnit
                        }})<input
                            v-model="line.physical_quantity"
                            inputmode="decimal"
                            required
                            class="h-11 min-w-0 rounded border bg-background px-3"
                    /></label>
                    <label class="flex min-h-11 items-center gap-2 text-sm"
                        ><input
                            v-model="line.short_delivery"
                            type="checkbox"
                            class="size-5"
                        />Declared short delivery (requires approval)</label
                    >
                    <div
                        v-for="(entry, number) in line.lots"
                        :key="number"
                        class="grid min-w-0 gap-2 rounded border p-3 sm:grid-cols-2"
                    >
                        <p class="font-medium sm:col-span-2">
                            Lot {{ number + 1 }}
                        </p>
                        <label class="grid gap-1 text-sm"
                            >Batch number<input
                                v-model="entry.batch_number"
                                maxlength="100"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label class="grid gap-1 text-sm"
                            >Real expiry date<input
                                v-model="entry.expiry_date"
                                type="date"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label class="grid gap-1 text-sm"
                            >Lot quantity<input
                                v-model="entry.quantity"
                                inputmode="decimal"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label
                            v-if="entry.outcome !== 'rejected'"
                            class="grid gap-1 text-sm"
                            >Total paid for this lot (RM)<input
                                v-model="entry.cost_total_rm"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                :aria-label="`${row.lines[index].sku} lot ${number + 1} total paid RM`"
                                :aria-describedby="`${row.publicId}-lot-cost-help`"
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label class="grid gap-1 text-sm"
                            >Outcome<select
                                v-model="entry.outcome"
                                class="h-11 min-w-0 rounded border bg-background px-3"
                                @change="entry.reason = null"
                            >
                                <option>accepted</option>
                                <option>quarantined</option>
                                <option>rejected</option>
                            </select></label
                        >
                        <label
                            v-if="entry.outcome !== 'accepted'"
                            class="grid gap-1 text-sm"
                            >Controlled reason<select
                                v-model="entry.reason"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                            >
                                <option :value="null" disabled>
                                    Choose a reason
                                </option>
                                <option
                                    v-for="reason in reasons"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{ formatInventoryReason(reason) }}
                                </option>
                            </select></label
                        >
                        <button
                            type="button"
                            class="min-h-11 rounded border px-3"
                            :disabled="line.lots.length === 1"
                            @click="line.lots.splice(number, 1)"
                        >
                            Remove lot
                        </button>
                    </div>
                    <button
                        type="button"
                        class="min-h-11 rounded border px-3"
                        :disabled="line.lots.length >= 20"
                        @click="line.lots.push(lot())"
                    >
                        Add lot (maximum 20)
                    </button>
                </template>
            </fieldset>
            <p v-if="costError" class="text-sm text-destructive" role="alert">
                {{ costError }}
            </p>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                    :disabled="form.processing"
                >
                    Post receipt</button
                ><button
                    type="button"
                    class="min-h-11 rounded border px-4"
                    :disabled="form.processing"
                    @click="submit(true)"
                >
                    Request exception approval
                </button>
            </div>
        </form>
    </details>
</template>

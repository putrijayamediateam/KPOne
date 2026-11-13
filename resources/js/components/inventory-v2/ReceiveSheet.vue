<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ScanInput from '@/components/inventory/ScanInput.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { formatDate } from '@/lib/presentation';
import { boxLotHint } from '@/lib/receiving-gs1';
import type { Gs1Read, ScannedSku } from '@/lib/scan';
import type { InventoryDocument } from '@/types/inventory-operations';
const props = defineProps<{
    row: InventoryDocument;
    branchId: number;
    nonce: string;
    open: boolean;
}>();
const emit = defineEmits<{ close: [] }>();
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
        {
            preserveScroll: true,
            // V2 only: the sheet closes on success and stays open with every value after a refusal.
            onSuccess: () => emit('close'),
            onError: () => void nextTick(() => summary.value?.focus()),
        },
    );
};
// STK-9: a scan only finds and highlights the order line; it never ticks, fills or saves anything.
const scannedLine = ref<string | null>(null);
// STK-26: what a GS1 box said (batch, expiry). Shown as a hint on the scanned line only; it never fills a lot.
const boxRead = ref<Gs1Read | null>(null);
const boxHint = computed(() => {
    const line = form.lines.find(
        (entry) => entry.line_public_id === scannedLine.value,
    );

    return line ? boxLotHint(boxRead.value, line.lots, formatDate) : null;
});
function lineFor(sku: ScannedSku) {
    return props.row.lines.find((line) => line.skuPublicId === sku.public_id);
}
function onOrder(sku: ScannedSku): string | null {
    if (lineFor(sku)) {
        return null;
    }

    // STK-26: a refused scan must not leave the previous box's batch on screen.
    boxRead.value = null;

    return `SKU ${sku.sku_code} · ${sku.item_name} is not on this receiving order. Nothing was changed.`;
}
async function highlight(sku: ScannedSku, gs1: Gs1Read | null = null) {
    const line = lineFor(sku);

    if (!line) {
        return;
    }

    scannedLine.value = line.publicId;
    boxRead.value = gs1;
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

// ---- V2 only -------------------------------------------------------------------------------------------------------
const summary = ref<HTMLElement | null>(null);
// A receipt with typed values asks "Discard your changes?" first (V2Dialog `dirty`); this runs after Discard.
const requestClose = (open: boolean) => {
    if (!open) {
        form.reset();
        form.clearErrors();
        emit('close');
    }
};
/** "Paracetamol 500 mg tablet (PV20-01)": the item first, its code after; the code alone if the name is missing. */
const lineName = (line: InventoryDocument['lines'][number]) =>
    line.item ? `${line.item} (${line.sku})` : line.sku;
const outcomes = [
    { value: 'accepted', label: 'Accepted' },
    { value: 'quarantined', label: 'Quarantined' },
    { value: 'rejected', label: 'Rejected' },
];
const reasonOptions = reasons.map((reason) => ({
    value: reason,
    label: formatInventoryReason(reason),
}));
const plural = (amount: number, unit: string) =>
    amount === 1 || unit === ''
        ? unit
        : /(s|x|z|ch|sh)$/i.test(unit)
          ? `${unit}es`
          : `${unit}s`;
/** "Receiving adds 120 tablets of Paracetamol to Cheras Dispensary" for each included line (Daniel: it changes stock). */
const stockSummary = computed(() =>
    form.lines.flatMap((line, index) => {
        if (!line.included) {
            return [];
        }

        const source = props.row.lines[index];
        const unit = source?.stockUnit ?? '';
        const sum = (outcome: string) =>
            line.lots
                .filter((entry) => entry.outcome === outcome)
                .reduce(
                    (total, entry) => total + (Number(entry.quantity) || 0),
                    0,
                );
        const accepted = sum('accepted');
        const quarantined = sum('quarantined');
        const parts: string[] = [];

        if (accepted > 0) {
            parts.push(
                `adds ${accepted} ${plural(accepted, unit)} of ${source?.item ?? source?.sku} to ${props.row.destination}`,
            );
        }

        if (quarantined > 0) {
            parts.push(
                `puts ${quarantined} ${plural(quarantined, unit)} on hold (quarantined)`,
            );
        }

        return parts.length ? [`Receiving ${parts.join(' and ')}.`] : [];
    }),
);
/** Said before the press: these need a different approver first (Daniel, condition 4). */
const needsApproval = computed(() =>
    form.lines.some(
        (line) =>
            line.included &&
            (line.short_delivery ||
                line.lots.some((entry) => entry.outcome !== 'accepted')),
    ),
);
</script>

<template>
    <V2Dialog
        :open="open"
        size="tall"
        :dirty="form.isDirty"
        :title="`Receive ${row.number}`"
        :description="`${row.supplier} · to ${row.destination}`"
        confirm-label="Post receipt"
        cancel-label="Cancel"
        :busy="form.processing"
        @update:open="requestClose"
        @confirm="submit(false)"
    >
        <div
            v-if="costError || Object.keys(form.errors).length"
            ref="summary"
            tabindex="-1"
            role="alert"
            class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive outline-none"
        >
            <p class="font-medium">This receipt was not saved.</p>
            <p v-if="costError">{{ costError }}</p>
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
        </div>
        <p class="text-sm text-muted-foreground">
            Enter quantities in the item's stock unit (for example tablets), not
            boxes. Every lot needs its real expiry date.
        </p>
        <ScanInput
            context="receiving"
            :refocus-key="`v2-receiving-${row.publicId}`"
            lookup-variant="outline"
            help="Scanning only finds the line on this order. It doesn't fill in or save anything."
            hide-unavailable-camera
            :accept="onOrder"
            @found="highlight"
        />
        <label class="grid gap-1.5 text-sm font-medium"
            >Delivery note or supplier invoice reference
            <span class="sr-only">(required)</span>
            <input
                v-model="form.delivery_reference"
                maxlength="120"
                required
                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                :aria-invalid="!!form.errors.delivery_reference"
            />
        </label>
        <fieldset
            v-for="(line, index) in form.lines"
            :id="`receiving-line-${line.line_public_id}`"
            :key="line.line_public_id"
            class="grid min-w-0 gap-3 rounded-[14px] border p-3"
            :class="{
                'border-2 border-primary': scannedLine === line.line_public_id,
            }"
        >
            <legend class="max-w-full px-1 text-sm font-medium wrap-anywhere">
                {{ lineName(row.lines[index]) }}
                <span
                    v-if="scannedLine === line.line_public_id"
                    class="ml-1 rounded-full bg-muted px-2 py-0.5 text-xs whitespace-nowrap"
                    >Scanned</span
                >
            </legend>
            <p
                v-if="scannedLine === line.line_public_id"
                class="text-sm wrap-anywhere empty:sr-only"
                :class="
                    boxHint?.warn
                        ? 'font-medium text-warning-strong'
                        : 'text-muted-foreground'
                "
                role="status"
            >
                {{ boxHint?.message ?? '' }}
            </p>
            <label class="flex min-h-11 items-center gap-2 text-sm"
                ><input
                    v-model="line.included"
                    type="checkbox"
                    class="size-5"
                />
                Receive this line now</label
            >
            <template v-if="line.included">
                <label class="grid gap-1.5 text-sm font-medium"
                    >Counted ({{ row.lines[index].stockUnit }})
                    <span class="sr-only">(required)</span>
                    <input
                        v-model="line.physical_quantity"
                        inputmode="decimal"
                        required
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                </label>
                <label class="flex min-h-11 items-center gap-2 text-sm"
                    ><input
                        v-model="line.short_delivery"
                        type="checkbox"
                        class="size-5"
                    />
                    The supplier delivered less than ordered (needs a different
                    approver)</label
                >
                <div
                    v-for="(entry, number) in line.lots"
                    :key="number"
                    class="grid min-w-0 gap-3 rounded-[14px] border bg-muted/30 p-3"
                >
                    <p class="text-sm font-medium">Lot {{ number + 1 }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-sm font-medium"
                            >Batch number
                            <span class="sr-only">(required)</span>
                            <input
                                v-model="entry.batch_number"
                                maxlength="100"
                                required
                                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                            />
                        </label>
                        <label class="grid gap-1.5 text-sm font-medium"
                            >Expiry date <span class="sr-only">(required)</span>
                            <input
                                v-model="entry.expiry_date"
                                type="date"
                                required
                                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                            />
                            <span
                                v-if="entry.expiry_date"
                                class="text-xs font-normal text-muted-foreground"
                                >{{ formatDate(entry.expiry_date) }}</span
                            >
                        </label>
                        <label class="grid gap-1.5 text-sm font-medium"
                            >Quantity in this lot
                            <span class="sr-only">(required)</span>
                            <input
                                v-model="entry.quantity"
                                inputmode="decimal"
                                required
                                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                            />
                        </label>
                        <label
                            v-if="entry.outcome !== 'rejected'"
                            class="grid gap-1.5 text-sm font-medium"
                            >Total paid for this lot, RM (optional)
                            <input
                                v-model="entry.cost_total_rm"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="0.01"
                                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                            />
                            <span
                                class="text-xs font-normal text-muted-foreground"
                                >From the supplier invoice. Leave empty to use
                                the order's estimate.</span
                            >
                        </label>
                    </div>
                    <V2OptionGroup
                        :model-value="entry.outcome"
                        label="Outcome"
                        :options="outcomes"
                        @update:model-value="
                            (value) => {
                                entry.outcome = value;
                                entry.reason = null;
                            }
                        "
                    />
                    <V2OptionGroup
                        v-if="entry.outcome !== 'accepted'"
                        :model-value="entry.reason"
                        label="Reason"
                        required
                        :options="reasonOptions"
                        @update:model-value="(value) => (entry.reason = value)"
                    />
                    <button
                        type="button"
                        class="justify-self-start text-sm font-medium text-primary underline-offset-4 hover:underline disabled:text-muted-foreground disabled:no-underline"
                        :disabled="line.lots.length === 1"
                        @click="line.lots.splice(number, 1)"
                    >
                        Remove lot {{ number + 1 }}
                    </button>
                </div>
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted disabled:opacity-50 lg:h-9"
                    :disabled="line.lots.length >= 20"
                    @click="line.lots.push(lot())"
                >
                    Add a lot (up to 20)
                </button>
            </template>
        </fieldset>
        <div
            class="space-y-1 rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
            role="status"
        >
            <p v-for="sentence in stockSummary" :key="sentence">
                {{ sentence }}
            </p>
            <p v-if="!stockSummary.length" class="text-muted-foreground">
                Nothing is added to stock yet.
            </p>
            <p v-if="needsApproval" class="font-medium">
                This delivery has an exception, so it needs a different CA
                Supervisor, Finance Officer or Director to approve it before it
                posts. Use "Request exception approval".
            </p>
        </div>
        <button
            type="button"
            class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted disabled:opacity-50 lg:h-9"
            :disabled="form.processing"
            @click="submit(true)"
        >
            Request exception approval
        </button>
    </V2Dialog>
</template>

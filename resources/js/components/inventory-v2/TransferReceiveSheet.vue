<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { trimQuantity } from '@/lib/supplier-terms';
import {
    defaultTransferReceiptRow,
    transferReceiptDifferenceCount,
    transferReceiptPayload,
    transferReceiptRowError,
} from '@/lib/transfer-receipt';
import type {
    TransferReceiptLinePayload,
    TransferReceiptRow,
} from '@/lib/transfer-receipt';
import type { InventoryDocument } from '@/types/inventory-operations';

/**
 * Inventory V2 page 2b: receive a dispatched stock transfer in a tall sheet. The script is the Classic
 * `components/inventory/TransferReceiptForm.vue` (a test compares them); only the layout and the plain sentences differ.
 * The parent sends the request and closes the sheet only on success.
 */
const props = defineProps<{
    row: InventoryDocument;
    busy: boolean;
    open: boolean;
    /** The refused save's message, kept inside the sheet with every value. */
    error?: string | null;
}>();
const emit = defineEmits<{
    submit: [
        lines: TransferReceiptLinePayload[],
        differences: number,
        retryKey: string,
    ];
    close: [];
}>();

const rows = reactive<TransferReceiptRow[]>([]);
// One retry key per version of this transfer, so a resend replays the same receipt instead of a new one.
let retryKey = crypto.randomUUID();
const reset = () => {
    retryKey = crypto.randomUUID();
    rows.splice(
        0,
        rows.length,
        ...props.row.lines.map((line) =>
            defaultTransferReceiptRow(
                line.publicId,
                line.dispatchedQuantity ?? '0',
            ),
        ),
    );
};
watch(() => props.row.lockVersion, reset, { immediate: true });

const fields = [
    { key: 'accepted', label: 'Accepted into stock' },
    { key: 'short', label: 'Short' },
    { key: 'damaged', label: 'Damaged' },
    { key: 'extra', label: 'Extra' },
] as const;
const errors = computed(() => rows.map(transferReceiptRowError));
const valid = computed(() => errors.value.every((error) => error === null));
const differences = computed(() => transferReceiptDifferenceCount(rows));
const skuFor = (index: number) => props.row.lines[index]?.sku ?? '';
const batchFor = (index: number) => props.row.lines[index]?.batch ?? '';
const submit = () => {
    if (valid.value && !props.busy) {
        emit(
            'submit',
            transferReceiptPayload(rows),
            differences.value,
            retryKey,
        );
    }
};

// ---- V2 only -------------------------------------------------------------------------------------------------------
/** "Paracetamol 500 mg tablet (PV20-01)": the item first, its code after. */
const lineName = (index: number) => {
    const line = props.row.lines[index];

    return line?.item ? `${line.item} (${line.sku})` : skuFor(index);
};
/** A changed count asks "Discard your changes?" before the sheet closes. */
const dirty = computed(() =>
    rows.some(
        (receipt) =>
            receipt.accepted !== receipt.dispatched ||
            receipt.short !== '0' ||
            receipt.damaged !== '0' ||
            receipt.extra !== '0',
    ),
);
const requestClose = (open: boolean) => {
    if (!open) {
        reset();
        emit('close');
    }
};
</script>

<template>
    <V2Dialog
        :open="open"
        size="tall"
        :dirty="dirty"
        :title="`Receive ${row.number}`"
        :description="`${row.source} → ${row.destination}`"
        confirm-label="Confirm receipt"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="submit"
    >
        <div
            v-if="error"
            role="alert"
            class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <p class="font-medium">This receipt was not saved.</p>
            <p>{{ error }}</p>
        </div>
        <p class="text-sm text-muted-foreground">
            Count what physically arrived. Only the accepted quantity enters
            this branch's stock. Short, damaged and extra quantities stay open
            until a CA Supervisor, Finance Officer or Director who did not
            receive this transfer resolves them.
        </p>
        <fieldset
            v-for="(receipt, index) in rows"
            :key="receipt.linePublicId"
            class="grid min-w-0 gap-3 rounded-[14px] border p-3"
        >
            <legend class="max-w-full px-1 text-sm font-medium wrap-anywhere">
                {{ lineName(index) }}
            </legend>
            <p class="text-sm text-muted-foreground">
                Dispatched {{ trimQuantity(receipt.dispatched)
                }}<template v-if="batchFor(index)">
                    · batch {{ batchFor(index) }}</template
                >
            </p>
            <div class="grid grid-cols-2 items-start gap-3 sm:grid-cols-4">
                <label
                    v-for="field in fields"
                    :key="field.key"
                    class="grid gap-1.5 text-sm font-medium"
                >
                    {{ field.label }}
                    <input
                        v-model="receipt[field.key]"
                        inputmode="decimal"
                        required
                        :aria-invalid="errors[index] !== null"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                </label>
            </div>
            <p
                v-if="errors[index]"
                class="text-sm text-destructive"
                aria-live="polite"
            >
                {{ errors[index] }}
            </p>
        </fieldset>
        <div
            class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
            role="status"
        >
            {{
                differences === 0
                    ? `Everything arrived as dispatched. Confirming adds it to ${row.destination}.`
                    : `Confirming adds only the accepted quantities to ${row.destination}. ${differences} difference${differences === 1 ? '' : 's'} will stay open until someone who did not receive this transfer resolves ${differences === 1 ? 'it' : 'them'}.`
            }}
        </div>
    </V2Dialog>
</template>

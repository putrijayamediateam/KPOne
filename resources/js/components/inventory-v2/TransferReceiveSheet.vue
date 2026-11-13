<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { trimQuantity } from '@/lib/supplier-terms';
import {
    defaultTransferReceiptRow,
    formatTransferThousandths,
    transferQuantityThousandths,
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
const unitFor = (index: number) => props.row.lines[index]?.stockUnit ?? '';
/**
 * The live sum under the four boxes (Daniel, page 2b): "4 accepted + 1 short + 0 damaged = 5 of 5 units dispatched",
 * and in words what is missing or over, so a mistake shows before the press. Exact thousandths, as the receipt rule.
 */
const checkLine = (index: number) => {
    const receipt = rows[index];
    const [dispatched, accepted, short, damaged] = [
        receipt.dispatched,
        receipt.accepted,
        receipt.short,
        receipt.damaged,
    ].map(transferQuantityThousandths);

    if (
        [dispatched, accepted, short, damaged].some((value) => value === null)
    ) {
        return null;
    }

    const [d, a, s, g] = [dispatched, accepted, short, damaged] as bigint[];
    const sum = a + s + g;
    // "5", not "5.000": decimals only when there is a fraction.
    const f = (value: bigint) => trimQuantity(formatTransferThousandths(value));
    const total = `${f(a)} accepted + ${f(s)} short + ${f(g)} damaged = ${f(sum)} of ${quantityWithUnit(f(d), unitFor(index))} dispatched`;

    if (sum < d) {
        return { text: `${total}. ${f(d - sum)} not counted yet.`, ok: false };
    }

    if (sum > d) {
        return {
            text: `${total}. That is ${f(sum - d)} more than dispatched: count anything extra under Extra.`,
            ok: false,
        };
    }

    return { text: `${total}.`, ok: true };
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
/**
 * The server refuses a receipt whose lines do not add up, and the shared sheet's button cannot be switched off, so a
 * press while a line does not add up says why and moves to the first such line instead of sending (Daniel, page 2b).
 */
const tried = ref(false);
const confirm = () => {
    if (!valid.value) {
        tried.value = true;
        const first = errors.value.findIndex((error) => error !== null);
        void nextTick(() =>
            document
                .getElementById(`v2-receive-line-${rows[first]?.linePublicId}`)
                ?.querySelector('input')
                ?.focus(),
        );

        return;
    }

    tried.value = false;
    submit();
};
/**
 * Confirm receipt is switched off while a line does not add up (V2Dialog confirmDisabled): the reason line names the
 * first such line, then what fixes it. Enter in a count box sends nothing (the sheet has no form).
 */
const disabledReason = computed(() => {
    const first = errors.value.findIndex((error) => error !== null);

    if (first < 0) {
        return undefined;
    }

    const name = props.row.lines[first]?.item ?? skuFor(first);
    const more = errors.value.filter((error) => error !== null).length - 1;

    return `${name} does not add up to what was dispatched${more > 0 ? `, and ${more} more line${more === 1 ? '' : 's'}` : ''}. Finish the counts to continue.`;
});
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
        :confirm-disabled="!valid"
        :confirm-disabled-reason="disabledReason"
        @update:open="requestClose"
        @confirm="confirm"
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
            :id="`v2-receive-line-${receipt.linePublicId}`"
            :key="receipt.linePublicId"
            class="grid min-w-0 gap-3 rounded-[14px] border p-3"
        >
            <legend class="max-w-full px-1 text-sm font-medium wrap-anywhere">
                {{ row.lines[index]?.item ?? skuFor(index) }}
            </legend>
            <p
                v-if="row.lines[index]?.item"
                class="-mt-2 text-xs text-muted-foreground"
            >
                {{ skuFor(index) }}
            </p>
            <p class="text-sm text-muted-foreground">
                Dispatched
                {{ quantityWithUnit(receipt.dispatched, unitFor(index))
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
                v-if="checkLine(index)"
                class="text-sm"
                :class="
                    checkLine(index)?.ok
                        ? 'text-muted-foreground'
                        : 'font-medium text-warning-strong'
                "
                aria-live="polite"
            >
                {{ checkLine(index)?.text }}
            </p>
            <p
                v-else-if="errors[index]"
                class="text-sm text-destructive"
                aria-live="polite"
            >
                {{ errors[index] }}
            </p>
        </fieldset>
        <!-- While a line does not add up, the reason line by Confirm receipt says so once (Daniel); this box speaks only after. -->
        <div
            v-if="valid"
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

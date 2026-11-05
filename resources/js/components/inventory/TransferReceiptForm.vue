<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

const props = defineProps<{ row: InventoryDocument; busy: boolean }>();
const emit = defineEmits<{
    submit: [
        lines: TransferReceiptLinePayload[],
        differences: number,
        retryKey: string,
    ];
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
</script>

<template>
    <form
        class="space-y-2"
        :aria-label="`Record receipt of ${row.number}`"
        @submit.prevent="submit"
    >
        <p class="text-xs text-muted-foreground">
            Count what physically arrived. Only the accepted quantity enters
            this branch's stock. Short, damaged and extra quantities stay open
            until a CA Supervisor, Finance Officer or Director who did not
            receive this transfer resolves them.
        </p>
        <fieldset
            v-for="(receipt, index) in rows"
            :key="receipt.linePublicId"
            class="space-y-2 rounded-lg border p-2"
        >
            <legend class="px-1 text-xs font-medium">
                {{ skuFor(index) }}
                <span v-if="batchFor(index)">· {{ batchFor(index) }}</span>
                · dispatched {{ receipt.dispatched }}
            </legend>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <label
                    v-for="field in fields"
                    :key="field.key"
                    class="grid gap-1 text-xs"
                >
                    <span>{{ field.label }}</span>
                    <Input
                        v-model="receipt[field.key]"
                        inputmode="decimal"
                        :aria-label="`${skuFor(index)} ${field.label.toLowerCase()}`"
                        :aria-invalid="errors[index] !== null"
                        required
                    />
                </label>
            </div>
            <p
                v-if="errors[index]"
                class="text-xs text-red-700 dark:text-red-300"
                aria-live="polite"
            >
                {{ errors[index] }}
            </p>
        </fieldset>
        <p class="text-xs" aria-live="polite">
            {{
                differences === 0
                    ? 'Everything arrived as dispatched.'
                    : `${differences} difference${differences === 1 ? '' : 's'} will stay open for independent resolution.`
            }}
        </p>
        <Button
            type="submit"
            size="sm"
            class="min-h-11"
            :disabled="busy || !valid"
            >Confirm receipt</Button
        >
    </form>
</template>

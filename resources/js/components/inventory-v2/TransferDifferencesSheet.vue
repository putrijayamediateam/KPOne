<script setup lang="ts">
import { reactive, watch } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import {
    transferDifferenceKindLabels,
    transferOutcomeLabels,
    transferOutcomesNeedingReference,
} from '@/lib/transfer-receipt';
import type { TransferDifferenceKind } from '@/lib/transfer-receipt';
import type { InventoryTransferDifference } from '@/types/inventory-operations';

/**
 * Inventory V2 page 2b: a transfer's short, damaged and extra quantities in one sheet. The script is the Classic
 * `components/inventory/TransferDifferences.vue` (a test compares them); each difference is resolved on its own,
 * with the same outcome, reference and retry key.
 */
const props = defineProps<{
    differences: InventoryTransferDifference[];
    number: string;
    busy: boolean;
    open: boolean;
    error?: string | null;
}>();
const emit = defineEmits<{
    resolve: [
        difference: InventoryTransferDifference,
        outcome: string,
        reference: string,
        retryKey: string,
    ];
    close: [];
}>();

type Choice = { outcome: string; reference: string; retryKey: string };
const choices = reactive<Record<string, Choice>>({});
// One retry key per open difference, so a resend of the same resolution replays instead of duplicating.
watch(
    () => props.differences.map((difference) => difference.publicId),
    (ids) => {
        for (const id of ids) {
            choices[id] ??= {
                outcome: '',
                reference: '',
                retryKey: crypto.randomUUID(),
            };
        }
    },
    { immediate: true },
);
const blank: Choice = { outcome: '', reference: '', retryKey: '' };
const choice = (publicId: string): Choice => choices[publicId] ?? blank;
const needsReference = (outcome: string) =>
    transferOutcomesNeedingReference.includes(outcome);
const ready = (difference: InventoryTransferDifference) => {
    const selected = choice(difference.publicId);

    return (
        selected.outcome !== '' &&
        (!needsReference(selected.outcome) || selected.reference.trim() !== '')
    );
};
const kindLabel = (kind: string) =>
    transferDifferenceKindLabels[kind as TransferDifferenceKind] ?? kind;
const submit = (difference: InventoryTransferDifference) => {
    if (ready(difference) && !props.busy) {
        const selected = choice(difference.publicId);
        emit(
            'resolve',
            difference,
            selected.outcome,
            selected.reference.trim(),
            selected.retryKey,
        );
    }
};

// ---- V2 only -------------------------------------------------------------------------------------------------------
const outcomeOptions = (difference: InventoryTransferDifference) =>
    difference.outcomes.map((outcome) => ({
        value: outcome,
        label: transferOutcomeLabels[outcome] ?? outcome,
    }));
const requestClose = (open: boolean) => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <V2Dialog
        :open="open"
        size="tall"
        :title="`Differences on ${number}`"
        description="Each resolution is permanent and recorded against your account. The original receipt is kept."
        confirm-label="Done"
        single-action
        :busy="busy"
        @update:open="requestClose"
        @confirm="emit('close')"
    >
        <div
            v-if="error"
            role="alert"
            class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <p class="font-medium">This difference was not resolved.</p>
            <p>{{ error }}</p>
        </div>
        <article
            v-for="difference in differences"
            :key="difference.publicId"
            class="grid min-w-0 gap-3 rounded-[14px] border p-3"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="min-w-0 text-sm font-medium wrap-anywhere">
                    {{ kindLabel(difference.kind) }} ·
                    {{ quantityWithUnit(difference.quantity, difference.unit) }}
                    <span
                        class="block text-xs font-normal text-muted-foreground"
                        >{{ difference.sku }} · batch
                        {{ difference.batch }}</span
                    >
                </p>
                <V2Chip
                    :tone="difference.status === 'open' ? 'warning' : 'success'"
                    :label="difference.status === 'open' ? 'Open' : 'Resolved'"
                />
            </div>
            <p
                v-if="difference.status === 'resolved'"
                class="text-sm text-muted-foreground"
            >
                {{
                    transferOutcomeLabels[difference.outcome ?? ''] ??
                    difference.outcome
                }}<template v-if="difference.reference">
                    · Reference {{ difference.reference }}</template
                >
            </p>
            <p
                v-else-if="difference.receivedByYou"
                class="text-sm text-muted-foreground"
            >
                You received this transfer, so a different CA Supervisor,
                Finance Officer or Director must resolve it.
            </p>
            <template v-else-if="difference.canResolve">
                <V2OptionGroup
                    :model-value="choice(difference.publicId).outcome"
                    label="Outcome"
                    required
                    :options="outcomeOptions(difference)"
                    @update:model-value="
                        (value) => (choice(difference.publicId).outcome = value)
                    "
                />
                <label class="grid gap-1.5 text-sm font-medium"
                    >Delivery note, inspection log or document reference
                    <span class="sr-only">(required)</span>
                    <input
                        v-model="choice(difference.publicId).reference"
                        maxlength="200"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                </label>
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center justify-self-start rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted disabled:opacity-50"
                    :disabled="busy || !ready(difference)"
                    @click="submit(difference)"
                >
                    Resolve this difference
                </button>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                Waiting for a CA Supervisor, Finance Officer or Director to
                resolve.
            </p>
        </article>
    </V2Dialog>
</template>

<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    transferDifferenceKindLabels,
    transferOutcomeLabels,
    transferOutcomesNeedingReference,
} from '@/lib/transfer-receipt';
import type { TransferDifferenceKind } from '@/lib/transfer-receipt';
import type { InventoryTransferDifference } from '@/types/inventory-operations';

const props = defineProps<{
    differences: InventoryTransferDifference[];
    number: string;
    busy: boolean;
}>();
const emit = defineEmits<{
    resolve: [
        difference: InventoryTransferDifference,
        outcome: string,
        reference: string,
        retryKey: string,
    ];
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
</script>

<template>
    <section
        v-if="differences.length > 0"
        class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-2 dark:border-amber-700 dark:bg-amber-950/40"
        :aria-label="`Transfer differences for ${number}`"
    >
        <h4 class="text-sm font-semibold">Transfer differences</h4>
        <article
            v-for="difference in differences"
            :key="difference.publicId"
            class="space-y-2 rounded-md border bg-card p-2 text-sm"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span
                    >{{ kindLabel(difference.kind) }} ·
                    {{ difference.quantity }} {{ difference.unit }} ·
                    {{ difference.sku }} · {{ difference.batch }}</span
                >
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="
                        difference.status === 'open'
                            ? 'bg-amber-200 text-amber-950 dark:bg-amber-800 dark:text-amber-50'
                            : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-50'
                    "
                    >{{
                        difference.status === 'open' ? 'Open' : 'Resolved'
                    }}</span
                >
            </div>
            <p
                v-if="difference.status === 'resolved'"
                class="text-xs text-muted-foreground"
            >
                {{
                    transferOutcomeLabels[difference.outcome ?? ''] ??
                    difference.outcome
                }}
                <span v-if="difference.reference">
                    · Reference {{ difference.reference }}</span
                >
            </p>
            <p
                v-else-if="difference.receivedByYou"
                class="text-xs text-muted-foreground"
            >
                You received this transfer, so a different CA Supervisor,
                Finance Officer or Director must resolve it.
            </p>
            <form
                v-else-if="difference.canResolve"
                class="space-y-2"
                @submit.prevent="submit(difference)"
            >
                <fieldset class="space-y-1">
                    <legend class="text-xs font-medium">Outcome</legend>
                    <label
                        v-for="outcome in difference.outcomes"
                        :key="outcome"
                        class="flex min-h-11 items-center gap-2 text-xs"
                    >
                        <input
                            v-model="choice(difference.publicId).outcome"
                            type="radio"
                            :name="`outcome-${difference.publicId}`"
                            :value="outcome"
                        />
                        {{ transferOutcomeLabels[outcome] ?? outcome }}
                    </label>
                </fieldset>
                <label class="grid gap-1 text-xs">
                    <span
                        >Delivery note, inspection log or document reference
                        (required)</span
                    >
                    <Input
                        v-model="choice(difference.publicId).reference"
                        maxlength="200"
                        :aria-label="`Reference for ${kindLabel(difference.kind)} ${difference.sku}`"
                    />
                </label>
                <Button
                    type="submit"
                    size="sm"
                    class="min-h-11"
                    :disabled="busy || !ready(difference)"
                    >Resolve difference</Button
                >
            </form>
            <p v-else class="text-xs text-muted-foreground">
                Waiting for a CA Supervisor, Finance Officer or Director to
                resolve.
            </p>
        </article>
    </section>
</template>

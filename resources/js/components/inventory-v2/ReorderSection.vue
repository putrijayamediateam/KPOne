<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { useReorderLevel } from '@/composables/inventory-v2/useReorderLevel';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate } from '@/lib/presentation';
import { trimQuantity } from '@/lib/supplier-terms';
import type { InventoryOperations } from '@/types/inventory-operations';

/**
 * Inventory V2 page 2c (reorder levels and alerts): the low-stock and expiring lists as V2 tables, and the reorder
 * level form as a short sheet. Same request, lock version, idempotency key and saved-level lookup as the Classic panel
 * (`useReorderLevel` is a copy of its logic). The sheet closes only after a successful save.
 */
const props = defineProps<{
    operations: InventoryOperations;
    /** The branch time zone, so "in N days" counts from the branch's today. */
    timezone: string;
}>();

const {
    busy,
    operationError,
    operationErrorContext,
    send,
    reorder,
    loadReorderLevel,
    reorderErrors,
} = useReorderLevel(props);

const open = ref(false);
const summary = ref<HTMLElement | null>(null);
watch(operationError, (value) => {
    if (value) {
        void nextTick(() => summary.value?.focus());
    }
});

const closeSheet = () => {
    open.value = false;
    reorder.location = '';
    reorder.sku = '';
};
/** Typed values ask "Discard your changes?" before the sheet closes. */
const dirty = computed(() => reorder.location !== '' || reorder.sku !== '');
const requestClose = (value: boolean) => {
    if (!value) {
        operationError.value = null;
        closeSheet();
    }
};
const ready = computed(
    () =>
        reorder.location !== '' &&
        reorder.sku !== '' &&
        !reorder.loading &&
        !reorder.loadFailed,
);
/** What the sheet knows about the saved level for the chosen pair, in words. */
const savedLine = computed(() => {
    if (!reorder.location || !reorder.sku) {
        return 'Choose a location and an item to see the saved level.';
    }

    if (reorder.loading) {
        return 'Loading the saved level…';
    }

    if (reorder.loadFailed) {
        return 'The saved level could not be loaded. Choose the location and item again.';
    }

    return reorder.lockVersion === null
        ? 'No level saved yet for this item here.'
        : 'This is the saved level. Change it and save, or close without saving.';
});

/**
 * The Classic save (same route, payload and refusal handling), except that a successful save closes the sheet. When
 * someone else saved first, the saved level is loaded again and the sheet stays open with the message.
 */
const saveReorder = () => {
    if (!ready.value) {
        return;
    }

    send(
        'put',
        '/inventory/reorder-levels',
        {
            expected_branch_id: props.operations.expectedBranchId,
            location_public_id: reorder.location,
            sku_public_id: reorder.sku,
            reorder_level: reorder.level,
            max_level: reorder.max,
            expected_lock_version: reorder.lockVersion,
            idempotency_key: reorder.key,
        },
        () => {
            reorderErrors.max_level = undefined;
            closeSheet();
        },
        (errors) => {
            reorderErrors.max_level = errors.max_level;

            if (errors.expected_lock_version) {
                // Someone else saved this level: show the saved numbers again so they can be checked first.
                void loadReorderLevel().then(() => {
                    operationErrorContext.value = 'Reorder level action';
                    operationError.value =
                        'Someone else changed this reorder level. The saved level is shown again; check it and save.';
                });
            }
        },
    );
};

const n = (value: unknown) => trimQuantity(String(value ?? '0'));
const units = (value: unknown, unit: unknown) =>
    quantityWithUnit(String(value ?? '0'), String(unit ?? ''));
/**
 * Show "100", not "100.000", in the boxes (Daniel): formatted once, when the copied Classic loader has filled them.
 * The server accepts "100", so the value sent is the same quantity.
 */
watch(
    () => reorder.loading,
    (loading, wasLoading) => {
        if (wasLoading && !loading && !reorder.loadFailed) {
            reorder.level = reorder.level === '' ? '' : n(reorder.level);
            reorder.max = reorder.max === '' ? '' : n(reorder.max);
        }
    },
);
const branchToday = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: props.timezone }).format(
        new Date(),
    );
/** "in 22 days", "tomorrow", "today": how long is left, from the branch's today (Daniel, 2c change 3). */
const daysLeft = (expiry: string) => {
    const days = Math.round(
        (Date.parse(`${expiry}T00:00:00Z`) -
            Date.parse(`${branchToday()}T00:00:00Z`)) /
            86_400_000,
    );

    return days <= 0 ? 'today' : days === 1 ? 'tomorrow' : `in ${days} days`;
};
</script>

<template>
    <div class="space-y-5" data-test="v2-reorder-section">
        <section class="space-y-3" aria-labelledby="v2-low-stock-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="v2-low-stock-title" class="text-[15px] font-semibold">
                    Low stock
                </h3>
                <button
                    v-if="operations.permissions.manageReorder"
                    type="button"
                    class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    :disabled="busy"
                    @click="open = true"
                >
                    Set a reorder level
                </button>
            </div>
            <V2Table
                label="Low stock"
                :columns="3"
                cards="xl"
                min-width="640px"
                :empty="operations.lowStock.length === 0"
                empty-message="No item is at or below its reorder level."
            >
                <template #head>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Location</th>
                        <th scope="col">Available</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in operations.lowStock"
                        :key="`${row.location}-${row.sku}`"
                    >
                        <td data-label="Item" data-primary>
                            <div class="min-w-0">
                                <span class="block font-medium break-words">{{
                                    row.item ?? row.sku
                                }}</span>
                                <span
                                    v-if="row.item"
                                    class="block text-xs break-words text-muted-foreground"
                                    >{{ row.sku }}</span
                                >
                            </div>
                        </td>
                        <td data-label="Location">{{ row.location }}</td>
                        <td data-label="Available">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <V2Chip
                                    tone="warning"
                                    :label="`${units(row.availableQuantity, row.stockUnit)} left`"
                                />
                                <span class="text-sm text-muted-foreground"
                                    >Below reorder level of
                                    {{ units(row.reorderLevel, row.stockUnit)
                                    }}<template v-if="row.maxLevel !== null">
                                        · fill to
                                        {{ n(row.maxLevel) }}</template
                                    ></span
                                >
                            </div>
                        </td>
                    </tr>
                </template>
            </V2Table>
            <p class="text-xs text-muted-foreground">
                Up to 100 low-stock entries and 100 expiring batches are shown.
                Use the Stock and Batches tabs above to search everything.
            </p>
        </section>

        <section class="space-y-3" aria-labelledby="v2-expiring-title">
            <h3 id="v2-expiring-title" class="text-[15px] font-semibold">
                Expiring in 90 days
            </h3>
            <V2Table
                label="Expiring in 90 days"
                :columns="4"
                cards="xl"
                min-width="640px"
                :empty="operations.expiringBatches.length === 0"
                empty-message="No available batch at this branch is near expiry."
            >
                <template #head>
                    <tr>
                        <th scope="col">Item and batch</th>
                        <th scope="col">Expires</th>
                        <th scope="col">Location</th>
                        <th scope="col">In stock</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in operations.expiringBatches"
                        :key="`${row.sku}-${row.batch}-${row.location}`"
                    >
                        <td data-label="Item and batch" data-primary>
                            <div class="min-w-0">
                                <span class="block font-medium break-words">{{
                                    row.item ?? row.sku
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    >{{ row.item ? `${row.sku} · ` : '' }}Batch
                                    {{ row.batch }}</span
                                >
                            </div>
                        </td>
                        <td data-label="Expires">
                            <span class="block whitespace-nowrap">{{
                                formatDate(row.expiryDate)
                            }}</span>
                            <span
                                class="block text-xs font-medium text-warning-strong"
                                >{{ daysLeft(row.expiryDate) }}</span
                            >
                        </td>
                        <td data-label="Location">{{ row.location }}</td>
                        <td data-label="In stock">
                            {{ units(row.quantity, row.stockUnit) }}
                        </td>
                    </tr>
                </template>
            </V2Table>
        </section>
    </div>

    <V2Dialog
        :open="open"
        :dirty="dirty"
        title="Set a reorder level"
        description="When stock at this location falls to the level, it shows under Low stock and in reorder suggestions."
        confirm-label="Save level"
        cancel-label="Cancel"
        :busy="busy"
        @update:open="requestClose"
        @confirm="saveReorder"
    >
        <div
            v-if="operationError"
            ref="summary"
            tabindex="-1"
            role="alert"
            class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive outline-none"
        >
            <p class="font-medium">
                {{ operationErrorContext }} needs attention
            </p>
            <p>{{ operationError }}</p>
        </div>
        <InventoryPickerField
            v-model="reorder.location"
            kind="locations"
            label="Location"
            required
            :expected-branch-id="operations.expectedBranchId"
        />
        <InventoryPickerField
            v-model="reorder.sku"
            kind="skus"
            label="Item"
            required
            :expected-branch-id="operations.expectedBranchId"
        />
        <p
            class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
            role="status"
        >
            {{ savedLine }}
        </p>
        <template v-if="ready">
            <label class="grid gap-1.5 text-sm font-medium"
                >Reorder level (stock units)
                <span class="sr-only">(required)</span>
                <input
                    v-model="reorder.level"
                    inputmode="decimal"
                    required
                    class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                />
            </label>
            <label class="grid gap-1.5 text-sm font-medium"
                >Maximum, stock units (optional)
                <input
                    v-model="reorder.max"
                    inputmode="decimal"
                    aria-describedby="v2-reorder-max-help v2-reorder-max-error"
                    :aria-invalid="reorderErrors.max_level ? true : undefined"
                    class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                />
                <span
                    id="v2-reorder-max-help"
                    class="text-xs font-normal text-muted-foreground"
                    >With a maximum, reorder suggestions fill up to it. Leave
                    blank to remove any maximum.</span
                >
                <span
                    v-if="reorderErrors.max_level"
                    id="v2-reorder-max-error"
                    class="text-sm text-destructive"
                    >{{ reorderErrors.max_level }}</span
                >
            </label>
        </template>
    </V2Dialog>
</template>

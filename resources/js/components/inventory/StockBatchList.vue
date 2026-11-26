<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { nextTick, ref, useId, watch } from 'vue';
import type { StockBalanceRow } from '@/components/inventory/StockOnHandList.vue';
import { StatusBadge } from '@/components/ui/status';
import {
    formatInventoryHoldLabel,
    formatInventoryReason,
} from '@/lib/inventory-picker';

const props = defineProps<{ rows: StockBalanceRow[]; loading: boolean }>();
const id = useId();
const list = ref<HTMLElement | null>(null);
const expanded = ref(new Set<number>());
watch(
    [() => props.rows, () => props.loading],
    async ([rows], [previousRows]) => {
        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const focusedKey =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('button[data-batch-key]')?.dataset
                      .batchKey
                : undefined;

        if (rows !== previousRows) {
            expanded.value = new Set();
        }

        if (focusedKey) {
            await nextTick();

            if (
                document.activeElement !== active &&
                document.activeElement !== document.body
            ) {
                return;
            }

            const remaining = Array.from(
                list.value?.querySelectorAll<HTMLButtonElement>(
                    'button[data-batch-key]',
                ) ?? [],
            ).find((button) => button.dataset.batchKey === focusedKey);
            (remaining ?? list.value)?.focus();
        }
    },
);
function rowKey(row: StockBalanceRow): string {
    return JSON.stringify([row.skuCode, row.batchNumber, row.location]);
}
function toggle(index: number): void {
    const next = new Set(expanded.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    expanded.value = next;
}
function batchLabel(row: StockBalanceRow): string {
    return row.holdReason ||
        row.held === true ||
        String(row.batchStatus).toLowerCase() === 'held'
        ? formatInventoryHoldLabel(formatInventoryReason(row.holdReason))
        : String(row.batchStatus);
}
</script>

<template>
    <section
        ref="list"
        tabindex="-1"
        aria-label="Batch and expiry balances by location"
        :aria-busy="loading"
        class="overflow-hidden rounded-2xl border border-border bg-card"
    >
        <div
            aria-hidden="true"
            class="hidden grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.2fr)] gap-3 border-b px-4 py-3 text-xs text-muted-foreground lg:grid"
        >
            <span class="pl-7">Item / batch</span><span>Quantity</span
            ><span>Location</span><span>Expiry</span><span>Batch status</span>
        </div>
        <p
            v-if="loading"
            role="status"
            class="p-6 text-center text-sm text-muted-foreground"
        >
            Loading records…
        </p>
        <ul v-else class="divide-y divide-border">
            <li
                v-for="(row, index) in rows"
                :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
            >
                <div class="px-2 lg:px-4">
                    <button
                        type="button"
                        :data-batch-key="rowKey(row)"
                        class="grid min-h-11 w-full grid-cols-2 items-start gap-x-3 gap-y-3 rounded-lg p-3 text-left hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.2fr)] lg:items-center lg:px-0 lg:py-4"
                        :aria-expanded="expanded.has(index)"
                        :aria-controls="`${id}-batch-${index}`"
                        @click="toggle(index)"
                    >
                        <span
                            class="col-span-2 flex min-w-0 items-start gap-3 lg:col-span-1"
                        >
                            <ChevronRight
                                class="mt-0.5 size-4 shrink-0 transition-transform"
                                :class="expanded.has(index) ? 'rotate-90' : ''"
                                aria-hidden="true"
                            />
                            <span class="min-w-0"
                                ><span
                                    class="block text-sm font-semibold break-words"
                                    >{{
                                        row.medicineName ?? row.itemName
                                    }}</span
                                ><span
                                    class="mt-1 block font-mono text-xs break-words text-muted-foreground"
                                    >{{ row.batchNumber }}</span
                                ></span
                            >
                        </span>
                        <span class="min-w-0 text-sm break-words"
                            ><span
                                class="block text-xs text-muted-foreground lg:sr-only"
                                >Quantity</span
                            ><span class="font-semibold tabular-nums"
                                >{{ row.quantity }} {{ row.stockUnit }}</span
                            ></span
                        >
                        <span class="min-w-0 text-sm break-words"
                            ><span
                                class="block text-xs text-muted-foreground lg:sr-only"
                                >Location</span
                            >{{ row.location }}</span
                        >
                        <span class="min-w-0 space-y-1 text-sm"
                            ><span
                                class="block text-xs text-muted-foreground lg:sr-only"
                                >Expiry</span
                            ><span class="block break-words">{{
                                row.expiryDate
                            }}</span
                            ><StatusBadge
                                :status="String(row.expiryStatus).toLowerCase()"
                                :label="String(row.expiryStatus)"
                                :tone="
                                    row.expiryStatus === 'Expired'
                                        ? 'danger'
                                        : 'success'
                                "
                        /></span>
                        <span class="min-w-0 space-y-1"
                            ><span
                                class="block text-xs text-muted-foreground lg:sr-only"
                                >Batch status</span
                            ><StatusBadge
                                class="max-w-full break-words whitespace-normal! [&>span]:shrink-0"
                                :status="String(row.batchStatus).toLowerCase()"
                                :label="batchLabel(row)"
                        /></span>
                    </button>
                </div>
                <div
                    v-show="expanded.has(index)"
                    :id="`${id}-batch-${index}`"
                    class="border-t bg-muted/30 px-5 py-4 lg:px-11"
                >
                    <p class="mb-3 text-xs text-muted-foreground">
                        This balance is for one batch at one location. Expiry
                        and batch status are shown separately.
                    </p>
                    <dl
                        class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-3"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">SKU</dt>
                            <dd class="mt-1 font-mono break-words">
                                {{ row.skuCode }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Item</dt>
                            <dd class="mt-1 break-words">{{ row.itemName }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Medicine
                            </dt>
                            <dd class="mt-1 break-words">
                                {{
                                    row.medicineName ?? 'Not mapped to Medicine'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Received
                            </dt>
                            <dd class="mt-1 break-words">
                                {{ row.receivedDate ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Batch</dt>
                            <dd class="mt-1 font-mono break-words">
                                {{ row.batchNumber }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Location
                            </dt>
                            <dd class="mt-1 break-words">{{ row.location }}</dd>
                        </div>
                    </dl>
                </div>
            </li>
        </ul>
    </section>
</template>

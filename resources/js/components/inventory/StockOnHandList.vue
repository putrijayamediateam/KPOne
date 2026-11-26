<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { ref, useId, watch } from 'vue';
import DifferenceRowMenu from '@/components/inventory/DifferenceRowMenu.vue';
import type { DifferenceState } from '@/components/inventory/ReportDifferenceSheet.vue';
import { StatusBadge } from '@/components/ui/status';
import {
    formatInventoryHoldLabel,
    formatInventoryReason,
} from '@/lib/inventory-picker';

export type StockBalanceRow = Record<
    string,
    string | boolean | null | undefined | DifferenceState
> & {
    held?: boolean;
    holdReason?: string | null;
    difference?: DifferenceState;
};
const props = defineProps<{
    rows: StockBalanceRow[];
    canReport: boolean;
    loading: boolean;
}>();
const emit = defineEmits<{
    report: [row: StockBalanceRow, trigger: HTMLElement | null];
}>();
const id = useId();
const expanded = ref(new Set<number>());
watch(
    () => props.rows,
    () => {
        expanded.value = new Set();
    },
);
function toggle(index: number): void {
    const next = new Set(expanded.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    expanded.value = next;
}
function statusLabel(row: StockBalanceRow): string {
    return row.holdReason ||
        row.held === true ||
        String(row.availabilityStatus).toLowerCase() === 'held'
        ? formatInventoryHoldLabel(formatInventoryReason(row.holdReason))
        : String(row.availabilityStatus);
}
</script>

<template>
    <section
        aria-label="On-hand batch and location balances"
        :aria-busy="loading"
        class="overflow-hidden rounded-2xl border border-border bg-card"
    >
        <div
            aria-hidden="true"
            class="hidden grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_2.75rem] gap-3 border-b px-4 py-3 text-xs text-muted-foreground lg:grid"
        >
            <span class="pl-7">Item / batch</span><span>On hand</span
            ><span>Batch expiry</span><span>Location</span><span>Status</span
            ><span></span>
        </div>
        <p
            v-if="loading"
            class="p-6 text-center text-sm text-muted-foreground"
            role="status"
        >
            Loading records…
        </p>
        <ul v-else class="divide-y divide-border">
            <li
                v-for="(row, index) in rows"
                :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
            >
                <div
                    class="flex items-start gap-1 px-2 lg:items-center lg:px-4"
                >
                    <button
                        type="button"
                        class="grid min-h-11 min-w-0 flex-1 grid-cols-2 items-start gap-x-3 gap-y-3 rounded-lg p-3 text-left hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)] lg:items-center lg:px-0 lg:py-4"
                        :aria-expanded="expanded.has(index)"
                        :aria-controls="`${id}-balance-${index}`"
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
                                >On hand</span
                            ><span class="font-semibold tabular-nums"
                                >{{ row.quantity }} {{ row.stockUnit }}</span
                            ></span
                        >
                        <span class="min-w-0 text-sm break-words"
                            ><span
                                class="block text-xs text-muted-foreground lg:sr-only"
                                >Batch expiry</span
                            >{{ row.expiryDate }}</span
                        >
                        <span class="min-w-0 text-sm break-words"
                            ><span
                                class="block text-xs text-muted-foreground lg:sr-only"
                                >Location</span
                            >{{ row.location }}</span
                        >
                        <span class="min-w-0 space-y-1"
                            ><StatusBadge
                                class="max-w-full break-words whitespace-normal! [&>span]:shrink-0"
                                :status="
                                    row.available ? 'available' : 'inactive'
                                "
                                :label="statusLabel(row)" /><StatusBadge
                                v-if="row.difference?.openCount"
                                status="count_open"
                                label="Count open"
                                tone="neutral"
                                dot
                        /></span>
                    </button>
                    <div v-if="canReport" class="mt-2 w-11 shrink-0 lg:mt-0">
                        <DifferenceRowMenu
                            v-if="row.difference"
                            :difference="row.difference"
                            :label="String(row.medicineName ?? row.itemName)"
                            @report="(trigger) => emit('report', row, trigger)"
                        />
                    </div>
                    <span
                        v-else
                        class="hidden w-11 shrink-0 lg:block"
                        aria-hidden="true"
                    ></span>
                </div>
                <div
                    v-show="expanded.has(index)"
                    :id="`${id}-balance-${index}`"
                    class="border-t bg-muted/30 px-5 py-4 lg:px-11"
                >
                    <p class="mb-3 text-xs text-muted-foreground">
                        This balance is for one batch at one location.
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
                                Branch
                            </dt>
                            <dd class="mt-1 break-words">{{ row.branch }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Location type
                            </dt>
                            <dd class="mt-1 break-words">
                                {{ row.locationType }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Availability
                            </dt>
                            <dd class="mt-1 break-words">
                                {{ statusLabel(row) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </li>
        </ul>
    </section>
</template>

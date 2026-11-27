<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status';
import type { InventoryDocument } from '@/types/inventory-operations';

const props = defineProps<{
    rows: InventoryDocument[];
    branchId: number;
    busy: boolean;
    statusLabel: (row: InventoryDocument) => string | undefined;
}>();
const id = useId();
const list = ref<HTMLElement | null>(null);
const selected = ref<string | null>(null);
const query = ref('');
const status = ref('all');
const focusedOrder = ref<string | undefined>();
const states = [
    ['draft', 'Draft'],
    ['submitted', 'Submitted'],
    ['approved', 'Approved'],
    ['partially_received', 'Partially received'],
    ['fully_received', 'Fully received'],
    ['closed', 'Closed'],
    ['cancelled', 'Cancelled'],
];
const matches = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();

    return props.rows.filter(
        (row) =>
            (status.value === 'all' || row.status === status.value) &&
            (!term ||
                [row.number, row.supplier, row.destination].some((value) =>
                    value?.toLocaleLowerCase().includes(term),
                )),
    );
});
const shown = (row: InventoryDocument) =>
    matches.value.some((match) => match.publicId === row.publicId) ||
    (props.busy && selected.value === row.publicId);
const toggle = (publicId: string) => {
    if (!props.busy) {
        selected.value = selected.value === publicId ? null : publicId;
    }
};
const rememberFocus = (event: FocusEvent) => {
    focusedOrder.value = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-po-row]',
    )?.dataset.poRow;
};
const leaveFocus = (event: FocusEvent) => {
    if (
        event.relatedTarget &&
        list.value?.contains(event.relatedTarget as Node)
    ) {
        return;
    }

    if (
        !event.relatedTarget &&
        (event.target as HTMLButtonElement).disabled === true
    ) {
        return;
    }

    focusedOrder.value = undefined;
};
watch(
    [() => props.rows, () => props.branchId],
    async ([rows, branch], [, oldBranch]) => {
        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const identity =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('[data-po-row]')?.dataset.poRow
                : typeof document !== 'undefined' && active === document.body
                  ? focusedOrder.value
                  : undefined;
        focusedOrder.value = undefined;

        if (branch !== oldBranch) {
            query.value = '';
            status.value = 'all';
        }

        if (
            branch !== oldBranch ||
            !rows.some((row) => row.publicId === selected.value)
        ) {
            selected.value = null;
        }

        if (!identity || typeof document === 'undefined') {
            return;
        }

        await nextTick();

        if (
            document.activeElement !== active &&
            document.activeElement !== document.body
        ) {
            return;
        }

        if (
            active !== document.body &&
            active?.isConnected &&
            active.getClientRects().length
        ) {
            return;
        }

        const trigger =
            branch === oldBranch
                ? Array.from(
                      list.value?.querySelectorAll<HTMLButtonElement>(
                          '[data-po-trigger]',
                      ) ?? [],
                  ).find((button) => button.dataset.poTrigger === identity)
                : undefined;
        (trigger ?? list.value)?.focus();
    },
);
</script>

<template>
    <section
        ref="list"
        tabindex="-1"
        aria-label="Purchase orders worklist"
        :aria-busy="busy"
        class="overflow-hidden rounded-2xl border border-border bg-card"
        @focusin="rememberFocus"
        @focusout="leaveFocus"
    >
        <div class="space-y-3 border-b border-border p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm"
                    ><span>Search loaded orders</span
                    ><Input
                        v-model="query"
                        :disabled="busy"
                        aria-label="Search loaded orders"
                        placeholder="Order, supplier or destination"
                        class="min-h-11 lg:min-h-9"
                /></label>
                <label class="grid gap-1 text-sm"
                    ><span>Show status</span
                    ><select
                        v-model="status"
                        :disabled="busy"
                        aria-label="Show purchase order status"
                        class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm lg:min-h-9"
                    >
                        <option value="all">All loaded statuses</option>
                        <option
                            v-for="[value, label] in states"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select></label
                >
            </div>
            <p class="text-sm text-muted-foreground">
                {{ matches.length }} matching loaded orders ·
                {{ rows.length }} loaded. Search and status apply only to the
                newest 50 orders available here.
            </p>
            <p v-if="busy" role="status" class="text-sm">
                An order action is in progress. Keep its details open until it
                finishes.
            </p>
        </div>
        <div
            class="hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1.5fr)_4rem_minmax(0,1fr)_5rem] gap-3 border-b border-border bg-muted/40 px-4 py-3 text-xs font-medium text-muted-foreground lg:grid"
            aria-hidden="true"
        >
            <span>ORDER</span><span>SUPPLIER / DESTINATION</span
            ><span>LINES</span><span>STATUS</span><span>DETAILS</span>
        </div>
        <p v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            No purchase orders are available in this branch context.
        </p>
        <p
            v-else-if="matches.length === 0 && !busy"
            class="p-6 text-sm text-muted-foreground"
        >
            No loaded orders match these filters. Clear the search or choose All
            loaded statuses.
        </p>
        <article
            v-for="row in rows"
            :key="row.publicId"
            v-show="shown(row)"
            :data-po-row="row.publicId"
            class="border-b border-border last:border-b-0"
        >
            <div
                class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1.5fr)_4rem_minmax(0,1fr)_5rem] lg:items-center"
            >
                <div class="min-w-0">
                    <span class="block text-xs text-muted-foreground lg:hidden"
                        >Order</span
                    ><span class="font-mono text-sm break-words">{{
                        row.number
                    }}</span>
                </div>
                <div class="min-w-0 text-sm">
                    <span class="block text-xs text-muted-foreground lg:hidden"
                        >Supplier / destination</span
                    >
                    <p class="break-words">
                        {{ row.supplier ?? 'Not specified' }}
                    </p>
                    <p class="break-words text-muted-foreground">
                        {{ row.destination ?? 'Not specified' }}
                    </p>
                </div>
                <div class="text-sm">
                    <span class="text-xs text-muted-foreground lg:hidden"
                        >Lines: </span
                    >{{ row.lines.length }}
                </div>
                <div>
                    <StatusBadge
                        :status="row.status"
                        :label="statusLabel(row)"
                    />
                </div>
                <button
                    type="button"
                    :disabled="busy"
                    :data-po-trigger="row.publicId"
                    :aria-label="`${selected === row.publicId ? 'Close' : 'Open'} purchase order ${row.number}`"
                    :aria-expanded="selected === row.publicId"
                    :aria-controls="`${id}-${row.publicId}`"
                    class="inline-flex min-h-11 items-center justify-center gap-1 rounded-md border border-border px-2 text-sm font-medium hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50 lg:min-h-9"
                    @click="toggle(row.publicId)"
                >
                    <ChevronRight
                        class="size-4 shrink-0"
                        :class="selected === row.publicId ? 'rotate-90' : ''"
                        aria-hidden="true"
                    />{{ selected === row.publicId ? 'Close' : 'Open' }}
                </button>
            </div>
            <div
                :id="`${id}-${row.publicId}`"
                v-show="selected === row.publicId"
                class="space-y-3 border-t border-border bg-muted/20 p-4"
                :aria-label="`Details for purchase order ${row.number}`"
            >
                <slot :row="row" />
            </div>
        </article>
    </section>
</template>

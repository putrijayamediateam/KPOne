<script
    setup
    lang="ts"
    generic="
        Row extends {
            publicId: string;
            number: string;
            supplier: string;
            invoiceDate: string;
            orderNumber: string;
            status: string;
            totalSen?: number;
        }
    "
>
import { ChevronRight } from '@lucide/vue';
import { nextTick, ref, useId, watch } from 'vue';
import { StatusBadge } from '@/components/ui/status';

const props = defineProps<{
    rows: Row[];
    contextKey: string;
    showAmounts: boolean;
    money: (sen: number | undefined) => string;
    branchId: number;
    busy: boolean;
    statusLabel: (status: string) => string;
    statusTone: (
        status: string,
    ) => 'warning' | 'success' | 'neutral' | 'danger';
}>();
const id = useId();
const list = ref<HTMLElement | null>(null);
const selected = ref<string | null>(null);
const focusedOrder = ref<string | undefined>();
const toggle = (publicId: string) => {
    if (!props.busy) {
        selected.value = selected.value === publicId ? null : publicId;
    }
};
const rememberFocus = (event: FocusEvent) => {
    focusedOrder.value = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-invoice-row]',
    )?.dataset.invoiceRow;
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
    [() => props.rows, () => props.branchId, () => props.contextKey],
    async ([rows, branch, context], [, oldBranch, oldContext]) => {
        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const identity =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('[data-invoice-row]')?.dataset
                      .invoiceRow
                : typeof document !== 'undefined' && active === document.body
                  ? focusedOrder.value
                  : undefined;
        focusedOrder.value = undefined;

        if (
            branch !== oldBranch ||
            context !== oldContext ||
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
                          '[data-invoice-trigger]',
                      ) ?? [],
                  ).find((button) => button.dataset.invoiceTrigger === identity)
                : undefined;
        (trigger ?? list.value)?.focus();
    },
);
</script>
<template>
    <section
        ref="list"
        tabindex="-1"
        aria-label="Supplier invoice matches"
        :aria-busy="busy"
        class="overflow-hidden rounded-2xl border border-border bg-card"
        @focusin="rememberFocus"
        @focusout="leaveFocus"
    >
        <p v-if="busy" role="status" class="border-b border-border p-4 text-sm">
            An invoice action is in progress. Keep its match details open until
            it finishes.
        </p>
        <div
            class="hidden grid-cols-[minmax(0,1.7fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)_7rem] gap-3 border-b border-border bg-muted/40 px-4 py-3 text-xs font-medium text-muted-foreground lg:grid"
            aria-hidden="true"
        >
            <span>INVOICE</span><span>ORDER</span><span>TOTAL</span
            ><span>STATUS</span><span>MATCH</span>
        </div>
        <p v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            No invoices here.
        </p>
        <article
            v-for="row in rows"
            :key="row.publicId"
            :data-invoice-row="row.publicId"
            class="border-b border-border last:border-b-0"
        >
            <div
                class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)_7rem] lg:items-start"
            >
                <div class="min-w-0 space-y-1">
                    <span class="block text-xs text-muted-foreground lg:hidden"
                        >Invoice</span
                    >
                    <p class="font-mono text-sm break-words">
                        {{ row.number }}
                    </p>
                    <p class="text-sm break-words">{{ row.supplier }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ row.invoiceDate }}
                    </p>
                </div>
                <div class="min-w-0">
                    <span
                        class="mb-1 block text-xs text-muted-foreground lg:hidden"
                        >Order</span
                    >
                    <p class="font-mono text-sm break-words">
                        {{ row.orderNumber }}
                    </p>
                </div>
                <div class="min-w-0">
                    <span
                        class="mb-1 block text-xs text-muted-foreground lg:hidden"
                        >Total</span
                    >
                    <p v-if="showAmounts" class="text-sm tabular-nums">
                        {{ money(row.totalSen) }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        Not shown
                    </p>
                </div>
                <div>
                    <span
                        class="mb-1 block text-xs text-muted-foreground lg:hidden"
                        >Status</span
                    ><StatusBadge
                        :status="row.status"
                        :label="statusLabel(row.status)"
                        :tone="statusTone(row.status)"
                    />
                </div>
                <button
                    type="button"
                    :data-invoice-trigger="row.publicId"
                    :aria-label="`${selected === row.publicId ? 'Close' : 'Open'} match for invoice ${row.number}`"
                    :aria-expanded="selected === row.publicId"
                    :aria-controls="`${id}-${row.publicId}`"
                    :disabled="busy"
                    class="inline-flex min-h-11 items-center justify-center gap-1 rounded-lg border border-border px-3 text-sm hover:bg-muted disabled:opacity-50 lg:min-h-9"
                    @click="toggle(row.publicId)"
                >
                    <ChevronRight
                        aria-hidden="true"
                        class="size-4 shrink-0"
                        :class="selected === row.publicId ? 'rotate-90' : ''"
                    />{{ selected === row.publicId ? 'Close' : 'Open match' }}
                </button>
            </div>
            <div
                v-show="selected === row.publicId"
                :id="`${id}-${row.publicId}`"
                :aria-label="`Match for invoice ${row.number}`"
                class="border-t border-border bg-muted/10 p-4"
            >
                <slot :row="row" />
            </div>
        </article>
    </section>
</template>

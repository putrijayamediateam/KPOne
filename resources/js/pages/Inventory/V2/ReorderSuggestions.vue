<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2SelectionBar from '@/components/v2/V2SelectionBar.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatRinggit, packLabel, trimQuantity } from '@/lib/supplier-terms';

/**
 * Version 2 Reorder suggestions (inventory V2 page 10a). Everything from `type Row` to `visit`, and the STK-18 tick block,
 * are verbatim copies of Classic (a test compares them). Only the layout is new: "Show" as pressed buttons, one table
 * (cards below 1280px), a tick per row that needs ordering, and the selection bar carrying "Request these".
 * Cost shows only for a Director or Finance Officer, as in Classic. Nothing is ordered from this page.
 */
type Row = {
    key: string;
    skuPublicId: string;
    locationPublicId: string;
    skuCode: string;
    itemName: string;
    location: string;
    stockUnit: string;
    reorderLevel: string;
    maxLevel: string | null;
    onHand: string;
    onOrder: string;
    incoming: string;
    averageDailyUse: string;
    supplier: {
        name: string;
        leadTimeDays: number;
        packDescription: string;
        packQuantity: string;
        minimumOrderPacks: number | null;
    } | null;
    suggestedPacks: number | null;
    suggestedQuantity: string;
    coveredByOrders: boolean;
    estimateSen?: number | null;
};

const props = defineProps<{
    suggestions: {
        branchName: string;
        canSeeCost: boolean;
        canRequest: boolean;
        lookBackDays: number;
        filters: { q: string; need: 'order' | 'all' };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.suggestions.filters.q);
const need = ref(props.suggestions.filters.need);
const visit = (page = 1) =>
    router.get(
        '/inventory/reorder-suggestions',
        { q: q.value || undefined, need: need.value, page },
        { preserveScroll: true },
    );

// STK-18: tick suggestions of one location and send them as a purchase request (checked and sent on the next page).
const needsOrder = (row: Row) =>
    !row.coveredByOrders && row.suggestedQuantity !== '0.000';
const ticked = ref<string[]>([]);
const tickedRows = computed(() =>
    props.suggestions.rows.filter((row) => ticked.value.includes(row.key)),
);
const oneLocation = computed(
    () =>
        new Set(tickedRows.value.map((row) => row.locationPublicId)).size <= 1,
);
const requestThese = () => {
    if (!tickedRows.value.length || !oneLocation.value) {
        return;
    }

    router.get('/inventory/purchase-requests', {
        location: tickedRows.value[0].locationPublicId,
        // "sku:quantity" strings: a list of plain values survives the query string unchanged.
        lines: tickedRows.value.map(
            (row) =>
                `${row.skuPublicId}:${trimQuantity(row.suggestedQuantity)}`,
        ),
    });
};

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Reorder suggestions' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const needOptions = [
    { value: 'order', label: 'Only rows that need ordering' },
    { value: 'all', label: 'All low-stock rows' },
];
const chooseNeed = (value: string | null) => {
    if (value === 'order' || value === 'all') {
        need.value = value;
        visit();
    }
};
const canTick = computed(
    () =>
        props.suggestions.canRequest && props.suggestions.rows.some(needsOrder),
);
const tick = (row: Row, value: boolean | 'indeterminate') => {
    ticked.value =
        value === true
            ? [...ticked.value, row.key]
            : ticked.value.filter((key) => key !== row.key);
};
const tickedSummary = computed(() => {
    const count = ticked.value.length;
    const place = tickedRows.value[0]?.location;

    return `${count} ${count === 1 ? 'suggestion' : 'suggestions'} ticked${oneLocation.value && place ? ` · ${place}` : ''}`;
});
const packs = (count: number) => `${count} ${count === 1 ? 'pack' : 'packs'}`;
const leadDays = (days: number) => `${days} ${days === 1 ? 'day' : 'days'}`;
</script>

<template>
    <Head title="Reorder suggestions" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Reorder suggestions"
            :description="`${suggestions.branchName} · Stock at or below its reorder level, with a suggested order. Nothing is ordered from this page.`"
        >
            <template #main>
                <details
                    class="rounded-[14px] border bg-card px-4 py-3 text-sm"
                    data-testid="reorder-method"
                >
                    <summary
                        class="flex min-h-11 cursor-pointer items-center font-medium lg:min-h-9"
                    >
                        How the suggestion is worked out
                    </summary>
                    <p class="pt-2">
                        Suggested = reorder level − on hand − on order −
                        incoming + {{ suggestions.lookBackDays }}-day average
                        use × lead time, rounded up to whole packs and at least
                        the supplier's minimum order. Where a maximum is set,
                        the maximum is used instead of the reorder level.
                    </p>
                </details>
                <p
                    v-if="!suggestions.canSeeCost"
                    class="text-sm text-muted-foreground"
                    data-testid="price-hidden-note"
                >
                    Estimated costs are shown only to a Director or Finance
                    Officer.
                </p>

                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    role="search"
                    @submit.prevent="visit()"
                >
                    <label
                        class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                        >Search SKU, item or location
                        <input
                            v-model="q"
                            type="search"
                            maxlength="100"
                            class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9"
                    /></label>
                    <button
                        type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >
                        Search
                    </button>
                </form>
                <V2OptionGroup
                    :model-value="need"
                    label="Show"
                    required
                    :options="needOptions"
                    @update:model-value="chooseNeed"
                />

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-reorder"
                >
                    <h2
                        id="v2-reorder"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Suggestions ({{ suggestions.total }})
                    </h2>
                    <p
                        v-if="canTick"
                        class="text-sm text-muted-foreground"
                        data-testid="reorder-request-bar"
                    >
                        Tick suggestions of one location to ask for them in a
                        purchase request. It is checked and sent on the next
                        page.
                    </p>
                    <V2Table
                        label="Reorder suggestions"
                        :columns="canTick ? 6 : 5"
                        cards="xl"
                        min-width="1000px"
                        :empty="suggestions.rows.length === 0"
                        :empty-message="
                            suggestions.filters.need === 'order'
                                ? 'Nothing needs ordering at this branch.'
                                : 'No stock is at or below its reorder level at this branch.'
                        "
                    >
                        <template #head>
                            <tr>
                                <th v-if="canTick" scope="col" class="w-12">
                                    <span class="sr-only">Tick</span>
                                </th>
                                <th scope="col">SKU and location</th>
                                <th scope="col">Suggested order</th>
                                <th scope="col" class="text-right">
                                    On hand and levels
                                </th>
                                <th scope="col" class="text-right">
                                    Coming in and use
                                </th>
                                <th scope="col">Preferred supplier</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in suggestions.rows" :key="row.key">
                                <td v-if="canTick" data-label="Ask for this">
                                    <span
                                        v-if="needsOrder(row)"
                                        class="-m-3 inline-flex min-h-11 min-w-11 items-center justify-center"
                                        ><Checkbox
                                            :model-value="
                                                ticked.includes(row.key)
                                            "
                                            :aria-label="`Ask for ${row.skuCode} at ${row.location}`"
                                            @update:model-value="
                                                (value) => tick(row, value)
                                            "
                                    /></span>
                                </td>
                                <td
                                    data-stack
                                    data-label="SKU and location"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ row.itemName }}</span
                                        >
                                        <span
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(row.skuCode) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.location }}</span
                                        >
                                        <V2Chip
                                            v-if="row.onHand === '0.000'"
                                            class="mt-1 whitespace-nowrap"
                                            tone="danger"
                                            label="Out of stock"
                                            data-testid="out-of-stock"
                                        />
                                    </div>
                                </td>
                                <td data-stack data-label="Suggested order">
                                    <V2Chip
                                        v-if="row.coveredByOrders"
                                        class="whitespace-nowrap"
                                        tone="success"
                                        label="Covered by orders"
                                    />
                                    <V2Chip
                                        v-else-if="
                                            row.suggestedQuantity === '0.000'
                                        "
                                        tone="neutral"
                                        label="At reorder level, no order needed"
                                    />
                                    <div v-else class="min-w-0">
                                        <span
                                            class="block text-sm font-semibold whitespace-nowrap tabular-nums"
                                            >{{
                                                row.suggestedPacks !== null
                                                    ? packs(row.suggestedPacks)
                                                    : quantityWithUnit(
                                                          row.suggestedQuantity,
                                                          row.stockUnit,
                                                      )
                                            }}</span
                                        >
                                        <span
                                            v-if="row.suggestedPacks !== null"
                                            class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                            >=
                                            {{
                                                quantityWithUnit(
                                                    row.suggestedQuantity,
                                                    row.stockUnit,
                                                )
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                suggestions.canSeeCost &&
                                                row.supplier &&
                                                row.suggestedPacks !== null
                                            "
                                            class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                            data-testid="reorder-estimate"
                                            >{{
                                                row.estimateSen === null ||
                                                row.estimateSen === undefined
                                                    ? 'No price recorded'
                                                    : `About RM ${formatRinggit(row.estimateSen)}`
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="On hand and levels">
                                    <dl
                                        class="grid grid-cols-[auto_auto] justify-end gap-x-3 gap-y-0.5 text-sm tabular-nums"
                                    >
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            On hand
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.onHand,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Reorder level
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.reorderLevel,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Maximum
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                row.maxLevel !== null
                                                    ? quantityWithUnit(
                                                          row.maxLevel,
                                                          row.stockUnit,
                                                      )
                                                    : 'Not set'
                                            }}
                                        </dd>
                                    </dl>
                                </td>
                                <td data-stack data-label="Coming in and use">
                                    <dl
                                        class="grid grid-cols-[auto_auto] justify-end gap-x-3 gap-y-0.5 text-sm tabular-nums"
                                    >
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            On order
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.onOrder,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Incoming transfers
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.incoming,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                        <dt
                                            class="whitespace-nowrap text-muted-foreground"
                                        >
                                            Use a day ({{
                                                suggestions.lookBackDays
                                            }}
                                            days)
                                        </dt>
                                        <dd
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{
                                                quantityWithUnit(
                                                    row.averageDailyUse,
                                                    row.stockUnit,
                                                )
                                            }}
                                        </dd>
                                    </dl>
                                </td>
                                <td data-stack data-label="Preferred supplier">
                                    <div
                                        v-if="row.supplier"
                                        class="min-w-0 text-sm"
                                    >
                                        <span class="block break-words">{{
                                            row.supplier.name
                                        }}</span>
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{
                                                leadDays(
                                                    row.supplier.leadTimeDays,
                                                )
                                            }}
                                            ·
                                            {{
                                                packLabel(
                                                    row.supplier
                                                        .packDescription,
                                                    row.supplier.packQuantity,
                                                    row.stockUnit,
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <p
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        None set. The suggestion is in whole
                                        stock units with no lead time. Set one
                                        in
                                        <Link
                                            href="/inventory/supplier-terms"
                                            class="text-primary underline underline-offset-4"
                                            >Supplier price list</Link
                                        >.
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="suggestions.total > 0"
                        :current-page="suggestions.page"
                        :last-page="suggestions.lastPage"
                        :total="suggestions.total"
                        :per-page="25"
                        @change="(n) => visit(n)"
                    />
                </section>
                <V2SelectionBar
                    :visible="ticked.length > 0"
                    :summary="tickedSummary"
                    action-label="Request these"
                    secondary-label="Clear"
                    :action-disabled="!oneLocation"
                    :hint="
                        oneLocation
                            ? undefined
                            : 'A request is for one location. Tick suggestions of one location only.'
                    "
                    hint-tone="warning"
                    data-testid="reorder-request-these"
                    @action="requestThese"
                    @secondary="ticked = []"
                />
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

<style scoped>
/* Phone and tablet cards: a cell with several lines puts its label on its own line and uses the full width. */
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
    :deep(.v2-cards-xl td[data-stack] dl) {
        justify-content: start;
    }
}
</style>

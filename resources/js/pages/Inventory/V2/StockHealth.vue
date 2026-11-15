<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';

/**
 * Version 2 Stock health (inventory V2 page 10a). Everything from `type Row` to `visit`, and `plural`, are verbatim copies
 * of Classic (a test compares them). Only the layout is new: three stat tiles, the period as pressed buttons, and one
 * table (cards below 1280px). Quantities read "40 tablets". Read only, as in Classic.
 */
type Row = {
    key: string;
    skuCode: string;
    itemName: string;
    location: string;
    locationCode: string;
    stockUnit: string;
    onHand: string;
    usable: string;
    used: string;
    averageOnHand: string;
    daysOnHand: number | null;
    noUse: boolean;
    turnover: string | null;
    expired: string;
    expiryLossPercent: string | null;
};

const props = defineProps<{
    health: {
        branchName: string;
        periods: number[];
        slowDays: number;
        filters: { days: number; q: string };
        summary: { slow: number; noUse: number; expiryLoss: number };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.health.filters.q);
const days = ref(props.health.filters.days);
const visit = (page = 1) =>
    router.get(
        '/inventory/stock-health',
        { q: q.value || undefined, days: days.value, page },
        { preserveScroll: true },
    );

const plural = (count: number, one: string, many: string) =>
    `${count} ${count === 1 ? one : many}`;

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock health' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const periodOptions = props.health.periods.map((period) => ({
    value: String(period),
    label: `Last ${period} days`,
}));
const choosePeriod = (value: string | null) => {
    if (value === null) {
        return;
    }

    days.value = Number(value);
    visit();
};
</script>

<template>
    <Head title="Stock health" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Stock health"
            :description="`${health.branchName} · How long stock lasts, how often it is used up, and how much is lost to expiry. Quantities only; nothing changes from this page.`"
        >
            <template #main>
                <ul
                    class="grid gap-3 sm:grid-cols-3"
                    aria-label="Stock health summary"
                    data-testid="stock-health-summary"
                >
                    <li>
                        <V2Stat
                            label="No use in the period"
                            :value="String(health.summary.noUse)"
                            detail="SKUs with stock but no use"
                        />
                    </li>
                    <li>
                        <V2Stat
                            label="Slow moving"
                            :value="String(health.summary.slow)"
                            :detail="`More than ${health.slowDays} days on hand`"
                        />
                    </li>
                    <li>
                        <V2Stat
                            label="Lost to expiry"
                            :value="String(health.summary.expiryLoss)"
                            detail="SKUs with stock written off as expired"
                        />
                    </li>
                </ul>

                <form
                    class="flex min-w-0 flex-wrap items-end gap-3"
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
                <div data-testid="stock-health-period">
                    <V2OptionGroup
                        :model-value="String(days)"
                        label="Period"
                        required
                        :options="periodOptions"
                        @update:model-value="choosePeriod"
                    />
                </div>

                <div data-testid="stock-health-method">
                    <V2DisclosureBar title="How these figures are worked out">
                        <div class="flex flex-col gap-2 text-sm">
                            <p>
                                <strong>Used</strong> is what was dispensed or
                                used in a treatment room in the period.
                                <strong>Days on hand</strong> is the usable
                                stock now ÷ the average use a day.
                                <strong>Turnover</strong> is used ÷ the average
                                stock held. <strong>Expiry loss</strong> is the
                                stock written off as expired ÷ (used + all
                                write-offs). Longest on hand first; stock with
                                no use in the period comes first of all.
                            </p>
                            <p class="text-muted-foreground">
                                No money figures: stock value waits for the
                                clinic's cost policy.
                            </p>
                        </div>
                    </V2DisclosureBar>
                </div>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-stock-health"
                >
                    <h2
                        id="v2-stock-health"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Stock by SKU and location ({{ health.total }})
                    </h2>
                    <V2Table
                        class="v2-health-cards"
                        label="Stock health by SKU"
                        :columns="6"
                        cards="xl"
                        min-width="960px"
                        :empty="health.rows.length === 0"
                        empty-message="No stock was held or used at this branch in the period."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and location</th>
                                <th scope="col" class="text-right">
                                    Days on hand
                                </th>
                                <th scope="col" class="text-right">On hand</th>
                                <th scope="col" class="text-right">Used</th>
                                <th scope="col" class="text-right">Turnover</th>
                                <th scope="col" class="text-right">
                                    Expiry loss
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in health.rows" :key="row.key">
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
                                            >{{ row.location }} ·
                                            {{
                                                unbroken(row.locationCode)
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-label="Days on hand"
                                    class="text-right text-sm tabular-nums"
                                >
                                    <V2Chip
                                        v-if="row.noUse"
                                        class="whitespace-nowrap"
                                        tone="warning"
                                        label="No use in this period"
                                        data-testid="no-use"
                                    />
                                    <span
                                        v-else-if="row.daysOnHand === null"
                                        class="text-muted-foreground"
                                        >No usable stock</span
                                    >
                                    <span
                                        v-else-if="row.daysOnHand > 365"
                                        class="font-semibold whitespace-nowrap"
                                        data-testid="over-a-year"
                                        >More than 1 year
                                        <span class="sr-only"
                                            >({{
                                                plural(
                                                    row.daysOnHand,
                                                    'day',
                                                    'days',
                                                )
                                            }})</span
                                        ></span
                                    >
                                    <span
                                        v-else
                                        class="font-semibold whitespace-nowrap"
                                        >{{
                                            plural(
                                                row.daysOnHand,
                                                'day',
                                                'days',
                                            )
                                        }}</span
                                    >
                                </td>
                                <td data-stack data-label="On hand">
                                    <div class="min-w-0 text-right">
                                        <span
                                            class="block text-sm whitespace-nowrap tabular-nums"
                                            >{{
                                                quantityWithUnit(
                                                    row.onHand,
                                                    row.stockUnit,
                                                )
                                            }}</span
                                        >
                                        <span
                                            class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                            >{{
                                                quantityWithUnit(
                                                    row.usable,
                                                    row.stockUnit,
                                                )
                                            }}
                                            usable</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-label="Used"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        quantityWithUnit(
                                            row.used,
                                            row.stockUnit,
                                        )
                                    }}
                                </td>
                                <td
                                    data-label="Turnover"
                                    class="text-right text-sm tabular-nums"
                                >
                                    <span
                                        v-if="row.turnover !== null"
                                        class="whitespace-nowrap"
                                        >{{ row.turnover }} times</span
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >Not enough stock history</span
                                    >
                                </td>
                                <td data-stack data-label="Expiry loss">
                                    <div class="min-w-0 text-right">
                                        <template
                                            v-if="
                                                row.expiryLossPercent !== null
                                            "
                                        >
                                            <span
                                                class="block text-sm whitespace-nowrap tabular-nums"
                                                >{{
                                                    row.expiryLossPercent
                                                }}%</span
                                            >
                                            <span
                                                class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                                >{{
                                                    quantityWithUnit(
                                                        row.expired,
                                                        row.stockUnit,
                                                    )
                                                }}
                                                expired</span
                                            >
                                        </template>
                                        <span
                                            v-else
                                            class="text-sm whitespace-nowrap text-muted-foreground"
                                            >No use or write-offs</span
                                        >
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="health.total > 0"
                        :current-page="health.page"
                        :last-page="health.lastPage"
                        :total="health.total"
                        :per-page="25"
                        @change="(n) => visit(n)"
                    />
                </section>
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
    :deep(.v2-cards-xl td[data-stack] .text-right) {
        text-align: left;
    }
    /* Daniel (page 10a): compact cards, the medicine on top, then a two-column label and value grid, so a card is about
       half as tall. Same fields and words as the table. */
    :deep(.v2-health-cards.v2-cards-xl tbody tr) {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        column-gap: 1rem;
        padding: 0.5rem 0.75rem;
    }
    :deep(.v2-health-cards.v2-cards-xl td) {
        flex-direction: column;
        align-items: flex-start;
        justify-content: flex-start;
        gap: 0.125rem;
        padding: 0.375rem 0 !important;
        text-align: left !important;
    }
    :deep(.v2-health-cards.v2-cards-xl td[data-primary]) {
        grid-column: 1 / -1;
    }
    :deep(.v2-health-cards.v2-cards-xl td .text-right) {
        text-align: left;
    }
}
</style>

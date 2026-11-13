<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatStatusLabel } from '@/lib/presentation';

/**
 * Version 2 Price list check (shown only in V2; Classic is `pages/Pricing/PriceCheck.vue`, untouched). Read-only: the
 * prices and checks come from the server, which uses billing's own price choice. Same props, same address and filters.
 */
type Check =
    | 'no_charge'
    | 'charge_inactive'
    | 'no_self_pay'
    | 'unit_differs'
    | 'no_panel_default'
    | 'zero_price';
type Row = {
    type: 'consultation' | 'medicine' | 'service';
    code: string;
    displayName: string;
    orderUnit: string | null;
    chargePublicId: string | null;
    chargeUnit: string | null;
    selfPaySen: number | null;
    selfPayFromBranch: boolean;
    panelDefaultSen: number | null;
    panelPrices: { panel: string; amountSen: number }[];
    checks: Check[];
};
type Filters = {
    search: string | null;
    type: string | null;
    attention: boolean;
};

const props = defineProps<{
    branch: { id: number; name: string } | null;
    filters: Filters;
    result: {
        rows: Row[];
        summary: {
            checked: number;
            attention: number;
            noSelfPay: number;
            noPanelDefault: number;
        };
        total: number;
        currentPage: number;
        lastPage: number;
    } | null;
}>();

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Pricing', href: '/pricing' },
    { title: 'Price list check' },
]);

const page = usePage<{ errors?: Record<string, string> }>();
const filterError = computed(
    () => Object.values(page.props.errors ?? {})[0] ?? '',
);

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
const attention = ref(props.filters.attention);
const typeOptions = [
    { id: 'medicine', label: 'Medicine' },
    { id: 'service', label: 'Clinical service' },
    { id: 'consultation', label: 'Consultation' },
];
const typeLabels: Record<Row['type'], string> = {
    consultation: 'Consultation',
    medicine: 'Medicine',
    service: 'Clinical service',
};

const visit = (overrides: Record<string, string | number | boolean> = {}) => {
    router.get(
        '/pricing/price-check',
        {
            search: search.value || undefined,
            type: type.value || undefined,
            attention: attention.value ? 1 : undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
// Toggles the attention filter using the filters already applied, not unsent text.
const toggleAttention = () => {
    const next = !props.filters.attention;
    attention.value = next;
    search.value = props.filters.search ?? '';
    type.value = props.filters.type ?? '';
    visit({ page: 1 });
};
const reload = () => router.reload();
// An item with a charge opens its price history; the history page offers a way back here.
const historyHref = (row: Row) =>
    `/pricing/charges/${row.chargePublicId}/history?from=price-check`;

const statusKey = (check: Check) =>
    check === 'zero_price' ? 'price_zero' : `price_${check}`;
const checkTone = (check: Check): 'danger' | 'warning' =>
    check === 'no_panel_default' || check === 'zero_price'
        ? 'warning'
        : 'danger';
// The server lists checks most serious first; show at most two chips.
const shownChecks = (row: Row) => row.checks.slice(0, 2);
const hiddenChecks = (row: Row) => row.checks.slice(2);
const allChecksText = (row: Row) =>
    row.checks.map((check) => formatStatusLabel(statusKey(check))).join(', ');
const unitText = (row: Row) =>
    `Price unit: ${row.chargeUnit ?? '—'} · Order unit: ${row.orderUnit ?? '—'}`;
const rm = (sen: number | null) =>
    sen === null
        ? '—'
        : `RM ${Math.floor(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;
const panelSummary = (row: Row) =>
    row.panelPrices.length === 0
        ? null
        : `${row.panelPrices.length} ${row.panelPrices.length === 1 ? 'Panel' : 'Panels'}`;
const panelText = (row: Row) =>
    row.panelPrices
        .map((price) => `${price.panel}: ${rm(price.amountSen)}`)
        .join(', ');

// "Nothing needs attention" only when the attention filter alone is on.
const onlyAttention = computed(
    () =>
        props.filters.attention && !props.filters.search && !props.filters.type,
);
const emptyTitle = computed(() =>
    onlyAttention.value
        ? 'Nothing needs attention.'
        : 'No active medicines or services match.',
);
const emptyDescription = computed(() =>
    onlyAttention.value
        ? 'All active items have the prices billing needs.'
        : 'Change the search or type, or add items in the catalogues.',
);
// A text button with an underline and a 44 px target at touch widths (opens the full list and the unit text).
const textButton =
    'inline-flex min-h-11 items-center text-sm font-medium text-primary underline underline-offset-2 hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const open = ref<Set<string>>(new Set());
const rowKey = (row: Row, part: string) => `${row.type}:${row.code}:${part}`;
const isOpen = (row: Row, part: string) => open.value.has(rowKey(row, part));
const toggleOpen = (row: Row, part: string) => {
    const next = new Set(open.value);
    const key = rowKey(row, part);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    open.value = next;
};
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
</script>

<template>
    <Head title="Price list check" />
    <PageFrame>
        <Link
            href="/pricing"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back-to-pricing"
        >
            ← Pricing
        </Link>
        <V2WorkLayout
            title="Price list check"
            description="Every active medicine and clinical service with its current prices. Fix anything flagged before using it in billing."
        >
            <template #main>
                <div
                    v-if="filterError"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
                >
                    The filters could not be applied. {{ filterError }}
                    <button
                        type="button"
                        :class="outlineButton"
                        @click="reload"
                    >
                        Reload
                    </button>
                </div>

                <p
                    v-if="!branch || !result"
                    class="rounded-[10px] border border-dashed p-4 text-sm text-muted-foreground"
                >
                    <span class="block font-medium text-foreground"
                        >Select a branch first.</span
                    >
                    Prices can differ by branch. Choose your branch in the
                    header, then open this page again.
                </p>

                <template v-else>
                    <p class="text-sm text-muted-foreground">
                        Self-pay prices are the ones billing uses at
                        <span class="font-medium text-foreground">{{
                            branch.name
                        }}</span
                        >.
                    </p>

                    <section
                        class="grid grid-cols-2 gap-3 lg:grid-cols-4"
                        aria-label="Summary"
                    >
                        <V2Stat
                            label="Items checked"
                            :value="String(result.summary.checked)"
                        />
                        <V2Stat
                            label="Need attention"
                            :value="String(result.summary.attention)"
                            :tone="
                                result.summary.attention > 0
                                    ? 'warning'
                                    : 'neutral'
                            "
                            :status="
                                result.summary.attention > 0
                                    ? 'Check these'
                                    : undefined
                            "
                        />
                        <V2Stat
                            label="No Self-pay price"
                            :value="String(result.summary.noSelfPay)"
                        />
                        <V2Stat
                            label="No default Panel price"
                            :value="String(result.summary.noPanelDefault)"
                        />
                    </section>

                    <form @submit.prevent="visit({ page: 1 })">
                        <V2Toolbar
                            v-model="search"
                            search-label="Search"
                            search-placeholder="Code or name"
                            @search="visit({ page: 1 })"
                        >
                            <template #filters>
                                <div class="w-full sm:w-52">
                                    <V2Picker
                                        :model-value="type || null"
                                        label="Type"
                                        placeholder="All types"
                                        :selected-label="
                                            typeOptions.find(
                                                (o) => o.id === type,
                                            )?.label
                                        "
                                        :options="typeOptions"
                                        @update:model-value="
                                            type = $event ?? ''
                                        "
                                    />
                                </div>
                            </template>
                        </V2Toolbar>
                    </form>
                    <div>
                        <button
                            type="button"
                            :class="outlineButton"
                            :aria-pressed="filters.attention"
                            data-action="show-attention"
                            @click="toggleAttention"
                        >
                            {{
                                filters.attention
                                    ? 'Show all items'
                                    : `Show only the ${result.summary.attention} that need attention`
                            }}
                        </button>
                    </div>

                    <p
                        class="text-sm text-muted-foreground"
                        data-test="colour-key"
                    >
                        Red chips need fixing before billing. Amber chips need a
                        check. Green means the prices are in place.
                    </p>

                    <V2Table
                        label="Price list"
                        :columns="5"
                        cards="xl"
                        min-width="880px"
                        :empty="result.rows.length === 0"
                        :empty-message="`${emptyTitle} ${emptyDescription}`"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col" class="text-right">Self-pay</th>
                                <th scope="col" class="text-right">
                                    Default Panel
                                </th>
                                <th scope="col">Panel prices</th>
                                <th scope="col">Checks</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in result.rows"
                                :key="`${row.type}:${row.code}`"
                            >
                                <td data-label="Item" data-primary>
                                    <span class="block text-sm font-medium">
                                        <Link
                                            v-if="row.chargePublicId"
                                            :href="historyHref(row)"
                                            class="text-primary underline underline-offset-2"
                                            data-testid="history-link"
                                            >{{ row.displayName }}</Link
                                        >
                                        <template v-else>{{
                                            row.displayName
                                        }}</template>
                                    </span>
                                    <span
                                        class="block text-xs break-words text-muted-foreground"
                                        >{{ row.code }} ·
                                        {{ typeLabels[row.type] }}</span
                                    >
                                </td>
                                <td
                                    data-label="Self-pay"
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ rm(row.selfPaySen) }}
                                    <span
                                        v-if="row.selfPayFromBranch"
                                        class="block text-xs text-muted-foreground"
                                        >Branch price</span
                                    >
                                </td>
                                <td
                                    data-label="Default Panel"
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ rm(row.panelDefaultSen) }}
                                </td>
                                <td data-label="Panel prices">
                                    <div v-if="row.panelPrices.length">
                                        <button
                                            type="button"
                                            :class="textButton"
                                            :aria-expanded="
                                                isOpen(row, 'panels')
                                            "
                                            @click="toggleOpen(row, 'panels')"
                                        >
                                            {{ panelSummary(row) }} ·
                                            {{
                                                isOpen(row, 'panels')
                                                    ? 'Hide'
                                                    : 'Show'
                                            }}
                                        </button>
                                        <p
                                            v-if="isOpen(row, 'panels')"
                                            class="text-xs break-words text-muted-foreground"
                                        >
                                            {{ panelText(row) }}
                                        </p>
                                    </div>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td data-label="Checks">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <V2Chip
                                            v-if="row.checks.length === 0"
                                            tone="success"
                                            :label="
                                                formatStatusLabel('price_ok')
                                            "
                                        />
                                        <V2Chip
                                            v-for="check in shownChecks(row)"
                                            :key="check"
                                            :tone="checkTone(check)"
                                            :label="
                                                formatStatusLabel(
                                                    statusKey(check),
                                                )
                                            "
                                        />
                                        <span
                                            v-if="hiddenChecks(row).length"
                                            class="text-xs text-muted-foreground"
                                            >+{{
                                                hiddenChecks(row).length
                                            }}
                                            more</span
                                        >
                                    </div>
                                    <div
                                        v-if="
                                            hiddenChecks(row).length ||
                                            row.checks.includes('unit_differs')
                                        "
                                    >
                                        <button
                                            type="button"
                                            :class="textButton"
                                            :aria-expanded="
                                                isOpen(row, 'checks')
                                            "
                                            @click="toggleOpen(row, 'checks')"
                                        >
                                            {{
                                                isOpen(row, 'checks')
                                                    ? 'Hide details'
                                                    : 'Show details'
                                            }}
                                        </button>
                                        <div
                                            v-if="isOpen(row, 'checks')"
                                            class="text-xs text-muted-foreground"
                                        >
                                            <p
                                                v-if="hiddenChecks(row).length"
                                                class="break-words"
                                            >
                                                All checks:
                                                {{ allChecksText(row) }}
                                            </p>
                                            <p
                                                v-if="
                                                    row.checks.includes(
                                                        'unit_differs',
                                                    )
                                                "
                                                class="break-words"
                                                data-testid="unit-text"
                                            >
                                                {{ unitText(row) }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        :current-page="result.currentPage"
                        :last-page="result.lastPage"
                        :total="result.total"
                        :per-page="25"
                        @change="(next) => visit({ page: next })"
                    />
                </template>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>

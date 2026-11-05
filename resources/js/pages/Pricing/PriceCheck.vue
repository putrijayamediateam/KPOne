<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DisclosureText } from '@/components/ui/disclosure';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { formatStatusLabel } from '@/lib/presentation';

// CAT-2: read-only price list check. Prices and checks come from the server, which uses billing's own price
// choice; this page only shows them. Fixes happen on the Pricing and catalogue screens.

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

const page = usePage<{ errors?: Record<string, string> }>();
const filterError = computed(
    () => Object.values(page.props.errors ?? {})[0] ?? '',
);

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
const attention = ref(props.filters.attention);
const typeOptions = [
    { value: '', label: 'All types' },
    { value: 'medicine', label: 'Medicine' },
    { value: 'service', label: 'Clinical service' },
    { value: 'consultation', label: 'Consultation' },
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
const showAttention = () => {
    attention.value = true;
    visit({ page: 1 });
};
const reload = () => router.reload();

const statusKey = (check: Check) =>
    check === 'zero_price' ? 'price_zero' : `price_${check}`;
// The server lists checks most serious first; show at most two badges.
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

const emptyTitle = computed(() =>
    props.filters.attention
        ? 'Nothing needs attention.'
        : 'No active medicines or services match.',
);
const emptyDescription = computed(() =>
    props.filters.attention
        ? 'All active items have the prices billing needs.'
        : 'Change the search or type, or add items in the catalogues.',
);
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Price list check"
            description="Every active medicine and clinical service with its current prices. Fix anything flagged before using it in billing."
        >
            <template #actions>
                <Button variant="outline" size="sm" as-child>
                    <Link href="/pricing">
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        Back to Pricing
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div v-if="filterError" class="grid gap-2">
            <ErrorState
                title="The filters could not be applied"
                :message="filterError"
            />
            <Button
                variant="outline"
                size="sm"
                class="justify-self-start"
                @click="reload"
                >Reload</Button
            >
        </div>

        <EmptyState
            v-if="!branch || !result"
            title="Select a branch first."
            description="Prices can differ by branch. Choose your branch in the header, then open this page again."
        />

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
                <div class="min-w-0 rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Items checked</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ result.summary.checked }}
                    </p>
                </div>
                <button
                    type="button"
                    class="min-w-0 rounded-xl border bg-card p-4 text-left transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-pressed="filters.attention"
                    data-action="show-attention"
                    @click="showAttention"
                >
                    <span class="block text-xs text-muted-foreground"
                        >Need attention</span
                    >
                    <span
                        class="mt-1 block text-2xl font-semibold tabular-nums"
                        >{{ result.summary.attention }}</span
                    >
                    <span class="mt-1 block text-xs text-muted-foreground"
                        >Show only these</span
                    >
                </button>
                <div class="min-w-0 rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        No Self-pay price
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ result.summary.noSelfPay }}
                    </p>
                </div>
                <div class="min-w-0 rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        No default Panel price
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ result.summary.noPanelDefault }}
                    </p>
                </div>
            </section>

            <form
                class="grid gap-3 sm:flex sm:flex-wrap sm:items-end"
                @submit.prevent="visit({ page: 1 })"
            >
                <div class="grid w-full gap-2 sm:max-w-xs">
                    <Label for="price-check-search">Search</Label>
                    <Input
                        id="price-check-search"
                        v-model="search"
                        placeholder="Code or name"
                        autocomplete="off"
                        maxlength="100"
                    />
                </div>
                <div class="grid w-full gap-2 sm:max-w-52">
                    <Label id="price-check-type-label" for="price-check-type"
                        >Type</Label
                    >
                    <OperationalSelect
                        id="price-check-type"
                        v-model="type"
                        label="Type"
                        labelledby="price-check-type-label"
                        :options="typeOptions"
                    />
                </div>
                <div class="flex min-h-11 items-center gap-2 sm:min-h-9">
                    <Checkbox
                        id="price-check-attention"
                        :model-value="attention"
                        @update:model-value="
                            (value) => (attention = value === true)
                        "
                    />
                    <Label for="price-check-attention"
                        >Only show items that need attention</Label
                    >
                </div>
                <Button size="sm" type="submit" class="min-h-11 sm:min-h-8"
                    >Apply</Button
                >
            </form>

            <section
                class="grid grid-cols-1 gap-3 lg:hidden"
                aria-label="Price list"
                data-testid="price-check-cards"
            >
                <EmptyState
                    v-if="result.rows.length === 0"
                    :title="emptyTitle"
                    :description="emptyDescription"
                />
                <article
                    v-for="row in result.rows"
                    :key="`${row.type}:${row.code}`"
                    class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border bg-card p-4"
                    data-testid="price-check-card"
                >
                    <div class="min-w-0">
                        <h2 class="text-sm font-medium break-words">
                            {{ row.displayName }}
                        </h2>
                        <p class="text-xs break-words text-muted-foreground">
                            {{ row.code }} · {{ typeLabels[row.type] }}
                        </p>
                    </div>
                    <dl class="grid gap-1 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Self-pay</dt>
                            <dd class="text-right font-medium tabular-nums">
                                {{ rm(row.selfPaySen) }}
                                <span
                                    v-if="row.selfPayFromBranch"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >Branch price</span
                                >
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Default Panel</dt>
                            <dd class="font-medium tabular-nums">
                                {{ rm(row.panelDefaultSen) }}
                            </dd>
                        </div>
                        <div v-if="row.panelPrices.length" class="grid gap-1">
                            <dt class="text-muted-foreground">
                                Panel prices ({{ panelSummary(row) }})
                            </dt>
                            <dd class="text-xs break-words">
                                {{ panelText(row) }}
                            </dd>
                        </div>
                    </dl>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <StatusBadge
                            v-if="row.checks.length === 0"
                            status="price_ok"
                        />
                        <StatusBadge
                            v-for="check in shownChecks(row)"
                            :key="check"
                            :status="statusKey(check)"
                        />
                        <span
                            v-if="hiddenChecks(row).length"
                            class="text-xs text-muted-foreground"
                            >+{{ hiddenChecks(row).length }} more</span
                        >
                    </div>
                    <div
                        v-if="hiddenChecks(row).length"
                        class="min-w-0 text-xs"
                    >
                        <DisclosureText
                            :text="`All checks: ${allChecksText(row)}`"
                        />
                    </div>
                    <p
                        v-if="row.checks.includes('unit_differs')"
                        class="text-xs break-words text-muted-foreground"
                        data-testid="unit-text"
                    >
                        {{ unitText(row) }}
                    </p>
                </article>
            </section>

            <div class="hidden min-w-0 lg:block">
                <OperationalTable
                    label="Price list"
                    :columns="5"
                    :empty="result.rows.length === 0"
                    :empty-message="`${emptyTitle} ${emptyDescription}`"
                    min-width="960px"
                >
                    <template #head>
                        <tr>
                            <th>Item</th>
                            <th class="text-right">Self-pay</th>
                            <th class="text-right">Default Panel</th>
                            <th>Panel prices</th>
                            <th>Checks</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in result.rows"
                            :key="`${row.type}:${row.code}`"
                        >
                            <td class="min-w-56">
                                <div class="font-medium">
                                    {{ row.displayName }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.code }} · {{ typeLabels[row.type] }}
                                </div>
                            </td>
                            <td
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
                                class="text-right whitespace-nowrap tabular-nums"
                            >
                                {{ rm(row.panelDefaultSen) }}
                            </td>
                            <td class="max-w-56">
                                <template v-if="row.panelPrices.length">
                                    <span class="text-sm">{{
                                        panelSummary(row)
                                    }}</span>
                                    <div class="min-w-0 text-xs">
                                        <DisclosureText
                                            :text="panelText(row)"
                                        />
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="min-w-64">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <StatusBadge
                                        v-if="row.checks.length === 0"
                                        status="price_ok"
                                    />
                                    <StatusBadge
                                        v-for="check in shownChecks(row)"
                                        :key="check"
                                        :status="statusKey(check)"
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
                                    v-if="hiddenChecks(row).length"
                                    class="mt-1 min-w-0 text-xs"
                                >
                                    <DisclosureText
                                        :text="`All checks: ${allChecksText(row)}`"
                                    />
                                </div>
                                <div
                                    v-if="row.checks.includes('unit_differs')"
                                    class="mt-1 min-w-0 text-xs"
                                >
                                    <DisclosureText :text="unitText(row)" />
                                </div>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>

            <CompactPagination
                :current-page="result.currentPage"
                :last-page="result.lastPage"
                :total="result.total"
                @change="(next) => visit({ page: next })"
            />
        </template>
    </main>
</template>

<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate } from '@/lib/presentation';

/**
 * Version 2 Stock review (inventory V2 page 10a). Everything from `type Review` to the branch watch is a verbatim copy of
 * Classic (a test compares them). Only the layout is new: the three lists as tabs with counts, one table each (cards
 * below 1280px), and V2 sheets for Acknowledge and the review history. The Acknowledge sheet starts with no outcome
 * chosen (the save stays off until one is picked) and calls Classic's `submit` unchanged.
 */
type Review = {
    id: number;
    version: number;
    reviewer: string;
    when: string;
    outcome: string;
    reviewBy: string | null;
    overdue: boolean;
};
type Row = {
    item_id: number;
    sku_code: string;
    item: string;
    location: string;
    stock_unit: string;
    batch: string | null;
    expiry: string | null;
    quantity: string;
    held: string;
    reorder_level: string | null;
    lock_version: number;
    latest: Review | null;
    history: Review[];
};
type Kind = 'low_stock' | 'expiring' | 'expired';
type List = { rows: Row[]; total: number; page: number; lastPage: number };
const props = defineProps<{
    review: {
        branchId: number;
        branchName: string;
        today: string;
        windowDays: number;
        canManage: boolean;
        q: string;
        lists: Record<Kind, List>;
    };
}>();
const headings: Record<Kind, string> = {
    low_stock: 'Low stock',
    expiring: 'Expiring soon',
    expired: 'Expired stock still on hand',
};
const kinds: Kind[] = ['low_stock', 'expiring', 'expired'];
const outcomes: Record<string, string> = {
    ordered_externally: 'Ordered externally',
    no_action_needed: 'No action needed',
    monitoring: 'Monitoring',
    disposal_requested_external: 'Disposal requested externally',
};
const q = ref(props.review.q);
const selected = ref<Row | null>(null);
const kind = ref<Kind>('low_stock');
const dialog = ref(false);
const history = ref<Row | null>(null);
const form = useForm({
    expected_branch_id: props.review.branchId,
    lock_version: 0,
    retry_key: '',
    outcome: 'monitoring',
    review_by: '',
});
function search(target?: Kind, page = 1) {
    const parameters: Record<string, string | number> = { q: q.value };

    for (const k of kinds) {
        parameters[`${k}_page`] = target
            ? target === k
                ? page
                : props.review.lists[k].page
            : 1;
    }

    router.get('/inventory/stock-review', parameters, { preserveScroll: true });
}
function show(k: Kind, row: Row) {
    kind.value = k;
    selected.value = row;
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.review.branchId;
    form.lock_version = row.lock_version;
    form.retry_key = crypto.randomUUID();
    dialog.value = true;
}
function submit() {
    if (!selected.value) {
        return;
    }

    form.post(
        `/inventory/stock-review/${kind.value}/${selected.value.item_id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                dialog.value = false;
                selected.value = null;
            },
        },
    );
}
const page = usePage();
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        dialog.value = false;
        selected.value = null;
        history.value = null;
        q.value = '';
        form.reset();
        form.clearErrors();
    },
);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock review' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
/** The list on show: the first one with rows, else Low stock. Switching tabs never reloads. */
const active = ref<Kind>(
    kinds.find((k) => props.review.lists[k].total > 0) ?? 'low_stock',
);
const tabs = computed(() =>
    kinds.map((k) => ({
        value: k,
        label: headings[k],
        count: props.review.lists[k].total,
    })),
);
const chooseTab = (value: string) => {
    if ((kinds as string[]).includes(value)) {
        active.value = value as Kind;
    }
};
const activeList = computed(() => props.review.lists[active.value]);
const outcomeOptions = Object.entries(outcomes).map(([value, label]) => ({
    value,
    label,
}));
/** Opens Classic's sheet with no outcome chosen, so nothing is saved by default. */
const askAcknowledge = (k: Kind, row: Row) => {
    show(k, row);
    form.outcome = '';
};
const sheetRows = computed<KeyValueItem[]>(() => {
    const row = selected.value;

    return row
        ? [
              { label: 'SKU', value: unbroken(row.sku_code) },
              { label: 'Item', value: row.item },
              { label: 'Location', value: row.location },
              ...(row.batch
                  ? [{ label: 'Batch', value: unbroken(row.batch) }]
                  : []),
              { label: 'List', value: headings[kind.value] },
          ]
        : [];
});
const needsReviewBy = computed(
    () =>
        form.outcome === 'monitoring' || form.outcome === 'ordered_externally',
);
const sheetError = computed(
    () =>
        Object.entries(form.errors).find(
            ([field]) => field !== 'review_by' && field !== 'outcome',
        )?.[1] ?? '',
);
const latestTone = (row: Row) =>
    row.latest?.overdue ? 'danger' : row.latest ? 'success' : 'warning';
const latestLabel = (row: Row) =>
    row.latest
        ? row.latest.overdue
            ? `Review overdue · ${outcomes[row.latest.outcome]}`
            : outcomes[row.latest.outcome]
        : 'Not yet acknowledged';
const outlineButton =
    'inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9';
</script>

<template>
    <Head title="Stock review" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Stock review"
            :description="`${review.branchName} · Branch-local date: ${formatDate(review.today)}`"
        >
            <template #main>
                <p class="text-sm text-muted-foreground">
                    Review evidence only. Acknowledgements do not change stock,
                    send messages, place orders or hide unresolved conditions.
                    Thresholds are per SKU and location. Held stock is shown
                    separately and is never eligible stock.
                </p>
                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    role="search"
                    @submit.prevent="search()"
                >
                    <label
                        class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                        >Search SKU, item, batch or location
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
                <V2Tabs
                    :model-value="active"
                    :tabs="tabs"
                    ariaLabel="Stock review lists"
                    id-prefix="v2-review"
                    @update:model-value="chooseTab"
                />
                <section
                    :id="`v2-review-panel-${active}`"
                    role="tabpanel"
                    :aria-labelledby="`v2-review-tab-${active}`"
                    class="flex min-w-0 flex-col gap-3"
                >
                    <p v-if="active === 'expiring'" class="text-sm">
                        Eligible positive stock expiring after today through day
                        {{ review.windowDays }}, inclusive.
                    </p>
                    <p
                        v-if="active === 'expired'"
                        class="rounded-[14px] border border-warning bg-warning/10 px-4 py-3 text-sm font-medium text-warning-strong"
                    >
                        Always listed while stock remains, including held or
                        unavailable stock. Record any disposal through Stock
                        count and Write-off; an acknowledgement does not prove
                        disposal.
                    </p>
                    <p class="text-sm text-muted-foreground">
                        25 per page. Use every page to review unresolved items.
                    </p>
                    <V2Table
                        :label="headings[active]"
                        :columns="4"
                        cards="xl"
                        min-width="900px"
                        :empty="activeList.rows.length === 0"
                        empty-message="No matching items on this page."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and location</th>
                                <th scope="col" class="text-right">
                                    {{
                                        active === 'low_stock'
                                            ? 'Eligible quantity'
                                            : 'On hand'
                                    }}
                                </th>
                                <th scope="col" class="w-72">Latest review</th>
                                <th scope="col">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in activeList.rows"
                                :key="row.item_id"
                            >
                                <td
                                    data-stack
                                    data-label="SKU and location"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ row.item }}</span
                                        >
                                        <span
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(row.sku_code) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.location
                                            }}<template v-if="row.batch">
                                                · batch
                                                {{ unbroken(row.batch) }}
                                                <template v-if="row.expiry"
                                                    >· expires
                                                    {{
                                                        formatDate(row.expiry)
                                                    }}</template
                                                ></template
                                            ></span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-stack
                                    :data-label="
                                        active === 'low_stock'
                                            ? 'Eligible quantity'
                                            : 'On hand'
                                    "
                                >
                                    <div class="min-w-0 text-right">
                                        <span
                                            class="block text-sm font-semibold whitespace-nowrap tabular-nums"
                                            >{{
                                                quantityWithUnit(
                                                    row.quantity,
                                                    row.stock_unit,
                                                )
                                            }}</span
                                        >
                                        <span
                                            v-if="active === 'low_stock'"
                                            class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                            >Reorder level
                                            {{
                                                quantityWithUnit(
                                                    row.reorder_level,
                                                    row.stock_unit,
                                                )
                                            }}</span
                                        >
                                        <span
                                            class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                            >Held
                                            {{
                                                quantityWithUnit(
                                                    row.held,
                                                    row.stock_unit,
                                                )
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Latest review">
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <V2Chip
                                            class="w-fit"
                                            :tone="latestTone(row)"
                                            :label="latestLabel(row)"
                                        />
                                        <span
                                            v-if="row.latest"
                                            class="text-xs break-words text-muted-foreground"
                                            >{{ row.latest.reviewer }} ·
                                            {{ row.latest.when
                                            }}<template
                                                v-if="row.latest.reviewBy"
                                            >
                                                · review by
                                                {{
                                                    row.latest.reviewBy
                                                }}</template
                                            ></span
                                        >
                                    </div>
                                </td>
                                <td data-actions>
                                    <div
                                        class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:justify-end"
                                    >
                                        <button
                                            v-if="review.canManage"
                                            type="button"
                                            :class="outlineButton"
                                            :aria-label="`Acknowledge ${row.sku_code} at ${row.location}`"
                                            @click="askAcknowledge(active, row)"
                                        >
                                            Acknowledge
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex h-11 items-center justify-center rounded-[10px] px-3 text-sm font-medium whitespace-nowrap text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                            :aria-label="`Review history for ${row.sku_code} at ${row.location}`"
                                            @click="history = row"
                                        >
                                            History
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="activeList.total > 0"
                        :current-page="activeList.page"
                        :last-page="activeList.lastPage"
                        :total="activeList.total"
                        :per-page="25"
                        @change="(n) => search(active, n)"
                    />
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="dialog"
            title="Acknowledge stock review?"
            :confirm-label="
                selected
                    ? `Save acknowledgement for ${unbroken(selected.sku_code)}`
                    : 'Save acknowledgement'
            "
            :busy="form.processing"
            :confirm-disabled="form.outcome === ''"
            :confirm-disabled-reason="
                form.outcome === '' ? 'Pick an outcome to continue.' : undefined
            "
            @update:open="(value) => (dialog = value)"
            @confirm="submit"
        >
            <p
                v-if="sheetError"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ sheetError }}
            </p>
            <V2KeyValueList :items="sheetRows" />
            <V2OptionGroup
                :model-value="form.outcome || null"
                label="Outcome"
                required
                :options="outcomeOptions"
                :error="form.errors.outcome"
                @update:model-value="(value) => (form.outcome = value ?? '')"
            />
            <V2DateField
                v-model="form.review_by"
                label="Review by"
                :required="needsReviewBy"
                :min="review.today"
                hint="Needed for Monitoring and Ordered externally. Today up to 14 days ahead."
                :error="form.errors.review_by"
            />
            <template #footer-note>
                <p class="text-sm" role="status">
                    Records who reviewed this item and when. No stock changes
                    and nothing is sent.
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="history !== null"
            title="Review history"
            :description="
                history
                    ? `${unbroken(history.sku_code)} · ${history.location}. The last 10 acknowledgements; earlier evidence is kept.`
                    : undefined
            "
            confirm-label="Close"
            single-action
            single-action-style="outline"
            @update:open="
                (value) => {
                    if (!value) history = null;
                }
            "
            @confirm="history = null"
        >
            <p
                v-if="!history?.history.length"
                class="text-sm text-muted-foreground"
            >
                No acknowledgements yet.
            </p>
            <ol
                v-else
                class="flex flex-col gap-3"
                aria-label="Acknowledgements"
            >
                <li
                    v-for="entry in history?.history"
                    :key="entry.id"
                    class="flex flex-col gap-1 border-t pt-3 text-sm first:border-t-0 first:pt-0"
                >
                    <V2Chip
                        class="w-fit"
                        :tone="entry.overdue ? 'danger' : 'neutral'"
                        :label="
                            entry.overdue
                                ? `Review overdue · ${outcomes[entry.outcome]}`
                                : outcomes[entry.outcome]
                        "
                    />
                    <span class="text-muted-foreground"
                        >{{ entry.reviewer }} · {{ entry.when }}</span
                    >
                    <span v-if="entry.reviewBy" class="text-muted-foreground"
                        >Review by {{ entry.reviewBy }}</span
                    >
                </li>
            </ol>
        </V2Dialog>
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
}
</style>

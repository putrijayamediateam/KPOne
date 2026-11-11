<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { formatRinggit, packLabel, trimQuantity } from '@/lib/supplier-terms';

type Row = {
    key: string;
    skuCode: string;
    itemName: string;
    location: string;
    stockUnit: string;
    reorderLevel: string;
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

const quantity = (value: string, unit: string) =>
    `${trimQuantity(value)} ${unit}`;
</script>

<template>
    <Head title="Reorder suggestions" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Reorder suggestions"
            :description="`${suggestions.branchName} · Stock at or below its reorder level, with a suggested order. Nothing is ordered from this page.`"
        />
        <p
            class="rounded-lg border bg-muted/40 p-3 text-sm"
            data-testid="reorder-method"
        >
            Suggested = reorder level − on hand − on order − incoming +
            {{ suggestions.lookBackDays }}-day average use × lead time, rounded
            up to whole packs and at least the supplier's minimum order.
        </p>
        <p
            v-if="!suggestions.canSeeCost"
            class="text-sm text-muted-foreground"
            data-testid="price-hidden-note"
        >
            Estimated costs are shown only to a Director or Finance Officer.
        </p>

        <form
            class="flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="visit()"
        >
            <label
                class="grid min-w-0 basis-full gap-1 text-sm sm:flex-1 sm:basis-auto"
                >Search SKU, item or location
                <Input v-model="q" maxlength="100" class="h-11 lg:h-9" />
            </label>
            <label class="grid gap-1 text-sm"
                >Show
                <select
                    v-model="need"
                    class="h-11 rounded-md border bg-background px-3 lg:h-9"
                >
                    <option value="order">Only rows that need ordering</option>
                    <option value="all">All low-stock rows</option>
                </select>
            </label>
            <Button type="submit" variant="outline" class="h-11 lg:h-9"
                >Search</Button
            >
        </form>

        <p
            v-if="suggestions.rows.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                suggestions.filters.need === 'order'
                    ? 'Nothing needs ordering at this branch.'
                    : 'No stock is at or below its reorder level at this branch.'
            }}
        </p>
        <ul v-else class="space-y-3" aria-label="Reorder suggestions">
            <li
                v-for="row in suggestions.rows"
                :key="row.key"
                class="space-y-3 rounded-lg border bg-card p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ row.skuCode }} · {{ row.itemName }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.location }}
                        </p>
                    </div>
                    <div class="sm:text-right">
                        <p
                            v-if="row.coveredByOrders"
                            class="rounded border px-2 py-0.5 text-sm"
                        >
                            Covered by orders
                        </p>
                        <p
                            v-else-if="row.suggestedQuantity === '0.000'"
                            class="rounded border px-2 py-0.5 text-sm"
                        >
                            At reorder level, no order needed
                        </p>
                        <template v-else>
                            <p class="text-xs text-muted-foreground">
                                Suggested order
                            </p>
                            <p class="font-semibold tabular-nums">
                                <template v-if="row.suggestedPacks !== null">
                                    {{ row.suggestedPacks }}
                                    {{
                                        row.suggestedPacks === 1
                                            ? 'pack'
                                            : 'packs'
                                    }}
                                    (=
                                    {{
                                        quantity(
                                            row.suggestedQuantity,
                                            row.stockUnit,
                                        )
                                    }})
                                </template>
                                <template v-else>{{
                                    quantity(
                                        row.suggestedQuantity,
                                        row.stockUnit,
                                    )
                                }}</template>
                            </p>
                        </template>
                    </div>
                </div>
                <dl
                    class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <dt class="text-muted-foreground">On hand (usable)</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.onHand, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Reorder level</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.reorderLevel, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">On order</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.onOrder, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Incoming transfers
                        </dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.incoming, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Average use a day ({{ suggestions.lookBackDays }}
                            days)
                        </dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.averageDailyUse, row.stockUnit) }}
                        </dd>
                    </div>
                    <template v-if="row.supplier">
                        <div>
                            <dt class="text-muted-foreground">
                                Preferred supplier
                            </dt>
                            <dd class="break-words">
                                {{ row.supplier.name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Lead time and pack
                            </dt>
                            <dd>
                                {{ row.supplier.leadTimeDays }}
                                {{
                                    row.supplier.leadTimeDays === 1
                                        ? 'day'
                                        : 'days'
                                }}
                                ·
                                {{
                                    packLabel(
                                        row.supplier.packDescription,
                                        row.supplier.packQuantity,
                                        row.stockUnit,
                                    )
                                }}
                            </dd>
                        </div>
                    </template>
                    <div
                        v-if="
                            suggestions.canSeeCost &&
                            row.supplier &&
                            row.suggestedPacks !== null
                        "
                    >
                        <dt class="text-muted-foreground">
                            Estimated cost (supplier price list)
                        </dt>
                        <dd class="tabular-nums">
                            {{
                                row.estimateSen === null ||
                                row.estimateSen === undefined
                                    ? 'No price recorded'
                                    : `RM ${formatRinggit(row.estimateSen)}`
                            }}
                        </dd>
                    </div>
                </dl>
                <p v-if="!row.supplier" class="text-sm text-muted-foreground">
                    No preferred supplier: set one in
                    <a
                        href="/inventory/supplier-terms"
                        class="underline underline-offset-4"
                        >Supplier price list</a
                    >. The suggestion is in whole stock units with no lead time.
                </p>
            </li>
        </ul>
        <CompactPagination
            v-if="suggestions.total > 0"
            :current-page="suggestions.page"
            :last-page="suggestions.lastPage"
            :total="suggestions.total"
            @change="(page) => visit(page)"
        />
    </main>
</template>

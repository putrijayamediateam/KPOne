<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { trimQuantity } from '@/lib/supplier-terms';

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

const quantity = (value: string, unit: string) =>
    `${trimQuantity(value)} ${unit}`;
const plural = (count: number, one: string, many: string) =>
    `${count} ${count === 1 ? one : many}`;
</script>

<template>
    <Head title="Stock health" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Stock health"
            :description="`${health.branchName} · How long stock lasts, how often it is used up, and how much is lost to expiry. Quantities only; nothing changes from this page.`"
        />
        <div
            class="space-y-1 rounded-lg border bg-muted/40 p-3 text-sm"
            data-testid="stock-health-method"
        >
            <p>
                <strong>Used</strong> is what was dispensed or used in a
                treatment room in the period. <strong>Days on hand</strong> is
                the usable stock now ÷ the average use a day.
                <strong>Turnover</strong> is used ÷ the average stock held.
                <strong>Expiry loss</strong> is the stock written off as expired
                ÷ (used + all write-offs). Longest on hand first; stock with no
                use in the period comes first of all.
            </p>
            <p class="text-muted-foreground">
                No money figures: stock value waits for the clinic's cost
                policy.
            </p>
        </div>

        <ul
            class="grid grid-cols-3 gap-2 sm:gap-3"
            aria-label="Stock health summary"
            data-testid="stock-health-summary"
        >
            <li class="rounded-lg border bg-card p-2 sm:p-3">
                <p class="text-xl font-semibold tabular-nums sm:text-2xl">
                    {{ health.summary.noUse }}
                </p>
                <p class="text-xs text-muted-foreground sm:text-sm">
                    With stock but no use in the period
                </p>
            </li>
            <li class="rounded-lg border bg-card p-2 sm:p-3">
                <p class="text-xl font-semibold tabular-nums sm:text-2xl">
                    {{ health.summary.slow }}
                </p>
                <p class="text-xs text-muted-foreground sm:text-sm">
                    With more than {{ health.slowDays }} days on hand
                </p>
            </li>
            <li class="rounded-lg border bg-card p-2 sm:p-3">
                <p class="text-xl font-semibold tabular-nums sm:text-2xl">
                    {{ health.summary.expiryLoss }}
                </p>
                <p class="text-xs text-muted-foreground sm:text-sm">
                    With stock lost to expiry
                </p>
            </li>
        </ul>

        <form
            class="flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="visit()"
        >
            <!-- Daniel: the period applies at once; a search runs on Enter. -->
            <label class="grid min-w-0 flex-1 gap-1 text-sm"
                >Search SKU, item or location
                <Input v-model="q" maxlength="100" class="h-11 lg:h-9" />
            </label>
            <label class="grid gap-1 text-sm"
                >Period
                <select
                    v-model.number="days"
                    @change="visit()"
                    class="h-11 rounded-md border bg-background px-3 lg:h-9"
                    data-testid="stock-health-period"
                >
                    <option
                        v-for="period in health.periods"
                        :key="period"
                        :value="period"
                    >
                        Last {{ period }} days
                    </option>
                </select>
            </label>
            <Button type="submit" class="sr-only">Search</Button>
        </form>

        <p
            v-if="health.rows.length === 0"
            class="text-sm text-muted-foreground"
        >
            No stock was held or used at this branch in the period.
        </p>
        <ul v-else class="space-y-3" aria-label="Stock health by SKU">
            <li
                v-for="row in health.rows"
                :key="row.key"
                class="space-y-2 rounded-lg border bg-card p-3 sm:space-y-3 sm:p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ row.skuCode }} · {{ row.itemName }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.location }} · {{ row.locationCode }}
                        </p>
                    </div>
                    <div class="max-w-[45%] shrink-0 text-right">
                        <p class="text-xs text-muted-foreground">
                            Days on hand
                        </p>
                        <p
                            v-if="row.noUse"
                            class="rounded border px-2 py-0.5 text-sm"
                            data-testid="no-use"
                        >
                            <span class="sm:hidden">No use</span
                            ><span class="hidden sm:inline"
                                >No use in this period</span
                            >
                        </p>
                        <p
                            v-else-if="row.daysOnHand === null"
                            class="text-sm text-muted-foreground"
                        >
                            No usable stock
                        </p>
                        <p
                            v-else-if="row.daysOnHand > 365"
                            class="font-semibold"
                            :title="plural(row.daysOnHand, 'day', 'days')"
                            data-testid="over-a-year"
                        >
                            More than 1 year
                            <span class="sr-only"
                                >({{
                                    plural(row.daysOnHand, 'day', 'days')
                                }})</span
                            >
                        </p>
                        <p v-else class="font-semibold tabular-nums">
                            {{ plural(row.daysOnHand, 'day', 'days') }}
                        </p>
                    </div>
                </div>
                <dl
                    class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-sm sm:gap-y-1 lg:grid-cols-5"
                >
                    <div>
                        <dt class="text-muted-foreground">On hand</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.onHand, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Usable</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.usable, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Used</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.used, row.stockUnit) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Turnover</dt>
                        <dd v-if="row.turnover !== null" class="tabular-nums">
                            {{ row.turnover }} times
                        </dd>
                        <dd v-else class="text-muted-foreground">
                            Not enough stock history
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Expiry loss</dt>
                        <dd
                            v-if="row.expiryLossPercent !== null"
                            class="tabular-nums"
                        >
                            {{ row.expiryLossPercent }}% ·
                            {{ quantity(row.expired, row.stockUnit) }} expired
                        </dd>
                        <dd v-else class="text-muted-foreground">
                            No use or write-offs
                        </dd>
                    </div>
                </dl>
            </li>
        </ul>
        <CompactPagination
            v-if="health.total > 0"
            :current-page="health.page"
            :last-page="health.lastPage"
            :total="health.total"
            @change="(page) => visit(page)"
        />
    </main>
</template>

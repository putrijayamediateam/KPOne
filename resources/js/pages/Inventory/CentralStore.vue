<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import { formatDate } from '@/lib/presentation';
import { trimQuantity } from '@/lib/supplier-terms';

// STK-47 and STK-60 (design note STK-47-60-DESIGN.md revision 2 with Daniel's conditions): the central store, read
// only, with the weekly request day. No cost or value is shown here.
type Tone = 'neutral' | 'success' | 'warning' | 'danger';
type StoreRow = {
    skuCode: string;
    itemName: string;
    stockUnit: string;
    usable: string;
    notUsable: string;
    nearestExpiry: string | null;
    locations: Array<{ name: string; usable: string }>;
    used30: string;
    coverWeeks: string | null;
    coverTone: Tone;
};
type BranchCell = {
    branchId: number;
    branch: string;
    usable: string;
    level: string | null;
    below: boolean;
};
type BranchRow = {
    skuCode: string;
    itemName: string;
    stockUnit: string;
    shortAnywhere: boolean;
    branches: BranchCell[];
};
type RequestRow = {
    publicId: string;
    number: string;
    status:
        'requested' | 'approved' | 'dispatched' | 'received_with_differences';
    requestedOn: string;
    from: string;
    to: string;
    lineCount: number;
    href: string;
};
type WeekCard = {
    branchId: number;
    branch: string;
    weekOf: string;
    sent: boolean;
    sentOn: string | null;
    sentBy: string | null;
    rejected: boolean;
    tone: 'success' | 'neutral' | 'warning';
};
type Paged<T> = { rows: T[]; page: number; lastPage: number; total: number };

const props = defineProps<{
    store: {
        stores: Array<{ name: string }>;
        requestDay: { weekday: number; name: string };
        canChangeDay: boolean;
        settingLockVersion: number;
        lookBackDays: number;
        filters: { tab: 'store' | 'branches' | 'requests'; q: string };
        thisWeek: WeekCard[];
        storeStock: Paged<StoreRow>;
        branchStock: Paged<BranchRow> & {
            branches: Array<{ id: number; name: string }>;
        };
        requests: Array<{ branch: string; rows: RequestRow[] }>;
    };
}>();

const q = ref(props.store.filters.q);
const visit = (query: { tab?: string; page?: number } = {}) =>
    router.get(
        '/inventory/central-store',
        {
            tab: query.tab ?? props.store.filters.tab,
            q: q.value || undefined,
            page: query.page ?? 1,
        },
        { preserveScroll: true, preserveState: true },
    );

const requestCount = computed(() =>
    props.store.requests.reduce((sum, group) => sum + group.rows.length, 0),
);
const tabs = computed(() => [
    {
        value: 'store',
        label: 'Store stock',
        count: props.store.storeStock.total,
    },
    {
        value: 'branches',
        label: 'Branch stock',
        count: props.store.branchStock.total,
    },
    {
        value: 'requests',
        label: 'Requests to the store',
        count: requestCount.value,
    },
]);

const quantity = (value: string, unit: string) =>
    `${trimQuantity(value)} ${unit}`;

// Cover in words with its tone: less than 1 week destructive, 1 to 2 weeks warning, never colour alone.
const coverWords = (row: StoreRow): string => {
    if (row.coverWeeks === null) {
        return 'No recent use';
    }

    if (row.coverTone === 'danger') {
        return 'Less than 1 week';
    }

    return `${row.coverWeeks} ${row.coverWeeks === '1.0' ? 'week' : 'weeks'}`;
};

const weekWords = (card: WeekCard): string =>
    card.sent
        ? `Sent ${card.sentOn}${card.sentBy ? ` by ${card.sentBy}` : ''}`
        : 'Not sent yet';

const statusWords: Record<RequestRow['status'], string> = {
    requested: 'Waiting for approval',
    approved: 'Approved',
    dispatched: 'Dispatched',
    received_with_differences: 'Received with differences',
};
const statusTone: Record<RequestRow['status'], Tone> = {
    requested: 'warning',
    approved: 'neutral',
    dispatched: 'neutral',
    received_with_differences: 'warning',
};

const levelWords = (cell: BranchCell, unit: string): string =>
    cell.level === null
        ? `${quantity(cell.usable, unit)} · no level`
        : `${trimQuantity(cell.usable)} of ${trimQuantity(cell.level)}`;

// The request-day sheet: one setting, weekday radios only (revision 2 drops the cut-off time).
const sheetOpen = ref(false);
const saving = ref(false);
const form = reactive({
    weekday: props.store.requestDay.weekday,
    key: crypto.randomUUID(),
});
const errors = ref<Record<string, string>>({});
const days = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];
const openSheet = () => {
    form.weekday = props.store.requestDay.weekday;
    form.key = crypto.randomUUID();
    errors.value = {};
    sheetOpen.value = true;
};
const generalError = computed(
    () =>
        errors.value.lock_version ?? errors.value.idempotency_key ?? undefined,
);
const saveDay = () =>
    router.put(
        '/inventory/central-store/request-day',
        {
            request_weekday: form.weekday,
            lock_version: props.store.settingLockVersion,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (sheetOpen.value = false),
            onError: (bag) => (errors.value = bag as Record<string, string>),
        },
    );
</script>

<template>
    <Head title="Central store" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader title="Central store">
            <template #description>
                <span data-testid="central-store-request-day"
                    >Request day: {{ store.requestDay.name }}</span
                >
                <span v-if="store.stores.length">
                    · {{ store.stores.map((s) => s.name).join(', ') }}</span
                >
                <span class="block"
                    >Stock only: no patient details and no cost. Nothing is
                    approved, sent or received on this page.</span
                >
            </template>
            <template v-if="store.canChangeDay" #actions>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    data-testid="central-store-change-day"
                    @click="openSheet"
                    >Change</Button
                >
            </template>
        </PageHeader>

        <EmptyState
            v-if="!store.stores.length"
            title="No central store yet"
            description="The central store is a stock location with no branch. Set one up in Stock setup."
        />

        <!-- Daniel: "This week" first, one card per branch, in the branch's own time. -->
        <section aria-labelledby="central-store-week" class="space-y-3">
            <h2 id="central-store-week" class="text-base font-semibold">
                This week
            </h2>
            <ul
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                aria-label="Store requests this week"
            >
                <li
                    v-for="card in store.thisWeek"
                    :key="card.branchId"
                    class="min-w-0 space-y-2 rounded-lg border bg-card p-4"
                    data-testid="central-store-week-card"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h3 class="font-medium">{{ card.branch }}</h3>
                        <span class="text-xs text-muted-foreground">{{
                            card.weekOf
                        }}</span>
                    </div>
                    <StatusBadge
                        :status="card.sent ? 'sent' : 'not_sent'"
                        :tone="card.tone"
                        :label="weekWords(card)"
                        dot
                    />
                    <p
                        v-if="card.rejected"
                        class="text-xs text-muted-foreground"
                    >
                        Its latest request was rejected. The branch can send
                        another.
                    </p>
                </li>
            </ul>
        </section>

        <OperationalTabs
            :model-value="store.filters.tab"
            :tabs="tabs"
            label="Central store views"
            @update:model-value="(tab) => visit({ tab })"
        />

        <form
            v-if="store.filters.tab !== 'requests'"
            class="flex flex-wrap items-end gap-2"
            role="search"
            @submit.prevent="visit()"
        >
            <label
                class="grid min-w-0 basis-full gap-1 text-sm sm:flex-1 sm:basis-auto"
                >Search SKU or item
                <Input v-model="q" maxlength="100" class="h-11 lg:h-9" />
            </label>
            <Button type="submit" variant="outline" class="h-11 lg:h-9"
                >Search</Button
            >
        </form>

        <!-- Store stock -->
        <section
            v-if="store.filters.tab === 'store'"
            aria-label="Store stock"
            class="space-y-3"
        >
            <p class="text-sm text-muted-foreground">
                Usable stock only. Held, recalled or expired stock is shown
                apart. Cover is at the branches' combined use over the last
                {{ store.lookBackDays }} days.
            </p>
            <div class="hidden lg:block">
                <OperationalTable
                    label="Store stock"
                    :columns="6"
                    :empty="store.storeStock.rows.length === 0"
                    empty-message="The store holds no stock."
                    min-width="900px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">SKU</th>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-right">Usable</th>
                            <th scope="col" class="text-right">
                                Held or expired
                            </th>
                            <th scope="col">Nearest expiry</th>
                            <th scope="col">Cover</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in store.storeStock.rows"
                            :key="row.skuCode"
                        >
                            <td class="font-medium">{{ row.skuCode }}</td>
                            <td>{{ row.itemName }}</td>
                            <td class="text-right tabular-nums">
                                {{ quantity(row.usable, row.stockUnit) }}
                                <span
                                    v-if="row.locations.length > 1"
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        row.locations
                                            .map(
                                                (l) =>
                                                    `${l.name} ${trimQuantity(l.usable)}`,
                                            )
                                            .join(' · ')
                                    }}</span
                                >
                            </td>
                            <td class="text-right tabular-nums">
                                {{ quantity(row.notUsable, row.stockUnit) }}
                            </td>
                            <td class="tabular-nums">
                                {{
                                    row.nearestExpiry
                                        ? formatDate(row.nearestExpiry)
                                        : '—'
                                }}
                            </td>
                            <td>
                                <StatusBadge
                                    status="cover"
                                    :tone="row.coverTone"
                                    :label="coverWords(row)"
                                    dot
                                />
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>
            <ul class="space-y-3 lg:hidden" aria-label="Store stock">
                <li
                    v-if="store.storeStock.rows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    The store holds no stock.
                </li>
                <li
                    v-for="row in store.storeStock.rows"
                    :key="row.skuCode"
                    class="space-y-2 rounded-lg border bg-card p-4"
                >
                    <p class="font-medium break-words">
                        {{ row.skuCode }} · {{ row.itemName }}
                    </p>
                    <StatusBadge
                        status="cover"
                        :tone="row.coverTone"
                        :label="coverWords(row)"
                        dot
                    />
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">Usable</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.usable, row.stockUnit) }}
                        </dd>
                        <dt class="text-muted-foreground">Held or expired</dt>
                        <dd class="tabular-nums">
                            {{ quantity(row.notUsable, row.stockUnit) }}
                        </dd>
                        <dt class="text-muted-foreground">Nearest expiry</dt>
                        <dd class="tabular-nums">
                            {{
                                row.nearestExpiry
                                    ? formatDate(row.nearestExpiry)
                                    : '—'
                            }}
                        </dd>
                        <template v-if="row.locations.length > 1">
                            <template v-for="l in row.locations" :key="l.name">
                                <dt class="text-muted-foreground">
                                    {{ l.name }}
                                </dt>
                                <dd class="tabular-nums">
                                    {{ quantity(l.usable, row.stockUnit) }}
                                </dd>
                            </template>
                        </template>
                    </dl>
                </li>
            </ul>
            <CompactPagination
                v-if="store.storeStock.total > 0"
                :current-page="store.storeStock.page"
                :last-page="store.storeStock.lastPage"
                :total="store.storeStock.total"
                @change="(page) => visit({ page })"
            />
        </section>

        <!-- Branch stock: a matrix from 1024 px, one card per SKU below; short anywhere first. -->
        <section
            v-if="store.filters.tab === 'branches'"
            aria-label="Branch stock"
            class="space-y-3"
        >
            <p class="text-sm text-muted-foreground">
                Each branch's usable stock against its reorder level, so the
                store sees who is short before they ask.
            </p>
            <div class="hidden lg:block">
                <OperationalTable
                    label="Branch stock"
                    :columns="2 + store.branchStock.branches.length"
                    :empty="store.branchStock.rows.length === 0"
                    empty-message="No stock or reorder levels to show."
                    min-width="900px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">SKU</th>
                            <th scope="col">Item</th>
                            <th
                                v-for="branch in store.branchStock.branches"
                                :key="branch.id"
                                scope="col"
                                class="text-right"
                            >
                                {{ branch.name }}
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in store.branchStock.rows"
                            :key="row.skuCode"
                        >
                            <td class="font-medium">{{ row.skuCode }}</td>
                            <td>{{ row.itemName }}</td>
                            <td
                                v-for="cell in row.branches"
                                :key="cell.branchId"
                                class="text-right tabular-nums"
                            >
                                <span class="block">{{
                                    levelWords(cell, row.stockUnit)
                                }}</span>
                                <StatusBadge
                                    v-if="cell.below"
                                    status="below_level"
                                    tone="warning"
                                    label="Below level"
                                    dot
                                />
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>
            <ul class="space-y-3 lg:hidden" aria-label="Branch stock">
                <li
                    v-if="store.branchStock.rows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No stock or reorder levels to show.
                </li>
                <li
                    v-for="row in store.branchStock.rows"
                    :key="row.skuCode"
                    class="space-y-2 rounded-lg border bg-card p-4"
                >
                    <p class="font-medium break-words">
                        {{ row.skuCode }} · {{ row.itemName }}
                    </p>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="cell in row.branches"
                            :key="cell.branchId"
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span>{{ cell.branch }}</span>
                            <span class="flex items-center gap-2 tabular-nums"
                                >{{ levelWords(cell, row.stockUnit) }}
                                <StatusBadge
                                    v-if="cell.below"
                                    status="below_level"
                                    tone="warning"
                                    label="Below level"
                                    dot
                            /></span>
                        </li>
                    </ul>
                </li>
            </ul>
            <CompactPagination
                v-if="store.branchStock.total > 0"
                :current-page="store.branchStock.page"
                :last-page="store.branchStock.lastPage"
                :total="store.branchStock.total"
                @change="(page) => visit({ page })"
            />
        </section>

        <!-- Requests to the store: grouped by branch, oldest first, links only. -->
        <section
            v-if="store.filters.tab === 'requests'"
            aria-label="Requests to the store"
            class="space-y-4"
        >
            <p class="text-sm text-muted-foreground">
                Approve, send and receive stay on the Inventory page. A request
                shows there for its own branch, so switch to that branch to act
                on it.
            </p>
            <p
                v-if="store.requests.length === 0"
                class="text-sm text-muted-foreground"
            >
                No open requests to the store.
            </p>
            <section
                v-for="group in store.requests"
                :key="group.branch"
                :aria-label="`Requests from ${group.branch}`"
                class="space-y-2"
            >
                <h3 class="font-medium">{{ group.branch }}</h3>
                <ul class="space-y-2">
                    <li
                        v-for="row in group.rows"
                        :key="row.publicId"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-3"
                        data-testid="central-store-request"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    row.number
                                }}</span>
                                <StatusBadge
                                    :status="row.status"
                                    :tone="statusTone[row.status]"
                                    :label="statusWords[row.status]"
                                />
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ row.requestedOn }} · {{ row.from }} to
                                {{ row.to }} · {{ row.lineCount }}
                                {{ row.lineCount === 1 ? 'line' : 'lines' }}
                            </p>
                        </div>
                        <ActionLink
                            :href="row.href"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            :aria-label="`Open ${row.number}`"
                            >Open</ActionLink
                        >
                    </li>
                </ul>
            </section>
        </section>

        <Sheet v-model:open="sheetOpen">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-testid="central-store-day-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Store request day</SheetTitle>
                    <SheetDescription
                        >This only reminds and warns. It never blocks a
                        request.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-1 flex-col gap-4 px-4 pb-4"
                    @submit.prevent="saveDay"
                >
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Request day
                        </legend>
                        <label
                            v-for="day in days"
                            :key="day.value"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 hover:bg-muted"
                        >
                            <input
                                v-model="form.weekday"
                                type="radio"
                                name="request_weekday"
                                class="size-4"
                                :value="day.value"
                            />
                            <span class="text-sm">{{ day.label }}</span>
                        </label>
                        <InputError :message="errors.request_weekday" />
                    </fieldset>
                    <InputError :message="generalError" />
                    <div class="mt-auto flex justify-end">
                        <Button
                            type="submit"
                            class="min-h-11 lg:min-h-9"
                            :disabled="saving"
                            >Save</Button
                        >
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    </main>
</template>

<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

// CAT-3: read-only price history of one charge. Every published version comes from the server, newest first;
// "In use" is the version billing picks in that book now. Prices are published on the Pricing page.

type Status = 'in_use' | 'scheduled' | 'replaced' | 'not_in_use';
type Row = {
    id: number;
    book: string;
    bookActive: boolean;
    version: number;
    amountSen: number;
    effectiveAt: string;
    effectiveAtIso: string;
    publishedBy: string;
    status: Status;
};

const props = defineProps<{
    filters: { book: string | null };
    from: 'price-check' | null;
    result: {
        charge: {
            publicId: string;
            code: string;
            displayName: string;
            unit: string | null;
            isActive: boolean;
        };
        books: { publicId: string; label: string; isActive: boolean }[];
        rows: Row[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
}>();

const page = usePage<{ errors?: Record<string, string> }>();
const filterError = computed(
    () => Object.values(page.props.errors ?? {})[0] ?? '',
);

// The way back comes from a fixed list of targets, never a free URL; Pricing is the default.
const backTargets = {
    'price-check': {
        href: '/pricing/price-check',
        label: 'Back to price list check',
    },
} as const;
const back = computed(
    () =>
        (props.from ? backTargets[props.from] : null) ?? {
            href: '/pricing',
            label: 'Back to Pricing',
        },
);

const book = ref(props.filters.book ?? '');
const bookOptions = computed(() => [
    { value: '', label: 'All price books' },
    ...props.result.books.map((option) => ({
        value: option.publicId,
        label: option.isActive ? option.label : `${option.label} (inactive)`,
    })),
]);

const visit = (overrides: Record<string, string | number> = {}) => {
    router.get(
        `/pricing/charges/${props.result.charge.publicId}/history`,
        {
            book: book.value || undefined,
            from: props.from ?? undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const changeBook = (value: string) => {
    book.value = value;
    visit({ page: 1 });
};
const reload = () => router.reload();

const subtitle = computed(() =>
    [
        props.result.charge.displayName,
        props.result.charge.code,
        props.result.charge.unit ? `unit ${props.result.charge.unit}` : null,
    ]
        .filter(Boolean)
        .join(' · '),
);
const rm = (sen: number) =>
    `RM ${Math.floor(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;
const bookLabel = (row: Row) =>
    row.bookActive ? row.book : `${row.book} (inactive)`;
const statusKey = (status: Status) => `price_${status}`;
// Only a version billing uses now or will use is a badge; older or unused versions are muted text.
const isBadge = (row: Row) =>
    row.status === 'in_use' || row.status === 'scheduled';
const mutedStatus = (row: Row) =>
    row.status === 'not_in_use' ? 'Not in use (book inactive)' : 'Replaced';
// A replaced RM 0.00 version is history: only a price in use or scheduled is flagged as free.
const flagFree = (row: Row) => row.amountSen === 0 && isBadge(row);

const emptyTitle = computed(() =>
    props.filters.book
        ? 'No prices in this price book yet.'
        : 'No prices published for this charge yet.',
);
const emptyDescription = computed(() =>
    props.filters.book
        ? 'Choose another price book, or All price books.'
        : 'Publish a price on the Pricing page.',
);
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader title="Price history" :description="subtitle">
            <template #actions>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="back.href" data-action="back">
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        {{ back.label }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="!result.charge.isActive"
            class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
        >
            <StatusBadge status="price_charge_inactive" />
            <span>Billing refuses this charge until it is switched on.</span>
        </div>

        <div v-if="filterError" class="grid gap-2">
            <ErrorState
                title="The filter could not be applied"
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

        <div class="grid w-full gap-2 sm:max-w-xs">
            <Label id="price-history-book-label" for="price-history-book"
                >Price book</Label
            >
            <OperationalSelect
                id="price-history-book"
                :model-value="book"
                label="Price book"
                labelledby="price-history-book-label"
                :options="bookOptions"
                @update:model-value="(value) => changeBook(String(value ?? ''))"
            />
        </div>

        <section
            class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden"
            aria-label="Price history"
            data-testid="price-history-cards"
        >
            <EmptyState
                v-if="result.rows.length === 0"
                class="md:col-span-2"
                :title="emptyTitle"
                :description="emptyDescription"
            />
            <article
                v-for="row in result.rows"
                :key="row.id"
                class="grid min-w-0 grid-cols-1 gap-2 rounded-xl border bg-card p-4"
                data-testid="price-history-card"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <p class="text-xl font-semibold tabular-nums">
                        {{ rm(row.amountSen) }}
                    </p>
                    <StatusBadge
                        v-if="isBadge(row)"
                        :status="statusKey(row.status)"
                    />
                    <span v-else class="text-xs text-muted-foreground">{{
                        mutedStatus(row)
                    }}</span>
                </div>
                <p class="text-sm break-words">
                    {{ bookLabel(row) }} ·
                    <span class="tabular-nums">v{{ row.version }}</span>
                </p>
                <dl class="grid gap-1 text-xs">
                    <div class="flex flex-wrap justify-between gap-x-3">
                        <dt class="text-muted-foreground">In effect from</dt>
                        <dd class="tabular-nums">
                            <time :datetime="row.effectiveAtIso">{{
                                row.effectiveAt
                            }}</time>
                        </dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-x-3">
                        <dt class="text-muted-foreground">Published by</dt>
                        <dd class="break-words">{{ row.publishedBy }}</dd>
                    </div>
                </dl>
                <div v-if="flagFree(row)">
                    <StatusBadge status="price_zero" />
                </div>
            </article>
        </section>

        <div class="hidden min-w-0 lg:block">
            <OperationalTable
                label="Price history"
                class="[&_table]:table-fixed"
                :columns="6"
                :empty="result.rows.length === 0"
                :empty-message="`${emptyTitle} ${emptyDescription}`"
                min-width="960px"
            >
                <template #head>
                    <tr>
                        <th class="w-[26%]">Price book</th>
                        <th class="w-[8%] text-right">Version</th>
                        <th class="w-[14%] text-right">Amount</th>
                        <th class="w-[18%]">In effect from</th>
                        <th class="w-[18%]">Published by</th>
                        <th class="w-[16%]">Status</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="row in result.rows" :key="row.id">
                        <td class="break-words">{{ bookLabel(row) }}</td>
                        <td class="text-right tabular-nums">
                            v{{ row.version }}
                        </td>
                        <td class="text-right font-medium tabular-nums">
                            {{ rm(row.amountSen) }}
                        </td>
                        <td class="tabular-nums">
                            <time :datetime="row.effectiveAtIso">{{
                                row.effectiveAt
                            }}</time>
                        </td>
                        <td class="break-words">{{ row.publishedBy }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <StatusBadge
                                    v-if="isBadge(row)"
                                    :status="statusKey(row.status)"
                                />
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >{{ mutedStatus(row) }}</span
                                >
                                <StatusBadge
                                    v-if="flagFree(row)"
                                    status="price_zero"
                                />
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
    </main>
</template>

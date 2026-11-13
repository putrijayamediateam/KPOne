<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatStatusLabel } from '@/lib/presentation';

/**
 * Version 2 Price history (shown only in V2; Classic is `pages/Pricing/PriceHistory.vue`, untouched). Read-only: same
 * props and the same address and filters. The way back is the crumb (Pricing, or the price list check when you came
 * from there), from the same fixed list of targets as Classic.
 */
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
    // The zone the times are shown in (the active branch's), for example "Asia/Kuala_Lumpur".
    timezone?: string;
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

// The way back comes from a fixed list of targets, never a free address; Pricing is the default.
const parent = computed(() =>
    props.from === 'price-check'
        ? { title: 'Price list check', href: '/pricing/price-check' }
        : { title: 'Pricing', href: '/pricing' },
);
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: parent.value.title, href: parent.value.href },
    { title: 'Price history' },
]);

const book = ref(props.filters.book ?? '');
const bookOptions = computed(() =>
    props.result.books.map((option) => ({
        id: option.publicId,
        label: option.isActive ? option.label : `${option.label} (inactive)`,
    })),
);
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
const changeBook = (value: string | null) => {
    book.value = value ?? '';
    visit({ page: 1 });
};
const reload = () => router.reload();

// The subtitle reads as words: a plain-word code such as CONSULTATION is shown as "Consultation", and a unit that only
// repeats the code is left out. Real codes (with digits or hyphens) and other units stay as they are.
const words = (code: string) =>
    /^[A-Z]+$/.test(code) ? code.charAt(0) + code.slice(1).toLowerCase() : code;
const subtitle = computed(() => {
    const code = words(props.result.charge.code);
    const unit = props.result.charge.unit;

    return [
        props.result.charge.displayName,
        code,
        unit && unit.toLowerCase() !== code.toLowerCase()
            ? `unit ${unit}`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
});
// "Asia/Kuala_Lumpur" reads as "Kuala Lumpur time"; a zone without a city (for example "UTC") is named as it is.
const timesNote = computed(() => {
    const zone = props.timezone?.trim();

    if (!zone) {
        return "Times are in the branch's time zone.";
    }

    const city = zone.includes('/')
        ? (zone.split('/').pop() ?? zone).replaceAll('_', ' ')
        : zone;

    return `Times are in ${city} time.`;
});
const rm = (sen: number) =>
    `RM ${Math.floor(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;
const bookLabel = (row: Row) =>
    row.bookActive ? row.book : `${row.book} (inactive)`;
const statusKey = (status: Status) => `price_${status}`;
// Only a version billing uses now or will use is a chip; older or unused versions are muted text.
const isChip = (row: Row) =>
    row.status === 'in_use' || row.status === 'scheduled';
const mutedStatus = (row: Row) =>
    row.status === 'not_in_use' ? 'Not in use (book inactive)' : 'Replaced';
// A replaced RM 0.00 version is history: only a price in use or scheduled is flagged as free.
const flagFree = (row: Row) => row.amountSen === 0 && isChip(row);

const emptyTitle = computed(() =>
    props.filters.book
        ? 'No prices in this price book yet.'
        : 'No prices published for this charge yet.',
);
const emptyDescription = computed(() =>
    props.filters.book
        ? 'Choose another price book, or clear the choice to see all.'
        : 'Publish a price on the Pricing page.',
);
</script>

<template>
    <Head title="Price history" />
    <PageFrame>
        <Link
            :href="parent.href"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← {{ parent.title }}
        </Link>
        <V2WorkLayout title="Price history" :description="subtitle">
            <template #main>
                <p
                    v-if="!result.charge.isActive"
                    class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                >
                    <V2Chip
                        tone="danger"
                        :label="formatStatusLabel('price_charge_inactive')"
                    />
                    Billing refuses this charge until it is switched on.
                </p>

                <div
                    v-if="filterError"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
                >
                    The filter could not be applied. {{ filterError }}
                    <button
                        type="button"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium text-foreground hover:bg-muted lg:h-9"
                        @click="reload"
                    >
                        Reload
                    </button>
                </div>

                <div class="w-full sm:max-w-xs">
                    <V2Picker
                        :model-value="book || null"
                        label="Price book"
                        placeholder="All price books"
                        :selected-label="
                            bookOptions.find((o) => o.id === book)?.label
                        "
                        :options="bookOptions"
                        @update:model-value="changeBook"
                    />
                </div>

                <p class="text-sm text-muted-foreground" data-test="times-note">
                    {{ timesNote }}
                </p>

                <V2Table
                    label="Price history"
                    :columns="6"
                    cards="xl"
                    min-width="880px"
                    :empty="result.rows.length === 0"
                    :empty-message="`${emptyTitle} ${emptyDescription}`"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Price book</th>
                            <th scope="col" class="text-right">Version</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col">In effect from</th>
                            <th scope="col">Published by</th>
                            <th scope="col">Status</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in result.rows" :key="row.id">
                            <td data-label="Price book" data-primary>
                                <span class="text-sm font-medium break-words">{{
                                    bookLabel(row)
                                }}</span>
                            </td>
                            <td
                                data-label="Version"
                                class="text-right tabular-nums"
                            >
                                v{{ row.version }}
                            </td>
                            <td
                                data-label="Amount"
                                class="text-right font-medium tabular-nums"
                            >
                                {{ rm(row.amountSen) }}
                            </td>
                            <td
                                data-label="In effect from"
                                class="tabular-nums"
                            >
                                <time :datetime="row.effectiveAtIso">{{
                                    row.effectiveAt
                                }}</time>
                            </td>
                            <td data-label="Published by" class="break-words">
                                {{ row.publishedBy }}
                            </td>
                            <td data-label="Status">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <V2Chip
                                        v-if="isChip(row)"
                                        :tone="
                                            row.status === 'in_use'
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            formatStatusLabel(
                                                statusKey(row.status),
                                            )
                                        "
                                    />
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                                        ><span
                                            class="size-2 shrink-0 rounded-full bg-muted-foreground/50"
                                            aria-hidden="true"
                                        ></span
                                        >{{ mutedStatus(row) }}</span
                                    >
                                    <V2Chip
                                        v-if="flagFree(row)"
                                        tone="warning"
                                        :label="formatStatusLabel('price_zero')"
                                    />
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
        </V2WorkLayout>
    </PageFrame>
</template>

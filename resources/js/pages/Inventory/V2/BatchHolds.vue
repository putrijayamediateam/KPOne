<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { formatDate } from '@/lib/presentation';

/**
 * Version 2 Batch safety holds (inventory V2 page 7a). Everything from `type Row` to the branch watch is a verbatim copy
 * of Classic (a test compares them). Only the layout and the plain sentences are new: a table with one outline action per
 * row and one sheet whose reason starts empty, with what the press does beside the button.
 */
type Row = {
    public_id: string;
    batch_number: string;
    expiry_date: string;
    sku_code: string;
    status: string;
    reason: string | null;
    lock_version: number;
    canPlace: boolean;
    canRelease: boolean;
};
const props = defineProps<{
    holds: {
        branchId: number;
        branchName: string;
        q: string;
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();
const query = ref(props.holds.q);
const open = ref(false);
const selected = ref<Row | null>(null);
const action = ref<'place' | 'release'>('place');
const page = usePage();
const form = useForm({
    expected_branch_id: props.holds.branchId,
    lock_version: 0,
    retry_key: '',
    reason: '',
});
const placeReasons = [
    'suspected_quality_issue',
    'recall',
    'damaged',
    'other_safety',
];
const releaseReasons = [
    'cleared_by_supplier',
    'inspection_passed',
    'other_approved',
];
const label = (value: string) => value.replaceAll('_', ' ');
const show = (row: Row, next: 'place' | 'release') => {
    selected.value = row;
    action.value = next;
    form.clearErrors();
    form.expected_branch_id = props.holds.branchId;
    form.lock_version = row.lock_version;
    form.retry_key = crypto.randomUUID();
    form.reason = '';
    open.value = true;
};
const search = (number = 1) =>
    router.get('/inventory/batch-holds', { q: query.value, page: number });
const submit = () => {
    if (!selected.value) {
        return;
    }

    form.post(
        `/inventory/batch-holds/${selected.value.public_id}/${action.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                selected.value = null;
            },
        },
    );
};
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        open.value = false;
        selected.value = null;
        query.value = '';
        form.reset();
    },
);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Batch safety holds' },
]);
/** A code never breaks across lines ("SYN-B-123" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '‑');
/** "quarantined" → "Quarantined": the stock status in sentence case. */
const statusWord = (status: string) => {
    const text = label(status);

    return text.charAt(0).toUpperCase() + text.slice(1);
};
const statusTone = (status: string) =>
    status === 'available' ? 'success' : 'warning';
const reasonOptions = computed(() =>
    (action.value === 'place' ? placeReasons : releaseReasons).map(
        (reason) => ({ value: reason, label: formatInventoryReason(reason) }),
    ),
);
const sheetRows = computed<KeyValueItem[]>(() => {
    const row = selected.value;

    return row
        ? [
              { label: 'SKU', value: unbroken(row.sku_code) },
              { label: 'Batch', value: unbroken(row.batch_number) },
              { label: 'Expiry', value: formatDate(row.expiry_date) },
              { label: 'Branch', value: props.holds.branchName },
              ...(row.reason
                  ? [
                        {
                            label: 'Held for',
                            value: formatInventoryReason(row.reason),
                        },
                    ]
                  : []),
          ]
        : [];
});
const sheetSentence = computed(() =>
    action.value === 'place'
        ? `Blocks this batch at ${props.holds.branchName} for dispensing and allocation. No stock quantity changes.`
        : `Lets this batch be used again at ${props.holds.branchName}. No stock quantity changes.`,
);
const confirmLabel = computed(() =>
    selected.value
        ? `${action.value === 'place' ? 'Place hold on' : 'Release hold on'} ${unbroken(selected.value.batch_number)}`
        : '',
);
const formError = computed(() => Object.values(form.errors)[0] ?? '');
</script>

<template>
    <Head title="Batch safety holds" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Batch safety holds"
            :description="`${holds.branchName} · A hold applies to this batch at this branch only. Multi-branch recalls need one hold per branch.`"
        >
            <template #main>
                <p class="text-sm text-muted-foreground">
                    Held stock cannot be allocated or dispensed. Placing or
                    releasing a hold changes no quantity and creates no
                    movement. A different authorised person must release the
                    hold. No disposal, patient tracing or notifications.
                </p>
                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    role="search"
                    @submit.prevent="search()"
                >
                    <label
                        class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                        >Search SKU or batch
                        <input
                            v-model="query"
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
                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-holds"
                >
                    <h2
                        id="v2-holds"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Batches ({{ holds.total }})
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        25 per page. Search includes batches beyond this page.
                    </p>
                    <V2Table
                        label="Batches at this branch"
                        :columns="5"
                        cards="xl"
                        min-width="820px"
                        :empty="holds.rows.length === 0"
                        empty-message="No batches match."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and batch</th>
                                <th scope="col">Expiry</th>
                                <th scope="col">Stock status</th>
                                <th scope="col" class="w-64">
                                    Hold at this branch
                                </th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in holds.rows" :key="row.public_id">
                                <td
                                    data-stack
                                    data-label="SKU and batch"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block font-mono text-sm [overflow-wrap:anywhere]"
                                            >{{ unbroken(row.sku_code) }}</span
                                        >
                                        <span
                                            class="block text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >Batch
                                            {{
                                                unbroken(row.batch_number)
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-label="Expiry"
                                    class="text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ formatDate(row.expiry_date) }}
                                </td>
                                <td data-label="Stock status">
                                    <V2Chip
                                        class="whitespace-nowrap"
                                        :tone="statusTone(row.status)"
                                        :label="statusWord(row.status)"
                                    />
                                </td>
                                <td data-label="Hold at this branch">
                                    <V2Chip
                                        class="whitespace-nowrap"
                                        :tone="
                                            row.reason ? 'danger' : 'neutral'
                                        "
                                        :label="
                                            row.reason
                                                ? `Held: ${formatInventoryReason(row.reason)}`
                                                : 'No hold at this branch'
                                        "
                                    />
                                </td>
                                <td data-actions>
                                    <button
                                        v-if="row.canPlace"
                                        type="button"
                                        class="inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9"
                                        :aria-label="`Place hold on ${row.sku_code}, batch ${row.batch_number}`"
                                        @click="show(row, 'place')"
                                    >
                                        Place hold
                                    </button>
                                    <button
                                        v-else-if="row.canRelease"
                                        type="button"
                                        class="inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9"
                                        :aria-label="`Release hold on ${row.sku_code}, batch ${row.batch_number}`"
                                        @click="show(row, 'release')"
                                    >
                                        Release hold
                                    </button>
                                    <p
                                        v-else-if="row.reason"
                                        class="text-xs text-muted-foreground"
                                        data-testid="release-checker"
                                    >
                                        A different authorised checker is
                                        required for release.
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="holds.total > 0"
                        :current-page="holds.page"
                        :last-page="holds.lastPage"
                        :total="holds.total"
                        :per-page="25"
                        @change="(n) => search(n)"
                    />
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="open"
            :title="
                action === 'place'
                    ? 'Place a safety hold?'
                    : 'Release the hold?'
            "
            :confirm-label="confirmLabel"
            :tone="action === 'place' ? 'danger' : 'default'"
            :busy="form.processing"
            :confirm-disabled="form.reason === ''"
            :confirm-disabled-reason="
                form.reason === '' ? 'Pick a reason to continue.' : undefined
            "
            @update:open="(value) => (open = value)"
            @confirm="submit"
        >
            <p
                v-if="formError"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ formError }}
            </p>
            <V2KeyValueList :items="sheetRows" />
            <V2OptionGroup
                :model-value="form.reason || null"
                label="Reason"
                required
                :options="reasonOptions"
                @update:model-value="(value) => (form.reason = value ?? '')"
            />
            <template #footer-note>
                <p class="text-sm" role="status">{{ sheetSentence }}</p>
            </template>
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
}
</style>

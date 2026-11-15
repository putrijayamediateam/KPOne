<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { formatDate } from '@/lib/presentation';

/**
 * Version 2 Pilot readiness (inventory V2 page 9a). Everything from `type Row` to the branch watch is a verbatim copy of
 * Classic (a test compares them). Only the layout and the plain sentences are new: a SKU table, an openings table, and a
 * sheet that says what Verify or Sign off does before the press. The sheet calls Classic's `verify` unchanged.
 */
type Row = {
    id: number;
    public_id: string;
    sku_code: string;
    stock_unit: string;
    purchase_unit: string;
    dispensing_unit: string;
    unit_verification_id: number | null;
    mapped: boolean;
    ambiguous: boolean;
    opening_count: number;
    signed_count: number;
};
type Opening = {
    public_id: string;
    inventory_sku_id: number;
    inventory_batch_id: number;
    batch_number: string;
    expiry_date: string;
    quantity: string;
    location_code: string;
    canVerify: boolean;
};
const props = defineProps<{
    readiness: {
        branchId: number;
        branchName: string;
        rows: Row[];
        q: string;
        page: number;
        lastPage: number;
        total: number;
        pending: Opening[];
        pendingLimited: boolean;
        openingPage: number;
        openingLastPage: number;
        canVerifyUnits: boolean;
        sharedBarcodes: {
            rows: {
                code: string;
                skus: {
                    public_id: string;
                    sku_code: string;
                    item_name: string;
                }[];
            }[];
            more: boolean;
        };
        canFixBarcodes: boolean;
        canImportOpening?: boolean;
    };
}>();
const search = ref(props.readiness.q);
const busy = ref(false);
// STK-9: per-SKU barcode drafts for fixing codes shared by more than one SKU.
const barcodeDrafts = ref<Record<string, string>>({});
function barcodeDraft(skuPublicId: string, code: string): string {
    return barcodeDrafts.value[skuPublicId] ?? code;
}
const savedSku = ref<string | null>(null);
function saveBarcode(skuPublicId: string, value: string) {
    savedSku.value = skuPublicId;
    busy.value = true;
    router.post(
        `/inventory/readiness/barcode/${skuPublicId}`,
        { barcode: value },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
const retryKeys = ref<Record<string, string>>({});
const page = usePage();
const run = (number = 1) =>
    router.get(
        '/inventory/readiness',
        { q: search.value, page: number },
        { preserveState: false },
    );
const openingPage = (number: number) =>
    router.get(
        '/inventory/readiness',
        { q: search.value, page: props.readiness.page, opening_page: number },
        { preserveState: false },
    );
const verify = (kind: 'unit' | 'opening', key: string, base?: string) => {
    busy.value = true;
    router.post(
        `/inventory/readiness/${kind}/${key}`,
        {
            expected_branch_id: props.readiness.branchId,
            lock_version: 0,
            retry_key: (retryKeys.value[`${kind}/${key}`] ??=
                crypto.randomUUID()),
            ...(base ? { base_unit: base } : {}),
        },
        {
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
watch(
    () => props.readiness.branchId,
    () => {
        search.value = '';
        retryKeys.value = {};
        busy.value = false;
    },
);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Pilot readiness' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '‑');
const mappingLabel = (row: Row) =>
    row.ambiguous
        ? 'Ambiguous: refused'
        : row.mapped
          ? 'Mapped to one medicine'
          : 'Not mapped';
const mappingTone = (row: Row) =>
    row.ambiguous ? 'danger' : row.mapped ? 'success' : 'warning';
const signedTone = (row: Row) =>
    row.opening_count === 0
        ? 'neutral'
        : row.signed_count === row.opening_count
          ? 'success'
          : 'warning';
const signedLabel = (row: Row) =>
    row.opening_count === 0
        ? 'No opening'
        : `${row.signed_count} of ${row.opening_count} signed off`;
const skuFor = (opening: Opening) =>
    props.readiness.rows.find((r) => r.id === opening.inventory_sku_id);

/** The sheet: one row or one opening, and what the press does. */
const confirming = ref<
    { kind: 'unit'; row: Row } | { kind: 'opening'; opening: Opening } | null
>(null);
const askUnit = (row: Row) => (confirming.value = { kind: 'unit', row });
const askOpening = (opening: Opening) =>
    (confirming.value = { kind: 'opening', opening });
const sheetTitle = computed(() =>
    confirming.value?.kind === 'unit'
        ? 'Verify the base stock unit?'
        : 'Sign off this opening?',
);
const sheetRows = computed<KeyValueItem[]>(() => {
    const c = confirming.value;

    if (!c) {
        return [];
    }

    if (c.kind === 'unit') {
        return [
            { label: 'SKU', value: unbroken(c.row.sku_code) },
            { label: 'Base stock unit', value: c.row.stock_unit },
            { label: 'Branch', value: props.readiness.branchName },
        ];
    }

    const sku = skuFor(c.opening);

    return [
        { label: 'SKU', value: unbroken(sku?.sku_code ?? '') },
        { label: 'Location', value: unbroken(c.opening.location_code) },
        { label: 'Batch', value: unbroken(c.opening.batch_number) },
        { label: 'Expiry', value: formatDate(c.opening.expiry_date) },
        {
            label: 'Quantity',
            value: quantityWithUnit(c.opening.quantity, sku?.stock_unit),
        },
    ];
});
const sheetSentence = computed(() =>
    confirming.value?.kind === 'unit'
        ? 'Records that this SKU counts in this one unit. No stock quantity changes.'
        : 'Records that you checked the batch, location and count yourself. No stock quantity changes.',
);
const confirmLabel = computed(() => {
    const c = confirming.value;

    if (!c) {
        return '';
    }

    return c.kind === 'unit'
        ? `Verify ${c.row.stock_unit} for ${unbroken(c.row.sku_code)}`
        : `Sign off ${unbroken(c.opening.batch_number)}`;
});
const confirm = () => {
    const c = confirming.value;

    if (!c) {
        return;
    }

    if (c.kind === 'unit') {
        verify('unit', c.row.public_id, c.row.stock_unit);
    } else {
        verify('opening', c.opening.public_id);
    }

    confirming.value = null;
};
/** The barcode error shows under its own field, so it is left out of the list at the top. */
const errorList = computed(() =>
    Object.entries(page.props.errors ?? {})
        .filter(([key]) => key !== 'barcode')
        .map(([, message]) => message),
);
const outlineButton =
    'inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto lg:h-9';
</script>

<template>
    <Head title="Inventory pilot readiness" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Pilot readiness"
            :description="`${readiness.branchName} · Check each SKU and sign off openings. No balance correction or pilot go-live approval.`"
        >
            <template v-if="readiness.canImportOpening" #actions>
                <Link
                    href="/inventory/opening-import"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    data-testid="readiness-import-link"
                    >Import opening stock</Link
                >
            </template>
            <template #main>
                <div class="flex flex-col gap-2 text-sm text-muted-foreground">
                    <p>
                        Each SKU has one base stock unit, with up to 3 decimals.
                        Other units and conversions are refused.
                    </p>
                    <p>
                        Someone other than the person who recorded an opening
                        must sign it off. This page never changes a count. Open
                        questions (responsible clinician, FEFO, regulated items)
                        are in the pharmacist review pack that goes with the
                        pilot papers, not in KPOne.
                    </p>
                </div>
                <p
                    v-for="(error, key) in errorList"
                    :key="key"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-readiness-skus"
                >
                    <h2
                        id="v2-readiness-skus"
                        class="v2-section-title text-lg font-semibold"
                    >
                        SKUs ({{ readiness.total }})
                    </h2>
                    <form
                        class="flex min-w-0 flex-wrap items-end gap-2"
                        role="search"
                        @submit.prevent="run()"
                    >
                        <label
                            class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                            >Search SKU
                            <input
                                v-model="search"
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
                    <p class="text-sm text-muted-foreground">
                        25 per page. Search includes SKUs beyond this page.
                        Mapping problems are flagged, never repaired for you.
                    </p>
                    <V2Table
                        label="SKUs at this branch"
                        :columns="5"
                        cards="xl"
                        min-width="880px"
                        :empty="readiness.rows.length === 0"
                        empty-message="No SKUs match this review."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and base unit</th>
                                <th scope="col">Base unit</th>
                                <th scope="col">Medicine mapping</th>
                                <th scope="col">Openings</th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in readiness.rows" :key="row.id">
                                <td
                                    data-stack
                                    data-label="SKU and base unit"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block font-mono text-sm [overflow-wrap:anywhere]"
                                            >{{ unbroken(row.sku_code) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >Base stock unit:
                                            {{
                                                row.stock_unit || 'Not defined'
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                row.stock_unit !==
                                                row.dispensing_unit
                                            "
                                            class="mt-1 block text-xs font-medium text-warning-strong"
                                            data-testid="readiness-unit-differs"
                                            >Dispensing uses a different unit.
                                            Conversion is unsupported; review
                                            the references before
                                            dispensing.</span
                                        >
                                    </div>
                                </td>
                                <td data-label="Base unit">
                                    <V2Chip
                                        class="whitespace-nowrap"
                                        :tone="
                                            row.unit_verification_id
                                                ? 'success'
                                                : 'warning'
                                        "
                                        :label="
                                            row.unit_verification_id
                                                ? 'Verified'
                                                : 'Not verified'
                                        "
                                    />
                                </td>
                                <td data-label="Medicine mapping">
                                    <V2Chip
                                        class="whitespace-nowrap"
                                        :tone="mappingTone(row)"
                                        :label="mappingLabel(row)"
                                    />
                                </td>
                                <td data-label="Openings">
                                    <V2Chip
                                        class="whitespace-nowrap"
                                        :tone="signedTone(row)"
                                        :label="signedLabel(row)"
                                    />
                                </td>
                                <td data-actions>
                                    <button
                                        v-if="
                                            readiness.canVerifyUnits &&
                                            !row.unit_verification_id
                                        "
                                        type="button"
                                        :disabled="busy"
                                        :class="outlineButton"
                                        :aria-label="`Verify base unit ${row.stock_unit} for ${row.sku_code}`"
                                        @click="askUnit(row)"
                                    >
                                        Verify unit
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="readiness.total > 0"
                        :current-page="readiness.page"
                        :last-page="readiness.lastPage"
                        :total="readiness.total"
                        :per-page="25"
                        :disabled="busy"
                        @change="(n) => run(n)"
                    />
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-readiness-openings"
                >
                    <h2
                        id="v2-readiness-openings"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Openings waiting for sign-off
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        For the SKUs on this page. Check the batch, location and
                        physical count yourself before you sign. You cannot sign
                        off an opening you recorded.
                    </p>
                    <p
                        v-if="readiness.pendingLimited"
                        class="text-sm text-muted-foreground"
                    >
                        25 openings per page. Use Previous and Next below to see
                        more.
                    </p>
                    <V2Table
                        label="Openings waiting for sign-off"
                        :columns="5"
                        cards="xl"
                        min-width="820px"
                        :empty="readiness.pending.length === 0"
                        empty-message="No openings waiting on this page."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and location</th>
                                <th scope="col">Batch</th>
                                <th scope="col">Expiry</th>
                                <th scope="col" class="text-right">Quantity</th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="opening in readiness.pending"
                                :key="opening.public_id"
                            >
                                <td
                                    data-stack
                                    data-label="SKU and location"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block font-mono text-sm [overflow-wrap:anywhere]"
                                            >{{
                                                unbroken(
                                                    skuFor(opening)?.sku_code ??
                                                        '',
                                                )
                                            }}</span
                                        >
                                        <span
                                            class="block text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{
                                                unbroken(opening.location_code)
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-label="Batch"
                                    class="font-mono text-sm [overflow-wrap:anywhere]"
                                >
                                    {{ unbroken(opening.batch_number) }}
                                </td>
                                <td
                                    data-label="Expiry"
                                    class="text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ formatDate(opening.expiry_date) }}
                                </td>
                                <td
                                    data-label="Quantity"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        quantityWithUnit(
                                            opening.quantity,
                                            skuFor(opening)?.stock_unit,
                                        )
                                    }}
                                </td>
                                <td data-actions>
                                    <button
                                        v-if="opening.canVerify"
                                        type="button"
                                        :disabled="busy"
                                        :class="outlineButton"
                                        :aria-label="`Sign off opening, batch ${opening.batch_number}`"
                                        @click="askOpening(opening)"
                                    >
                                        Sign off
                                    </button>
                                    <p
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        data-testid="readiness-checker"
                                    >
                                        A different authorised checker must sign
                                        this off.
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <nav
                        v-if="readiness.openingLastPage > 1"
                        aria-label="Opening sign-off pages"
                        class="grid grid-cols-2 items-center gap-2 text-sm sm:flex sm:justify-end"
                    >
                        <span
                            class="order-first col-span-2 text-center text-[13px] text-muted-foreground tabular-nums sm:order-none sm:col-span-1 sm:mr-2 sm:text-left"
                            >Page {{ readiness.openingPage }} of
                            {{ readiness.openingLastPage }}</span
                        >
                        <button
                            type="button"
                            :class="outlineButton"
                            :disabled="readiness.openingPage <= 1"
                            @click="openingPage(readiness.openingPage - 1)"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            :class="outlineButton"
                            :disabled="
                                readiness.openingPage >=
                                readiness.openingLastPage
                            "
                            @click="openingPage(readiness.openingPage + 1)"
                        >
                            Next
                        </button>
                    </nav>
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-readiness-barcodes"
                >
                    <h2
                        id="v2-readiness-barcodes"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Barcodes on more than one SKU ({{
                            readiness.sharedBarcodes.rows.length
                        }}{{ readiness.sharedBarcodes.more ? '+' : '' }})
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Scanning refuses these codes until each is on one SKU
                        only. Give each SKU its own barcode, or clear the wrong
                        one.
                        <span v-if="!readiness.canFixBarcodes"
                            >A Director, CA Supervisor or Administrator can
                            change barcodes here.</span
                        >
                    </p>
                    <p
                        v-if="readiness.sharedBarcodes.rows.length === 0"
                        class="rounded-[14px] border p-4 text-sm text-muted-foreground"
                    >
                        No shared barcodes.
                    </p>
                    <article
                        v-for="shared in readiness.sharedBarcodes.rows"
                        :key="shared.code"
                        class="flex min-w-0 flex-col gap-3 rounded-[14px] border bg-card p-4"
                    >
                        <p class="font-mono text-sm font-medium break-all">
                            {{ shared.code }}
                        </p>
                        <div
                            v-for="sku in shared.skus"
                            :key="sku.public_id"
                            class="flex min-w-0 flex-wrap items-end gap-2 border-t pt-3"
                        >
                            <p class="w-full min-w-0 text-sm break-words">
                                <span class="font-mono font-medium">{{
                                    unbroken(sku.sku_code)
                                }}</span>
                                · {{ sku.item_name }}
                            </p>
                            <template v-if="readiness.canFixBarcodes">
                                <label
                                    :for="`barcode-${sku.public_id}`"
                                    class="flex min-w-0 flex-1 basis-56 flex-col gap-1.5 text-sm font-medium"
                                    >Barcode for {{ sku.sku_code }}
                                    <Input
                                        :id="`barcode-${sku.public_id}`"
                                        :model-value="
                                            barcodeDraft(
                                                sku.public_id,
                                                shared.code,
                                            )
                                        "
                                        maxlength="100"
                                        autocomplete="off"
                                        :aria-invalid="
                                            savedSku === sku.public_id &&
                                            !!page.props.errors?.barcode
                                        "
                                        :aria-describedby="
                                            savedSku === sku.public_id &&
                                            page.props.errors?.barcode
                                                ? `barcode-${sku.public_id}-error`
                                                : undefined
                                        "
                                        class="h-11 rounded-[10px] lg:h-9"
                                        @update:model-value="
                                            barcodeDrafts[sku.public_id] =
                                                String($event)
                                        "
                                /></label>
                                <div
                                    class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto"
                                >
                                    <button
                                        type="button"
                                        :class="outlineButton"
                                        :disabled="busy"
                                        :aria-label="`Save barcode for ${sku.sku_code}`"
                                        @click="
                                            saveBarcode(
                                                sku.public_id,
                                                barcodeDraft(
                                                    sku.public_id,
                                                    shared.code,
                                                ),
                                            )
                                        "
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        :class="outlineButton"
                                        :disabled="busy"
                                        :aria-label="`Clear barcode for ${sku.sku_code}`"
                                        @click="saveBarcode(sku.public_id, '')"
                                    >
                                        Clear
                                    </button>
                                </div>
                                <p
                                    v-if="
                                        savedSku === sku.public_id &&
                                        page.props.errors?.barcode
                                    "
                                    :id="`barcode-${sku.public_id}-error`"
                                    role="alert"
                                    class="w-full text-sm break-words text-destructive"
                                >
                                    {{ page.props.errors.barcode }}
                                </p>
                            </template>
                        </div>
                    </article>
                    <p
                        v-if="readiness.sharedBarcodes.more"
                        class="text-sm text-muted-foreground"
                    >
                        Only the first 50 shared codes are shown. Fix these
                        first.
                    </p>
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="confirming !== null"
            :title="sheetTitle"
            :confirm-label="confirmLabel"
            :busy="busy"
            @update:open="(value) => (value ? null : (confirming = null))"
            @confirm="confirm"
        >
            <V2KeyValueList :items="sheetRows" />
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

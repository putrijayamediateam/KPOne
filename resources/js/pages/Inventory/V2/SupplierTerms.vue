<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2SwitchRow from '@/components/v2/V2SwitchRow.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatRinggit, packLabel, ringgitToSen } from '@/lib/supplier-terms';

/**
 * Version 2 Supplier price list (inventory V2 page 10b). Everything from `type Row` to `submitEdit` is a verbatim copy
 * of Classic (a test compares it). Only the layout is new: one table (cards below 1280px), "Show" as pressed buttons, and
 * V2 sheets for Add and Edit in place of the inline forms. The sheets call Classic's `submitCreate` and `submitEdit`
 * unchanged. Prices show only for a Director or Finance Officer, as in Classic.
 */
type Row = {
    publicId: string;
    supplier: string;
    skuCode: string;
    itemName: string;
    stockUnit: string;
    packDescription: string;
    packQuantity: string;
    packPriceSen?: number | null;
    leadTimeDays: number;
    minimumOrderPacks: number | null;
    preferred: boolean;
    active: boolean;
    lockVersion: number;
};

const props = defineProps<{
    terms: {
        branchName: string;
        expectedBranchId: number;
        canManage: boolean;
        canSeeCost: boolean;
        filters: { q: string; status: 'active' | 'inactive' };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.terms.filters.q);
const status = ref(props.terms.filters.status);
const visit = (page = 1) =>
    router.get(
        '/inventory/supplier-terms',
        { q: q.value || undefined, status: status.value, page },
        { preserveScroll: true },
    );

const blank = () => ({
    expected_branch_id: props.terms.expectedBranchId,
    idempotency_key: crypto.randomUUID(),
    supplier_public_id: '' as string | number,
    sku_public_id: '' as string | number,
    pack_description: '',
    pack_quantity: '',
    pack_price_rm: '',
    lead_time_days: '',
    minimum_order_packs: '',
    is_preferred: false,
});
const create = useForm(blank());
const priceError = ref('');

// RM to sen with whole-number arithmetic only (STK-10B): a pack price is kept exactly as quoted.
const payload = (
    data: ReturnType<typeof blank> & { pack_price_rm: string },
) => {
    const sen = ringgitToSen(data.pack_price_rm);
    const rest: Record<string, unknown> = { ...data };
    delete rest.pack_price_rm;

    return {
        ...rest,
        lead_time_days:
            data.lead_time_days === '' ? null : Number(data.lead_time_days),
        minimum_order_packs:
            data.minimum_order_packs === ''
                ? null
                : Number(data.minimum_order_packs),
        ...(props.terms.canSeeCost ? { pack_price_sen: sen } : {}),
    };
};
const priceIsValid = (value: string) => ringgitToSen(value) !== undefined;

const submitCreate = () => {
    priceError.value = '';

    if (props.terms.canSeeCost && !priceIsValid(create.pack_price_rm)) {
        priceError.value =
            'Enter the pack price as RM with up to 2 decimal places, for example 12.34, or leave it blank.';

        return;
    }

    create
        .transform((data) => payload(data))
        .post('/inventory/supplier-terms', {
            preserveScroll: true,
            onSuccess: () => {
                create.defaults(blank());
                create.reset();
            },
            onFinish: () => {
                create.idempotency_key = crypto.randomUUID();
            },
        });
};

const editing = ref<string | null>(null);
const edit = useForm({
    ...blank(),
    lock_version: 1,
    is_active: true,
});
const editPriceError = ref('');
const startEdit = (row: Row) => {
    editing.value = row.publicId;
    editPriceError.value = '';
    edit.clearErrors();
    Object.assign(edit, {
        expected_branch_id: props.terms.expectedBranchId,
        idempotency_key: crypto.randomUUID(),
        pack_description: row.packDescription,
        pack_quantity: row.packQuantity,
        pack_price_rm: formatRinggit(row.packPriceSen ?? null),
        lead_time_days: String(row.leadTimeDays),
        minimum_order_packs:
            row.minimumOrderPacks === null ? '' : String(row.minimumOrderPacks),
        is_preferred: row.preferred,
        is_active: row.active,
        lock_version: row.lockVersion,
    });
};
const submitEdit = (row: Row) => {
    editPriceError.value = '';

    if (props.terms.canSeeCost && !priceIsValid(edit.pack_price_rm)) {
        editPriceError.value =
            'Enter the pack price as RM with up to 2 decimal places, for example 12.34, or leave it blank.';

        return;
    }

    edit.transform((data) => {
        const rest: Record<string, unknown> = payload(data);
        delete rest.supplier_public_id;
        delete rest.sku_public_id;

        return rest;
    }).put(`/inventory/supplier-terms/${row.publicId}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
        onFinish: () => {
            edit.idempotency_key = crypto.randomUUID();
        },
    });
};

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Supplier price list' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const statusOptions = [
    { value: 'active', label: 'Active rows' },
    { value: 'inactive', label: 'Inactive rows' },
];
const chooseStatus = (value: string | null) => {
    if (value === 'active' || value === 'inactive') {
        status.value = value;
        visit();
    }
};
const addOpen = ref(false);
const openAdd = () => {
    create.clearErrors();
    priceError.value = '';
    addOpen.value = true;
};
// Classic resets the add form on success; the sheet closes then too.
watch(
    () => create.recentlySuccessful,
    (done) => {
        if (done) {
            addOpen.value = false;
        }
    },
);
const editingRow = computed(
    () =>
        props.terms.rows.find((row) => row.publicId === editing.value) ?? null,
);
/** Opens Classic's edit with the row's values as the baseline, so the sheet only asks before closing after a real change. */
const openEdit = (row: Row) => {
    startEdit(row);
    edit.defaults();
};
const days = (count: number) => `${count} ${count === 1 ? 'day' : 'days'}`;
const minimum = (row: Row) =>
    row.minimumOrderPacks === null
        ? 'None'
        : `${row.minimumOrderPacks} ${row.minimumOrderPacks === 1 ? 'pack' : 'packs'}`;
const inputClass =
    'h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9';
const PRICE_HINT = 'Leave blank if not quoted. Up to 2 decimal places.';
</script>

<template>
    <Head title="Supplier price list" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Supplier price list"
            :description="`${terms.branchName} · Pack sizes, prices and lead times for each supplier and SKU.`"
        >
            <template v-if="terms.canManage" #actions>
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    data-testid="terms-add"
                    @click="openAdd"
                >
                    Add a price list row
                </button>
            </template>
            <template #main>
                <p
                    v-if="!terms.canSeeCost"
                    class="text-sm text-muted-foreground"
                    data-testid="price-hidden-note"
                >
                    Prices are shown only to a Director or Finance Officer.
                </p>
                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    role="search"
                    @submit.prevent="visit()"
                >
                    <label
                        class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                        >Search SKU, item or supplier
                        <input
                            v-model="q"
                            type="search"
                            maxlength="100"
                            :class="inputClass"
                    /></label>
                    <button
                        type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >
                        Search
                    </button>
                </form>
                <V2OptionGroup
                    :model-value="status"
                    label="Show"
                    required
                    :options="statusOptions"
                    @update:model-value="chooseStatus"
                />
                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-terms"
                >
                    <h2
                        id="v2-terms"
                        class="v2-section-title text-lg font-semibold"
                    >
                        Price list rows ({{ terms.total }})
                    </h2>
                    <V2Table
                        label="Price list rows"
                        :columns="terms.canSeeCost ? 6 : 5"
                        cards="xl"
                        min-width="900px"
                        :empty="terms.rows.length === 0"
                        empty-message="No price list rows yet."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and supplier</th>
                                <th scope="col">Pack</th>
                                <th
                                    v-if="terms.canSeeCost"
                                    scope="col"
                                    class="text-right"
                                >
                                    Price for one pack
                                </th>
                                <th scope="col" class="text-right">
                                    Lead time
                                </th>
                                <th scope="col" class="text-right">
                                    Minimum order
                                </th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in terms.rows" :key="row.publicId">
                                <td
                                    data-stack
                                    data-label="SKU and supplier"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ row.itemName }}</span
                                        >
                                        <span
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(row.skuCode) }}</span
                                        >
                                        <span
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ row.supplier }}</span
                                        >
                                        <span
                                            v-if="row.preferred || !row.active"
                                            class="mt-1 flex flex-wrap gap-1"
                                        >
                                            <V2Chip
                                                v-if="row.preferred"
                                                tone="success"
                                                label="Preferred"
                                            />
                                            <V2Chip
                                                v-if="!row.active"
                                                tone="neutral"
                                                label="Inactive"
                                            />
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Pack" class="text-sm">
                                    {{
                                        packLabel(
                                            row.packDescription,
                                            row.packQuantity,
                                            row.stockUnit,
                                        )
                                    }}
                                </td>
                                <td
                                    v-if="terms.canSeeCost"
                                    data-label="Price for one pack"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    <span
                                        v-if="
                                            row.packPriceSen === null ||
                                            row.packPriceSen === undefined
                                        "
                                        class="text-muted-foreground"
                                        >Not recorded</span
                                    >
                                    <template v-else
                                        >RM
                                        {{
                                            formatRinggit(row.packPriceSen)
                                        }}</template
                                    >
                                </td>
                                <td
                                    data-label="Lead time"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ days(row.leadTimeDays) }}
                                </td>
                                <td
                                    data-label="Minimum order"
                                    class="text-right text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ minimum(row) }}
                                </td>
                                <td data-actions>
                                    <button
                                        v-if="terms.canManage"
                                        type="button"
                                        :class="outlineButton"
                                        :aria-label="`Edit ${row.skuCode} from ${row.supplier}`"
                                        @click="openEdit(row)"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="terms.total > 0"
                        :current-page="terms.page"
                        :last-page="terms.lastPage"
                        :total="terms.total"
                        :per-page="25"
                        @change="(n) => visit(n)"
                    />
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="addOpen"
            title="Add a price list row"
            confirm-label="Add row"
            :busy="create.processing"
            :dirty="create.isDirty"
            size="tall"
            @update:open="(value) => (addOpen = value)"
            @confirm="submitCreate"
        >
            <div class="flex flex-col gap-4 pb-8">
                <InventoryPickerField
                    :model-value="String(create.supplier_public_id)"
                    kind="suppliers"
                    label="Supplier"
                    required
                    :expected-branch-id="terms.expectedBranchId"
                    :error="create.errors.supplier_public_id"
                    @update:model-value="
                        (value) => (create.supplier_public_id = value)
                    "
                />
                <InventoryPickerField
                    :model-value="String(create.sku_public_id)"
                    kind="skus"
                    label="SKU"
                    required
                    :expected-branch-id="terms.expectedBranchId"
                    :error="create.errors.sku_public_id"
                    @update:model-value="
                        (value) => (create.sku_public_id = value)
                    "
                />
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Pack"
                    required
                    hint="For example: Box of 100"
                    :error="create.errors.pack_description"
                >
                    <input
                        :id="id"
                        v-model="create.pack_description"
                        maxlength="60"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Stock units in one pack"
                    required
                    :error="create.errors.pack_quantity"
                >
                    <input
                        :id="id"
                        v-model="create.pack_quantity"
                        inputmode="decimal"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <V2Field
                    v-if="terms.canSeeCost"
                    v-slot="{ id, describedBy }"
                    label="Price for one pack (RM)"
                    :hint="PRICE_HINT"
                    :error="
                        priceError ||
                        (create.errors as Record<string, string>).pack_price_sen
                    "
                >
                    <input
                        :id="id"
                        v-model="create.pack_price_rm"
                        inputmode="decimal"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Lead time in days"
                        required
                        :error="create.errors.lead_time_days"
                    >
                        <input
                            :id="id"
                            v-model="create.lead_time_days"
                            inputmode="numeric"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Minimum order in packs"
                        hint="Optional"
                        :error="create.errors.minimum_order_packs"
                    >
                        <input
                            :id="id"
                            v-model="create.minimum_order_packs"
                            inputmode="numeric"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <V2SwitchRow
                    v-model="create.is_preferred"
                    label="Preferred supplier for this SKU"
                    help="Reorder suggestions use the preferred supplier's pack, lead time and minimum order."
                />
            </div>
            <template #footer-note>
                <p class="text-sm" role="status">
                    Adds a row to the price list only. Nothing is ordered.
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="editing !== null"
            title="Edit price list row"
            :description="
                editingRow
                    ? `${unbroken(editingRow.skuCode)} · ${editingRow.itemName} from ${editingRow.supplier}`
                    : undefined
            "
            confirm-label="Save changes"
            :busy="edit.processing"
            :dirty="edit.isDirty"
            size="tall"
            @update:open="(value) => (value ? null : (editing = null))"
            @confirm="editingRow && submitEdit(editingRow)"
        >
            <div class="flex flex-col gap-4 pb-8">
                <p
                    v-if="edit.errors.lock_version"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ edit.errors.lock_version }}
                </p>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Pack"
                    required
                    :error="edit.errors.pack_description"
                >
                    <input
                        :id="id"
                        v-model="edit.pack_description"
                        maxlength="60"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Stock units in one pack"
                    required
                    :error="edit.errors.pack_quantity"
                >
                    <input
                        :id="id"
                        v-model="edit.pack_quantity"
                        inputmode="decimal"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <V2Field
                    v-if="terms.canSeeCost"
                    v-slot="{ id, describedBy }"
                    label="Price for one pack (RM)"
                    :hint="PRICE_HINT"
                    :error="
                        editPriceError ||
                        (edit.errors as Record<string, string>).pack_price_sen
                    "
                >
                    <input
                        :id="id"
                        v-model="edit.pack_price_rm"
                        inputmode="decimal"
                        :aria-describedby="describedBy"
                        :class="inputClass"
                    />
                </V2Field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Lead time in days"
                        required
                        :error="edit.errors.lead_time_days"
                    >
                        <input
                            :id="id"
                            v-model="edit.lead_time_days"
                            inputmode="numeric"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Minimum order in packs"
                        hint="Optional"
                        :error="edit.errors.minimum_order_packs"
                    >
                        <input
                            :id="id"
                            v-model="edit.minimum_order_packs"
                            inputmode="numeric"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <V2SwitchRow
                    v-model="edit.is_preferred"
                    label="Preferred supplier for this SKU"
                    :disabled="!edit.is_active"
                    disabled-reason="An inactive row cannot be preferred."
                />
                <V2SwitchRow
                    :model-value="edit.is_active"
                    label="Active"
                    help="An inactive row is kept but no longer used for suggestions."
                    @update:model-value="
                        (value) => {
                            edit.is_active = value;
                            edit.is_preferred =
                                edit.is_active && edit.is_preferred;
                        }
                    "
                />
                <p
                    v-if="edit.errors.is_preferred"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ edit.errors.is_preferred }}
                </p>
            </div>
            <template #footer-note>
                <p class="text-sm" role="status">
                    Changes this price list row only. Nothing is ordered.
                </p>
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

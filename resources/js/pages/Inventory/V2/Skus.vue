<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
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

/**
 * Version 2 SKUs (inventory V2 page 10b). Everything from `type Sku` to `errorFor` is a verbatim copy of Classic (a test
 * compares it). Only the layout is new: one table (cards below 1280px), "Show" as pressed buttons, a V2 sheet for Edit
 * in place of the inline form (switches for the ticks, storage as pressed buttons, locked fields with a lock), and the
 * V2 danger sheet for "Turn this off while it has stock?". The sheets call Classic's `submit` and `save` unchanged.
 */
type Sku = {
    publicId: string;
    skuCode: string;
    barcode: string | null;
    packSize: string;
    purchaseUnit: string;
    stockUnit: string;
    dispensingUnit: string;
    unitConversion: string;
    storageType: string;
    minimumTemperature: string | null;
    maximumTemperature: string | null;
    coldChainRequired: boolean;
    doNotFreeze: boolean;
    protectFromLight: boolean;
    batchTrackingRequired: boolean;
    expiryTrackingRequired: boolean;
    active: boolean;
    lockVersion: number;
    unitsLocked: boolean;
    onHand: boolean;
    barcodeShared: boolean;
    item: {
        publicId: string;
        code: string;
        genericName: string;
        brandName: string | null;
        active: boolean;
        lockVersion: number;
    };
};

const props = defineProps<{
    skus: {
        branchName: string;
        canManage: boolean;
        filters: { q: string; status: 'active' | 'inactive' | 'all' };
        rows: Sku[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();

const q = ref(props.skus.filters.q);
const status = ref(props.skus.filters.status);
const visit = (page = 1) =>
    router.get(
        '/inventory/skus',
        { q: q.value || undefined, status: status.value, page },
        { preserveScroll: true },
    );

const storageTypes = [
    { value: 'ambient', label: 'Room temperature' },
    { value: 'refrigerated', label: 'Refrigerated' },
    { value: 'frozen', label: 'Frozen' },
];
// A value saved before this screen keeps showing, so editing never changes it silently.
const storageOptions = (current: string) =>
    storageTypes.some((type) => type.value === current)
        ? storageTypes
        : [...storageTypes, { value: current, label: current }];
const storageLabel = (value: string) =>
    storageTypes.find((type) => type.value === value)?.label ?? value;

const editing = ref<string | null>(null);
const form = useForm({
    generic_name: '',
    brand_name: '',
    sku_code: '',
    barcode: '',
    pack_size: '',
    purchase_unit: '',
    stock_unit: '',
    dispensing_unit: '',
    unit_conversion: '',
    storage_type: 'ambient',
    minimum_temperature: '',
    maximum_temperature: '',
    cold_chain_required: false,
    do_not_freeze: false,
    protect_from_light: false,
    batch_tracking_required: true,
    expiry_tracking_required: true,
    sku_active: true,
    item_active: true,
    sku_lock_version: 1,
    item_lock_version: 1,
});
const startEdit = (sku: Sku) => {
    editing.value = sku.publicId;
    form.clearErrors();
    Object.assign(form, {
        generic_name: sku.item.genericName,
        brand_name: sku.item.brandName ?? '',
        sku_code: sku.skuCode,
        barcode: sku.barcode ?? '',
        pack_size: sku.packSize,
        purchase_unit: sku.purchaseUnit,
        stock_unit: sku.stockUnit,
        dispensing_unit: sku.dispensingUnit,
        unit_conversion: sku.unitConversion,
        storage_type: sku.storageType,
        minimum_temperature: sku.minimumTemperature ?? '',
        maximum_temperature: sku.maximumTemperature ?? '',
        cold_chain_required: sku.coldChainRequired,
        do_not_freeze: sku.doNotFreeze,
        protect_from_light: sku.protectFromLight,
        batch_tracking_required: sku.batchTrackingRequired,
        expiry_tracking_required: sku.expiryTrackingRequired,
        sku_active: sku.active,
        item_active: sku.item.active,
        sku_lock_version: sku.lockVersion,
        item_lock_version: sku.item.lockVersion,
    });
};
// Daniel's review: deactivating a SKU (or its item) that still has stock asks first, in the shared dialog.
const confirming = ref<Sku | null>(null);
const submit = (sku: Sku) => {
    const deactivates =
        (sku.active && !form.sku_active) ||
        (sku.item.active && !form.item_active);

    if (sku.onHand && deactivates && confirming.value === null) {
        confirming.value = sku;

        return;
    }

    save(sku);
};
const save = (sku: Sku) => {
    form.transform((data) => {
        const sent: Record<string, unknown> = {
            ...data,
            brand_name: data.brand_name.trim() === '' ? null : data.brand_name,
            barcode: data.barcode.trim() === '' ? null : data.barcode,
            minimum_temperature:
                data.minimum_temperature === ''
                    ? null
                    : data.minimum_temperature,
            maximum_temperature:
                data.maximum_temperature === ''
                    ? null
                    : data.maximum_temperature,
        };

        // Locked unit fields are never sent, so the server never sees a change to them.
        if (sku.unitsLocked) {
            for (const field of [
                'sku_code',
                'pack_size',
                'purchase_unit',
                'stock_unit',
                'dispensing_unit',
                'unit_conversion',
                'batch_tracking_required',
                'expiry_tracking_required',
            ]) {
                delete sent[field];
            }
        }

        return sent;
    }).put(`/inventory/skus/${sku.publicId}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
        onFinish: () => {
            confirming.value = null;
        },
    });
};
const lockedRows = (sku: Sku) => [
    ['SKU code', sku.skuCode],
    ['Stock unit', sku.stockUnit],
    ['Dispensing unit', sku.dispensingUnit],
    ['Unit conversion', sku.unitConversion],
    ['Batch tracking', sku.batchTrackingRequired ? 'Yes' : 'No'],
    ['Expiry tracking', sku.expiryTrackingRequired ? 'Yes' : 'No'],
];
const errorFor = (field: string) =>
    (form.errors as Record<string, string | undefined>)[field];

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'SKUs' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const statusOptions = [
    { value: 'active', label: 'Active SKUs' },
    { value: 'inactive', label: 'Inactive SKUs' },
    { value: 'all', label: 'All SKUs' },
];
const chooseStatus = (value: string | null) => {
    if (value === 'active' || value === 'inactive' || value === 'all') {
        status.value = value;
        visit();
    }
};
const editingSku = computed(
    () => props.skus.rows.find((sku) => sku.publicId === editing.value) ?? null,
);
/** Opens Classic's edit with the SKU's values as the baseline, so the sheet only asks before closing after a real change. */
const openEdit = (sku: Sku) => {
    startEdit(sku);
    form.defaults();
};
const temperature = (sku: Sku) =>
    sku.minimumTemperature === null && sku.maximumTemperature === null
        ? 'Not set'
        : `${sku.minimumTemperature ?? '–'} to ${sku.maximumTemperature ?? '–'} °C`;
const handling = (sku: Sku) =>
    [
        sku.coldChainRequired && 'Cold chain',
        sku.doNotFreeze && 'Do not freeze',
        sku.protectFromLight && 'Protect from light',
    ]
        .filter(Boolean)
        .join(', ') || 'None';
const sheetError = computed(
    () =>
        errorFor('expected_lock_version') ||
        errorFor('sku') ||
        errorFor('item') ||
        errorFor('inventory_item') ||
        '',
);
const inputClass =
    'h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9';
</script>

<template>
    <Head title="SKUs" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="SKUs"
            :description="`${skus.branchName} · Names, barcode, storage and active status for each stock item.`"
        >
            <template #main>
                <p
                    v-if="!skus.canManage"
                    class="text-sm text-muted-foreground"
                    data-testid="view-only-note"
                >
                    You can view SKUs but not change them.
                </p>
                <form
                    class="flex min-w-0 flex-wrap items-end gap-2"
                    role="search"
                    @submit.prevent="visit()"
                >
                    <label
                        class="flex min-w-0 flex-1 basis-64 flex-col gap-1.5 text-sm font-medium"
                        >Search SKU code, name or barcode
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
                    aria-labelledby="v2-skus"
                >
                    <h2
                        id="v2-skus"
                        class="v2-section-title text-lg font-semibold"
                    >
                        SKUs ({{ skus.total }})
                    </h2>
                    <V2Table
                        label="SKUs"
                        :columns="6"
                        cards="xl"
                        min-width="980px"
                        :empty="skus.rows.length === 0"
                        empty-message="No SKUs match."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">SKU and item</th>
                                <th scope="col">Storage</th>
                                <th scope="col">Temperature</th>
                                <th scope="col">Handling</th>
                                <th scope="col">Units</th>
                                <th scope="col">
                                    <span class="sr-only">Action</span>
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="sku in skus.rows" :key="sku.publicId">
                                <td
                                    data-stack
                                    data-label="SKU and item"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm font-medium break-words"
                                            >{{ sku.item.genericName
                                            }}<template
                                                v-if="sku.item.brandName"
                                            >
                                                ({{
                                                    sku.item.brandName
                                                }})</template
                                            ></span
                                        >
                                        <span
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(sku.skuCode) }}</span
                                        >
                                        <span
                                            v-if="sku.barcode"
                                            class="block font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >Barcode {{ sku.barcode }}</span
                                        >
                                        <span
                                            v-if="sku.barcodeShared"
                                            class="mt-1 block text-xs font-medium text-warning-strong"
                                            data-testid="barcode-shared"
                                            >Barcode also on another SKU. Fix it
                                            on
                                            <Link
                                                href="/inventory/readiness"
                                                class="underline underline-offset-4"
                                                >Pilot readiness</Link
                                            >.</span
                                        >
                                        <V2Chip
                                            v-if="!sku.active"
                                            class="mt-1"
                                            tone="neutral"
                                            label="Inactive"
                                        />
                                    </div>
                                </td>
                                <td data-label="Storage" class="text-sm">
                                    {{ storageLabel(sku.storageType) }}
                                </td>
                                <td
                                    data-label="Temperature"
                                    class="text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ temperature(sku) }}
                                </td>
                                <td data-label="Handling" class="text-sm">
                                    {{ handling(sku) }}
                                </td>
                                <td data-stack data-label="Units">
                                    <div class="min-w-0 text-sm">
                                        <span class="block"
                                            >Stock: {{ sku.stockUnit }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >Dispensing:
                                            {{ sku.dispensingUnit }}</span
                                        >
                                    </div>
                                </td>
                                <td data-actions>
                                    <button
                                        v-if="skus.canManage"
                                        type="button"
                                        :class="outlineButton"
                                        :aria-label="`Edit ${sku.skuCode}`"
                                        @click="openEdit(sku)"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="skus.total > 0"
                        :current-page="skus.page"
                        :last-page="skus.lastPage"
                        :total="skus.total"
                        :per-page="25"
                        @change="(n) => visit(n)"
                    />
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="editing !== null && confirming === null"
            :title="
                editingSku ? `Edit ${unbroken(editingSku.skuCode)}` : 'Edit SKU'
            "
            confirm-label="Save changes"
            :busy="form.processing"
            :dirty="form.isDirty"
            size="tall"
            @update:open="(value) => (value ? null : (editing = null))"
            @confirm="editingSku && submit(editingSku)"
        >
            <div v-if="editingSku" class="flex flex-col gap-6 pb-8">
                <p
                    v-if="sheetError"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ sheetError }}
                </p>
                <fieldset class="flex flex-col gap-4">
                    <legend class="mb-2 text-base font-semibold">Item</legend>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Generic name"
                        required
                        :error="errorFor('generic_name')"
                    >
                        <input
                            :id="id"
                            v-model="form.generic_name"
                            maxlength="300"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Brand name"
                        hint="Optional"
                        :error="errorFor('brand_name')"
                    >
                        <input
                            :id="id"
                            v-model="form.brand_name"
                            maxlength="300"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                </fieldset>

                <fieldset class="flex flex-col gap-4">
                    <legend class="mb-2 text-base font-semibold">
                        Barcode and storage
                    </legend>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Barcode"
                        hint="Optional"
                        :error="errorFor('barcode')"
                    >
                        <input
                            :id="id"
                            v-model="form.barcode"
                            maxlength="100"
                            :aria-describedby="describedBy"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2OptionGroup
                        :model-value="form.storage_type"
                        label="Storage"
                        required
                        :options="storageOptions(editingSku.storageType)"
                        :error="errorFor('storage_type')"
                        @update:model-value="
                            (value) => (form.storage_type = value ?? 'ambient')
                        "
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Minimum temperature °C"
                            hint="Optional"
                            :error="errorFor('minimum_temperature')"
                        >
                            <input
                                :id="id"
                                v-model="form.minimum_temperature"
                                inputmode="decimal"
                                :aria-describedby="describedBy"
                                :class="inputClass"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Maximum temperature °C"
                            hint="Optional"
                            :error="errorFor('maximum_temperature')"
                        >
                            <input
                                :id="id"
                                v-model="form.maximum_temperature"
                                inputmode="decimal"
                                :aria-describedby="describedBy"
                                :class="inputClass"
                            />
                        </V2Field>
                    </div>
                    <V2SwitchRow
                        v-model="form.cold_chain_required"
                        label="Cold chain"
                    />
                    <V2SwitchRow
                        v-model="form.do_not_freeze"
                        label="Do not freeze"
                    />
                    <V2SwitchRow
                        v-model="form.protect_from_light"
                        label="Protect from light"
                    />
                </fieldset>

                <fieldset class="flex flex-col gap-4">
                    <legend class="mb-2 text-base font-semibold">
                        Code and units
                    </legend>
                    <p
                        v-if="editingSku.unitsLocked"
                        class="text-sm text-muted-foreground"
                        data-testid="units-locked"
                    >
                        These cannot change because this SKU already has
                        batches, stock, a medicine link or dispensing records.
                    </p>
                    <template v-if="!editingSku.unitsLocked">
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="SKU code"
                            required
                            :error="errorFor('sku_code')"
                        >
                            <input
                                :id="id"
                                v-model="form.sku_code"
                                maxlength="64"
                                :aria-describedby="describedBy"
                                :class="inputClass"
                            />
                        </V2Field>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Stock unit"
                                required
                                :error="errorFor('stock_unit')"
                            >
                                <input
                                    :id="id"
                                    v-model="form.stock_unit"
                                    maxlength="100"
                                    :aria-describedby="describedBy"
                                    :class="inputClass"
                                />
                            </V2Field>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Dispensing unit"
                                required
                                :error="errorFor('dispensing_unit')"
                            >
                                <input
                                    :id="id"
                                    v-model="form.dispensing_unit"
                                    maxlength="100"
                                    :aria-describedby="describedBy"
                                    :class="inputClass"
                                />
                            </V2Field>
                        </div>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Unit conversion"
                            required
                            :error="errorFor('unit_conversion')"
                        >
                            <input
                                :id="id"
                                v-model="form.unit_conversion"
                                inputmode="decimal"
                                :aria-describedby="describedBy"
                                :class="inputClass"
                            />
                        </V2Field>
                        <V2SwitchRow
                            v-model="form.batch_tracking_required"
                            label="Batch tracking"
                        />
                        <V2SwitchRow
                            v-model="form.expiry_tracking_required"
                            label="Expiry tracking"
                        />
                    </template>
                    <dl
                        v-else
                        class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2"
                    >
                        <div
                            v-for="[label, value] in lockedRows(editingSku)"
                            :key="label"
                            class="flex items-start gap-2"
                        >
                            <Lock
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <dt class="text-muted-foreground">
                                    {{ label }}
                                </dt>
                                <dd class="break-words">{{ value }}</dd>
                            </div>
                        </div>
                    </dl>
                </fieldset>

                <fieldset class="flex flex-col gap-4">
                    <legend class="mb-1 text-base font-semibold">
                        Legacy pack fields
                    </legend>
                    <p class="text-sm text-muted-foreground">
                        Kept from before verified stock units. Supplier pack
                        sizes and prices belong on the Supplier price list.
                    </p>
                    <div
                        v-if="!editingSku.unitsLocked"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Pack size"
                            :error="errorFor('pack_size')"
                        >
                            <input
                                :id="id"
                                v-model="form.pack_size"
                                inputmode="decimal"
                                :aria-describedby="describedBy"
                                :class="inputClass"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Purchase unit"
                            :error="errorFor('purchase_unit')"
                        >
                            <input
                                :id="id"
                                v-model="form.purchase_unit"
                                maxlength="100"
                                :aria-describedby="describedBy"
                                :class="inputClass"
                            />
                        </V2Field>
                    </div>
                    <dl
                        v-else
                        class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2"
                    >
                        <div
                            v-for="[label, value] in [
                                ['Pack size', editingSku.packSize],
                                ['Purchase unit', editingSku.purchaseUnit],
                            ]"
                            :key="label"
                            class="flex items-start gap-2"
                        >
                            <Lock
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <dt class="text-muted-foreground">
                                    {{ label }}
                                </dt>
                                <dd class="break-words">{{ value }}</dd>
                            </div>
                        </div>
                    </dl>
                </fieldset>

                <fieldset class="flex flex-col gap-4">
                    <legend class="mb-1 text-base font-semibold">Status</legend>
                    <V2SwitchRow
                        v-model="form.sku_active"
                        label="SKU active"
                        help="An inactive SKU cannot be ordered, received or dispensed. Its stock and history stay."
                    />
                    <V2SwitchRow
                        v-model="form.item_active"
                        label="Item active"
                        help="Item active affects every SKU of this item. Turn off its SKUs first; an inactive item's SKUs cannot be used."
                    />
                </fieldset>
            </div>
            <template #footer-note>
                <p class="text-sm" role="status">
                    Changes this SKU and its item only. No stock changes.
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="confirming !== null"
            title="Turn this off while it has stock?"
            :description="`${confirming?.skuCode ?? ''} still has stock on hand. Turning it off stops ordering, receiving and dispensing; the stock and its history stay. Use a governed write-off or transfer to move the stock.`"
            confirm-label="Save and turn off"
            tone="danger"
            :busy="form.processing"
            @update:open="(open) => (confirming = open ? confirming : null)"
            @confirm="confirming && save(confirming)"
        />
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

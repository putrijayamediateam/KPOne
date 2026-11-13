<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type { PickerOption } from '@/components/v2/V2Picker.vue';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { requestJson } from '@/lib/json-client';
import { formatDate } from '@/lib/presentation';

/**
 * Inventory V2 page 2d (stock setup): the six Classic Stock setup forms as V2 sheets. The forms, their routes and
 * payloads, the batch loader and the location types are copied from the Classic
 * `components/inventory/InventoryReferenceDataPanel.vue`, which keeps its own copy (a test compares them). Each sheet
 * has one filled action and asks before it closes with typed values; a refused save keeps the sheet open.
 */
type ReferenceData = {
    canManage: boolean;
    expectedBranchId: number;
    items: Array<{ publicId: string; code: string; genericName: string }>;
    skus: Array<{ publicId: string; skuCode: string; inventoryItemId: number }>;
    locations: Array<{ publicId: string; name: string; type: string }>;
    medicines: Array<{ publicId: string; code: string; displayName: string }>;
    branches: Array<{ id: number; name: string }>;
};

const props = defineProps<{
    referenceData: ReferenceData;
    /** The branch time zone, to say when a batch's expiry has already passed. */
    timezone: string;
}>();

type SkuBatch = {
    publicId: string;
    batchNumber: string;
    expiryDate: string;
    held?: boolean;
    holdReason?: string;
};
const skuBatches = ref<SkuBatch[]>([]);
const batchesLoading = ref(false);
const batchesError = ref('');
const batchSearch = ref('');
let batchesRequest = 0;
const loadSkuBatches = async (skuPublicId: string) => {
    const request = ++batchesRequest;
    skuBatches.value = [];
    batchesError.value = '';

    if (!skuPublicId) {
        batchesLoading.value = false;

        return;
    }

    batchesLoading.value = true;

    try {
        const response = await requestJson<{ data: SkuBatch[] }>(
            `/inventory-references/skus/${skuPublicId}/batches?q=${encodeURIComponent(batchSearch.value)}`,
        );

        if (request === batchesRequest) {
            skuBatches.value = response.data;
        }
    } catch {
        if (request === batchesRequest) {
            batchesError.value = 'Batches could not be loaded for this SKU.';
        }
    } finally {
        if (request === batchesRequest) {
            batchesLoading.value = false;
        }
    }
};
const openingBalanceForm = useForm({
    expected_branch_id: props.referenceData.branches[0]?.id ?? '',
    location_public_id: '',
    sku_public_id: '',
    batch_public_id: '',
    quantity: '',
});
const openingBalanceError = computed(
    () =>
        Object.entries(openingBalanceForm.errors).find(
            ([key]) => key === 'opening_balance',
        )?.[1],
);
watch(
    () => openingBalanceForm.sku_public_id,
    (skuPublicId) => {
        openingBalanceForm.batch_public_id = '';
        batchSearch.value = '';
        void loadSkuBatches(skuPublicId);
    },
);
const submitOpeningBalance = () => {
    openingBalanceForm.post('/inventory/opening-balances', {
        preserveScroll: true,
        onSuccess: () => {
            openingBalanceForm.reset('quantity');
        },
    });
};
const itemForm = useForm({
    code: '',
    generic_name: '',
    brand_name: '',
});
const submitItem = () => {
    itemForm.post('/inventory-references/items', {
        preserveScroll: true,
        onSuccess: () => itemForm.reset(),
    });
};
const skuForm = useForm({
    inventory_item_public_id: '',
    sku_code: '',
    pack_size: '1',
    purchase_unit: '',
    stock_unit: '',
    dispensing_unit: '',
    unit_conversion: '1',
});
const submitSku = () => {
    skuForm.post('/inventory-references/skus', {
        preserveScroll: true,
        onSuccess: () => skuForm.reset(),
    });
};
const locationForm = useForm({
    branch_id: '',
    code: '',
    name: '',
    type: 'medical_stock',
});
const locationTypeOptions = [
    { value: 'medical_stock', label: 'Medical Stock' },
    { value: 'branch_store', label: 'Branch Store' },
    { value: 'dispensary', label: 'Dispensary' },
    {
        value: 'dispensary_returns',
        label: 'Patient Returns (not for dispensing)',
    },
];
const submitLocation = () => {
    locationForm.post('/inventory-references/locations', {
        preserveScroll: true,
        onSuccess: () => locationForm.reset(),
    });
};
const batchForm = useForm({
    inventory_sku_public_id: '',
    batch_number: '',
    expiry_date: '',
});
const submitBatch = () => {
    batchForm.post('/inventory-references/batches', {
        preserveScroll: true,
        onSuccess: () => batchForm.reset(),
    });
};
const mappingForm = useForm({
    medicine_public_id: '',
    inventory_sku_public_id: '',
});
const submitMapping = () => {
    mappingForm.post('/inventory-references/mappings', {
        preserveScroll: true,
        onSuccess: () => mappingForm.reset(),
    });
};

// ---- V2 only -------------------------------------------------------------------------------------------------------
type Sheet =
    'opening_balance' | 'item' | 'sku' | 'location' | 'batch' | 'mapping';
const sheet = ref<Sheet | null>(null);
/** What the sheets need from each form: reset, clear errors, and whether the last save succeeded. */
type SheetForm = {
    reset: () => void;
    clearErrors: () => void;
    readonly wasSuccessful: boolean;
};
const forms: Record<Sheet, SheetForm> = {
    opening_balance: openingBalanceForm,
    item: itemForm,
    sku: skuForm,
    location: locationForm,
    batch: batchForm,
    mapping: mappingForm,
};
const actions: Array<{ id: Sheet; label: string; line: string }> = [
    {
        id: 'opening_balance',
        label: 'Record an opening balance',
        line: 'The stock already on the shelf when a location starts using KPOne.',
    },
    {
        id: 'item',
        label: 'Add an item',
        line: 'A medicine or supply, by its generic name.',
    },
    {
        id: 'sku',
        label: 'Add a SKU',
        line: 'How an item is bought, stocked and dispensed, with its units.',
    },
    {
        id: 'location',
        label: 'Add a location',
        line: 'A store, dispensary or patient returns shelf.',
    },
    {
        id: 'batch',
        label: 'Add a batch',
        line: 'A batch number and its expiry for a SKU.',
    },
    {
        id: 'mapping',
        label: 'Link a medicine to a SKU',
        line: 'So Dispensary knows which stock a medicine uses.',
    },
];
const open = (id: Sheet) => {
    forms[id].clearErrors();
    sheet.value = id;

    if (id === 'opening_balance' && openingBalanceForm.sku_public_id) {
        void loadSkuBatches(openingBalanceForm.sku_public_id);
    }
};
/** Esc, the X, the backdrop and Cancel: runs once the person chose to discard, or when nothing was typed. */
const requestClose = (value: boolean) => {
    if (!value && sheet.value) {
        forms[sheet.value].reset();
        forms[sheet.value].clearErrors();
        sheet.value = null;
    }
};

// The Classic submits reset their form on success; the sheet then closes. A refusal keeps it open with every value.
for (const [id, form] of Object.entries(forms) as Array<[Sheet, SheetForm]>) {
    watch(
        () => form.wasSuccessful,
        (done) => {
            if (done && sheet.value === id) {
                sheet.value = null;
            }
        },
    );
}

const submit = () => {
    const run = {
        opening_balance: submitOpeningBalance,
        item: submitItem,
        sku: submitSku,
        location: submitLocation,
        batch: submitBatch,
        mapping: submitMapping,
    };

    if (sheet.value) {
        run[sheet.value]();
    }
};

// Pickers read the page's own lists (V2Picker filters them).
const batchChoices = computed<PickerOption[]>(() =>
    skuBatches.value.map((b) => ({
        id: b.publicId,
        label: b.batchNumber,
        detail: `${b.held ? `Held: ${formatInventoryReason(b.holdReason)} · ` : ''}Expires ${formatDate(b.expiryDate)}`,
    })),
);
const branchChoices = computed(() =>
    props.referenceData.branches.map((b) => ({
        value: String(b.id),
        label: b.name,
    })),
);
/** A patient returns location must belong to a branch (STK-11), so "Shared" is offered only for the other types. */
const locationBranchChoices = computed(() => [
    ...(locationForm.type === 'dispensary_returns'
        ? []
        : [{ value: '', label: 'Shared (no branch)' }]),
    ...branchChoices.value,
]);
const returnsNeedsBranch = computed(
    () => locationForm.type === 'dispensary_returns' && !locationForm.branch_id,
);
/** The chosen location's and SKU's words, from the pickers, for the sentence below. */
const openingLocation = ref('');
const openingSku = ref('');
const openingUnit = ref('');
watch(
    () => [
        openingBalanceForm.location_public_id,
        openingBalanceForm.sku_public_id,
    ],
    ([location, sku]) => {
        if (!location) {
            openingLocation.value = '';
        }

        if (!sku) {
            openingSku.value = '';
            openingUnit.value = '';
        }
    },
);

/** Said before the press, and updated as the fields change (Daniel, 2d change e). */
const openingSentence = computed(() => {
    const quantity = String(openingBalanceForm.quantity).trim();
    const sku = openingSku.value;
    const location = openingLocation.value;

    if (!quantity || !sku || !location || !openingBalanceForm.batch_public_id) {
        return 'Choose the location, SKU and batch, and enter the quantity, to see what this records.';
    }

    const amount = openingUnit.value
        ? quantityWithUnit(quantity, openingUnit.value)
        : `${quantity} stock units`;

    return `Records ${amount} of ${sku} in ${location} as opening stock. It counts at once; someone else checks it on Pilot readiness.`;
});

const branchToday = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: props.timezone }).format(
        new Date(),
    );
/** No minimum: an expired batch may be recorded for history, but it is said in words (Daniel, 2d answer 1). */
const expiryWarning = computed(() =>
    batchForm.expiry_date && batchForm.expiry_date < branchToday()
        ? 'This date has passed. The batch can be recorded for history but cannot be dispensed.'
        : undefined,
);
const fieldClass =
    'h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]';
</script>

<template>
    <div
        v-if="referenceData.canManage"
        class="space-y-3"
        data-test="v2-stock-setup"
    >
        <p class="text-sm text-muted-foreground">
            Set up what a branch needs before Dispensary can hand out a
            medicine.
        </p>
        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="action in actions"
                :key="action.id"
                class="grid gap-1.5 rounded-[14px] border p-3"
            >
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    @click="open(action.id)"
                >
                    {{ action.label }}
                </button>
                <p class="text-xs text-muted-foreground">{{ action.line }}</p>
            </li>
        </ul>
    </div>

    <!-- Record an opening balance (long form). -->
    <V2Dialog
        :open="sheet === 'opening_balance'"
        size="tall"
        :dirty="openingBalanceForm.isDirty"
        title="Record an opening balance"
        description="The stock already on the shelf when this location starts using KPOne."
        confirm-label="Record opening balance"
        cancel-label="Cancel"
        :busy="openingBalanceForm.processing"
        @update:open="requestClose"
        @confirm="submit"
    >
        <p
            v-if="openingBalanceError"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ openingBalanceError }}
        </p>
        <V2OptionGroup
            :model-value="String(openingBalanceForm.expected_branch_id)"
            label="Branch"
            required
            :options="branchChoices"
            @update:model-value="
                (value) =>
                    (openingBalanceForm.expected_branch_id = Number(value))
            "
        />
        <InventoryPickerField
            v-model="openingBalanceForm.location_public_id"
            kind="locations"
            context="references"
            label="Location"
            required
            :expected-branch-id="referenceData.expectedBranchId"
            :error="openingBalanceForm.errors.location_public_id"
            @picked="(value) => (openingLocation = value)"
        />
        <InventoryPickerField
            v-model="openingBalanceForm.sku_public_id"
            kind="skus"
            context="references"
            label="SKU"
            required
            :expected-branch-id="referenceData.expectedBranchId"
            :error="openingBalanceForm.errors.sku_public_id"
            @picked="
                (value, unit) => {
                    openingSku = value;
                    openingUnit = unit;
                }
            "
        />
        <V2Picker
            :model-value="openingBalanceForm.batch_public_id || null"
            label="Batch"
            required
            :placeholder="
                !openingBalanceForm.sku_public_id
                    ? 'Choose a SKU first'
                    : batchesLoading
                      ? 'Loading batches…'
                      : 'Choose a batch'
            "
            :disabled="!openingBalanceForm.sku_public_id"
            :options="batchChoices"
            hint="Up to 25 batches of this SKU are listed."
            :error="batchesError || openingBalanceForm.errors.batch_public_id"
            @update:model-value="
                (value) => (openingBalanceForm.batch_public_id = value ?? '')
            "
        />
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >Quantity (stock units)
                <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="openingBalanceForm.quantity"
                inputmode="decimal"
                required
                :class="fieldClass"
            />
            <span
                v-if="openingBalanceForm.errors.quantity"
                class="text-sm text-destructive"
                >{{ openingBalanceForm.errors.quantity }}</span
            >
        </label>
        <p
            class="rounded-[14px] border bg-muted/40 px-4 py-3 text-sm"
            role="status"
        >
            {{ openingSentence }}
        </p>
    </V2Dialog>

    <!-- Add an item. -->
    <V2Dialog
        :open="sheet === 'item'"
        :dirty="itemForm.isDirty"
        title="Add an item"
        confirm-label="Add item"
        cancel-label="Cancel"
        :busy="itemForm.processing"
        @update:open="requestClose"
        @confirm="submit"
    >
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >Code <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="itemForm.code"
                autocomplete="off"
                maxlength="64"
                required
                :class="fieldClass"
            />
            <span
                v-if="itemForm.errors.code"
                class="text-sm text-destructive"
                >{{ itemForm.errors.code }}</span
            >
        </label>
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >Generic name
                <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="itemForm.generic_name"
                autocomplete="off"
                maxlength="300"
                required
                :class="fieldClass"
            />
            <span
                v-if="itemForm.errors.generic_name"
                class="text-sm text-destructive"
                >{{ itemForm.errors.generic_name }}</span
            >
        </label>
        <label class="grid gap-1.5 text-sm font-medium"
            >Brand name (optional)
            <input
                v-model="itemForm.brand_name"
                autocomplete="off"
                maxlength="300"
                :class="fieldClass"
            />
            <span
                v-if="itemForm.errors.brand_name"
                class="text-sm text-destructive"
                >{{ itemForm.errors.brand_name }}</span
            >
        </label>
    </V2Dialog>

    <!-- Add a SKU (long form). -->
    <V2Dialog
        :open="sheet === 'sku'"
        size="tall"
        :dirty="skuForm.isDirty"
        title="Add a SKU"
        description="How an item is bought, stocked and dispensed. Stock is always counted in the stock unit."
        confirm-label="Add SKU"
        cancel-label="Cancel"
        :busy="skuForm.processing"
        @update:open="requestClose"
        @confirm="submit"
    >
        <InventoryPickerField
            v-model="skuForm.inventory_item_public_id"
            kind="items"
            context="references"
            label="Item"
            required
            :expected-branch-id="referenceData.expectedBranchId"
            :error="skuForm.errors.inventory_item_public_id"
        />
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >SKU code
                <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="skuForm.sku_code"
                autocomplete="off"
                maxlength="64"
                required
                :class="fieldClass"
            />
            <span
                v-if="skuForm.errors.sku_code"
                class="text-sm text-destructive"
                >{{ skuForm.errors.sku_code }}</span
            >
        </label>
        <div class="grid items-start gap-3 sm:grid-cols-2">
            <label class="grid gap-1.5 text-sm font-medium"
                ><span
                    >Pack size
                    <span class="text-destructive" aria-hidden="true">*</span
                    ><span class="sr-only">(required)</span></span
                >
                <input
                    v-model="skuForm.pack_size"
                    required
                    inputmode="decimal"
                    :class="fieldClass"
                />
                <span class="text-xs font-normal text-muted-foreground"
                    >Recorded only. Stock is always counted in the stock unit,
                    so leave 1 unless your records use it.</span
                >
                <span
                    v-if="skuForm.errors.pack_size"
                    class="text-sm text-destructive"
                    >{{ skuForm.errors.pack_size }}</span
                >
            </label>
            <label class="grid gap-1.5 text-sm font-medium"
                ><span
                    >Unit conversion
                    <span class="text-destructive" aria-hidden="true">*</span
                    ><span class="sr-only">(required)</span></span
                >
                <input
                    v-model="skuForm.unit_conversion"
                    required
                    inputmode="decimal"
                    :class="fieldClass"
                />
                <span class="text-xs font-normal text-muted-foreground"
                    >Recorded only, not used in any count. Leave 1.</span
                >
                <span
                    v-if="skuForm.errors.unit_conversion"
                    class="text-sm text-destructive"
                    >{{ skuForm.errors.unit_conversion }}</span
                >
            </label>
            <label class="grid gap-1.5 text-sm font-medium"
                ><span
                    >Purchase unit
                    <span class="text-destructive" aria-hidden="true">*</span
                    ><span class="sr-only">(required)</span></span
                >
                <input
                    v-model="skuForm.purchase_unit"
                    required
                    autocomplete="off"
                    maxlength="100"
                    :class="fieldClass"
                />
                <span class="text-xs font-normal text-muted-foreground"
                    >How the supplier sells it, for example box.</span
                >
                <span
                    v-if="skuForm.errors.purchase_unit"
                    class="text-sm text-destructive"
                    >{{ skuForm.errors.purchase_unit }}</span
                >
            </label>
            <label class="grid gap-1.5 text-sm font-medium"
                ><span
                    >Stock unit
                    <span class="text-destructive" aria-hidden="true">*</span
                    ><span class="sr-only">(required)</span></span
                >
                <input
                    v-model="skuForm.stock_unit"
                    required
                    autocomplete="off"
                    maxlength="100"
                    :class="fieldClass"
                />
                <span class="text-xs font-normal text-muted-foreground"
                    >For example tablet: every count uses it.</span
                >
                <span
                    v-if="skuForm.errors.stock_unit"
                    class="text-sm text-destructive"
                    >{{ skuForm.errors.stock_unit }}</span
                >
            </label>
            <label class="grid gap-1.5 text-sm font-medium"
                ><span
                    >Dispensing unit
                    <span class="text-destructive" aria-hidden="true">*</span
                    ><span class="sr-only">(required)</span></span
                >
                <input
                    v-model="skuForm.dispensing_unit"
                    required
                    autocomplete="off"
                    maxlength="100"
                    :class="fieldClass"
                />
                <span class="text-xs font-normal text-muted-foreground"
                    >Must be the same as the stock unit, or Dispensary will not
                    dispense this SKU.</span
                >
                <span
                    v-if="skuForm.errors.dispensing_unit"
                    class="text-sm text-destructive"
                    >{{ skuForm.errors.dispensing_unit }}</span
                >
            </label>
        </div>
    </V2Dialog>

    <!-- Add a location. -->
    <V2Dialog
        :open="sheet === 'location'"
        :dirty="locationForm.isDirty"
        title="Add a location"
        confirm-label="Add location"
        cancel-label="Cancel"
        :busy="locationForm.processing"
        :confirm-disabled="returnsNeedsBranch"
        :confirm-disabled-reason="
            returnsNeedsBranch
                ? 'A patient returns location must belong to a branch. Choose a branch.'
                : undefined
        "
        @update:open="requestClose"
        @confirm="submit"
    >
        <V2OptionGroup
            :model-value="locationForm.branch_id"
            label="Branch"
            :options="locationBranchChoices"
            :error="locationForm.errors.branch_id"
            @update:model-value="(value) => (locationForm.branch_id = value)"
        />
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >Code <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="locationForm.code"
                autocomplete="off"
                maxlength="64"
                required
                :class="fieldClass"
            />
            <span
                v-if="locationForm.errors.code"
                class="text-sm text-destructive"
                >{{ locationForm.errors.code }}</span
            >
        </label>
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >Name <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="locationForm.name"
                autocomplete="off"
                maxlength="200"
                required
                :class="fieldClass"
            />
            <span
                v-if="locationForm.errors.name"
                class="text-sm text-destructive"
                >{{ locationForm.errors.name }}</span
            >
        </label>
        <V2OptionGroup
            :model-value="locationForm.type"
            label="Type"
            required
            :options="locationTypeOptions"
            :error="locationForm.errors.type"
            @update:model-value="(value) => (locationForm.type = value)"
        />
        <p class="text-sm text-muted-foreground">
            The type cannot be changed later.
        </p>
        <p
            v-if="locationForm.type === 'dispensary_returns'"
            class="text-sm text-muted-foreground"
        >
            Medicines patients bring back go here. Stock here is never
            dispensed. It leaves only by Stock count, Write-off, or a move to
            another returns location.
        </p>
    </V2Dialog>

    <!-- Add a batch. -->
    <V2Dialog
        :open="sheet === 'batch'"
        :dirty="batchForm.isDirty"
        title="Add a batch"
        confirm-label="Add batch"
        cancel-label="Cancel"
        :busy="batchForm.processing"
        @update:open="requestClose"
        @confirm="submit"
    >
        <InventoryPickerField
            v-model="batchForm.inventory_sku_public_id"
            kind="skus"
            context="references"
            label="SKU"
            required
            :expected-branch-id="referenceData.expectedBranchId"
            :error="batchForm.errors.inventory_sku_public_id"
        />
        <label class="grid gap-1.5 text-sm font-medium"
            ><span
                >Batch number
                <span class="text-destructive" aria-hidden="true">*</span
                ><span class="sr-only">(required)</span></span
            >
            <input
                v-model="batchForm.batch_number"
                autocomplete="off"
                maxlength="100"
                required
                :class="fieldClass"
            />
            <span
                v-if="batchForm.errors.batch_number"
                class="text-sm text-destructive"
                >{{ batchForm.errors.batch_number }}</span
            >
        </label>
        <V2DateField
            v-model="batchForm.expiry_date"
            label="Expiry date"
            subject="Expiry"
            required
            :error="batchForm.errors.expiry_date"
        />
        <p
            v-if="expiryWarning"
            class="text-sm font-medium text-warning-strong"
            role="status"
        >
            {{ expiryWarning }}
        </p>
    </V2Dialog>

    <!-- Link a medicine to a SKU. -->
    <V2Dialog
        :open="sheet === 'mapping'"
        :dirty="mappingForm.isDirty"
        title="Link a medicine to a SKU"
        description="Dispensary then takes this medicine from this SKU's stock."
        confirm-label="Link medicine"
        cancel-label="Cancel"
        :busy="mappingForm.processing"
        @update:open="requestClose"
        @confirm="submit"
    >
        <InventoryPickerField
            v-model="mappingForm.medicine_public_id"
            kind="medicines"
            context="references"
            label="Medicine"
            required
            :expected-branch-id="referenceData.expectedBranchId"
            :error="mappingForm.errors.medicine_public_id"
        />
        <InventoryPickerField
            v-model="mappingForm.inventory_sku_public_id"
            kind="skus"
            context="references"
            label="SKU"
            required
            :expected-branch-id="referenceData.expectedBranchId"
            :error="mappingForm.errors.inventory_sku_public_id"
        />
    </V2Dialog>
</template>

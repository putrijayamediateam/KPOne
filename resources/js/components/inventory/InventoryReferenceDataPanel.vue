<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';
import { OperationalTabs } from '@/components/ui/tabs';
import { requestJson } from '@/lib/json-client';

type ReferenceData = {
    canManage: boolean;
    items: Array<{ publicId: string; code: string; genericName: string }>;
    skus: Array<{ publicId: string; skuCode: string; inventoryItemId: number }>;
    locations: Array<{ publicId: string; name: string; type: string }>;
    medicines: Array<{ publicId: string; code: string; displayName: string }>;
    branches: Array<{ id: number; name: string }>;
};

const props = defineProps<{ referenceData: ReferenceData }>();

type SectionValue =
    'opening_balance' | 'item' | 'sku' | 'location' | 'batch' | 'mapping';
const sections: Array<{ value: SectionValue; label: string }> = [
    { value: 'opening_balance', label: 'Opening Balance' },
    { value: 'item', label: 'New Item' },
    { value: 'sku', label: 'New SKU' },
    { value: 'location', label: 'New Location' },
    { value: 'batch', label: 'New Batch' },
    { value: 'mapping', label: 'Link Medicine to SKU' },
];
const activeSection = ref<SectionValue>('opening_balance');

const locationOptions = computed(() => [
    { value: '', label: 'Select a location' },
    ...props.referenceData.locations.map((l) => ({
        value: l.publicId,
        label: `${l.name} (${l.type})`,
    })),
]);
const skuOptions = computed(() => [
    { value: '', label: 'Select a SKU' },
    ...props.referenceData.skus.map((s) => ({
        value: s.publicId,
        label: s.skuCode,
    })),
]);
type SkuBatch = { publicId: string; batchNumber: string; expiryDate: string };
const skuBatches = ref<SkuBatch[]>([]);
const batchesLoading = ref(false);
const batchesError = ref('');
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
            `/inventory-references/skus/${skuPublicId}/batches`,
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
const openingBalanceBatchOptions = computed(() => [
    {
        value: '',
        label: !openingBalanceForm.sku_public_id
            ? 'Select a SKU first'
            : batchesLoading.value
              ? 'Loading batches...'
              : 'Select a Batch',
    },
    ...skuBatches.value.map((b) => ({
        value: b.publicId,
        label: `${b.batchNumber} (expires ${b.expiryDate})`,
    })),
]);
const itemOptions = computed(() => [
    { value: '', label: 'Select an Item' },
    ...props.referenceData.items.map((i) => ({
        value: i.publicId,
        label: `${i.code} · ${i.genericName}`,
    })),
]);
const medicineOptions = computed(() => [
    { value: '', label: 'Select a Medicine' },
    ...props.referenceData.medicines.map((m) => ({
        value: m.publicId,
        label: `${m.code} · ${m.displayName}`,
    })),
]);
const branchOptions = computed(() => [
    { value: '', label: 'No specific branch (shared location)' },
    ...props.referenceData.branches.map((b) => ({
        value: String(b.id),
        label: b.name,
    })),
]);

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
        void loadSkuBatches(skuPublicId);
    },
);
watch(activeSection, (section) => {
    if (section === 'opening_balance' && openingBalanceForm.sku_public_id) {
        void loadSkuBatches(openingBalanceForm.sku_public_id);
    }
});
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
</script>

<template>
    <section
        v-if="referenceData.canManage"
        class="rounded-xl border bg-card p-4"
    >
        <h2 class="text-sm font-semibold">Stock Setup</h2>
        <p class="mt-1 text-xs text-muted-foreground">
            Create the reference data a branch needs before Dispensary can hand
            out a Medicine.
        </p>
        <OperationalTabs
            class="mt-3"
            :model-value="activeSection"
            label="Stock setup section"
            :tabs="sections"
            @update:model-value="
                (value) => (activeSection = value as SectionValue)
            "
        />

        <form
            v-if="activeSection === 'opening_balance'"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="submitOpeningBalance"
        >
            <OperationalSelect
                v-model="openingBalanceForm.expected_branch_id"
                label="Branch"
                :options="branchOptions.filter((o) => o.value !== '')"
            />
            <OperationalSelect
                v-model="openingBalanceForm.location_public_id"
                label="Location"
                :options="locationOptions"
            />
            <OperationalSelect
                v-model="openingBalanceForm.sku_public_id"
                label="SKU"
                :options="skuOptions"
            />
            <div>
                <OperationalSelect
                    v-model="openingBalanceForm.batch_public_id"
                    label="Batch"
                    :disabled="!openingBalanceForm.sku_public_id"
                    :options="openingBalanceBatchOptions"
                />
                <InputError
                    :message="
                        batchesError ||
                        openingBalanceForm.errors.batch_public_id
                    "
                />
            </div>
            <label class="grid gap-1 text-xs">
                Quantity
                <Input
                    v-model="openingBalanceForm.quantity"
                    inputmode="decimal"
                />
                <InputError :message="openingBalanceForm.errors.quantity" />
            </label>
            <div class="md:col-span-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="openingBalanceForm.processing"
                    >Record Opening Balance</Button
                >
                <InputError :message="openingBalanceError" />
            </div>
        </form>

        <form
            v-else-if="activeSection === 'item'"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="submitItem"
        >
            <label class="grid gap-1 text-xs">
                Code
                <Input
                    v-model="itemForm.code"
                    autocomplete="off"
                    maxlength="64"
                />
                <InputError :message="itemForm.errors.code" />
            </label>
            <label class="grid gap-1 text-xs">
                Generic name
                <Input
                    v-model="itemForm.generic_name"
                    autocomplete="off"
                    maxlength="300"
                />
                <InputError :message="itemForm.errors.generic_name" />
            </label>
            <label class="grid gap-1 text-xs">
                Brand name (optional)
                <Input
                    v-model="itemForm.brand_name"
                    autocomplete="off"
                    maxlength="300"
                />
                <InputError :message="itemForm.errors.brand_name" />
            </label>
            <div class="md:col-span-2">
                <Button type="submit" size="sm" :disabled="itemForm.processing"
                    >Create Inventory Item</Button
                >
            </div>
        </form>

        <form
            v-else-if="activeSection === 'sku'"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="submitSku"
        >
            <OperationalSelect
                v-model="skuForm.inventory_item_public_id"
                label="Inventory Item"
                :options="itemOptions"
            />
            <label class="grid gap-1 text-xs">
                SKU code
                <Input
                    v-model="skuForm.sku_code"
                    autocomplete="off"
                    maxlength="64"
                />
                <InputError :message="skuForm.errors.sku_code" />
            </label>
            <label class="grid gap-1 text-xs">
                Pack size
                <Input v-model="skuForm.pack_size" inputmode="decimal" />
                <InputError :message="skuForm.errors.pack_size" />
            </label>
            <label class="grid gap-1 text-xs">
                Unit conversion
                <Input v-model="skuForm.unit_conversion" inputmode="decimal" />
                <InputError :message="skuForm.errors.unit_conversion" />
            </label>
            <label class="grid gap-1 text-xs">
                Purchase unit
                <Input
                    v-model="skuForm.purchase_unit"
                    autocomplete="off"
                    maxlength="100"
                />
                <InputError :message="skuForm.errors.purchase_unit" />
            </label>
            <label class="grid gap-1 text-xs">
                Stock unit
                <Input
                    v-model="skuForm.stock_unit"
                    autocomplete="off"
                    maxlength="100"
                />
                <InputError :message="skuForm.errors.stock_unit" />
            </label>
            <label class="grid gap-1 text-xs">
                Dispensing unit
                <Input
                    v-model="skuForm.dispensing_unit"
                    autocomplete="off"
                    maxlength="100"
                />
                <InputError :message="skuForm.errors.dispensing_unit" />
            </label>
            <div class="md:col-span-2">
                <Button type="submit" size="sm" :disabled="skuForm.processing"
                    >Create Inventory SKU</Button
                >
            </div>
        </form>

        <form
            v-else-if="activeSection === 'location'"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="submitLocation"
        >
            <OperationalSelect
                v-model="locationForm.branch_id"
                label="Branch"
                :options="branchOptions"
            />
            <OperationalSelect
                v-model="locationForm.type"
                label="Type"
                :options="locationTypeOptions"
            />
            <label class="grid gap-1 text-xs">
                Code
                <Input
                    v-model="locationForm.code"
                    autocomplete="off"
                    maxlength="64"
                />
                <InputError :message="locationForm.errors.code" />
            </label>
            <label class="grid gap-1 text-xs">
                Name
                <Input
                    v-model="locationForm.name"
                    autocomplete="off"
                    maxlength="200"
                />
                <InputError :message="locationForm.errors.name" />
            </label>
            <div class="md:col-span-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="locationForm.processing"
                    >Create Inventory Location</Button
                >
            </div>
        </form>

        <form
            v-else-if="activeSection === 'batch'"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="submitBatch"
        >
            <OperationalSelect
                v-model="batchForm.inventory_sku_public_id"
                label="SKU"
                :options="skuOptions"
            />
            <label class="grid gap-1 text-xs">
                Batch number
                <Input
                    v-model="batchForm.batch_number"
                    autocomplete="off"
                    maxlength="100"
                />
                <InputError :message="batchForm.errors.batch_number" />
            </label>
            <label class="grid gap-1 text-xs">
                Expiry date
                <Input v-model="batchForm.expiry_date" type="date" />
                <InputError :message="batchForm.errors.expiry_date" />
            </label>
            <div class="md:col-span-2">
                <Button type="submit" size="sm" :disabled="batchForm.processing"
                    >Create Inventory Batch</Button
                >
            </div>
        </form>

        <form
            v-else-if="activeSection === 'mapping'"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="submitMapping"
        >
            <OperationalSelect
                v-model="mappingForm.medicine_public_id"
                label="Medicine"
                :options="medicineOptions"
            />
            <OperationalSelect
                v-model="mappingForm.inventory_sku_public_id"
                label="Inventory SKU"
                :options="skuOptions"
            />
            <div class="md:col-span-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="mappingForm.processing"
                    >Link Medicine to SKU</Button
                >
            </div>
        </form>
    </section>
</template>

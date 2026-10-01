<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import CatalogueFormSectionHeading from '@/components/catalogue/CatalogueFormSectionHeading.vue';
import CatalogueOptionPicker from '@/components/catalogue/CatalogueOptionPicker.vue';
import InventorySkuPicker from '@/components/catalogue/InventorySkuPicker.vue';
import LocationPicker from '@/components/catalogue/LocationPicker.vue';
import PanelTariffEditor from '@/components/catalogue/PanelTariffEditor.vue';
import SupplierPicker from '@/components/catalogue/SupplierPicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type MedicineRow = {
    publicId: string;
    code: string;
    displayName: string;
    strengthText: string | null;
    dosageForm: string | null;
    orderUnit: string;
    isActive: boolean;
    genericName?: string | null;
    category?: string | null;
    groupName?: string | null;
    defaultDosageAmount?: string | null;
    defaultDosageUnit?: string | null;
    defaultInstruction?: string | null;
    defaultPrecaution?: string | null;
    defaultFrequency?: string | null;
    defaultDuration?: string | null;
    defaultIndication?: string | null;
};

type SetupOptions = {
    canManageInventory: boolean;
    canManageSupplier: boolean;
    canPublishPrices: boolean;
    canManagePrices: boolean;
    canReceiveStock: boolean;
    activeBranchId: number | null;
    branches: { id: number; name: string }[];
    locations: {
        publicId: string;
        name: string;
        branchId: number | null;
        type: string;
    }[];
    suppliers: { publicId: string; code: string; name: string }[];
    panels: { id: number; name: string }[];
};

const props = defineProps<{
    medicines: {
        data: MedicineRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    filters: { search: string; status: string };
    setup: SetupOptions;
}>();

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const visit = (overrides: Record<string, string | number> = {}) => {
    router.get(
        '/medicines',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const dialogOpen = ref(false);
const editing = ref<MedicineRow | null>(null);
const editingSkuLabel = ref('');
const editLoading = ref(false);
const editLoadError = ref('');
const form = useForm({
    code: '',
    display_name: '',
    strength_text: '',
    dosage_form: '',
    order_unit: '',
    generic_name: '',
    category: '',
    group_name: '',
    default_dosage_amount: '',
    default_dosage_unit: '',
    default_instruction: '',
    default_precaution: '',
    default_frequency: '',
    default_duration: '',
    default_indication: '',
    route: '',
    manufacturer: '',
    mal_number: '',
    inventory_sku_public_id: '',
    expected_branch_id: props.setup.activeBranchId
        ? String(props.setup.activeBranchId)
        : '',
    sku: {
        sku_code: '',
        barcode: '',
        pack_size: '1',
        purchase_unit: '',
        stock_unit: '',
        dispensing_unit: '',
        unit_conversion: '1',
        storage_type: 'ambient',
        batch_tracking_required: true,
        expiry_tracking_required: true,
    },
    prices: {
        self_pay_rm: '',
        panel_default_rm: '',
        panel_overrides: [] as { panel_id: string; amount_rm: string }[],
    },
    batch: { batch_number: '', expiry_date: '' },
    supplier_public_id: '',
    opening_stock: [] as {
        branch_id: string;
        location_public_id: string;
        quantity: string;
        unit_cost_rm: string;
    }[],
});

const openCreate = () => {
    editing.value = null;
    editingSkuLabel.value = '';
    editLoadError.value = '';
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.setup.activeBranchId
        ? String(props.setup.activeBranchId)
        : '';
    dialogOpen.value = true;
};
const openEdit = async (row: MedicineRow) => {
    editLoading.value = true;
    editLoadError.value = '';
    dialogOpen.value = true;
    editing.value = row;
    form.reset();
    form.clearErrors();

    try {
        const details = await requestJson<{
            medicine: Record<string, string>;
            sku: null | {
                public_id: string;
                sku_code: string;
                barcode: string;
                pack_size: string;
                purchase_unit: string;
                stock_unit: string;
                dispensing_unit: string;
                unit_conversion: string;
                storage_type: string;
                minimum_temperature: string | null;
                maximum_temperature: string | null;
                cold_chain_required: boolean;
                do_not_freeze: boolean;
                protect_from_light: boolean;
                batch_tracking_required: boolean;
                expiry_tracking_required: boolean;
                route: string;
                manufacturer: string;
                mal_number: string;
                generic_name: string;
            };
            prices: {
                self_pay_rm: string | null;
                panel_default_rm: string | null;
                panel_overrides: {
                    panel_id: string;
                    panel_name: string;
                    amount_rm: string;
                    version: number;
                }[];
            };
        }>(`/medicines/${row.publicId}/setup`);
        Object.assign(form, details.medicine);
        form.prices.self_pay_rm = details.prices.self_pay_rm ?? '';
        form.prices.panel_default_rm = details.prices.panel_default_rm ?? '';
        form.prices.panel_overrides = details.prices.panel_overrides.map(
            ({ panel_id, amount_rm }) => ({ panel_id, amount_rm }),
        );

        if (details.sku) {
            Object.assign(form.sku, details.sku);
            form.inventory_sku_public_id = details.sku.public_id;
            form.route = details.sku.route;
            form.manufacturer = details.sku.manufacturer;
            form.mal_number = details.sku.mal_number;
            editingSkuLabel.value = `${details.sku.sku_code} · ${details.sku.generic_name}`;
        } else {
            editingSkuLabel.value = '';
        }

        form.expected_branch_id = props.setup.activeBranchId
            ? String(props.setup.activeBranchId)
            : '';
    } catch (error) {
        editLoadError.value =
            error instanceof JsonRequestError
                ? 'Medicine setup details could not be loaded. Refresh the page and try again.'
                : 'Medicine setup details could not be loaded. Check your connection and try again.';
    } finally {
        editLoading.value = false;
    }
};
const toSen = (amount: string | number) => {
    const normalized =
        typeof amount === 'number' ? String(amount) : amount.trim();

    return normalized === '' ? null : Math.round(Number(normalized) * 100);
};
const formError = (key: string) => form.errors[key as keyof typeof form.errors];
const addStockLocation = () => {
    const branchId = props.setup.activeBranchId;

    if (!branchId) {
        return;
    }

    const location = props.setup.locations.find(
        (option) => option.branchId === branchId,
    );
    form.opening_stock.push({
        branch_id: String(branchId),
        location_public_id: location?.publicId ?? '',
        quantity: '',
        unit_cost_rm: '',
    });
};
const submit = () => {
    form.transform((data) => ({
        ...data,
        expected_branch_id: data.expected_branch_id || null,
        ...(props.setup.canManageInventory ? { sku: data.sku } : {}),
        ...(props.setup.canPublishPrices
            ? {
                  prices: {
                      self_pay_sen: toSen(data.prices.self_pay_rm),
                      panel_default_sen: toSen(data.prices.panel_default_rm),
                      panel_overrides: data.prices.panel_overrides.map(
                          (override) => ({
                              panel_id: override.panel_id,
                              amount_sen: toSen(override.amount_rm),
                          }),
                      ),
                  },
              }
            : {}),
        ...(props.setup.canReceiveStock
            ? {
                  opening_stock: data.opening_stock.map((row) => ({
                      branch_id: row.branch_id,
                      location_public_id: row.location_public_id,
                      quantity: row.quantity,
                      unit_cost_sen: toSen(row.unit_cost_rm),
                  })),
              }
            : {}),
    }));

    if (editing.value) {
        form.patch(`/medicines/${editing.value.publicId}`, {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.transform((data) => data);
            },
        });
    } else {
        form.post('/medicines', {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.transform((data) => data);
            },
        });
    }
};

const pendingToggle = reactive<{ row: MedicineRow | null }>({ row: null });
const toggleBusy = ref(false);
const requestToggle = (row: MedicineRow) => {
    pendingToggle.row = row;
};
const confirmToggle = () => {
    const row = pendingToggle.row;

    if (!row) {
        return;
    }

    toggleBusy.value = true;
    router.post(
        `/medicines/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggleBusy.value = false;
                pendingToggle.row = null;
            },
        },
    );
};
const toggleDescription = computed(() => {
    const row = pendingToggle.row;

    if (!row) {
        return '';
    }

    return row.isActive
        ? `${row.displayName} will no longer be available to order on a Treatment Plan.`
        : `${row.displayName} will become available to order on a Treatment Plan again.`;
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Medicine Catalogue"
            description="Create and manage the organisation Medicine Catalogue."
        >
            <template #actions>
                <Button size="sm" @click="openCreate">
                    <Plus class="size-4" /> Add Medicine
                </Button>
            </template>
        </PageHeader>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="visit()">
            <label class="grid min-w-56 gap-1 text-xs">
                Search
                <Input
                    v-model="search"
                    placeholder="Code or name"
                    autocomplete="off"
                />
            </label>
            <OperationalSelect
                v-model="status"
                label="Status"
                :options="statusOptions"
            />
            <Button size="sm" type="submit">Apply</Button>
        </form>

        <OperationalTable
            label="Medicine Catalogue"
            :columns="6"
            :empty="medicines.data.length === 0"
            empty-message="No medicines match this filter."
        >
            <template #head>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Strength</th>
                    <th>Dosage form</th>
                    <th>Order unit</th>
                    <th class="text-right">Status</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="row in medicines.data" :key="row.publicId">
                    <td class="font-medium tabular-nums">{{ row.code }}</td>
                    <td>{{ row.displayName }}</td>
                    <td>{{ row.strengthText ?? '—' }}</td>
                    <td>{{ row.dosageForm ?? '—' }}</td>
                    <td>{{ row.orderUnit }}</td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-2">
                            <StatusBadge
                                :status="row.isActive ? 'active' : 'inactive'"
                                :tone="row.isActive ? 'success' : 'neutral'"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                @click="openEdit(row)"
                                >Edit</Button
                            >
                            <Button
                                size="sm"
                                :variant="row.isActive ? 'ghost' : 'secondary'"
                                @click="requestToggle(row)"
                            >
                                {{ row.isActive ? 'Deactivate' : 'Activate' }}
                            </Button>
                        </div>
                    </td>
                </tr>
            </template>
        </OperationalTable>

        <CompactPagination
            :current-page="medicines.currentPage"
            :last-page="medicines.lastPage"
            :total="medicines.total"
            @change="(page) => visit({ page })"
        />

        <Dialog v-model:open="dialogOpen">
            <DialogContent
                class="max-h-[calc(100dvh-1rem)] overflow-y-auto p-4 sm:max-w-6xl sm:p-6"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Edit Medicine' : 'Add Medicine'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Update this Medicine Catalogue entry.'
                                : 'Complete the sections below on this page. Inventory, prices and opening stock are linked to this medicine when you save.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form
                    v-if="!editLoading"
                    class="grid gap-4 rounded-xl bg-muted/30 p-2 sm:p-4"
                    @submit.prevent="submit"
                >
                    <p
                        v-if="editLoadError"
                        class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                        role="alert"
                    >
                        {{ editLoadError }}
                    </p>
                    <section
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="01"
                            title="Inventory details"
                            description="Identify the medicine and describe how it is classified in the catalogue."
                        />
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="grid gap-1 text-xs">
                                Catalogue code
                                <Input
                                    v-model="form.code"
                                    autocomplete="off"
                                    maxlength="64"
                                />
                                <InputError :message="form.errors.code" />
                            </label>
                            <label class="grid gap-1 text-xs">
                                Display name
                                <Input
                                    v-model="form.display_name"
                                    autocomplete="off"
                                    maxlength="500"
                                />
                                <InputError
                                    :message="form.errors.display_name"
                                />
                            </label>
                            <label class="grid gap-1 text-xs">
                                Generic name
                                <Input
                                    v-model="form.generic_name"
                                    autocomplete="off"
                                    maxlength="200"
                                />
                                <InputError
                                    :message="form.errors.generic_name"
                                />
                            </label>
                            <CatalogueOptionPicker
                                v-model="form.category"
                                type="medicine_category"
                                label="Category"
                                :error="form.errors.category"
                            />
                            <CatalogueOptionPicker
                                v-model="form.group_name"
                                type="medicine_group"
                                label="Group"
                                :error="form.errors.group_name"
                            />
                            <label class="grid gap-1 text-xs">
                                Strength
                                <Input
                                    v-model="form.strength_text"
                                    autocomplete="off"
                                    maxlength="100"
                                />
                                <InputError
                                    :message="form.errors.strength_text"
                                />
                            </label>
                            <CatalogueOptionPicker
                                v-model="form.dosage_form"
                                type="dosage_form"
                                label="Dosage form"
                                :error="form.errors.dosage_form"
                            />
                            <CatalogueOptionPicker
                                v-model="form.order_unit"
                                type="order_unit"
                                label="Order / dispensing unit"
                                :error="form.errors.order_unit"
                            />
                            <CatalogueOptionPicker
                                v-model="form.route"
                                type="route"
                                label="Route"
                                :error="form.errors.route"
                            />
                            <CatalogueOptionPicker
                                v-model="form.manufacturer"
                                type="manufacturer"
                                label="Manufacturer"
                                :error="form.errors.manufacturer"
                            />
                            <label class="grid gap-1 text-xs">
                                MAL number
                                <Input
                                    v-model="form.mal_number"
                                    autocomplete="off"
                                    maxlength="100"
                                />
                                <InputError :message="form.errors.mal_number" />
                            </label>
                        </div>
                    </section>

                    <section
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="02"
                            title="Dosage and instructions"
                            description="Optional catalogue defaults for staff reference. Review and adjust for each individual order; these values are not patient-specific instructions."
                        />
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="grid gap-1 text-xs"
                                >Default dose amount<Input
                                    v-model="form.default_dosage_amount"
                                    type="number"
                                    min="0"
                                    step="0.001" /><InputError
                                    :message="
                                        form.errors.default_dosage_amount
                                    "
                            /></label>
                            <CatalogueOptionPicker
                                v-model="form.default_dosage_unit"
                                type="dosage_unit"
                                label="Dose unit"
                                :error="form.errors.default_dosage_unit"
                            />
                            <CatalogueOptionPicker
                                v-model="form.default_instruction"
                                type="instruction"
                                label="Instruction"
                                :error="form.errors.default_instruction"
                            />
                            <CatalogueOptionPicker
                                v-model="form.default_precaution"
                                type="precaution"
                                label="Precaution"
                                :error="form.errors.default_precaution"
                            />
                            <CatalogueOptionPicker
                                v-model="form.default_frequency"
                                type="frequency"
                                label="Frequency"
                                :error="form.errors.default_frequency"
                            />
                            <CatalogueOptionPicker
                                v-model="form.default_duration"
                                type="duration"
                                label="Duration"
                                :error="form.errors.default_duration"
                            />
                            <CatalogueOptionPicker
                                v-model="form.default_indication"
                                type="indication"
                                label="Indication"
                                :error="form.errors.default_indication"
                            />
                        </div>
                    </section>

                    <section
                        v-if="setup.canManageInventory"
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="03"
                            title="Unit of measurement and stock link"
                            description="Reuse an existing SKU to avoid duplicate stock records, or create the linked Inventory Item and SKU here. Enter the purchase, stock and dispensing units used by your team."
                        />
                        <p
                            v-if="editing && editingSkuLabel"
                            class="rounded-md bg-muted/40 p-3 text-sm text-muted-foreground"
                        >
                            Currently linked inventory SKU:
                            <span class="font-medium text-foreground">{{
                                editingSkuLabel
                            }}</span
                            >. Existing stock history stays unchanged.
                        </p>
                        <InventorySkuPicker
                            v-if="!editing || !editingSkuLabel"
                            v-model="form.inventory_sku_public_id"
                            :error="form.errors.inventory_sku_public_id"
                        />
                        <div
                            v-if="editing || !form.inventory_sku_public_id"
                            class="grid gap-3 md:grid-cols-2"
                        >
                            <label class="grid gap-1 text-xs"
                                >SKU code<Input
                                    v-model="form.sku.sku_code"
                                    placeholder="Defaults to catalogue code"
                                    maxlength="64" /><InputError
                                    :message="form.errors['sku.sku_code']"
                            /></label>
                            <label class="grid gap-1 text-xs"
                                >Barcode<Input
                                    v-model="form.sku.barcode"
                                    maxlength="100" /><InputError
                                    :message="form.errors['sku.barcode']"
                            /></label>
                            <label class="grid gap-1 text-xs"
                                >Pack size<Input
                                    v-model="form.sku.pack_size"
                                    type="number"
                                    min="0.001"
                                    step="0.001" /><InputError
                                    :message="form.errors['sku.pack_size']"
                            /></label>
                            <CatalogueOptionPicker
                                v-model="form.sku.purchase_unit"
                                type="order_unit"
                                label="Purchase unit"
                                :error="form.errors['sku.purchase_unit']"
                            />
                            <CatalogueOptionPicker
                                v-model="form.sku.stock_unit"
                                type="order_unit"
                                label="Stock unit"
                                :error="form.errors['sku.stock_unit']"
                            />
                            <CatalogueOptionPicker
                                v-model="form.sku.dispensing_unit"
                                type="order_unit"
                                label="Dispensing unit"
                                :error="form.errors['sku.dispensing_unit']"
                            />
                            <label class="grid gap-1 text-xs"
                                >Unit conversion<Input
                                    v-model="form.sku.unit_conversion"
                                    type="number"
                                    min="0.001"
                                    step="0.001" /><InputError
                                    :message="
                                        form.errors['sku.unit_conversion']
                                    "
                            /></label>
                        </div>
                    </section>

                    <section
                        v-if="setup.canManagePrices"
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="04"
                            title="Pricing"
                            description="Set default self-pay and Panel tariffs. Published prices are retained as versioned records and are separate from purchase cost."
                        />
                        <template v-if="setup.canPublishPrices">
                            <div class="grid gap-2">
                                <div>
                                    <h4 class="text-sm font-medium">
                                        Default pricing
                                    </h4>
                                    <p class="text-xs text-muted-foreground">
                                        These rates apply unless a
                                        Panel-specific override is added below.
                                    </p>
                                </div>
                                <div class="overflow-hidden rounded-lg border">
                                    <div
                                        class="hidden grid-cols-[minmax(8rem,0.8fr)_minmax(12rem,1.5fr)_minmax(10rem,1fr)] gap-3 bg-muted/60 px-3 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:grid"
                                    >
                                        <span>Price tier</span>
                                        <span>Coverage</span>
                                        <span>Default price (RM)</span>
                                    </div>
                                    <div
                                        class="grid gap-2 border-t p-3 sm:grid-cols-[minmax(8rem,0.8fr)_minmax(12rem,1.5fr)_minmax(10rem,1fr)] sm:items-center"
                                    >
                                        <span class="font-medium"
                                            >Self-pay</span
                                        >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Patients paying directly</span
                                        >
                                        <div class="grid gap-1">
                                            <Input
                                                v-model="
                                                    form.prices.self_pay_rm
                                                "
                                                aria-label="Self-pay default price in RM"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                            />
                                            <InputError
                                                :message="
                                                    formError(
                                                        'prices.self_pay_sen',
                                                    )
                                                "
                                            />
                                        </div>
                                    </div>
                                    <div
                                        class="grid gap-2 border-t p-3 sm:grid-cols-[minmax(8rem,0.8fr)_minmax(12rem,1.5fr)_minmax(10rem,1fr)] sm:items-center"
                                    >
                                        <span class="font-medium">Panel</span>
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Default for Panels without a custom
                                            rate</span
                                        >
                                        <div class="grid gap-1">
                                            <Input
                                                v-model="
                                                    form.prices.panel_default_rm
                                                "
                                                aria-label="Default Panel price in RM"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                            />
                                            <InputError
                                                :message="
                                                    formError(
                                                        'prices.panel_default_sen',
                                                    )
                                                "
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <div>
                                    <h4 class="text-sm font-medium">
                                        Panel-specific price overrides
                                    </h4>
                                    <p class="text-xs text-muted-foreground">
                                        Add a Panel only when its tariff differs
                                        from the default Panel price.
                                    </p>
                                </div>
                                <PanelTariffEditor
                                    v-model="form.prices.panel_overrides"
                                    :panels="setup.panels"
                                />
                                <p
                                    v-if="editing"
                                    class="text-xs text-muted-foreground"
                                >
                                    Published Panel rates are versioned.
                                    Removing a row here does not erase its
                                    existing price history or disable that Panel
                                    rate.
                                </p>
                            </div>
                            <label class="grid gap-1 text-xs">
                                Branch used for price publication
                                <OperationalSelect
                                    v-model="form.expected_branch_id"
                                    label="Price publication branch"
                                    :options="
                                        setup.branches.map((branch) => ({
                                            value: String(branch.id),
                                            label: branch.name,
                                        }))
                                    "
                                />
                                <InputError
                                    :message="form.errors.expected_branch_id"
                                />
                            </label>
                        </template>
                        <p v-else class="text-xs text-muted-foreground">
                            Your account can manage catalogue references but
                            cannot publish prices. An authorised pricing user
                            can publish these prices later.
                        </p>
                    </section>

                    <section
                        v-if="setup.canReceiveStock"
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="05"
                            title="Stock details"
                            :description="
                                editing
                                    ? 'Add a new opening-stock movement if needed. Existing stock movements and purchase costs are historical records and will not be changed.'
                                    : 'Optional. Enter an initial quantity, its branch and location, and the batch details. Purchase cost is tracked separately from the selling prices above.'
                            "
                        />
                        <div
                            v-for="(stock, index) in form.opening_stock"
                            :key="index"
                            class="grid gap-3 rounded-md bg-muted/40 p-3 md:grid-cols-2"
                        >
                            <label class="grid gap-1 text-xs"
                                >Branch<OperationalSelect
                                    v-model="stock.branch_id"
                                    label="Stock branch"
                                    :options="
                                        setup.branches.map((branch) => ({
                                            value: String(branch.id),
                                            label: branch.name,
                                        }))
                                    " /><InputError
                                    :message="
                                        form.errors[
                                            `opening_stock.${index}.branch_id`
                                        ]
                                    "
                            /></label>
                            <div class="grid gap-1 text-xs">
                                <span>Location</span>
                                <LocationPicker
                                    v-model="stock.location_public_id"
                                    :branch-id="stock.branch_id"
                                    :locations="setup.locations"
                                    :can-create="setup.canManageInventory"
                                />
                                <InputError
                                    :message="
                                        form.errors[
                                            `opening_stock.${index}.location_public_id`
                                        ]
                                    "
                                />
                            </div>
                            <label class="grid gap-1 text-xs"
                                >Quantity<Input
                                    v-model="stock.quantity"
                                    type="number"
                                    min="0"
                                    step="0.001" /><InputError
                                    :message="
                                        form.errors[
                                            `opening_stock.${index}.quantity`
                                        ]
                                    "
                            /></label>
                            <label class="grid gap-1 text-xs"
                                >Purchase unit cost (RM)<Input
                                    v-model="stock.unit_cost_rm"
                                    type="number"
                                    min="0"
                                    step="0.01" /><InputError
                                    :message="
                                        formError(
                                            `opening_stock.${index}.unit_cost_sen`,
                                        )
                                    "
                            /></label>
                        </div>
                        <div
                            v-if="form.opening_stock.length"
                            class="grid max-w-md gap-1 text-xs"
                        >
                            <span>Supplier</span>
                            <SupplierPicker
                                v-model="form.supplier_public_id"
                                :suppliers="setup.suppliers"
                                :can-create="setup.canManageSupplier"
                            />
                            <InputError
                                :message="form.errors.supplier_public_id"
                            />
                        </div>
                        <div
                            v-if="form.opening_stock.length"
                            class="grid gap-3 md:grid-cols-2"
                        >
                            <label class="grid gap-1 text-xs"
                                >Batch number<Input
                                    v-model="form.batch.batch_number"
                                    maxlength="100" /><InputError
                                    :message="
                                        form.errors['batch.batch_number']
                                    "
                            /></label>
                            <label class="grid gap-1 text-xs"
                                >Expiry date<Input
                                    v-model="form.batch.expiry_date"
                                    type="date" /><InputError
                                    :message="form.errors['batch.expiry_date']"
                            /></label>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addStockLocation"
                                >Add opening stock</Button
                            >
                        </div>
                        <InputError :message="form.errors.batch" />
                    </section>
                    <DialogFooter
                        class="sticky bottom-0 z-10 mt-1 border-t bg-background/95 py-3 backdrop-blur"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing || editLoading"
                            @click="dialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing || editLoading"
                        >
                            {{ editing ? 'Save' : 'Add Medicine' }}
                        </Button>
                    </DialogFooter>
                </form>
                <p
                    v-else
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    Loading the saved Medicine, inventory and tariff details…
                </p>
            </DialogContent>
        </Dialog>

        <OperationalConfirmDialog
            :open="pendingToggle.row !== null"
            :title="
                pendingToggle.row?.isActive
                    ? 'Deactivate Medicine'
                    : 'Activate Medicine'
            "
            :description="toggleDescription"
            :confirm-label="
                pendingToggle.row?.isActive ? 'Deactivate' : 'Activate'
            "
            :destructive="pendingToggle.row?.isActive ?? false"
            :processing="toggleBusy"
            @update:open="
                (open) => {
                    if (!open) pendingToggle.row = null;
                }
            "
            @confirm="confirmToggle"
        />
    </main>
</template>

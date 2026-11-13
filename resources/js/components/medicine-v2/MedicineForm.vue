<script setup lang="ts">
import { computed } from 'vue';
import CatalogueOptionPicker from '@/components/catalogue/CatalogueOptionPicker.vue';
import InventorySkuPicker from '@/components/catalogue/InventorySkuPicker.vue';
import LocationPicker from '@/components/catalogue/LocationPicker.vue';
import PanelTariffEditor from '@/components/catalogue/PanelTariffEditor.vue';
import SupplierPicker from '@/components/catalogue/SupplierPicker.vue';
import InputError from '@/components/InputError.vue';
import { DateField } from '@/components/ui/date-field';
import { OperationalSelect } from '@/components/ui/select';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import type {
    SetupOptions,
    useMedicineForm,
} from '@/composables/medicine/useMedicineForm';

/**
 * Version 2 Add and Edit medicine: one full page, one column of form cards. Same fields and the same payload as the
 * Classic dialog (the state and the submit come from `useMedicineForm`, a copy of Classic's script). Shared catalogue
 * pickers are reused as they are.
 */
const props = defineProps<{
    c: ReturnType<typeof useMedicineForm>;
    setup: SetupOptions;
}>();
defineEmits<{ cancel: [] }>();

const form = props.c.form;
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const field = (invalid?: boolean) =>
    invalid
        ? inputClass.replace('border-input', 'border-destructive')
        : inputClass;
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';

const err = (key: string) => props.c.formError(key);
const saveLabel = computed(() =>
    props.c.editing.value ? 'Save' : 'Add medicine',
);
const saveClass = computed(() =>
    props.c.editing.value && !props.c.isDirty.value
        ? outlineButton
        : filledButton,
);
const saveDisabled = computed(
    () =>
        form.processing ||
        props.c.editLoading.value ||
        !!props.c.editLoadError.value,
);
const branchOptions = computed(() =>
    props.setup.branches.map((branch) => ({
        value: String(branch.id),
        label: branch.name,
    })),
);
</script>

<template>
    <p
        v-if="c.editLoading.value"
        class="py-10 text-center text-sm text-muted-foreground"
        role="status"
    >
        Loading the saved medicine, inventory and price details…
    </p>
    <form
        v-else
        class="flex min-w-0 flex-col gap-4"
        data-test="medicine-form"
        @submit.prevent="c.submit()"
    >
        <p
            v-if="c.editLoadError.value"
            class="rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
            role="alert"
        >
            {{ c.editLoadError.value }}
        </p>

        <V2FormCard
            :step="1"
            title="Inventory details"
            description="Identify the medicine and how it is classified in the catalogue."
        >
            <div class="grid gap-4 md:grid-cols-2">
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Catalogue code"
                    required
                    :error="form.errors.code"
                >
                    <input
                        :id="id"
                        v-model="form.code"
                        autocomplete="off"
                        maxlength="64"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Display name"
                    required
                    :error="form.errors.display_name"
                >
                    <input
                        :id="id"
                        v-model="form.display_name"
                        autocomplete="off"
                        maxlength="500"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Generic name"
                    :error="form.errors.generic_name"
                >
                    <input
                        :id="id"
                        v-model="form.generic_name"
                        autocomplete="off"
                        maxlength="200"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
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
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Strength"
                    :error="form.errors.strength_text"
                >
                    <input
                        :id="id"
                        v-model="form.strength_text"
                        autocomplete="off"
                        maxlength="100"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
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
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="MAL number"
                    :error="form.errors.mal_number"
                >
                    <input
                        :id="id"
                        v-model="form.mal_number"
                        autocomplete="off"
                        maxlength="100"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
            </div>
        </V2FormCard>

        <V2DisclosureBar title="Dosage and instructions (optional)">
            <p class="mb-3 text-sm text-muted-foreground">
                Catalogue defaults for staff reference. Review and adjust for
                each individual order; these are not patient-specific
                instructions.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Default dose amount"
                    :error="form.errors.default_dosage_amount"
                >
                    <input
                        :id="id"
                        v-model="form.default_dosage_amount"
                        type="number"
                        min="0"
                        step="0.001"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
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
                    hint="Prints on drug labels: up to 72 characters."
                    :error="form.errors.default_indication"
                />
            </div>
        </V2DisclosureBar>

        <V2FormCard
            v-if="setup.canManageInventory"
            :step="2"
            title="Unit of measurement and stock link"
            description="Reuse an existing SKU to avoid duplicate stock records, or create the linked Inventory Item and SKU here."
        >
            <p
                v-if="c.editing.value && c.editingSkuLabel.value"
                class="rounded-[10px] bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
            >
                Currently linked inventory SKU:
                <span class="font-medium text-foreground">{{
                    c.editingSkuLabel.value
                }}</span
                >. Existing stock history stays unchanged.
            </p>
            <InventorySkuPicker
                v-if="!c.editing.value || !c.editingSkuLabel.value"
                v-model="form.inventory_sku_public_id"
                :error="form.errors.inventory_sku_public_id"
            />
            <div
                v-if="c.editing.value || !form.inventory_sku_public_id"
                class="grid gap-4 md:grid-cols-2"
            >
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="SKU code"
                    hint="Defaults to the catalogue code."
                    :error="err('sku.sku_code')"
                >
                    <input
                        :id="id"
                        v-model="form.sku.sku_code"
                        maxlength="64"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Barcode"
                    :error="err('sku.barcode')"
                >
                    <input
                        :id="id"
                        v-model="form.sku.barcode"
                        maxlength="100"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Pack size"
                    :error="err('sku.pack_size')"
                >
                    <input
                        :id="id"
                        v-model="form.sku.pack_size"
                        type="number"
                        min="0.001"
                        step="0.001"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <CatalogueOptionPicker
                    v-model="form.sku.purchase_unit"
                    type="order_unit"
                    label="Purchase unit"
                    :error="err('sku.purchase_unit')"
                />
                <CatalogueOptionPicker
                    v-model="form.sku.stock_unit"
                    type="order_unit"
                    label="Stock unit"
                    :error="err('sku.stock_unit')"
                />
                <CatalogueOptionPicker
                    v-model="form.sku.dispensing_unit"
                    type="order_unit"
                    label="Dispensing unit"
                    :error="err('sku.dispensing_unit')"
                />
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Unit conversion"
                    :error="err('sku.unit_conversion')"
                >
                    <input
                        :id="id"
                        v-model="form.sku.unit_conversion"
                        type="number"
                        min="0.001"
                        step="0.001"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
            </div>
        </V2FormCard>

        <V2FormCard
            v-if="setup.canManagePrices"
            :step="3"
            title="Pricing"
            description="Default self-pay and Panel prices. Published prices are kept as versioned records."
        >
            <template v-if="setup.canPublishPrices">
                <div class="grid gap-4 md:grid-cols-2">
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Self-pay default price (RM)"
                        hint="Patients paying directly."
                        :error="err('prices.self_pay_sen')"
                    >
                        <input
                            :id="id"
                            v-model="form.prices.self_pay_rm"
                            aria-label="Self-pay default price in RM"
                            type="number"
                            min="0"
                            step="0.01"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="field(invalid)"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Default Panel price (RM)"
                        hint="For Panels without a custom price."
                        :error="err('prices.panel_default_sen')"
                    >
                        <input
                            :id="id"
                            v-model="form.prices.panel_default_rm"
                            aria-label="Default Panel price in RM"
                            type="number"
                            min="0"
                            step="0.01"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="field(invalid)"
                        />
                    </V2Field>
                </div>
                <div class="grid gap-2">
                    <div>
                        <h3 class="text-sm font-medium">
                            Panel-specific prices
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Add a Panel only when its price differs from the
                            default Panel price.
                        </p>
                    </div>
                    <PanelTariffEditor
                        v-model="form.prices.panel_overrides"
                        :panels="setup.panels"
                    />
                    <p
                        v-if="c.editing.value"
                        class="text-sm text-muted-foreground"
                    >
                        Published Panel prices are versioned. Removing a row
                        here does not erase its price history or switch that
                        Panel price off.
                    </p>
                </div>
                <V2Field
                    v-slot="{ id }"
                    label="Branch used for price publication"
                    :error="form.errors.expected_branch_id"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="form.expected_branch_id"
                        label="Branch used for price publication"
                        :invalid="!!form.errors.expected_branch_id"
                        :options="branchOptions"
                    />
                </V2Field>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                Your account can manage catalogue references but cannot publish
                prices. An authorised pricing user can publish these prices
                later.
            </p>
        </V2FormCard>

        <V2FormCard
            v-if="setup.canReceiveStock"
            :step="4"
            title="Stock details"
            :description="
                c.editing.value
                    ? 'Add a new opening-stock movement if needed. Existing stock movements and purchase costs are historical records and are not changed.'
                    : 'Optional. Enter an initial quantity with its branch, location and batch details.'
            "
        >
            <div
                v-for="(stock, index) in form.opening_stock"
                :key="index"
                class="grid gap-4 rounded-[10px] bg-muted/40 p-3 md:grid-cols-2"
            >
                <V2Field
                    v-slot="{ id }"
                    label="Branch"
                    :error="err(`opening_stock.${index}.branch_id`)"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="stock.branch_id"
                        label="Stock branch"
                        :options="branchOptions"
                    />
                </V2Field>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <span class="text-sm font-medium">Location</span>
                    <LocationPicker
                        v-model="stock.location_public_id"
                        :branch-id="stock.branch_id"
                        :locations="setup.locations"
                        :can-create="setup.canManageInventory"
                    />
                    <InputError
                        :message="
                            err(`opening_stock.${index}.location_public_id`)
                        "
                    />
                </div>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Quantity"
                    :error="err(`opening_stock.${index}.quantity`)"
                >
                    <input
                        :id="id"
                        v-model="stock.quantity"
                        type="number"
                        min="0"
                        step="0.001"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Purchase unit cost (RM)"
                    :error="err(`opening_stock.${index}.unit_cost_sen`)"
                >
                    <input
                        :id="id"
                        v-model="stock.unit_cost_rm"
                        type="number"
                        min="0"
                        step="0.01"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <button
                    type="button"
                    :class="[outlineButton, 'w-fit md:col-span-2']"
                    @click="c.removeStockLocation(index)"
                >
                    Remove opening stock row
                </button>
            </div>
            <div
                v-if="form.opening_stock.length"
                class="grid gap-4 md:grid-cols-2"
            >
                <div class="flex min-w-0 flex-col gap-1.5 md:col-span-2">
                    <span class="text-sm font-medium">Supplier</span>
                    <SupplierPicker
                        v-model="form.supplier_public_id"
                        :suppliers="setup.suppliers"
                        :can-create="setup.canManageSupplier"
                    />
                    <InputError :message="form.errors.supplier_public_id" />
                </div>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Batch number"
                    :error="err('batch.batch_number')"
                >
                    <input
                        :id="id"
                        v-model="form.batch.batch_number"
                        maxlength="100"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <span class="text-sm font-medium">Expiry date</span>
                    <DateField v-model="form.batch.expiry_date" />
                    <InputError :message="err('batch.expiry_date')" />
                </div>
            </div>
            <button
                type="button"
                :class="[outlineButton, 'w-fit']"
                @click="c.addStockLocation()"
            >
                {{
                    form.opening_stock.length
                        ? 'Add another stock location'
                        : 'Add opening stock'
                }}
            </button>
            <InputError :message="form.errors.batch" />
        </V2FormCard>

        <!-- One action bar at every width for this long form, so the filled button is always in reach. -->
        <div
            class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:-mx-8 lg:px-8"
            data-test="medicine-form-actions"
        >
            <button
                type="button"
                :class="[outlineButton, 'max-sm:hidden']"
                :disabled="form.processing || c.editLoading.value"
                @click="$emit('cancel')"
            >
                Cancel
            </button>
            <button
                type="submit"
                :class="[saveClass, 'max-sm:w-full']"
                :disabled="saveDisabled"
            >
                {{ saveLabel }}
            </button>
        </div>
    </form>
</template>

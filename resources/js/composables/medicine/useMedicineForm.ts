import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';
import { rmToSen } from '@/lib/money';

/**
 * Version 2 Medicine Catalogue: the Add and Edit form's state and rules. It is a copy of the Classic page's script
 * (`pages/Medicine/Index.vue`, which stays as it is): the same fields, the same `GET /medicines/<built-in function id>/setup`, the same
 * `POST /medicines` and `PATCH /medicines/<built-in function id>` payloads and the same money conversion. `formOpen` says whether the full
 * page is showing instead of the list.
 */
export type MedicineRow = {
    publicId: string;
    code: string;
    displayName: string;
    strengthText: string | null;
    dosageForm: string | null;
    orderUnit: string;
    isActive: boolean;
    isControlled: boolean;
    allergyGroups: string[];
    allergyAliases: string[];
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
    selfPayAmountRm: string | null;
    panelDefaultAmountRm: string | null;
};

export type SetupOptions = {
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

export function useMedicineForm(setup: SetupOptions) {
    const formOpen = ref(false);
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
        expected_branch_id: setup.activeBranchId
            ? String(setup.activeBranchId)
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
        form.expected_branch_id = setup.activeBranchId
            ? String(setup.activeBranchId)
            : '';
        formOpen.value = true;
        snapshot();
    };
    const openEdit = async (row: MedicineRow) => {
        editLoading.value = true;
        editLoadError.value = '';
        formOpen.value = true;
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
            form.prices.panel_default_rm =
                details.prices.panel_default_rm ?? '';
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

            form.expected_branch_id = setup.activeBranchId
                ? String(setup.activeBranchId)
                : '';
            // A shared link opens the page with only the id: take the name from the loaded details.
            editing.value = {
                ...row,
                displayName: details.medicine.display_name ?? row.displayName,
            };
            snapshot();
        } catch (error) {
            editLoadError.value =
                error instanceof JsonRequestError
                    ? 'Medicine setup details could not be loaded. Refresh the page and try again.'
                    : 'Medicine setup details could not be loaded. Check your connection and try again.';
        } finally {
            editLoading.value = false;
        }
    };
    const formError = (key: string) =>
        form.errors[key as keyof typeof form.errors];
    const addStockLocation = () => {
        const branchId = setup.activeBranchId;

        if (!branchId) {
            return;
        }

        const defaultLocation = setup.locations.find(
            (option) => option.branchId === branchId,
        );
        const location =
            defaultLocation &&
            form.opening_stock.some(
                (row) => row.location_public_id === defaultLocation.publicId,
            )
                ? undefined
                : defaultLocation;
        form.opening_stock.push({
            branch_id: String(branchId),
            location_public_id: location?.publicId ?? '',
            quantity: '',
            unit_cost_rm: '',
        });
    };
    const removeStockLocation = (index: number) => {
        form.opening_stock.splice(index, 1);

        if (form.opening_stock.length === 0) {
            form.batch = { batch_number: '', expiry_date: '' };
            form.supplier_public_id = '';
        }
    };
    const submit = () => {
        form.transform((data) => ({
            ...data,
            expected_branch_id: data.expected_branch_id || null,
            ...(setup.canManageInventory ? { sku: data.sku } : {}),
            ...(setup.canPublishPrices
                ? {
                      prices: {
                          self_pay_sen: rmToSen(data.prices.self_pay_rm),
                          panel_default_sen: rmToSen(
                              data.prices.panel_default_rm,
                          ),
                          panel_overrides: data.prices.panel_overrides.map(
                              (override) => ({
                                  panel_id: override.panel_id,
                                  amount_sen: rmToSen(override.amount_rm),
                              }),
                          ),
                      },
                  }
                : {}),
            ...(setup.canReceiveStock
                ? {
                      opening_stock: data.opening_stock.map((row) => ({
                          branch_id: row.branch_id,
                          location_public_id: row.location_public_id,
                          quantity: row.quantity,
                          unit_cost_sen: rmToSen(row.unit_cost_rm),
                      })),
                  }
                : {}),
        }));

        if (editing.value) {
            form.patch(`/medicines/${editing.value.publicId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    formOpen.value = false;
                    form.transform((data) => data);
                },
            });
        } else {
            form.post('/medicines', {
                preserveScroll: true,
                onSuccess: () => {
                    formOpen.value = false;
                    form.transform((data) => data);
                },
            });
        }
    };

    // Unsaved-work check for the full page: the form as loaded (or as blank) against the form now.
    const baseline = ref('');
    const snapshot = () => {
        baseline.value = JSON.stringify(form.data());
    };
    const isDirty = computed(
        () => formOpen.value && JSON.stringify(form.data()) !== baseline.value,
    );

    return {
        isDirty,
        formOpen,
        editing,
        editingSkuLabel,
        editLoading,
        editLoadError,
        form,
        openCreate,
        openEdit,
        formError,
        addStockLocation,
        removeStockLocation,
        submit,
    };
}

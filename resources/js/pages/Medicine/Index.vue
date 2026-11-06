<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Ellipsis, Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import CatalogueFormSectionHeading from '@/components/catalogue/CatalogueFormSectionHeading.vue';
import CatalogueOptionPicker from '@/components/catalogue/CatalogueOptionPicker.vue';
import InventorySkuPicker from '@/components/catalogue/InventorySkuPicker.vue';
import LocationPicker from '@/components/catalogue/LocationPicker.vue';
import PanelTariffEditor from '@/components/catalogue/PanelTariffEditor.vue';
import SupplierPicker from '@/components/catalogue/SupplierPicker.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { JsonRequestError, requestJson } from '@/lib/json-client';
import { rmToSen } from '@/lib/money';

type MedicineRow = {
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
const formError = (key: string) => form.errors[key as keyof typeof form.errors];
const addStockLocation = () => {
    const branchId = props.setup.activeBranchId;

    if (!branchId) {
        return;
    }

    const defaultLocation = props.setup.locations.find(
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
        ...(props.setup.canManageInventory ? { sku: data.sku } : {}),
        ...(props.setup.canPublishPrices
            ? {
                  prices: {
                      self_pay_sen: rmToSen(data.prices.self_pay_rm),
                      panel_default_sen: rmToSen(data.prices.panel_default_rm),
                      panel_overrides: data.prices.panel_overrides.map(
                          (override) => ({
                              panel_id: override.panel_id,
                              amount_sen: rmToSen(override.amount_rm),
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
                      unit_cost_sen: rmToSen(row.unit_cost_rm),
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
// DSP-3: the controlled-medicine flag, printed as a marker on the drug label.
const pendingControlled = reactive<{ row: MedicineRow | null }>({ row: null });
const controlledBusy = ref(false);
const confirmControlled = () => {
    const row = pendingControlled.row;

    if (!row) {
        return;
    }

    controlledBusy.value = true;
    router.post(
        `/medicines/${row.publicId}/controlled`,
        {
            is_controlled: !row.isControlled,
            expected_is_controlled: row.isControlled,
        },
        {
            preserveScroll: true,
            // A refusal (for example someone else changed it first) must be visible, not silent.
            onError: (errors: Record<string, string>) => {
                toast.error(
                    errors.is_controlled ??
                        'Could not change the controlled medicine mark. Reload and try again.',
                );
            },
            onFinish: () => {
                controlledBusy.value = false;
                pendingControlled.row = null;
            },
        },
    );
};
const controlledDescription = computed(() => {
    const row = pendingControlled.row;

    if (!row) {
        return '';
    }

    return row.isControlled
        ? `${row.displayName} will no longer show "Ubat Terkawal / Controlled Medicine" on drug labels, including reprints. The change is recorded.`
        : `New drug labels for ${row.displayName} will show "Ubat Terkawal / Controlled Medicine". The change is recorded.`;
});
// DSP-1b: drug groups the Dispensary allergy warning matches against (one per line).
const groupsRow = ref<MedicineRow | null>(null);
const groupsText = ref('');
const groupsBusy = ref(false);
const groupsError = ref('');
const openGroups = (row: MedicineRow) => {
    groupsRow.value = row;
    groupsText.value = row.allergyGroups.join('\n');
    groupsError.value = '';
};
const saveGroups = () => {
    const row = groupsRow.value;

    if (!row) {
        return;
    }

    groupsBusy.value = true;
    router.post(
        `/medicines/${row.publicId}/allergy-groups`,
        {
            allergy_groups: groupsText.value
                .split('\n')
                .map((group) => group.trim())
                .filter((group) => group !== ''),
            expected_allergy_groups: row.allergyGroups,
        },
        {
            preserveScroll: true,
            onSuccess: () => (groupsRow.value = null),
            onError: (errors: Record<string, string>) => {
                const message =
                    errors.allergy_groups ??
                    'Could not save the drug groups. Reload and try again.';

                // DD-37: a list changed meanwhile closes, so the next attempt starts from the reloaded list.
                if (message.startsWith('This medicine changed.')) {
                    groupsRow.value = null;
                    toast.error(message);

                    return;
                }

                // Anything else (too short, too many): keep the dialog and what was typed.
                groupsError.value = message;
            },
            onFinish: () => (groupsBusy.value = false),
        },
    );
};
// DSP-1c: other names (brand names, common misspellings) the Dispensary allergy warning matches against.
const aliasesRow = ref<MedicineRow | null>(null);
const aliasesText = ref('');
const aliasesBusy = ref(false);
const aliasesError = ref('');
const openAliases = (row: MedicineRow) => {
    aliasesRow.value = row;
    aliasesText.value = row.allergyAliases.join('\n');
    aliasesError.value = '';
};
const saveAliases = () => {
    const row = aliasesRow.value;

    if (!row) {
        return;
    }

    aliasesBusy.value = true;
    router.post(
        `/medicines/${row.publicId}/allergy-aliases`,
        {
            allergy_aliases: aliasesText.value
                .split('\n')
                .map((alias) => alias.trim())
                .filter((alias) => alias !== ''),
            expected_allergy_aliases: row.allergyAliases,
        },
        {
            preserveScroll: true,
            onSuccess: () => (aliasesRow.value = null),
            onError: (errors: Record<string, string>) => {
                const message =
                    errors.allergy_aliases ??
                    'Could not save the other names. Reload and try again.';

                // A list changed meanwhile: close, so the next attempt starts from the reloaded list.
                if (message.startsWith('This medicine changed.')) {
                    aliasesRow.value = null;
                    toast.error(message);

                    return;
                }

                // Anything else (too short, too many): keep the dialog and what was typed.
                aliasesError.value = message;
            },
            onFinish: () => (aliasesBusy.value = false),
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
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Medicine Catalogue"
            description="Create and manage the organisation Medicine Catalogue."
        >
            <template #actions>
                <Button size="sm" @click="openCreate">
                    <Plus class="size-4" aria-hidden="true" /> Add medicine
                </Button>
            </template>
        </PageHeader>

        <form
            class="grid gap-3 sm:flex sm:flex-wrap sm:items-end"
            @submit.prevent="visit()"
        >
            <div class="grid w-full gap-2 sm:max-w-xs">
                <Label for="medicine-search">Search</Label>
                <Input
                    id="medicine-search"
                    v-model="search"
                    placeholder="Code or name"
                    autocomplete="off"
                />
            </div>
            <div class="grid w-full gap-2 sm:max-w-44">
                <Label id="medicine-status-label" for="medicine-status"
                    >Status</Label
                >
                <OperationalSelect
                    id="medicine-status"
                    v-model="status"
                    label="Status"
                    labelledby="medicine-status-label"
                    :options="statusOptions"
                />
            </div>
            <Button size="sm" type="submit" class="min-h-11 sm:min-h-8"
                >Apply</Button
            >
        </form>

        <section class="grid gap-3 lg:hidden" aria-label="Medicine Catalogue">
            <EmptyState
                v-if="medicines.data.length === 0"
                title="No medicines match this filter."
                description="Change the search or status, or add a medicine."
            />
            <article
                v-for="row in medicines.data"
                :key="row.publicId"
                class="grid min-w-0 gap-3 rounded-xl border bg-card p-4"
                data-testid="medicine-card"
            >
                <div class="flex min-w-0 items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-medium break-words">
                            {{ row.displayName }}
                        </h2>
                        <p
                            class="text-xs break-words text-muted-foreground tabular-nums"
                        >
                            {{ row.code }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                        <StatusBadge
                            :status="row.isActive ? 'active' : 'inactive'"
                            :tone="row.isActive ? 'success' : 'neutral'"
                        />
                        <Badge
                            v-if="row.isControlled"
                            variant="outline"
                            class="border-border bg-card text-foreground"
                            >Controlled</Badge
                        >
                    </div>
                </div>
                <p class="text-xs break-words text-muted-foreground">
                    {{
                        [row.strengthText, row.dosageForm, row.orderUnit]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
                <p
                    v-if="row.allergyGroups.length"
                    class="text-xs break-words text-muted-foreground"
                >
                    Drug groups: {{ row.allergyGroups.slice(0, 3).join(', ')
                    }}<template v-if="row.allergyGroups.length > 3">
                        +{{ row.allergyGroups.length - 3 }} more</template
                    >
                </p>
                <p
                    v-if="row.allergyAliases.length"
                    class="text-xs break-words text-muted-foreground"
                >
                    Other names: {{ row.allergyAliases.slice(0, 3).join(', ')
                    }}<span
                        v-if="row.allergyAliases.length > 3"
                        class="whitespace-nowrap"
                    >
                        +{{ row.allergyAliases.length - 3 }} more</span
                    >
                </p>
                <dl v-if="setup.canManagePrices" class="grid gap-1 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Self-pay</dt>
                        <dd class="font-medium tabular-nums">
                            {{
                                row.selfPayAmountRm
                                    ? `RM ${row.selfPayAmountRm}`
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Default Panel</dt>
                        <dd class="font-medium tabular-nums">
                            {{
                                row.panelDefaultAmountRm
                                    ? `RM ${row.panelDefaultAmountRm}`
                                    : '—'
                            }}
                        </dd>
                    </div>
                </dl>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        class="min-h-11 flex-1"
                        :aria-label="`Edit ${row.displayName}`"
                        @click="openEdit(row)"
                        >Edit</Button
                    >
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                class="min-h-11 min-w-11"
                                :aria-label="`More actions for ${row.displayName}`"
                                data-action="medicine-row-menu"
                            >
                                <Ellipsis class="size-4" aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <DropdownMenuItem
                                class="min-h-11"
                                @select="requestToggle(row)"
                                >{{
                                    row.isActive ? 'Deactivate' : 'Activate'
                                }}</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                class="min-h-11"
                                :aria-label="`Drug groups for ${row.displayName}`"
                                @select="openGroups(row)"
                                >Drug groups</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                class="min-h-11"
                                :aria-label="`Other names for ${row.displayName}`"
                                @select="openAliases(row)"
                                >Other names</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                class="min-h-11"
                                @select="pendingControlled.row = row"
                                >{{
                                    row.isControlled
                                        ? 'Unmark controlled'
                                        : 'Mark controlled'
                                }}</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </article>
        </section>

        <div class="hidden min-w-0 lg:block">
            <OperationalTable
                label="Medicine Catalogue"
                variant="catalogue"
                :columns="setup.canManagePrices ? 8 : 7"
                min-width="960px"
                :empty="medicines.data.length === 0"
                empty-message="No medicines match this filter."
            >
                <template #head>
                    <tr>
                        <th class="min-w-32">Code</th>
                        <th class="min-w-56">Name</th>
                        <th>Strength</th>
                        <th>Dosage form</th>
                        <th>Order unit</th>
                        <th v-if="setup.canManagePrices">Prices</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="row in medicines.data" :key="row.publicId">
                        <td class="font-medium whitespace-nowrap tabular-nums">
                            {{ row.code }}
                        </td>
                        <td>
                            {{ row.displayName }}
                            <span
                                v-if="row.allergyGroups.length"
                                class="mt-0.5 block text-xs text-muted-foreground"
                                data-testid="allergy-groups"
                                >Drug groups:
                                {{ row.allergyGroups.join(', ') }}</span
                            >
                            <span
                                v-if="row.allergyAliases.length"
                                class="mt-0.5 block text-xs text-muted-foreground"
                                data-testid="allergy-aliases"
                                >Other names:
                                {{ row.allergyAliases.join(', ') }}</span
                            >
                        </td>
                        <td>{{ row.strengthText ?? '—' }}</td>
                        <td>{{ row.dosageForm ?? '—' }}</td>
                        <td>{{ row.orderUnit }}</td>
                        <td v-if="setup.canManagePrices" class="min-w-44">
                            <div class="grid gap-1">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span class="text-muted-foreground"
                                        >Self-pay</span
                                    >
                                    <span
                                        class="font-medium whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            row.selfPayAmountRm
                                                ? `RM ${row.selfPayAmountRm}`
                                                : '—'
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span class="text-muted-foreground"
                                        >Default Panel</span
                                    >
                                    <span
                                        class="font-medium whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            row.panelDefaultAmountRm
                                                ? `RM ${row.panelDefaultAmountRm}`
                                                : '—'
                                        }}
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap">
                            <StatusBadge
                                :status="row.isActive ? 'active' : 'inactive'"
                                :tone="row.isActive ? 'success' : 'neutral'"
                            />
                            <Badge
                                v-if="row.isControlled"
                                variant="outline"
                                class="ml-1 border-border bg-card text-foreground"
                                data-testid="controlled-badge"
                                >Controlled</Badge
                            >
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :aria-label="`Edit ${row.displayName}`"
                                    @click="openEdit(row)"
                                    >Edit</Button
                                >
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="size-8"
                                            :aria-label="`More actions for ${row.displayName}`"
                                            data-action="medicine-row-menu"
                                        >
                                            <Ellipsis
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-56"
                                    >
                                        <DropdownMenuItem
                                            @select="requestToggle(row)"
                                            >{{
                                                row.isActive
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                            }}</DropdownMenuItem
                                        >
                                        <DropdownMenuItem
                                            data-action="edit-allergy-groups"
                                            :aria-label="`Drug groups for ${row.displayName}`"
                                            @select="openGroups(row)"
                                            >Drug groups</DropdownMenuItem
                                        >
                                        <DropdownMenuItem
                                            data-action="edit-allergy-aliases"
                                            :aria-label="`Other names for ${row.displayName}`"
                                            @select="openAliases(row)"
                                            >Other names</DropdownMenuItem
                                        >
                                        <DropdownMenuItem
                                            data-action="toggle-controlled"
                                            :aria-label="`${row.isControlled ? 'Unmark' : 'Mark'} ${row.displayName} as a controlled medicine`"
                                            @select="
                                                pendingControlled.row = row
                                            "
                                        >
                                            {{
                                                row.isControlled
                                                    ? 'Unmark controlled'
                                                    : 'Mark controlled'
                                            }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </td>
                    </tr>
                </template>
            </OperationalTable>
        </div>

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
                        editing ? 'Edit medicine' : 'Add medicine'
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
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="w-fit md:col-span-2"
                                @click="removeStockLocation(index)"
                            >
                                Remove opening stock row
                            </Button>
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
                                >{{
                                    form.opening_stock.length
                                        ? 'Add another stock location'
                                        : 'Add opening stock'
                                }}</Button
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
                            :disabled="
                                form.processing ||
                                editLoading ||
                                !!editLoadError
                            "
                        >
                            {{ editing ? 'Save' : 'Add medicine' }}
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

        <Dialog
            :open="groupsRow !== null"
            @update:open="(open) => !open && (groupsRow = null)"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Drug groups</DialogTitle>
                    <DialogDescription>
                        {{ groupsRow?.displayName }}: the drug groups this
                        medicine belongs to, one per line (for example
                        Penicillins, NSAIDs). The Dispensary allergy warning
                        checks a patient's recorded allergies against these as
                        well as the medicine's names. KPOne does not decide the
                        groups; check them against a reliable reference. Use
                        drug-class names, not dosage forms or broad words such
                        as Tablets.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-1 text-sm">
                    <label for="medicine-allergy-groups"
                        >Drug groups (up to 10, 3 to 60 characters each)</label
                    >
                    <textarea
                        id="medicine-allergy-groups"
                        v-model="groupsText"
                        rows="5"
                        maxlength="700"
                        class="w-full rounded-md border bg-background p-2 text-sm aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40"
                        :aria-invalid="groupsError ? 'true' : undefined"
                        aria-describedby="medicine-allergy-groups-error"
                        data-field="allergy-groups"
                    ></textarea>
                    <InputError
                        id="medicine-allergy-groups-error"
                        :message="groupsError"
                    />
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="groupsBusy"
                        @click="groupsRow = null"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="groupsBusy"
                        data-action="save-allergy-groups"
                        @click="saveGroups"
                        >Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="aliasesRow !== null"
            @update:open="(open) => !open && (aliasesRow = null)"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Other names</DialogTitle>
                    <DialogDescription>
                        {{ aliasesRow?.displayName }}: brand names and common
                        misspellings, one per line (for example Augmentin,
                        amoxycillin). These are used only to match a patient's
                        recorded allergies at Dispensary. They are never printed
                        on drug labels and do not change the medicine name.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-1 text-sm">
                    <label for="medicine-allergy-aliases"
                        >Other names (up to 10, 3 to 60 characters each)</label
                    >
                    <textarea
                        id="medicine-allergy-aliases"
                        v-model="aliasesText"
                        rows="5"
                        maxlength="700"
                        :placeholder="'Augmentin\namoxycillin'"
                        class="w-full rounded-md border bg-background p-2 text-sm aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40"
                        :aria-invalid="aliasesError ? 'true' : undefined"
                        aria-describedby="medicine-allergy-aliases-error"
                        data-field="allergy-aliases"
                    ></textarea>
                    <InputError
                        id="medicine-allergy-aliases-error"
                        :message="aliasesError"
                    />
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="aliasesBusy"
                        @click="aliasesRow = null"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="aliasesBusy"
                        data-action="save-allergy-aliases"
                        @click="saveAliases"
                        >Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <OperationalConfirmDialog
            :open="pendingControlled.row !== null"
            :title="
                pendingControlled.row?.isControlled
                    ? 'Remove controlled medicine mark'
                    : 'Mark as controlled medicine'
            "
            :description="controlledDescription"
            :confirm-label="
                pendingControlled.row?.isControlled ? 'Remove mark' : 'Mark'
            "
            :destructive="pendingControlled.row?.isControlled ?? false"
            :processing="controlledBusy"
            @update:open="
                (open) => {
                    if (!open) pendingControlled.row = null;
                }
            "
            @confirm="confirmControlled"
        />
    </main>
</template>

<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import CatalogueFormSectionHeading from '@/components/catalogue/CatalogueFormSectionHeading.vue';
import CatalogueOptionPicker from '@/components/catalogue/CatalogueOptionPicker.vue';
import ConsultationTariffPanel from '@/components/catalogue/ConsultationTariffPanel.vue';
import PanelTariffEditor from '@/components/catalogue/PanelTariffEditor.vue';
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

type ServiceRow = {
    publicId: string;
    code: string;
    displayName: string;
    orderUnit: string;
    category?: string | null;
    isActive: boolean;
};

type SetupOptions = {
    canManagePrices: boolean;
    canPublishPrices: boolean;
    activeBranchId: number | null;
    branches: { id: number; name: string }[];
    panels: { id: number; name: string }[];
};

type ConsultationTariff = {
    chargeExists: boolean;
    code: string;
    displayName: string;
    isActive: boolean;
    selfPayAmountRm: string | null;
    selfPayVersion: number;
    panelDefaultAmountRm: string | null;
    panelDefaultVersion: number;
    panelOverrides: {
        panelId: string;
        panelName: string;
        amountRm: string | null;
        version: number;
    }[];
};

const props = defineProps<{
    services: {
        data: ServiceRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    filters: { search: string; status: string };
    setup: SetupOptions;
    consultationTariff: ConsultationTariff;
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
        '/clinical-services',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const dialogOpen = ref(false);
const editing = ref<ServiceRow | null>(null);
const form = useForm({
    code: '',
    display_name: '',
    order_unit: '',
    category: '',
    expected_branch_id: props.setup.activeBranchId
        ? String(props.setup.activeBranchId)
        : '',
    prices: {
        self_pay_rm: '',
        panel_default_rm: '',
        panel_overrides: [] as { panel_id: string; amount_rm: string }[],
    },
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.setup.activeBranchId
        ? String(props.setup.activeBranchId)
        : '';
    dialogOpen.value = true;
};
const openEdit = (row: ServiceRow) => {
    editing.value = row;
    form.clearErrors();
    form.code = row.code;
    form.display_name = row.displayName;
    form.order_unit = row.orderUnit;
    form.category = row.category ?? '';
    dialogOpen.value = true;
};
const toSen = (amount: string) =>
    amount.trim() === '' ? null : Math.round(Number(amount) * 100);
const formError = (key: string) => form.errors[key as keyof typeof form.errors];
const submit = () => {
    form.transform((data) => ({
        ...data,
        expected_branch_id: data.expected_branch_id || null,
        prices: {
            self_pay_sen: toSen(data.prices.self_pay_rm),
            panel_default_sen: toSen(data.prices.panel_default_rm),
            panel_overrides: data.prices.panel_overrides.map((override) => ({
                panel_id: override.panel_id,
                amount_sen: toSen(override.amount_rm),
            })),
        },
    }));

    if (editing.value) {
        form.patch(`/clinical-services/${editing.value.publicId}`, {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.transform((data) => data);
            },
        });
    } else {
        form.post('/clinical-services', {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
                form.transform((data) => data);
            },
        });
    }
};

const pendingToggle = reactive<{ row: ServiceRow | null }>({ row: null });
const toggleBusy = ref(false);
const requestToggle = (row: ServiceRow) => {
    pendingToggle.row = row;
};
const confirmToggle = () => {
    const row = pendingToggle.row;

    if (!row) {
        return;
    }

    toggleBusy.value = true;
    router.post(
        `/clinical-services/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`,
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
            title="Clinical Service Catalogue"
            description="Create and manage clinical services available for ordering on a Treatment Plan."
        >
            <template #actions>
                <Button size="sm" @click="openCreate">
                    <Plus class="size-4" /> Add Clinical Service
                </Button>
            </template>
        </PageHeader>

        <ConsultationTariffPanel
            :tariff="consultationTariff"
            :can-manage-prices="setup.canManagePrices"
            :can-publish-prices="setup.canPublishPrices"
            :active-branch-id="setup.activeBranchId"
            :branches="setup.branches"
            :panels="setup.panels"
        />

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
            label="Clinical Service Catalogue"
            :columns="4"
            :empty="services.data.length === 0"
            empty-message="No clinical services match this filter."
        >
            <template #head>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Order unit</th>
                    <th class="text-right">Status</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="row in services.data" :key="row.publicId">
                    <td class="font-medium tabular-nums">{{ row.code }}</td>
                    <td>{{ row.displayName }}</td>
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
            :current-page="services.currentPage"
            :last-page="services.lastPage"
            :total="services.total"
            @change="(page) => visit({ page })"
        />

        <Dialog v-model:open="dialogOpen">
            <DialogContent
                class="max-h-[calc(100dvh-1rem)] overflow-y-auto p-4 sm:max-w-6xl sm:p-6"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? 'Edit Clinical Service'
                            : 'Add Clinical Service'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Update this Clinical Service Catalogue entry.'
                                : 'Complete the sections below on this page. The service details and its default and Panel tariffs are saved together.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="grid gap-4 rounded-xl bg-muted/30 p-2 sm:p-4"
                    @submit.prevent="submit"
                >
                    <section
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="01"
                            title="Service details"
                            description="Identify and classify the clinical service that staff can select when creating an order."
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
                            <CatalogueOptionPicker
                                v-model="form.category"
                                type="service_category"
                                label="Category"
                                :error="form.errors.category"
                            />
                            <CatalogueOptionPicker
                                v-model="form.order_unit"
                                type="service_unit"
                                label="Order unit"
                                :error="form.errors.order_unit"
                            />
                        </div>
                    </section>
                    <section
                        v-if="!editing && setup.canManagePrices"
                        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
                    >
                        <CatalogueFormSectionHeading
                            number="02"
                            title="Pricing"
                            description="Set default self-pay and Panel tariffs. Published prices are retained as versioned records."
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
                            can publish them later.
                        </p>
                    </section>
                    <DialogFooter
                        class="sticky bottom-0 z-10 mt-1 border-t bg-background/95 py-3 backdrop-blur"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="dialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ editing ? 'Save' : 'Add Clinical Service' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <OperationalConfirmDialog
            :open="pendingToggle.row !== null"
            :title="
                pendingToggle.row?.isActive
                    ? 'Deactivate Clinical Service'
                    : 'Activate Clinical Service'
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

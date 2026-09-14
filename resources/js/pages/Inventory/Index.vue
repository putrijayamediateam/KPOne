<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InventoryOperationsPanel from '@/components/inventory/InventoryOperationsPanel.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import { inventoryFilterError } from '@/types/inventory-operations';
import type {
    InventoryOperations,
    InventoryReceiptMemoryContext,
} from '@/types/inventory-operations';

type InventoryRow = Record<string, string | boolean | null>;
type InventoryDirectory = {
    tab: 'stock' | 'batches' | 'movements';
    branch: string;
    branchTimezone: string;
    filters: {
        search: string;
        location: string;
        status: string;
        movementType: string;
        batch: string;
    };
    locations: Array<{ publicId: string; name: string; type: string }>;
    summary: {
        skusWithStock: number;
        totalBatches: number;
        expiredBatches: number;
    };
    data: InventoryRow[];
    total: number;
    currentPage: number;
    lastPage: number;
};

const props = defineProps<{
    inventory: InventoryDirectory;
    operations: InventoryOperations;
    receiptMemoryContext: InventoryReceiptMemoryContext;
    errors?: Record<string, unknown>;
}>();
const search = ref(props.inventory.filters.search);
const location = ref(props.inventory.filters.location);
const status = ref(props.inventory.filters.status);
const movementType = ref(props.inventory.filters.movementType);
const batch = ref(props.inventory.filters.batch);
const loading = ref(false);
const filterError = computed(() => inventoryFilterError(props.errors ?? {}));

watch(
    () => props.inventory.filters,
    (filters) => {
        search.value = filters.search;
        location.value = filters.location;
        status.value = filters.status;
        movementType.value = filters.movementType;
        batch.value = filters.batch;
    },
);

const tabs = [
    { value: 'stock', label: 'Stock Overview' },
    { value: 'batches', label: 'Batch & Expiry' },
    { value: 'movements', label: 'Movements' },
];
const locationOptions = computed(() => [
    { value: '', label: 'All branch locations' },
    ...props.inventory.locations.map((item) => ({
        value: item.publicId,
        label: `${item.name} · ${item.type}`,
    })),
]);
const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Available' },
    { value: 'inactive', label: 'Unavailable' },
];
const movementOptions = [
    { value: '', label: 'All movement types' },
    { value: 'opening_balance', label: 'Opening Balance' },
    { value: 'transfer', label: 'Inventory Transfer' },
    { value: 'dispense', label: 'Dispense' },
    { value: 'purchase_receipt', label: 'Purchase Receipt' },
    { value: 'transfer_dispatch', label: 'Transfer Dispatch' },
    { value: 'transfer_receipt', label: 'Transfer Receipt' },
    { value: 'stocktake_gain', label: 'Stocktake Gain' },
    { value: 'stocktake_loss', label: 'Stocktake Loss' },
    { value: 'adjustment_in', label: 'Adjustment In' },
    { value: 'adjustment_out', label: 'Adjustment Out' },
];

const visit = (overrides: Record<string, string | number> = {}) => {
    router.get(
        '/inventory',
        {
            tab: props.inventory.tab,
            search: search.value || undefined,
            location: location.value || undefined,
            status:
                props.inventory.tab === 'movements'
                    ? undefined
                    : status.value || undefined,
            movement_type:
                props.inventory.tab === 'movements'
                    ? movementType.value || undefined
                    : undefined,
            batch:
                props.inventory.tab === 'stock'
                    ? undefined
                    : batch.value || undefined,
            ...overrides,
        },
        {
            preserveState: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
};
const changeTab = (tab: string) => visit({ tab, page: 1 });
const applyFilters = () => visit({ page: 1 });
const clearFilters = () => {
    search.value = '';
    location.value = '';
    status.value = '';
    movementType.value = '';
    batch.value = '';
    visit({ page: 1 });
};
</script>

<template>
    <Head title="Inventory" />
    <main class="mx-auto w-full max-w-[1600px] space-y-4 px-4 py-6">
        <PageHeader
            title="Inventory"
            :description="`Stock, batches and movement history for ${inventory.branch}.`"
        >
            <template #actions>
                <ActionLink href="/workspace" variant="secondary">
                    Return to Workspace
                </ActionLink>
            </template>
        </PageHeader>

        <section
            class="grid gap-3 sm:grid-cols-3"
            aria-label="Inventory summary"
        >
            <div class="rounded-xl border bg-card px-4 py-3">
                <p class="text-xs text-muted-foreground">SKUs with Stock</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ inventory.summary.skusWithStock }}
                </p>
            </div>
            <div class="rounded-xl border bg-card px-4 py-3">
                <p class="text-xs text-muted-foreground">Total Batches</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ inventory.summary.totalBatches }}
                </p>
            </div>
            <div class="rounded-xl border bg-card px-4 py-3">
                <p class="text-xs text-muted-foreground">Expired Batches</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ inventory.summary.expiredBatches }}
                </p>
            </div>
        </section>

        <section class="space-y-4 rounded-xl border bg-card p-4">
            <OperationalTabs
                :model-value="inventory.tab"
                :tabs="tabs"
                label="Inventory views"
                @update:model-value="changeTab"
            />

            <form
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-5"
                aria-label="Inventory filters"
                @submit.prevent="applyFilters"
            >
                <div class="space-y-1">
                    <label for="inventory-search" class="text-xs font-medium">
                        Search
                    </label>
                    <Input
                        id="inventory-search"
                        v-model="search"
                        maxlength="100"
                        placeholder="SKU, item or medicine"
                    />
                </div>
                <div class="space-y-1">
                    <label for="inventory-location" class="text-xs font-medium">
                        Location
                    </label>
                    <OperationalSelect
                        id="inventory-location"
                        v-model="location"
                        label="Inventory location"
                        :options="locationOptions"
                    />
                </div>
                <div v-if="inventory.tab !== 'movements'" class="space-y-1">
                    <label for="inventory-status" class="text-xs font-medium">
                        Status
                    </label>
                    <OperationalSelect
                        id="inventory-status"
                        v-model="status"
                        label="Inventory status"
                        :options="statusOptions"
                    />
                </div>
                <div v-else class="space-y-1">
                    <label for="movement-type" class="text-xs font-medium">
                        Movement type
                    </label>
                    <OperationalSelect
                        id="movement-type"
                        v-model="movementType"
                        label="Movement type"
                        :options="movementOptions"
                    />
                </div>
                <div v-if="inventory.tab !== 'stock'" class="space-y-1">
                    <label for="batch-search" class="text-xs font-medium">
                        Batch
                    </label>
                    <Input
                        id="batch-search"
                        v-model="batch"
                        maxlength="100"
                        placeholder="Batch number"
                    />
                </div>
                <div class="flex items-end gap-2">
                    <Button type="submit" :disabled="loading">Apply</Button>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="loading"
                        @click="clearFilters"
                    >
                        Clear
                    </Button>
                </div>
            </form>
        </section>

        <ErrorState
            v-if="filterError"
            title="Inventory filters need attention"
            :message="filterError"
        />
        <p v-if="loading" class="text-sm text-muted-foreground" role="status">
            Updating inventory…
        </p>

        <EmptyState
            v-if="!loading && inventory.data.length === 0"
            title="No inventory records"
            :description="`No ${inventory.tab === 'movements' ? 'movement' : 'stock'} records are available for ${inventory.branch} with these filters.`"
        />

        <OperationalTable
            v-else-if="inventory.tab === 'stock'"
            label="Stock Overview"
            :columns="8"
            min-width="1040px"
            :loading="loading"
        >
            <template #head
                ><tr>
                    <th>SKU / Item</th>
                    <th>Medicine</th>
                    <th>Location</th>
                    <th>Branch</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th class="text-right">Quantity</th>
                    <th>Status</th>
                </tr></template
            >
            <template #body>
                <tr
                    v-for="(row, index) in inventory.data"
                    :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
                >
                    <td>
                        <span class="block font-mono text-xs">{{
                            row.skuCode
                        }}</span
                        ><span class="block font-medium">{{
                            row.itemName
                        }}</span>
                    </td>
                    <td>{{ row.medicineName ?? 'Not mapped to Medicine' }}</td>
                    <td>
                        <span class="block">{{ row.location }}</span
                        ><span class="text-xs text-muted-foreground">{{
                            row.locationType
                        }}</span>
                    </td>
                    <td>{{ row.branch }}</td>
                    <td class="font-mono text-xs">{{ row.batchNumber }}</td>
                    <td class="whitespace-nowrap">{{ row.expiryDate }}</td>
                    <td
                        class="text-right font-semibold whitespace-nowrap tabular-nums"
                    >
                        {{ row.quantity }} {{ row.stockUnit }}
                    </td>
                    <td>
                        <StatusBadge
                            :status="row.available ? 'available' : 'inactive'"
                            :label="String(row.availabilityStatus)"
                        />
                    </td>
                </tr>
            </template>
        </OperationalTable>

        <OperationalTable
            v-else-if="inventory.tab === 'batches'"
            label="Batch & Expiry"
            :columns="9"
            min-width="1120px"
            :loading="loading"
        >
            <template #head
                ><tr>
                    <th>SKU / Item</th>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Location</th>
                    <th>Received</th>
                    <th>Expiry</th>
                    <th>Expiry status</th>
                    <th>Batch status</th>
                    <th class="text-right">Quantity</th>
                </tr></template
            >
            <template #body>
                <tr
                    v-for="(row, index) in inventory.data"
                    :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
                >
                    <td>
                        <span class="block font-mono text-xs">{{
                            row.skuCode
                        }}</span
                        ><span class="block font-medium">{{
                            row.itemName
                        }}</span>
                    </td>
                    <td>{{ row.medicineName ?? 'Not mapped to Medicine' }}</td>
                    <td class="font-mono text-xs">{{ row.batchNumber }}</td>
                    <td>{{ row.location }}</td>
                    <td class="whitespace-nowrap">
                        {{ row.receivedDate ?? '—' }}
                    </td>
                    <td class="whitespace-nowrap">{{ row.expiryDate }}</td>
                    <td>
                        <StatusBadge
                            :status="String(row.expiryStatus).toLowerCase()"
                            :label="String(row.expiryStatus)"
                            :tone="
                                row.expiryStatus === 'Expired'
                                    ? 'danger'
                                    : 'success'
                            "
                        />
                    </td>
                    <td>
                        <StatusBadge
                            :status="String(row.batchStatus).toLowerCase()"
                            :label="String(row.batchStatus)"
                        />
                    </td>
                    <td
                        class="text-right font-semibold whitespace-nowrap tabular-nums"
                    >
                        {{ row.quantity }} {{ row.stockUnit }}
                    </td>
                </tr>
            </template>
        </OperationalTable>

        <OperationalTable
            v-else
            label="Stock Movements"
            :columns="8"
            min-width="1120px"
            :loading="loading"
        >
            <template #head
                ><tr>
                    <th>Date / Time</th>
                    <th>Type</th>
                    <th>SKU / Item</th>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Direction</th>
                    <th class="text-right">Quantity</th>
                    <th>Reference</th>
                </tr></template
            >
            <template #body>
                <tr
                    v-for="(row, index) in inventory.data"
                    :key="`${row.occurredAt}-${row.skuCode}-${row.batchNumber}-${index}`"
                >
                    <td class="whitespace-nowrap">{{ row.occurredAt }}</td>
                    <td>
                        <StatusBadge
                            :status="String(row.type)"
                            :label="String(row.typeLabel)"
                        />
                    </td>
                    <td>
                        <span class="block font-mono text-xs">{{
                            row.skuCode
                        }}</span
                        ><span class="block font-medium">{{
                            row.itemName
                        }}</span>
                    </td>
                    <td>{{ row.medicineName ?? 'Not mapped to Medicine' }}</td>
                    <td class="font-mono text-xs">{{ row.batchNumber }}</td>
                    <td>{{ row.direction }}</td>
                    <td
                        class="text-right font-semibold whitespace-nowrap tabular-nums"
                    >
                        {{ row.quantityDisplay }} {{ row.stockUnit }}
                    </td>
                    <td>{{ row.reference }}</td>
                </tr>
            </template>
        </OperationalTable>

        <CompactPagination
            v-if="inventory.total > 0"
            :current-page="inventory.currentPage"
            :last-page="inventory.lastPage"
            :total="inventory.total"
            :disabled="loading"
            @change="(page) => visit({ page })"
        />

        <p class="text-xs text-muted-foreground">
            Dates and times use {{ inventory.branchTimezone }}. Movement records
            are read-only.
        </p>

        <InventoryOperationsPanel
            :operations="operations"
            :receipt-memory-context="receiptMemoryContext"
        />
    </main>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import InventoryOperationsPanel from '@/components/inventory/InventoryOperationsPanel.vue';
import InventoryReferenceDataPanel from '@/components/inventory/InventoryReferenceDataPanel.vue';
import InventorySearchSelect from '@/components/inventory/InventorySearchSelect.vue';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import { ErrorState } from '@/components/ui/state';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import {
    inventoryMovementOptions,
    inventoryStatusLabel,
    inventoryStatusOptions,
    inventoryTabs,
    useInventoryHome,
} from '@/composables/useInventoryHome';
import type { InventoryDirectory } from '@/composables/useInventoryHome';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import type {
    InventoryOperations,
    InventoryReceiptMemoryContext,
} from '@/types/inventory-operations';

/**
 * Version 2 Inventory home (shown only in V2; Classic is `pages/Inventory/Index.vue`, untouched). Same props, same
 * route and query keys (`GET /inventory`), same task links and permission checks. The receiving, transfer and stock
 * setup panels are the Classic components, unchanged, inside two closed bars; converting them is a later PR.
 */
const props = defineProps<{
    inventory: InventoryDirectory;
    operations: InventoryOperations;
    receiptMemoryContext: InventoryReceiptMemoryContext;
    referenceData: {
        canManage: boolean;
        expectedBranchId: number;
        items: Array<{ publicId: string; code: string; genericName: string }>;
        skus: Array<{
            publicId: string;
            skuCode: string;
            inventoryItemId: number;
        }>;
        locations: Array<{ publicId: string; name: string; type: string }>;
        medicines: Array<{
            publicId: string;
            code: string;
            displayName: string;
        }>;
        branches: Array<{ id: number; name: string }>;
    };
    errors?: Record<string, unknown>;
}>();
usePageCrumbs(() => [{ title: 'Inventory' }]);

const {
    taskGroups,
    search,
    location,
    status,
    movementType,
    dateFrom,
    dateTo,
    batch,
    loading,
    filterError,
    locationOptions,
    visit,
    changeTab,
    applyFilters,
    clearFilters,
} = useInventoryHome(
    () => props.inventory,
    () => props.errors,
);

// Filters kept behind "More filters": say how many are applied, so a hidden filter is never a surprise.
const extraFilters = computed(
    () =>
        [
            props.inventory.filters.location,
            ...(props.inventory.tab === 'stock'
                ? []
                : [props.inventory.filters.batch]),
            ...(props.inventory.tab === 'movements'
                ? [
                      props.inventory.filters.dateFrom,
                      props.inventory.filters.dateTo,
                  ]
                : []),
        ].filter(Boolean).length,
);
const tile = 'min-w-0 rounded-[14px] border bg-card p-3 sm:px-4';
const taskLink =
    'inline-flex min-h-11 items-center rounded-[10px] border border-border bg-card px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const medicine = (value: unknown) =>
    value == null ? 'Not mapped to Medicine' : String(value);
const availabilityTone = (row: InventoryDirectory['data'][number]) =>
    row.available ? 'success' : 'neutral';
const batchTone = (status: unknown) =>
    String(status).toLowerCase() === 'available' ? 'success' : 'neutral';
</script>

<template>
    <Head title="Inventory" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Inventory"
            :description="`${inventory.branch} · Stock, batches and movement history.`"
        >
            <template #main>
                <section
                    class="grid grid-cols-3 gap-2 sm:gap-3"
                    aria-label="Inventory summary"
                >
                    <div :class="tile">
                        <p class="text-xs break-words text-muted-foreground">
                            SKUs with stock
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ inventory.summary.skusWithStock }}
                        </p>
                    </div>
                    <div :class="tile">
                        <p class="text-xs break-words text-muted-foreground">
                            Total batches
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ inventory.summary.totalBatches }}
                        </p>
                    </div>
                    <div :class="tile">
                        <p class="text-xs break-words text-muted-foreground">
                            Expired batches
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ inventory.summary.expiredBatches }}
                        </p>
                    </div>
                </section>

                <nav aria-label="Inventory tasks">
                    <!-- From 1024 px each group is a card with its links; below that the same groups fold. -->
                    <div class="hidden gap-3 lg:grid lg:grid-cols-3">
                        <section
                            v-for="group in taskGroups"
                            :key="group.id"
                            class="space-y-2 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-inventory-tasks-${group.id}`"
                        >
                            <h2
                                :id="`v2-inventory-tasks-${group.id}`"
                                class="text-[15px] font-semibold"
                            >
                                {{ group.title }}
                            </h2>
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-for="link in group.links"
                                    :key="link.href"
                                    :href="link.href"
                                    :class="taskLink"
                                    >{{ link.label }}</Link
                                >
                            </div>
                        </section>
                    </div>
                    <div class="space-y-2 lg:hidden">
                        <V2DisclosureBar
                            v-for="group in taskGroups"
                            :key="group.id"
                            :title="`${group.title} · ${group.links.length}`"
                        >
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-for="link in group.links"
                                    :key="link.href"
                                    :href="link.href"
                                    :class="taskLink"
                                    >{{ link.label }}</Link
                                >
                            </div>
                        </V2DisclosureBar>
                    </div>
                </nav>

                <h2 class="sr-only">Stock records</h2>
                <V2Tabs
                    :model-value="inventory.tab"
                    :tabs="inventoryTabs"
                    ariaLabel="Inventory views"
                    @update:model-value="changeTab"
                />

                <form
                    aria-label="Inventory filters"
                    @submit.prevent="applyFilters"
                >
                    <V2Toolbar
                        v-model="search"
                        search-label="Search SKU, item or medicine"
                        search-placeholder="SKU, item or medicine"
                        more-filters
                        @search="applyFilters"
                        @reset="clearFilters"
                    >
                        <template #filters>
                            <div
                                v-if="inventory.tab !== 'movements'"
                                class="w-full sm:w-48"
                            >
                                <OperationalSelect
                                    v-model="status"
                                    label="Inventory status"
                                    :options="inventoryStatusOptions"
                                />
                            </div>
                            <div v-else class="w-full sm:w-56">
                                <OperationalSelect
                                    v-model="movementType"
                                    label="Movement type"
                                    :options="inventoryMovementOptions"
                                />
                            </div>
                        </template>
                        <template #primary>
                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    :class="[button, 'flex-1 sm:flex-none']"
                                    :disabled="loading"
                                >
                                    {{ loading ? 'Applying…' : 'Apply' }}
                                </button>
                                <button
                                    type="button"
                                    :class="[button, 'flex-1 sm:flex-none']"
                                    :disabled="loading"
                                    @click="clearFilters"
                                >
                                    Clear
                                </button>
                            </div>
                        </template>
                        <template #more>
                            <div class="grid w-full gap-1 text-xs sm:w-64">
                                <span aria-hidden="true">Location</span>
                                <InventorySearchSelect
                                    v-model="location"
                                    label="Inventory location"
                                    :options="locationOptions"
                                    kind="locations"
                                    context="directory"
                                    :expected-branch-id="
                                        operations.expectedBranchId
                                    "
                                />
                            </div>
                            <label
                                v-if="inventory.tab === 'movements'"
                                class="grid gap-1 text-xs"
                                >Date from
                                <DateField
                                    v-model="dateFrom"
                                    :max="dateTo || undefined"
                                    :echo="false"
                                />
                            </label>
                            <label
                                v-if="inventory.tab === 'movements'"
                                class="grid gap-1 text-xs"
                                >Date to
                                <DateField
                                    v-model="dateTo"
                                    :min="dateFrom || undefined"
                                    :echo="false"
                                />
                            </label>
                            <label
                                v-if="inventory.tab !== 'stock'"
                                class="grid min-w-48 gap-1 text-xs"
                                >Batch
                                <Input
                                    v-model="batch"
                                    maxlength="100"
                                    placeholder="Batch number"
                                />
                            </label>
                        </template>
                    </V2Toolbar>
                    <p
                        v-if="extraFilters"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        {{ extraFilters }} extra
                        {{ extraFilters === 1 ? 'filter' : 'filters' }} applied
                    </p>
                </form>

                <ErrorState
                    v-if="filterError"
                    title="Inventory filters need attention"
                    :message="filterError"
                />
                <p class="text-[13px] text-muted-foreground" role="status">
                    {{
                        loading
                            ? 'Updating inventory…'
                            : `${inventory.total} ${inventory.total === 1 ? 'record' : 'records'} · 25 per page · times in ${inventory.branchTimezone}`
                    }}
                </p>

                <V2Table
                    v-if="inventory.tab === 'stock'"
                    label="Stock overview"
                    :columns="7"
                    cards="lg"
                    min-width="1040px"
                    :empty="inventory.data.length === 0"
                    :empty-message="`No stock records are available for ${inventory.branch} with these filters.`"
                    :loading="loading"
                >
                    <template #head>
                        <tr>
                            <th scope="col">SKU and item</th>
                            <th scope="col">Medicine</th>
                            <th scope="col">Location</th>
                            <th scope="col">Batch</th>
                            <th scope="col">Expiry</th>
                            <th scope="col" class="text-right">Quantity</th>
                            <th scope="col">Status</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="(row, index) in inventory.data"
                            :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
                        >
                            <td data-label="SKU and item" data-primary>
                                <span class="block font-medium break-words">{{
                                    row.itemName
                                }}</span>
                                <span
                                    class="block font-mono text-xs break-words text-muted-foreground"
                                    >{{ row.skuCode }}</span
                                >
                            </td>
                            <td data-label="Medicine" class="break-words">
                                {{ medicine(row.medicineName) }}
                            </td>
                            <td data-label="Location" class="break-words">
                                {{ row.location }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ row.locationType }} ·
                                    {{ row.branch }}</span
                                >
                            </td>
                            <td
                                data-label="Batch"
                                class="font-mono text-xs break-words"
                            >
                                {{ row.batchNumber }}
                            </td>
                            <td data-label="Expiry" class="whitespace-nowrap">
                                {{ row.expiryDate }}
                            </td>
                            <td
                                data-label="Quantity"
                                class="text-right font-semibold whitespace-nowrap"
                            >
                                {{ row.quantity }} {{ row.stockUnit }}
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    :tone="availabilityTone(row)"
                                    :label="
                                        inventoryStatusLabel(
                                            row,
                                            row.availabilityStatus,
                                        )
                                    "
                                />
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Table
                    v-else-if="inventory.tab === 'batches'"
                    label="Batch and expiry"
                    :columns="8"
                    cards="lg"
                    min-width="1120px"
                    :empty="inventory.data.length === 0"
                    :empty-message="`No stock records are available for ${inventory.branch} with these filters.`"
                    :loading="loading"
                >
                    <template #head>
                        <tr>
                            <th scope="col">SKU and item</th>
                            <th scope="col">Medicine</th>
                            <th scope="col">Batch</th>
                            <th scope="col">Location</th>
                            <th scope="col">Received</th>
                            <th scope="col">Expiry</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Quantity</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="(row, index) in inventory.data"
                            :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
                        >
                            <td data-label="SKU and item" data-primary>
                                <span class="block font-medium break-words">{{
                                    row.itemName
                                }}</span>
                                <span
                                    class="block font-mono text-xs break-words text-muted-foreground"
                                    >{{ row.skuCode }}</span
                                >
                            </td>
                            <td data-label="Medicine" class="break-words">
                                {{ medicine(row.medicineName) }}
                            </td>
                            <td
                                data-label="Batch"
                                class="font-mono text-xs break-words"
                            >
                                {{ row.batchNumber }}
                            </td>
                            <td data-label="Location" class="break-words">
                                {{ row.location }}
                            </td>
                            <td data-label="Received" class="whitespace-nowrap">
                                {{ row.receivedDate ?? '—' }}
                            </td>
                            <td data-label="Expiry" class="whitespace-nowrap">
                                {{ row.expiryDate }}
                            </td>
                            <td data-label="Status">
                                <div class="flex flex-wrap gap-1">
                                    <V2Chip
                                        :tone="
                                            row.expiryStatus === 'Expired'
                                                ? 'danger'
                                                : 'success'
                                        "
                                        :label="String(row.expiryStatus)"
                                    />
                                    <V2Chip
                                        :tone="batchTone(row.batchStatus)"
                                        :label="
                                            inventoryStatusLabel(
                                                row,
                                                row.batchStatus,
                                            )
                                        "
                                    />
                                </div>
                            </td>
                            <td
                                data-label="Quantity"
                                class="text-right font-semibold whitespace-nowrap"
                            >
                                {{ row.quantity }} {{ row.stockUnit }}
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Table
                    v-else
                    label="Stock movements"
                    :columns="7"
                    cards="lg"
                    min-width="1120px"
                    :empty="inventory.data.length === 0"
                    :empty-message="`No movement records are available for ${inventory.branch} with these filters.`"
                    :loading="loading"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Date and time</th>
                            <th scope="col">Type</th>
                            <th scope="col">SKU and item</th>
                            <th scope="col">Batch</th>
                            <th scope="col">Direction</th>
                            <th scope="col" class="text-right">Quantity</th>
                            <th scope="col">Reference</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="(row, index) in inventory.data"
                            :key="`${row.occurredAt}-${row.skuCode}-${row.batchNumber}-${index}`"
                        >
                            <td
                                data-label="Date and time"
                                data-primary
                                class="whitespace-nowrap"
                            >
                                {{ row.occurredAt }}
                            </td>
                            <td data-label="Type">
                                <V2Chip
                                    tone="neutral"
                                    :label="String(row.typeLabel)"
                                />
                            </td>
                            <td data-label="SKU and item">
                                <span class="block font-medium break-words">{{
                                    row.itemName
                                }}</span>
                                <span
                                    class="block font-mono text-xs break-words text-muted-foreground"
                                    >{{ row.skuCode }} ·
                                    {{ medicine(row.medicineName) }}</span
                                >
                            </td>
                            <td
                                data-label="Batch"
                                class="font-mono text-xs break-words"
                            >
                                {{ row.batchNumber }}
                            </td>
                            <td data-label="Direction">{{ row.direction }}</td>
                            <td
                                data-label="Quantity"
                                class="text-right font-semibold whitespace-nowrap"
                            >
                                {{ row.quantityDisplay }} {{ row.stockUnit }}
                            </td>
                            <td data-label="Reference" class="break-words">
                                {{ row.reference }}
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Pager
                    v-if="inventory.total > 0"
                    :current-page="inventory.currentPage"
                    :last-page="inventory.lastPage"
                    :total="inventory.total"
                    :per-page="25"
                    :disabled="loading"
                    @change="(page) => visit({ page })"
                />
                <p class="text-xs text-muted-foreground">
                    Movement records are read-only.
                </p>

                <V2DisclosureBar
                    title="Receiving, transfers and stock requests"
                >
                    <InventoryOperationsPanel
                        :operations="operations"
                        :receipt-memory-context="receiptMemoryContext"
                    />
                </V2DisclosureBar>
                <V2DisclosureBar
                    v-if="referenceData.canManage"
                    title="Stock setup"
                >
                    <InventoryReferenceDataPanel
                        :reference-data="referenceData"
                    />
                </V2DisclosureBar>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
